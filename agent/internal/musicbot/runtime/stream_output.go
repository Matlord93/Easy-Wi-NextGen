package musicbotruntime

import (
	"context"
	"crypto/subtle"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// WebradioStreamConfig is the optional webradio HTTP stream configuration.
// When Enabled is true the runtime starts an HTTP server that serves a
// continuous PCM/WAV stream on Port. The StreamToken is the plaintext token
// required for token-mode access; it is stored in the runtime config file
// (which must have 0600 permissions) and is never logged or returned in any
// status response.
type WebradioStreamConfig struct {
	Enabled        bool   `json:"enabled"`
	BindAddr       string `json:"bind_addr,omitempty"`    // default: "127.0.0.1"
	Port           int    `json:"port"`                   // required when enabled
	Slug           string `json:"slug"`                   // required: URL path segment
	AccessMode     string `json:"access_mode"`            // "public" | "private" | "token"
	StreamToken    string `json:"stream_token"`           // plaintext; never logged
	MaxListeners   int    `json:"max_listeners,omitempty"` // 0 = unlimited
	RateLimitBurst int    `json:"rate_limit_burst,omitempty"` // max new conns per IP per minute (default 20)
}

// WebradioStreamStatus is a snapshot of the stream backend state.
// It never includes the StreamToken.
type WebradioStreamStatus struct {
	Enabled        bool   `json:"enabled"`
	Running        bool   `json:"running"`
	Port           int    `json:"port"`
	AccessMode     string `json:"access_mode"`
	ListenerCount  int64  `json:"listener_count"`
	FramesReceived uint64 `json:"frames_received"`
	Slug           string `json:"slug"`
	StreamURL      string `json:"stream_url,omitempty"`
}

const (
	streamAccessPublic  = "public"
	streamAccessPrivate = "private"
	streamAccessToken   = "token"

	// wavDataSizeIndefinite signals an indefinite-length WAV stream.
	// Streaming-aware players (VLC, mpv, ffplay) accept this.
	wavDataSizeIndefinite uint32 = 0xFFFFFFFF

	defaultStreamRateLimitBurst = 20
	streamRateLimitWindow       = time.Minute
	maxStreamSlugLen            = 128
	maxStreamTokenQueryLen      = 512
)

// WebradioStreamOutput implements AudioOutput and serves connected HTTP clients
// a continuous streaming WAV (PCM s16le 48 kHz stereo) audio stream.
//
// Frames are broadcast non-blocking: clients with full receive buffers miss
// frames without stalling the audio pipeline. This is correct for live radio.
type WebradioStreamOutput struct {
	config WebradioStreamConfig
	logger *log.Logger

	mu      sync.RWMutex
	clients map[string]*streamClient
	running bool
	addr    string // actual bound address (may differ from config if port 0)

	srv      *http.Server
	listener net.Listener

	framesReceived atomic.Uint64
	listenerCount  atomic.Int64

	rateMu  sync.Mutex
	rateMap map[string][]time.Time
}

type streamClient struct {
	id       string
	remoteIP string
	ch       chan []byte
	joined   time.Time
}

// NewWebradioStreamOutput creates a WebradioStreamOutput. Call Start to bind
// the HTTP server.
func NewWebradioStreamOutput(config WebradioStreamConfig, logger *log.Logger) *WebradioStreamOutput {
	if config.RateLimitBurst <= 0 {
		config.RateLimitBurst = defaultStreamRateLimitBurst
	}
	if config.BindAddr == "" {
		config.BindAddr = "127.0.0.1"
	}
	if logger == nil {
		logger = log.New(log.Writer(), "webradio: ", log.LstdFlags)
	}
	return &WebradioStreamOutput{
		config:  config,
		logger:  logger,
		clients: make(map[string]*streamClient),
		rateMap: make(map[string][]time.Time),
	}
}

// Start binds the HTTP listener and serves clients. It returns immediately;
// the server shuts down when ctx is cancelled.
func (o *WebradioStreamOutput) Start(ctx context.Context) error {
	addr := fmt.Sprintf("%s:%d", o.config.BindAddr, o.config.Port)
	ln, err := net.Listen("tcp", addr)
	if err != nil {
		return fmt.Errorf("webradio stream: listen %s: %w", addr, err)
	}
	mux := http.NewServeMux()
	slug := o.config.Slug
	mux.HandleFunc("/stream/"+slug, o.handleStream)
	mux.HandleFunc("/stream/"+slug+"/", o.handleStream) // trailing slash
	mux.HandleFunc("/stream/"+slug+"/status", o.handleStatus)
	srv := &http.Server{
		Handler:      mux,
		ReadTimeout:  15 * time.Second,
		WriteTimeout: 0, // streaming: no write deadline
		IdleTimeout:  60 * time.Second,
	}
	o.mu.Lock()
	o.srv = srv
	o.listener = ln
	o.addr = ln.Addr().String()
	o.running = true
	o.mu.Unlock()

	o.logger.Printf("webradio stream listening addr=%s slug=%s access=%s", ln.Addr(), slug, o.config.AccessMode)
	go func() {
		if serveErr := srv.Serve(ln); serveErr != nil && serveErr != http.ErrServerClosed {
			o.logger.Printf("webradio stream server: %v", serveErr)
		}
		o.mu.Lock()
		o.running = false
		o.mu.Unlock()
	}()
	go func() {
		<-ctx.Done()
		_ = srv.Close()
	}()
	return nil
}

// Addr returns the actual bound address (e.g. "127.0.0.1:8765").
func (o *WebradioStreamOutput) Addr() string {
	o.mu.RLock()
	defer o.mu.RUnlock()
	return o.addr
}

// IsRunning reports whether the HTTP server is currently active.
func (o *WebradioStreamOutput) IsRunning() bool {
	o.mu.RLock()
	defer o.mu.RUnlock()
	return o.running
}

// OutputName implements AudioOutputName.
func (o *WebradioStreamOutput) OutputName() string { return "webradio_stream" }

// SendAudioFrame implements AudioOutput. It extracts PCM bytes from the frame
// and broadcasts them non-blocking to all connected HTTP clients.
func (o *WebradioStreamOutput) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	// DummyOpusEncoder labels PCM as "opus" and moves data to Payload; handle both.
	pcm := frame.PCM
	if len(pcm) == 0 {
		pcm = frame.Payload
	}
	if len(pcm) == 0 {
		return nil
	}
	o.framesReceived.Add(1)
	chunk := append([]byte(nil), pcm...)
	o.mu.RLock()
	defer o.mu.RUnlock()
	for _, c := range o.clients {
		select {
		case c.ch <- chunk:
		default:
			// Slow client: drop frame rather than stalling the pipeline.
		}
	}
	return nil
}

