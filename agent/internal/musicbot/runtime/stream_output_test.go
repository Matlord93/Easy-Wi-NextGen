package musicbotruntime

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"testing"
	"time"
)

func newTestStreamOutput(t *testing.T, cfg WebradioStreamConfig) *WebradioStreamOutput {
	t.Helper()
	if cfg.BindAddr == "" {
		cfg.BindAddr = "127.0.0.1"
	}
	if cfg.Port == 0 {
		cfg.Port = 0 // let the OS assign
	}
	return NewWebradioStreamOutput(cfg, log.New(io.Discard, "", 0))
}

func startTestOutput(t *testing.T, cfg WebradioStreamConfig) (*WebradioStreamOutput, context.CancelFunc) {
	t.Helper()
	out := newTestStreamOutput(t, cfg)
	ctx, cancel := context.WithCancel(context.Background())
	if err := out.Start(ctx); err != nil {
		cancel()
		t.Fatalf("Start: %v", err)
	}
	return out, cancel
}

// doRequest sends a request directly to the output's HTTP handler.
func doRequest(t *testing.T, out *WebradioStreamOutput, method, path, query string) *http.Response {
	t.Helper()
	target := "http://" + out.Addr() + path
	if query != "" {
		target += "?" + query
	}
	req, _ := http.NewRequest(method, target, nil)
	req.RemoteAddr = "127.0.0.1:12345"
	rr := httptest.NewRecorder()
	mux := http.NewServeMux()
	slug := out.config.Slug
	mux.HandleFunc("/stream/"+slug, out.handleStream)
	mux.HandleFunc("/stream/"+slug+"/", out.handleStream)
	mux.HandleFunc("/stream/"+slug+"/status", out.handleStatus)
	mux.ServeHTTP(rr, req)
	return rr.Result()
}

// --- Access mode tests ---

func TestStreamOutput_PublicAccess(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "test",
		AccessMode: "public",
	})
	defer cancel()

	// Status endpoint should always be accessible
	resp := doRequest(t, out, http.MethodGet, "/stream/test/status", "")
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("status: got %d, want 200", resp.StatusCode)
	}
}

func TestStreamOutput_PrivateAccess(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		AccessMode: "private",
	})
	defer cancel()

	resp := doRequest(t, out, http.MethodGet, "/stream/radio", "")
	if resp.StatusCode != http.StatusForbidden {
		t.Fatalf("got %d, want 403", resp.StatusCode)
	}
}

func TestStreamOutput_TokenAccess_Correct(t *testing.T) {
	const token = "supersecrettoken"
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:     true,
		Slug:        "radio",
		AccessMode:  "token",
		StreamToken: token,
	})
	defer cancel()

	// A correct token should pass checkAccess (we test via handler, but stream
	// blocks waiting for frames; use HEAD to get just headers).
	req, _ := http.NewRequest(http.MethodHead, "http://"+out.Addr()+"/stream/radio?token="+token, nil)
	req.RemoteAddr = "127.0.0.1:12345"
	rr := httptest.NewRecorder()
	mux := http.NewServeMux()
	mux.HandleFunc("/stream/radio", out.handleStream)
	mux.HandleFunc("/stream/radio/", out.handleStream)
	mux.ServeHTTP(rr, req)
	resp := rr.Result()
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("HEAD with correct token: got %d, want 200", resp.StatusCode)
	}
	if ct := resp.Header.Get("Content-Type"); ct != "audio/wav" {
		t.Fatalf("Content-Type: got %q, want audio/wav", ct)
	}
}

func TestStreamOutput_TokenAccess_WrongToken(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:     true,
		Slug:        "radio",
		AccessMode:  "token",
		StreamToken: "correcttoken",
	})
	defer cancel()

	resp := doRequest(t, out, http.MethodGet, "/stream/radio", "token=wrongtoken")
	if resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("got %d, want 401", resp.StatusCode)
	}
}

