package musicbotruntime

import (
	"context"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

// RadioReconnectPolicy configures automatic reconnect behaviour for radio streams.
type RadioReconnectPolicy struct {
	MaxRetries        int     `json:"max_retries"`
	RetryDelaySeconds int     `json:"retry_delay_seconds"`
	BackoffMultiplier float64 `json:"backoff_multiplier"`
}

func defaultRadioReconnectPolicy() RadioReconnectPolicy {
	return RadioReconnectPolicy{
		MaxRetries:        5,
		RetryDelaySeconds: 10,
		BackoffMultiplier: 1.5,
	}
}

// RadioSourceConfig holds configuration for a radio stream source.
type RadioSourceConfig struct {
	StreamURL       string               `json:"stream_url"`
	ResolvedURL     string               `json:"resolved_url,omitempty"`
	StationName     string               `json:"station_name,omitempty"`
	Genre           string               `json:"genre,omitempty"`
	ReconnectPolicy RadioReconnectPolicy `json:"reconnect_policy"`
}

// RadioSourceStatus represents the current status of a radio source.
type RadioSourceStatus struct {
	State          string `json:"state"`
	ConnectCount   int    `json:"connect_count"`
	RetryCount     int    `json:"retry_count"`
	LastError      string `json:"last_error,omitempty"`
	StreamName     string `json:"stream_name,omitempty"`
	Genre          string `json:"genre,omitempty"`
	ContentType    string `json:"content_type,omitempty"`
	BitrateKbps    int    `json:"bitrate_kbps,omitempty"`
	ConnectedAt    string `json:"connected_at,omitempty"`
	DisconnectedAt string `json:"disconnected_at,omitempty"`
}

// RadioStreamSource connects to a remote radio stream, reads audio data and writes it
// to an io.Writer. It handles automatic reconnection on stream failure.
type RadioStreamSource struct {
	config RadioSourceConfig
	status RadioSourceStatus
	mu     sync.Mutex
}

// NewRadioStreamSource creates a configured RadioStreamSource. If ReconnectPolicy
// has zero values, defaults are applied.
func NewRadioStreamSource(config RadioSourceConfig) *RadioStreamSource {
	if config.ReconnectPolicy.MaxRetries == 0 && config.ReconnectPolicy.RetryDelaySeconds == 0 {
		config.ReconnectPolicy = defaultRadioReconnectPolicy()
	}
	if config.ReconnectPolicy.BackoffMultiplier <= 0 {
		config.ReconnectPolicy.BackoffMultiplier = 1.5
	}
	effectiveURL := config.ResolvedURL
	if effectiveURL == "" {
		effectiveURL = config.StreamURL
	}
	config.ResolvedURL = effectiveURL

	return &RadioStreamSource{
		config: config,
		status: RadioSourceStatus{State: "idle"},
	}
}

// GetStatus returns a snapshot of the current stream status.
func (r *RadioStreamSource) GetStatus() RadioSourceStatus {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.status
}

// Stream opens the radio URL and copies audio bytes to w until the context is cancelled
// or a non-recoverable error occurs. Reconnects are attempted according to the policy.
func (r *RadioStreamSource) Stream(ctx context.Context, w io.Writer) error {
	policy := r.config.ReconnectPolicy
	effectiveURL := r.config.ResolvedURL
	retries := 0
	delay := time.Duration(policy.RetryDelaySeconds) * time.Second

	for {
		if ctx.Err() != nil {
			return ctx.Err()
		}

		r.setStatus(RadioSourceStatus{
			State:        "connecting",
			ConnectCount: r.currentConnectCount() + 1,
			RetryCount:   retries,
		})

		err := r.streamOnce(ctx, effectiveURL, w)
		if err == nil || ctx.Err() != nil {
			return err
		}

		retries++
		r.mu.Lock()
		r.status.State = "reconnecting"
		r.status.LastError = err.Error()
		r.status.RetryCount = retries
		r.status.DisconnectedAt = time.Now().UTC().Format(time.RFC3339)
		r.mu.Unlock()

		if policy.MaxRetries >= 0 && retries > policy.MaxRetries {
			r.mu.Lock()
			r.status.State = "error"
			r.mu.Unlock()
			return fmt.Errorf("radio stream failed after %d reconnect attempt(s): %w", retries-1, err)
		}

		select {
		case <-ctx.Done():
			return ctx.Err()
		case <-time.After(delay):
		}

		delay = time.Duration(float64(delay) * policy.BackoffMultiplier)
	}
}

func (r *RadioStreamSource) streamOnce(ctx context.Context, url string, w io.Writer) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return fmt.Errorf("building request: %w", err)
	}
	req.Header.Set("User-Agent", "EasyWI-Musicbot/1.0 (radio-source)")
	req.Header.Set("Icy-MetaData", "1")

	client := &http.Client{
		Timeout: 0, // streaming – no global timeout
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 10 {
				return fmt.Errorf("too many redirects")
			}
			return nil
		},
	}

	resp, err := client.Do(req)
	if err != nil {
		return fmt.Errorf("HTTP connect: %w", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("HTTP %d from radio stream", resp.StatusCode)
	}

	r.mu.Lock()
	r.status.State = "connected"
	r.status.ConnectedAt = time.Now().UTC().Format(time.RFC3339)
	r.status.ContentType = resp.Header.Get("Content-Type")
	r.status.StreamName = r.cleanICYHeader(resp.Header.Get("icy-name"))
	r.status.Genre = r.cleanICYHeader(resp.Header.Get("icy-genre"))
	if br := resp.Header.Get("icy-br"); br != "" {
		bitrateKbps, err := strconv.Atoi(strings.TrimSpace(br))
		if err == nil {
			r.status.BitrateKbps = bitrateKbps
		}
	}
	r.mu.Unlock()

	buf := make([]byte, 32*1024)
	_, copyErr := io.CopyBuffer(w, resp.Body, buf)
	if copyErr != nil && ctx.Err() == nil {
		return fmt.Errorf("stream read: %w", copyErr)
	}

	return nil
}

