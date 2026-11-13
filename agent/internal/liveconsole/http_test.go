package liveconsole

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"github.com/gorilla/websocket"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func grantFor(c Claims, secret string) string {
	raw, _ := json.Marshal(c)
	payload := base64.RawURLEncoding.EncodeToString(raw)
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(ticketDomain + payload))
	return payload + "." + base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
}
func testClaims() Claims {
	now := time.Now().Unix()
	return Claims{1, "gameserver.console.read", "node", "7", "customer", "http://panel.example.test", now, now + 30, now + 300, strings.Repeat("a", 32)}
}
func TestTicketBinding(t *testing.T) {
	c := testClaims()
	token := grantFor(c, "secret")
	if _, err := verifyTicket(token, "secret", "node", "7", c.Origin, time.Now()); err != nil {
		t.Fatal(err)
	}
	for _, tc := range []struct {
		secret, node, id, origin string
		now                      time.Time
	}{{"wrong", "node", "7", c.Origin, time.Now()}, {"secret", "foreign", "7", c.Origin, time.Now()}, {"secret", "node", "8", c.Origin, time.Now()}, {"secret", "node", "7", "https://evil.example.test", time.Now()}, {"secret", "node", "7", c.Origin, time.Now().Add(time.Minute)}} {
		if _, err := verifyTicket(token, tc.secret, tc.node, tc.id, tc.origin, tc.now); err == nil {
			t.Fatal("unbound ticket")
		}
	}
	c.Until = c.Issued + 301
	if _, err := verifyTicket(grantFor(c, "secret"), "secret", "node", "7", c.Origin, time.Now()); err == nil {
		t.Fatal("unbounded session")
	}
}
func TestWebSocketOutputAndSingleUse(t *testing.T) {
	h := NewHub()
	h.instance = "7"
	defer h.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	server := &Server{Agent: "node", Secret: "secret", SocketPath: func(string) string { return "fixture" }, Dial: func(ctx context.Context, _ string) (net.Conn, error) {
		reader, writer := net.Pipe()
		go func() {
			defer func() { _ = writer.Close() }()
			var r Resume
			if json.NewDecoder(writer).Decode(&r) != nil {
				return
			}
			history, ch, stop, err := h.Subscribe(r)
			if err != nil {
				return
			}
			defer stop()
			enc := json.NewEncoder(writer)
			for _, f := range history {
				if enc.Encode(f) != nil {
					return
				}
			}
			for {
				select {
				case <-ctx.Done():
					return
				case f, ok := <-ch:
					if !ok {
						return
					}
					if enc.Encode(f) != nil {
						return
					}
				}
			}
		}()
		return reader, nil
	}}
	// The fixture uses a long-lived parent context for its local stream.
	dial := server.Dial
	server.Dial = func(_ context.Context, p string) (net.Conn, error) { return dial(ctx, p) }
	httpServer := httptest.NewServer(server)
	defer httpServer.Close()
	c := testClaims()
	headers := http.Header{"Origin": {c.Origin}, "Authorization": {"Bearer " + grantFor(c, "secret")}}
	url := "ws" + strings.TrimPrefix(httpServer.URL, "http") + "/v1/console/live/7"
	conn, _, err := websocket.DefaultDialer.Dial(url, headers)
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = conn.Close() }()
	_ = conn.SetReadDeadline(time.Now().Add(time.Second))
	var hello Frame
	if err = conn.ReadJSON(&hello); err != nil {
		t.Fatal(err)
	}
	h.Publish([]byte("no newline"))
	var output Frame
	if err = conn.ReadJSON(&output); err != nil {
		t.Fatal(err)
	}
	if string(output.Data) != "no newline" {
		t.Fatal(output)
	}
	_, response, err := websocket.DefaultDialer.Dial(url, headers)
	if err == nil || response.StatusCode != 429 {
		t.Fatal("reused authorization accepted", err)
	}
	headers.Set("Origin", "https://evil.example.test")
	_, response, err = websocket.DefaultDialer.Dial(url, headers)
	if err == nil || response.StatusCode != 403 {
		t.Fatal("cross-origin access accepted")
	}
	h.mu.Lock()
	h.instance = "8"
	h.mu.Unlock()
	c.Nonce = strings.Repeat("b", 32)
	headers.Set("Origin", c.Origin)
	headers.Set("Authorization", "Bearer "+grantFor(c, "secret"))
	_, response, err = websocket.DefaultDialer.Dial(url, headers)
	if err == nil || response.StatusCode != 503 {
		t.Fatal("cross-instance local socket accepted")
	}
	_ = conn.Close()
	h.Publish([]byte("still running"))
	if h.seq != 2 {
		t.Fatal("viewer ended producer")
	}
}