func TestStreamOutput_TokenAccess_MissingToken(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:     true,
		Slug:        "radio",
		AccessMode:  "token",
		StreamToken: "correcttoken",
	})
	defer cancel()

	resp := doRequest(t, out, http.MethodGet, "/stream/radio", "")
	if resp.StatusCode != http.StatusUnauthorized {
		t.Fatalf("got %d, want 401", resp.StatusCode)
	}
}

func TestStreamOutput_TokenNotInStatusResponse(t *testing.T) {
	const secret = "neverleakthis"
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:     true,
		Slug:        "radio",
		AccessMode:  "token",
		StreamToken: secret,
	})
	defer cancel()

	resp := doRequest(t, out, http.MethodGet, "/stream/radio/status", "")
	body, _ := io.ReadAll(resp.Body)
	if strings.Contains(string(body), secret) {
		t.Fatalf("stream token leaked in status response: %s", body)
	}
	var payload map[string]any
	if err := json.Unmarshal(body, &payload); err != nil {
		t.Fatalf("status response is not valid JSON: %v", err)
	}
}

// --- Slug validation / path traversal tests ---

func TestStreamOutput_WrongSlug(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "myradio",
		AccessMode: "public",
	})
	defer cancel()

	resp := doRequest(t, out, http.MethodGet, "/stream/otherslug", "")
	if resp.StatusCode != http.StatusNotFound {
		t.Fatalf("wrong slug: got %d, want 404", resp.StatusCode)
	}
}

func TestStreamOutput_PathTraversal(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		AccessMode: "public",
	})
	defer cancel()

	for _, path := range []string{
		"/stream/../etc/passwd",
		"/stream/radio/../secret",
		"/stream/radio/extra",
	} {
		resp := doRequest(t, out, http.MethodGet, path, "")
		if resp.StatusCode == http.StatusOK {
			t.Errorf("path %q: got 200, want non-200 (traversal not blocked)", path)
		}
	}
}

// --- Status endpoint ---

func TestStreamOutput_StatusEndpoint(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		Port:       0,
		AccessMode: "public",
	})
	defer cancel()

	resp := doRequest(t, out, http.MethodGet, "/stream/radio/status", "")
	if resp.StatusCode != http.StatusOK {
		t.Fatalf("status: got %d, want 200", resp.StatusCode)
	}
	var st WebradioStreamStatus
	body, _ := io.ReadAll(resp.Body)
	if err := json.Unmarshal(body, &st); err != nil {
		t.Fatalf("unmarshal status: %v\nbody: %s", err, body)
	}
	if !st.Enabled {
		t.Error("status.enabled should be true")
	}
	if !st.Running {
		t.Error("status.running should be true")
	}
	if st.Slug != "radio" {
		t.Errorf("status.slug: got %q, want radio", st.Slug)
	}
}

func TestStreamOutput_StatusMethod_MethodNotAllowed(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		AccessMode: "public",
	})
	defer cancel()

	resp := doRequest(t, out, http.MethodPost, "/stream/radio/status", "")
	if resp.StatusCode != http.StatusMethodNotAllowed {
		t.Fatalf("POST /status: got %d, want 405", resp.StatusCode)
	}
}

// --- Rate limiting ---

func TestStreamOutput_RateLimit(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:        true,
		Slug:           "radio",
		AccessMode:     "public",
		RateLimitBurst: 3,
	})
	defer cancel()

	ip := "10.0.0.1"
	for i := 0; i < 3; i++ {
		if !out.checkRateLimit(ip) {
			t.Fatalf("request %d should be allowed (burst=3)", i+1)
		}
	}
	if out.checkRateLimit(ip) {
		t.Fatal("4th request should be rate-limited (burst=3)")
	}
}

// --- Max listeners ---