// Status returns a snapshot of the stream output state without the token.
func (o *WebradioStreamOutput) Status() WebradioStreamStatus {
	o.mu.RLock()
	running := o.running
	slug := o.config.Slug
	bindAddr := o.config.BindAddr
	o.mu.RUnlock()
	st := WebradioStreamStatus{
		Enabled:        o.config.Enabled,
		Running:        running,
		Port:           o.config.Port,
		AccessMode:     o.config.AccessMode,
		ListenerCount:  o.listenerCount.Load(),
		FramesReceived: o.framesReceived.Load(),
		Slug:           slug,
	}
	if running && slug != "" {
		st.StreamURL = fmt.Sprintf("http://%s:%d/stream/%s", bindAddr, o.config.Port, slug)
	}
	return st
}

// checkAccess validates the HTTP request against the configured access mode.
// Returns false and writes an appropriate HTTP error if access is denied.
func (o *WebradioStreamOutput) checkAccess(w http.ResponseWriter, r *http.Request) bool {
	switch strings.ToLower(strings.TrimSpace(o.config.AccessMode)) {
	case streamAccessPublic, "":
		return true
	case streamAccessPrivate:
		http.Error(w, "stream is private", http.StatusForbidden)
		return false
	case streamAccessToken:
		provided := r.URL.Query().Get("token")
		if len(provided) == 0 || len(provided) > maxStreamTokenQueryLen {
			http.Error(w, "token required", http.StatusUnauthorized)
			return false
		}
		expected := o.config.StreamToken
		if len(expected) == 0 {
			http.Error(w, "stream token not configured", http.StatusServiceUnavailable)
			return false
		}
		// Constant-time comparison prevents timing-based token guessing.
		if subtle.ConstantTimeCompare([]byte(provided), []byte(expected)) != 1 {
			http.Error(w, "invalid token", http.StatusUnauthorized)
			return false
		}
		return true
	default:
		http.Error(w, "stream access mode not recognised", http.StatusForbidden)
		return false
	}
}