func (r *RadioStreamSource) setStatus(s RadioSourceStatus) {
	r.mu.Lock()
	defer r.mu.Unlock()
	prev := r.status
	s.ConnectCount = prev.ConnectCount + 1
	s.StreamName = prev.StreamName
	s.Genre = prev.Genre
	s.ContentType = prev.ContentType
	s.BitrateKbps = prev.BitrateKbps
	r.status = s
}

func (r *RadioStreamSource) currentConnectCount() int {
	r.mu.Lock()
	defer r.mu.Unlock()
	return r.status.ConnectCount
}

func (r *RadioStreamSource) cleanICYHeader(v string) string {
	return strings.TrimSpace(v)
}

// ResolvePlaylistURL attempts to extract the first HTTP stream URL from a
// M3U or PLS playlist body. Returns the original url if no playlist is detected.
func ResolvePlaylistURL(url string, body string) string {
	lower := strings.ToLower(url)
	if strings.HasSuffix(lower, ".m3u") || strings.HasSuffix(lower, ".m3u8") {
		for _, line := range strings.Split(body, "\n") {
			line = strings.TrimSpace(line)
			if line != "" && !strings.HasPrefix(line, "#") &&
				(strings.HasPrefix(line, "http://") || strings.HasPrefix(line, "https://")) {
				return line
			}
		}
	}
	if strings.HasSuffix(lower, ".pls") {
		for _, line := range strings.Split(body, "\n") {
			line = strings.TrimSpace(line)
			lower := strings.ToLower(line)
			if strings.HasPrefix(lower, "file") && strings.Contains(line, "=") {
				parts := strings.SplitN(line, "=", 2)
				if len(parts) == 2 {
					candidate := strings.TrimSpace(parts[1])
					if strings.HasPrefix(candidate, "http://") || strings.HasPrefix(candidate, "https://") {
						return candidate
					}
				}
			}
		}
	}
	return url
}
