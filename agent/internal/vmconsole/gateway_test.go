package vmconsole

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

func TestVNCChallengeUsesBitReversedDESKey(t *testing.T) {
	// Reversing 0x80 gives the parity bit 0x01, equivalent to the standard
	// all-zero DES key. Known DES vector for an all-zero plaintext block.
	response := challengeResponse(string([]byte{0x80}), make([]byte, 16))
	if hex.EncodeToString(response) != "8ca64de9c1b123a78ca64de9c1b123a7" {
		t.Fatalf("invalid VNC DES response: %x", response)
	}
}
func fakeRFBAuth(s io.ReadWriter, password string) error {
	if _, err := s.Write([]byte("RFB 003.008\n")); err != nil {
		return err
	}
	version := make([]byte, 12)
	if _, err := io.ReadFull(s, version); err != nil {
		return err
	}
	kind := byte(1)
	if password != "" {
		kind = 2
	}
	if _, err := s.Write([]byte{1, kind}); err != nil {
		return err
	}
	choice := make([]byte, 1)
	if _, err := io.ReadFull(s, choice); err != nil {
		return err
	}
	if password != "" {
		challenge := []byte("0123456789abcdef")
		if _, err := s.Write(challenge); err != nil {
			return err
		}
		response := make([]byte, 16)
		if _, err := io.ReadFull(s, response); err != nil {
			return err
		}
		if !bytes.Equal(response, challengeResponse(password, challenge)) {
			_, _ = s.Write([]byte{0, 0, 0, 1})
			return io.ErrUnexpectedEOF
		}
	}
	_, err := s.Write(make([]byte, 4))
	return err
}

type gatewayFixture struct {
	gateway       *httptest.Server
	upstream      *httptest.Server
	broker        *httptest.Server
	revoked       atomic.Bool
	claimed       atomic.Bool
	checks        atomic.Int32
	skipTLSVerify atomic.Bool
	input         chan string
}

func newGatewayFixture(t *testing.T, lifetime time.Duration, configure ...func(*Gateway)) *gatewayFixture {
	t.Helper()
	f := &gatewayFixture{input: make(chan string, 1)}
	f.upstream = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "PVEAPIToken=synthetic-upstream-secret" {
			http.Error(w, "denied", 403)
			return
		}
		ws, err := (&websocket.Upgrader{CheckOrigin: func(*http.Request) bool { return true }}).Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer ws.Close()
		s := &Stream{Conn: ws}
		if fakeRFBAuth(s, "synthetic-vnc-password") != nil {
			return
		}
		data := make([]byte, len("client-input"))
		if _, err := io.ReadFull(s, data); err != nil {
			return
		}
		f.input <- string(data)
		_, _ = s.Write([]byte("framebuffer-bytes"))
		_, _ = s.Read(make([]byte, 1))
	}))
	f.broker = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("X-EasyWI-Console-Gateway") != "synthetic-gateway-secret" || r.Header.Get("Cookie") != "customer=synthetic-session" {
			http.Error(w, "denied", 403)
			return
		}
		if strings.HasSuffix(r.URL.Path, "/validate") {
			f.checks.Add(1)
			if f.revoked.Load() {
				http.Error(w, "revoked", 403)
				return
			}
			_, _ = w.Write([]byte(`{"valid":true}`))
			return
		}
		if f.claimed.Swap(true) {
			http.Error(w, "reused", 403)
			return
		}
		_ = json.NewEncoder(w).Encode(Target{Session: "11111111-1111-4111-8111-111111111111", ExpiresAt: time.Now().Add(5 * time.Second).Unix(), URL: strings.Replace(f.upstream.URL, "https:", "wss:", 1), Authorization: "PVEAPIToken=synthetic-upstream-secret", Password: "synthetic-vnc-password", SkipTLSVerify: f.skipTLSVerify.Load()})
	}))
	g := &Gateway{PanelURL: f.broker.URL, Origin: "https://panel.test", Secret: "synthetic-gateway-secret", Client: f.broker.Client(), Dialer: &websocket.Dialer{TLSClientConfig: f.upstream.Client().Transport.(*http.Transport).TLSClientConfig}, Recheck: 30 * time.Millisecond, Lifetime: lifetime}
	for _, fn := range configure {
		fn(g)
	}
	f.gateway = httptest.NewServer(g)
	t.Cleanup(func() { f.gateway.Close(); f.upstream.Close(); f.broker.Close() })
	return f
}