// checkRateLimit returns false if the IP has exceeded the burst limit in the
// last minute. It prunes stale entries on each call.
func (o *WebradioStreamOutput) checkRateLimit(ip string) bool {
	if o.config.RateLimitBurst <= 0 {
		return true
	}
	o.rateMu.Lock()
	defer o.rateMu.Unlock()
	now := time.Now()
	cutoff := now.Add(-streamRateLimitWindow)
	prev := o.rateMap[ip]
	// Remove entries outside the window.
	j := 0
	for _, t := range prev {
		if t.After(cutoff) {
			prev[j] = t
			j++
		}
	}
	prev = prev[:j]
	if len(prev) >= o.config.RateLimitBurst {
		o.rateMap[ip] = prev
		return false
	}
	o.rateMap[ip] = append(prev, now)
	return true
}

func (o *WebradioStreamOutput) handleStream(w http.ResponseWriter, r *http.Request) {
	// Validate the slug extracted from the URL to prevent path-traversal tricks.
	rawPath := strings.TrimPrefix(r.URL.Path, "/stream/")
	rawPath = strings.TrimSuffix(rawPath, "/")
	// Strip any nested path segments (e.g. "slug/../../etc").
	if rawPath != o.config.Slug || len(rawPath) == 0 || len(rawPath) > maxStreamSlugLen ||
		strings.Contains(rawPath, "/") || strings.Contains(rawPath, "..") {
		http.NotFound(w, r)
		return
	}

	switch r.Method {
	case http.MethodHead:
		if !o.checkAccess(w, r) {
			return
		}
		w.Header().Set("Content-Type", "audio/wav")
		w.Header().Set("Cache-Control", "no-cache, no-store")
		return
	case http.MethodGet:
		// handled below
	default:
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}

	if !o.checkAccess(w, r) {
		return
	}

	remoteIP, _, _ := net.SplitHostPort(r.RemoteAddr)
	if !o.checkRateLimit(remoteIP) {
		http.Error(w, "too many connections from this address", http.StatusTooManyRequests)
		return
	}

	o.mu.RLock()
	maxL := o.config.MaxListeners
	o.mu.RUnlock()
	if maxL > 0 && int(o.listenerCount.Load()) >= maxL {
		http.Error(w, "stream is at listener capacity", http.StatusServiceUnavailable)
		return
	}

	clientID := fmt.Sprintf("%s-%d", remoteIP, time.Now().UnixNano())
	ch := make(chan []byte, 128)
	client := &streamClient{id: clientID, remoteIP: remoteIP, ch: ch, joined: time.Now()}
	o.mu.Lock()
	o.clients[clientID] = client
	o.mu.Unlock()
	o.listenerCount.Add(1)
	defer func() {
		o.mu.Lock()
		delete(o.clients, clientID)
		o.mu.Unlock()
		o.listenerCount.Add(-1)
		o.logger.Printf("webradio client disconnected ip=%s listeners=%d", remoteIP, o.listenerCount.Load())
	}()
	o.logger.Printf("webradio client connected ip=%s listeners=%d", remoteIP, o.listenerCount.Load())

	w.Header().Set("Content-Type", "audio/wav")
	w.Header().Set("Cache-Control", "no-cache, no-store")
	w.Header().Set("X-Content-Type-Options", "nosniff")
	w.Header().Set("Connection", "close")

	// 44-byte streaming WAV header with data size = 0xFFFFFFFF (indefinite).
	if _, err := w.Write(streamingWAVHeader(48000, 2, 16)); err != nil {
		return
	}
	if f, ok := w.(http.Flusher); ok {
		f.Flush()
	}

	ctx := r.Context()
	for {
		select {
		case <-ctx.Done():
			return
		case pcm, ok := <-ch:
			if !ok {
				return
			}
			if _, err := w.Write(pcm); err != nil {
				return
			}
			if f, ok := w.(http.Flusher); ok {
				f.Flush()
			}
		}
	}
}