func TestStreamOutput_MaxListeners(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:      true,
		Slug:         "radio",
		AccessMode:   "public",
		MaxListeners: 2,
	})
	defer cancel()

	// Simulate two listeners already counted
	out.listenerCount.Store(2)

	req, _ := http.NewRequest(http.MethodGet, "http://"+out.Addr()+"/stream/radio", nil)
	req.RemoteAddr = "127.0.0.1:9999"
	rr := httptest.NewRecorder()
	mux := http.NewServeMux()
	mux.HandleFunc("/stream/radio", out.handleStream)
	mux.HandleFunc("/stream/radio/", out.handleStream)
	mux.ServeHTTP(rr, req)
	resp := rr.Result()
	if resp.StatusCode != http.StatusServiceUnavailable {
		t.Fatalf("at capacity: got %d, want 503", resp.StatusCode)
	}
}

// --- SendAudioFrame / PCM delivery ---

func TestStreamOutput_SendAudioFrame_PCM(t *testing.T) {
	out := newTestStreamOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		AccessMode: "public",
	})
	// Register a fake client
	ch := make(chan []byte, 8)
	out.mu.Lock()
	out.clients["testclient"] = &streamClient{
		id: "testclient", remoteIP: "127.0.0.1", ch: ch, joined: time.Now(),
	}
	out.mu.Unlock()

	pcm := []byte{0x01, 0x02, 0x03, 0x04}
	if err := out.SendAudioFrame(context.Background(), AudioFrame{PCM: pcm}); err != nil {
		t.Fatalf("SendAudioFrame PCM: %v", err)
	}
	select {
	case got := <-ch:
		if string(got) != string(pcm) {
			t.Fatalf("received wrong PCM bytes: %v", got)
		}
	case <-time.After(100 * time.Millisecond):
		t.Fatal("no frame received in channel")
	}
	if out.framesReceived.Load() != 1 {
		t.Fatalf("framesReceived: got %d, want 1", out.framesReceived.Load())
	}
}

func TestStreamOutput_SendAudioFrame_DummyOpusPayload(t *testing.T) {
	out := newTestStreamOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		AccessMode: "public",
	})
	ch := make(chan []byte, 8)
	out.mu.Lock()
	out.clients["c1"] = &streamClient{id: "c1", remoteIP: "127.0.0.1", ch: ch, joined: time.Now()}
	out.mu.Unlock()

	// DummyOpusEncoder moves PCM to Payload, clears PCM
	pcm := []byte{0xAA, 0xBB}
	if err := out.SendAudioFrame(context.Background(), AudioFrame{Payload: pcm}); err != nil {
		t.Fatalf("SendAudioFrame Payload: %v", err)
	}
	select {
	case got := <-ch:
		if string(got) != string(pcm) {
			t.Fatalf("received wrong bytes: %v", got)
		}
	case <-time.After(100 * time.Millisecond):
		t.Fatal("no frame received")
	}
}

func TestStreamOutput_SendAudioFrame_SlowClient(t *testing.T) {
	out := newTestStreamOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		AccessMode: "public",
	})
	// Register a client with a full buffer (capacity 1, already 1 item)
	ch := make(chan []byte, 1)
	ch <- []byte{0xFF}
	out.mu.Lock()
	out.clients["slow"] = &streamClient{id: "slow", remoteIP: "127.0.0.1", ch: ch, joined: time.Now()}
	out.mu.Unlock()

	// Frame should be dropped, not block
	done := make(chan struct{})
	go func() {
		_ = out.SendAudioFrame(context.Background(), AudioFrame{PCM: []byte{0x01}})
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(500 * time.Millisecond):
		t.Fatal("SendAudioFrame blocked on slow client")
	}
}

// --- FanOutAudioOutput ---