func TestGatewayTLSOptOutIsLimitedToTheBrokerTarget(t *testing.T) {
	for _, withConfig := range []bool{false, true} {
		name := "nil TLS config"
		if withConfig {
			name = "existing TLS config"
		}
		t.Run(name, func(t *testing.T) {
			var base *websocket.Dialer
			f := newGatewayFixture(t, time.Second, func(g *Gateway) {
				base = &websocket.Dialer{HandshakeTimeout: time.Second}
				if withConfig {
					base.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12}
				}
				g.Dialer = base
			})
			// The test upstream has a self-signed certificate. Verification must
			// fail before and after the explicitly unverified console succeeds.
			for _, skip := range []bool{false, true, false} {
				f.claimed.Store(false)
				f.skipTLSVerify.Store(skip)
				ws, response, err := f.dial("https://panel.test")
				if !skip {
					if ws != nil {
						ws.Close()
					}
					if err == nil || response == nil || response.StatusCode != http.StatusBadGateway {
						t.Fatal("untrusted upstream accepted without broker opt-out")
					}
					response.Body.Close()
					continue
				}
				if err != nil {
					t.Fatalf("explicit broker opt-out failed: %v", err)
				}
				ws.SetReadDeadline(time.Now().Add(time.Second))
				if err := Authenticate(&Stream{Conn: ws}, ""); err != nil {
					ws.Close()
					t.Fatal(err)
				}
				ws.Close()
			}
			if base.TLSClientConfig != nil && base.TLSClientConfig.InsecureSkipVerify {
				t.Fatal("per-target opt-out mutated shared TLS configuration")
			}
		})
	}
}
func (f *gatewayFixture) dial(origin string) (*websocket.Conn, *http.Response, error) {
	d := websocket.Dialer{Subprotocols: []string{"binary", "easywi-console." + strings.Repeat("a", 64)}}
	return d.Dial(strings.Replace(f.gateway.URL, "http:", "ws:", 1)+"/vm-console/ws", http.Header{"Origin": []string{origin}, "Cookie": []string{"customer=synthetic-session"}})
}
func TestGatewayRelaysRFBWithoutExposingPasswordAndClosesAfterRevocation(t *testing.T) {
	f := newGatewayFixture(t, 2*time.Second)
	ws, _, err := f.dial("https://panel.test")
	if err != nil {
		t.Fatal(err)
	}
	defer ws.Close()
	_ = ws.SetReadDeadline(time.Now().Add(2 * time.Second))
	stream := &Stream{Conn: ws}
	if err := Authenticate(stream, ""); err != nil {
		t.Fatalf("browser must see unauthenticated RFB over its authorized tunnel: %v", err)
	}
	if _, err := stream.Write([]byte("client-input")); err != nil {
		t.Fatal(err)
	}
	data := make([]byte, len("framebuffer-bytes"))
	if _, err := io.ReadFull(stream, data); err != nil {
		t.Fatal(err)
	}
	if string(data) != "framebuffer-bytes" || <-f.input != "client-input" {
		t.Fatal("RFB data changed")
	}
	f.revoked.Store(true)
	if _, err := stream.Read(make([]byte, 1)); err == nil {
		t.Fatal("revoked console remained open")
	}
	if f.checks.Load() == 0 {
		t.Fatal("no permission recheck")
	}
}
func TestGatewayRejectsCrossOriginBeforeConsumingGrant(t *testing.T) {
	f := newGatewayFixture(t, time.Second)
	ws, response, err := f.dial("https://attacker.test")
	if ws != nil {
		ws.Close()
	}
	if err == nil || response.StatusCode != 403 || f.claimed.Load() {
		t.Fatal("cross-origin console accepted")
	}
}
func TestGatewayRejectsReusedGrant(t *testing.T) {
	f := newGatewayFixture(t, time.Second)
	ws, _, err := f.dial("https://panel.test")
	if err != nil {
		t.Fatal(err)
	}
	defer ws.Close()
	if err := Authenticate(&Stream{Conn: ws}, ""); err != nil {
		t.Fatal(err)
	}
	other, response, err := f.dial("https://panel.test")
	if other != nil {
		other.Close()
	}
	if err == nil || response.StatusCode != 403 {
		t.Fatal("reused console grant accepted")
	}
}
func TestGatewayHardDeadlineClosesIdleSession(t *testing.T) {
	f := newGatewayFixture(t, 150*time.Millisecond)
	ws, _, err := f.dial("https://panel.test")
	if err != nil {
		t.Fatal(err)
	}
	defer ws.Close()
	stream := &Stream{Conn: ws}
	if err := Authenticate(stream, ""); err != nil {
		t.Fatal(err)
	}
	_ = ws.SetReadDeadline(time.Now().Add(time.Second))
	if _, err := stream.Read(make([]byte, 1)); err == nil {
		t.Fatal("expired console remained open")
	}
}
func TestGatewayRejectsUnsafeTarget(t *testing.T) {
	for _, raw := range []string{"ws://10.0.0.2:5900", "wss://user:pass@example.test/x", "file:///etc/shadow", "wss://example.test/x#secret"} {
		if validTarget(Target{Session: strings.Repeat("a", 36), ExpiresAt: time.Now().Add(time.Minute).Unix(), URL: raw, Authorization: "Bearer test"}) {
			t.Fatalf("unsafe target: %s", raw)
		}
	}
}
func TestBrokerNeverFollowsCredentialRedirect(t *testing.T) {
	leaked := atomic.Bool{}
	destination := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { leaked.Store(true) }))
	defer destination.Close()
	source := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, destination.URL, 302) }))
	defer source.Close()
	g := Gateway{PanelURL: source.URL, Client: &http.Client{CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}
	if g.broker(context.Background(), "/connect", "sensitive-cookie", nil, nil) == nil || leaked.Load() {
		t.Fatal("broker credentials followed a redirect")
	}
}