func (o *WebradioStreamOutput) handleStatus(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet && r.Method != http.MethodHead {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	st := o.Status()
	w.Header().Set("Content-Type", "application/json")
	w.Header().Set("Cache-Control", "no-cache")
	_ = json.NewEncoder(w).Encode(st)
}

// streamingWAVHeader returns a 44-byte RIFF/WAV header for a streaming PCM
// s16le signal. The RIFF chunk size and data chunk size are both set to
// 0xFFFFFFFF to indicate an indefinite-length stream.
func streamingWAVHeader(sampleRate, channels, bitsPerSample int) []byte {
	h := make([]byte, 44)
	byteRate := uint32(sampleRate * channels * bitsPerSample / 8)
	blockAlign := uint16(channels * bitsPerSample / 8)

	copy(h[0:4], "RIFF")
	binary.LittleEndian.PutUint32(h[4:8], wavDataSizeIndefinite) // RIFF chunk size
	copy(h[8:12], "WAVE")
	copy(h[12:16], "fmt ")
	binary.LittleEndian.PutUint32(h[16:20], 16)                        // PCM subchunk size
	binary.LittleEndian.PutUint16(h[20:22], 1)                         // PCM format tag
	binary.LittleEndian.PutUint16(h[22:24], uint16(channels))          // channels
	binary.LittleEndian.PutUint32(h[24:28], uint32(sampleRate))        // sample rate
	binary.LittleEndian.PutUint32(h[28:32], byteRate)                  // byte rate
	binary.LittleEndian.PutUint16(h[32:34], blockAlign)                // block align
	binary.LittleEndian.PutUint16(h[34:36], uint16(bitsPerSample))     // bits per sample
	copy(h[36:40], "data")
	binary.LittleEndian.PutUint32(h[40:44], wavDataSizeIndefinite) // data chunk size
	return h
}

// FanOutAudioOutput delivers each audio frame to every configured output.
// Errors from individual outputs are collected and logged, but delivery
// continues to the remaining outputs. The first error is returned.
type FanOutAudioOutput struct {
	outputs []AudioOutput
	logger  *log.Logger
}

// NewFanOutAudioOutput returns a FanOutAudioOutput that forwards frames to all
// provided outputs. Nil entries are skipped. A nil logger suppresses error logs.
func NewFanOutAudioOutput(logger *log.Logger, outputs ...AudioOutput) *FanOutAudioOutput {
	filtered := make([]AudioOutput, 0, len(outputs))
	for _, o := range outputs {
		if o != nil {
			filtered = append(filtered, o)
		}
	}
	return &FanOutAudioOutput{outputs: filtered, logger: logger}
}

// SendAudioFrame implements AudioOutput.
func (f *FanOutAudioOutput) SendAudioFrame(ctx context.Context, frame AudioFrame) error {
	var firstErr error
	for _, out := range f.outputs {
		if err := out.SendAudioFrame(ctx, frame); err != nil {
			if firstErr == nil {
				firstErr = err
			}
			if f.logger != nil {
				f.logger.Printf("fanout output error (%s): %v", outputBackendName(out), err)
			}
		}
	}
	return firstErr
}

// OutputName implements AudioOutputName and returns a "+"-joined list of
// the names of all underlying outputs.
func (f *FanOutAudioOutput) OutputName() string {
	names := make([]string, 0, len(f.outputs))
	for _, out := range f.outputs {
		names = append(names, outputBackendName(out))
	}
	if len(names) == 0 {
		return "fanout(empty)"
	}
	return strings.Join(names, "+")
}