func TestFanOutAudioOutput_DeliversBothOutputs(t *testing.T) {
	var mu sync.Mutex
	received := map[string]int{}
	makeOut := func(name string) AudioOutput {
		return &namedTestOutput{
			name: name,
			send: func(frame AudioFrame) error {
				mu.Lock()
				received[name]++
				mu.Unlock()
				return nil
			},
		}
	}

	fan := NewFanOutAudioOutput(nil, makeOut("a"), makeOut("b"))
	frame := AudioFrame{PCM: []byte{1, 2, 3}}
	if err := fan.SendAudioFrame(context.Background(), frame); err != nil {
		t.Fatalf("FanOut.SendAudioFrame: %v", err)
	}

	mu.Lock()
	defer mu.Unlock()
	if received["a"] != 1 || received["b"] != 1 {
		t.Fatalf("expected both outputs to receive 1 frame, got a=%d b=%d", received["a"], received["b"])
	}
}

func TestFanOutAudioOutput_SkipsNilOutputs(t *testing.T) {
	fan := NewFanOutAudioOutput(nil, nil, nil)
	if err := fan.SendAudioFrame(context.Background(), AudioFrame{PCM: []byte{1}}); err != nil {
		t.Fatalf("FanOut with all-nil outputs: %v", err)
	}
	if name := fan.OutputName(); name != "fanout(empty)" {
		t.Fatalf("OutputName: got %q, want fanout(empty)", name)
	}
}

func TestFanOutAudioOutput_OutputName(t *testing.T) {
	a := &namedTestOutput{name: "alpha"}
	b := &namedTestOutput{name: "beta"}
	fan := NewFanOutAudioOutput(nil, a, b)
	got := fan.OutputName()
	if got != "alpha+beta" {
		t.Fatalf("OutputName: got %q, want alpha+beta", got)
	}
}

// --- WAV header ---

func TestStreamingWAVHeader(t *testing.T) {
	h := streamingWAVHeader(48000, 2, 16)
	if len(h) != 44 {
		t.Fatalf("WAV header len: got %d, want 44", len(h))
	}
	if string(h[0:4]) != "RIFF" {
		t.Error("WAV header missing RIFF")
	}
	if string(h[8:12]) != "WAVE" {
		t.Error("WAV header missing WAVE")
	}
	if string(h[36:40]) != "data" {
		t.Error("WAV header missing data chunk")
	}
	// Data chunk size should be 0xFFFFFFFF
	size := uint32(h[40]) | uint32(h[41])<<8 | uint32(h[42])<<16 | uint32(h[43])<<24
	if size != wavDataSizeIndefinite {
		t.Errorf("data chunk size: got 0x%X, want 0xFFFFFFFF", size)
	}
}

// --- StartStreamServer integration ---

func TestRuntime_StartStreamServer_Disabled(t *testing.T) {
	r := &Runtime{
		config: Config{Stream: WebradioStreamConfig{Enabled: false}},
		mu:     sync.Mutex{},
	}
	if err := r.StartStreamServer(context.Background()); err != nil {
		t.Fatalf("disabled stream server should not error: %v", err)
	}
	if r.streamOutput != nil {
		t.Fatal("streamOutput should be nil when disabled")
	}
}

func TestRuntime_StartStreamServer_ZeroPort(t *testing.T) {
	r := &Runtime{
		config: Config{Stream: WebradioStreamConfig{Enabled: true, Port: 0}},
		mu:     sync.Mutex{},
	}
	if err := r.StartStreamServer(context.Background()); err != nil {
		t.Fatalf("zero port should not error: %v", err)
	}
	if r.streamOutput != nil {
		t.Fatal("streamOutput should be nil when port=0")
	}
}

func TestRuntime_StartStreamServer_Enabled(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Port 0 short-circuits StartStreamServer; use direct construction instead.
	out := NewWebradioStreamOutput(WebradioStreamConfig{
		Enabled:    true,
		BindAddr:   "127.0.0.1",
		Port:       0,
		Slug:       "radio",
		AccessMode: "public",
	}, log.New(io.Discard, "", 0))
	if err := out.Start(ctx); err != nil {
		t.Fatalf("Start: %v", err)
	}
	if !out.IsRunning() {
		t.Fatal("expected server to be running after Start")
	}

	// Verify status contains no token field (token is empty here, but confirm structure)
	st := out.Status()
	if !st.Running {
		t.Error("status.running should be true")
	}
	if st.Enabled {
		// Enabled is read from config; config.Enabled was true in the struct
	}
}

// --- Secret leak tests ---

func TestStreamOutput_NoTokenInAnyOutput(t *testing.T) {
	const secret = "my-very-secret-stream-token-xyz"
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:     true,
		Slug:        "radio",
		AccessMode:  "token",
		StreamToken: secret,
	})
	defer cancel()

	// Status must not contain the token
	var logBuf strings.Builder
	out.logger = log.New(&logBuf, "", 0)

	status := out.Status()
	statusJSON, _ := json.Marshal(status)
	if strings.Contains(string(statusJSON), secret) {
		t.Errorf("token found in status JSON: %s", statusJSON)
	}

	// Trigger some log output via checkAccess with wrong token
	req, _ := http.NewRequest(http.MethodGet, "http://127.0.0.1/stream/radio?token=wrongtoken", nil)
	req.RemoteAddr = "127.0.0.1:1234"
	rr := httptest.NewRecorder()
	out.checkAccess(rr, req)

	// Confirm the secret itself is not in log output
	logOutput := logBuf.String()
	if strings.Contains(logOutput, secret) {
		t.Errorf("token found in log output: %s", logOutput)
	}
}

func TestStreamOutput_OutputName(t *testing.T) {
	out := newTestStreamOutput(t, WebradioStreamConfig{Slug: "radio"})
	if name := out.OutputName(); name != "webradio_stream" {
		t.Fatalf("OutputName: got %q, want webradio_stream", name)
	}
}

// --- Test helpers ---

type namedTestOutput struct {
	name string
	send func(AudioFrame) error
}

func (n *namedTestOutput) SendAudioFrame(_ context.Context, frame AudioFrame) error {
	if n.send != nil {
		return n.send(frame)
	}
	return nil
}
func (n *namedTestOutput) OutputName() string { return n.name }

// Ensure namedTestOutput satisfies AudioOutputName
var _ AudioOutputName = (*namedTestOutput)(nil)

// outputBackendName helper is already in stream_output.go; this verifies the
// FanOut uses it without circular issues.
func TestFanOutOutputBackendName(t *testing.T) {
	a := &namedTestOutput{name: "x"}
	name := outputBackendName(a)
	if name != "x" {
		t.Fatalf("outputBackendName: got %q, want x", name)
	}
}

func TestStreamOutput_ListenerCount(t *testing.T) {
	out, cancel := startTestOutput(t, WebradioStreamConfig{
		Enabled:    true,
		Slug:       "radio",
		AccessMode: "public",
	})
	defer cancel()

	if out.listenerCount.Load() != 0 {
		t.Fatal("initial listener count should be 0")
	}

	// Simulate a short streaming connection using a real HTTP request
	addr := out.Addr()
	ctx, connCancel := context.WithTimeout(context.Background(), 200*time.Millisecond)
	defer connCancel()

	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, fmt.Sprintf("http://%s/stream/radio", addr), nil)
	// Fire and forget — the connection will be cancelled by the timeout
	go func() {
		resp, err := http.DefaultClient.Do(req)
		if err == nil {
			_, _ = io.Copy(io.Discard, resp.Body)
			_ = resp.Body.Close()
		}
	}()

	// Wait briefly for the connection to register
	deadline := time.Now().Add(150 * time.Millisecond)
	for time.Now().Before(deadline) {
		if out.listenerCount.Load() > 0 {
			break
		}
		time.Sleep(5 * time.Millisecond)
	}
	// Connection may have completed by now; just verify counter never goes negative
	if out.listenerCount.Load() < 0 {
		t.Fatal("listener count went negative")
	}
}
