package vmconsole

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/gorilla/websocket"
)

type Target struct {
	Session       string `json:"session_id"`
	ExpiresAt     int64  `json:"expires_at"`
	URL           string `json:"url"`
	Authorization string `json:"authorization"`
	Password      string `json:"password"`
}
type Gateway struct {
	PanelURL string
	Origin   string
	Secret   string
	Client   *http.Client
	Dialer   *websocket.Dialer
	Recheck  time.Duration
	Lifetime time.Duration
	mu       sync.Mutex
	active   int
}

func (g *Gateway) broker(ctx context.Context, path, cookie string, body any, out any) error {
	data, err := json.Marshal(body)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, "POST", g.PanelURL+path, bytes.NewReader(data))
	if err != nil {
		return err
	}
	req.Header.Set("X-EasyWI-Console-Gateway", g.Secret)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Cookie", cookie)
	response, err := g.Client.Do(req)
	if err != nil {
		return errors.New("panel authorization unavailable")
	}
	defer response.Body.Close()
	if response.StatusCode != 200 {
		return errors.New("console authorization denied")
	}
	if out != nil {
		return json.NewDecoder(io.LimitReader(response.Body, 32<<10)).Decode(out)
	}
	return nil
}
func (g *Gateway) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	if r.URL.Path != "/vm-console/ws" || r.Method != "GET" {
		http.NotFound(w, r)
		return
	}
	if r.Header.Get("Origin") != g.Origin || !websocket.IsWebSocketUpgrade(r) {
		http.Error(w, "Console origin denied", 403)
		return
	}
	grant := ""
	for _, protocol := range websocket.Subprotocols(r) {
		if strings.HasPrefix(protocol, "easywi-console.") {
			grant = strings.TrimPrefix(protocol, "easywi-console.")
		}
	}
	if len(grant) != 64 || strings.Trim(grant, "0123456789abcdef") != "" {
		http.Error(w, "Console ticket required", 403)
		return
	}
	g.mu.Lock()
	if g.active >= 128 {
		g.mu.Unlock()
		http.Error(w, "Console capacity reached", 503)
		return
	}
	g.active++
	g.mu.Unlock()
	defer func() { g.mu.Lock(); g.active--; g.mu.Unlock() }()
	lifetime := g.Lifetime
	if lifetime == 0 {
		lifetime = 10 * time.Minute
	}
	ctx, cancel := context.WithTimeout(r.Context(), lifetime)
	defer cancel()
	var target Target
	if err := g.broker(ctx, "/customer/vm-console/connect", r.Header.Get("Cookie"), map[string]string{"ticket": grant}, &target); err != nil {
		http.Error(w, "Console authorization denied", 403)
		return
	}
	if !validTarget(target) {
		http.Error(w, "Console target unavailable", 502)
		return
	}
	if deadline, _ := ctx.Deadline(); time.Unix(target.ExpiresAt, 0).Before(deadline) {
		limited, cancelExpiry := context.WithDeadline(ctx, time.Unix(target.ExpiresAt, 0))
		defer cancelExpiry()
		ctx = limited
	}
	headers := http.Header{"Authorization": []string{target.Authorization}}
	upstream, _, err := g.Dialer.DialContext(ctx, target.URL, headers)
	if err != nil {
		http.Error(w, "Console upstream unavailable", 502)
		return
	}
	defer upstream.Close()
	upstream.SetReadLimit(4 << 20)
	_ = upstream.SetReadDeadline(time.Now().Add(15 * time.Second))
	_ = upstream.SetWriteDeadline(time.Now().Add(15 * time.Second))
	up := &Stream{Conn: upstream}
	if err := Authenticate(up, target.Password); err != nil {
		http.Error(w, "Console authentication failed", 502)
		return
	}
	target.Password = ""
	target.Authorization = ""
	target.URL = ""
	upgrader := websocket.Upgrader{Subprotocols: []string{"binary"}, CheckOrigin: func(req *http.Request) bool { return req.Header.Get("Origin") == g.Origin }}
	downstream, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	defer downstream.Close()
	downstream.SetReadLimit(1 << 20)
	_ = downstream.SetReadDeadline(time.Now().Add(15 * time.Second))
	_ = downstream.SetWriteDeadline(time.Now().Add(15 * time.Second))
	down := &Stream{Conn: downstream}
	if err := AcceptBrowser(down); err != nil {
		return
	}
	deadline, _ := ctx.Deadline()
	_ = upstream.SetReadDeadline(deadline)
	_ = upstream.SetWriteDeadline(deadline)
	_ = downstream.SetReadDeadline(deadline)
	_ = downstream.SetWriteDeadline(deadline)
	finished := make(chan struct{})
	defer close(finished)
	go func() {
		interval := g.Recheck
		if interval == 0 {
			interval = 15 * time.Second
		}
		ticker := time.NewTicker(interval)
		defer ticker.Stop()
		for {
			select {
			case <-finished:
				return
			case <-ctx.Done():
				upstream.Close()
				downstream.Close()
				return
			case <-ticker.C:
				_ = upstream.WriteControl(websocket.PingMessage, nil, time.Now().Add(5*time.Second))
				_ = downstream.WriteControl(websocket.PingMessage, nil, time.Now().Add(5*time.Second))
				if err := g.broker(ctx, "/customer/vm-console/validate", r.Header.Get("Cookie"), map[string]string{"session_id": target.Session}, nil); err != nil {
					upstream.Close()
					downstream.Close()
					return
				}
			}
		}
	}()
	Relay(down, up)
}
func validTarget(t Target) bool {
	u, err := url.Parse(t.URL)
	if err != nil || u.User != nil || u.Fragment != "" || u.Host == "" || len(t.Session) != 36 || t.ExpiresAt <= time.Now().Unix() || t.Authorization == "" || strings.ContainsAny(t.Authorization, "\r\n") {
		return false
	}
	if u.Scheme == "wss" {
		return true
	}
	return u.Scheme == "ws" && isLoopback(u.Hostname())
}
func isLoopback(host string) bool { ip := net.ParseIP(host); return ip != nil && ip.IsLoopback() }

// RunFromEnv is also available through the existing agent release binary, so a
// console gateway does not need a separate release artifact or agent config.
func RunFromEnv() error {
	secret := os.Getenv("VM_CONSOLE_GATEWAY_SECRET")
	origin := os.Getenv("VM_CONSOLE_PUBLIC_ORIGIN")
	panel := os.Getenv("VM_CONSOLE_PANEL_URL")
	listen := os.Getenv("VM_CONSOLE_LISTEN")
	if listen == "" {
		listen = "127.0.0.1:7457"
	}
	if len(secret) < 32 {
		return errors.New("VM_CONSOLE_GATEWAY_SECRET must contain at least 32 characters")
	}
	o, err := url.Parse(origin)
	if err != nil || o.Host == "" || o.User != nil || o.Path != "" || o.RawQuery != "" || o.Fragment != "" || (o.Scheme != "https" && !(o.Scheme == "http" && isLoopback(o.Hostname()))) {
		return errors.New("VM_CONSOLE_PUBLIC_ORIGIN must be an HTTPS origin (HTTP only on loopback)")
	}
	p, err := url.Parse(panel)
	if err != nil || p.Host == "" || p.User != nil || p.RawQuery != "" || p.Fragment != "" || p.Path != "" || (p.Scheme != "https" && !(p.Scheme == "http" && isLoopback(p.Hostname()))) {
		return errors.New("VM_CONSOLE_PANEL_URL must be an HTTPS origin or a loopback HTTP origin")
	}
	host, _, err := net.SplitHostPort(listen)
	if err != nil || !isLoopback(host) {
		return errors.New("console gateway must listen on loopback")
	}
	roots, err := x509.SystemCertPool()
	if err != nil {
		return err
	}
	if file := os.Getenv("VM_CONSOLE_CA_FILE"); file != "" {
		data, err := os.ReadFile(file)
		if err != nil {
			return err
		}
		if !roots.AppendCertsFromPEM(data) {
			return errors.New("invalid console CA file")
		}
	}
	tlsConfig := &tls.Config{MinVersion: tls.VersionTLS12, RootCAs: roots}
	client := &http.Client{Timeout: 10 * time.Second, Transport: &http.Transport{Proxy: http.ProxyFromEnvironment, TLSClientConfig: tlsConfig}, CheckRedirect: func(*http.Request, []*http.Request) error {
		return errors.New("console broker redirects are forbidden")
	}}
	gateway := &Gateway{PanelURL: panel, Origin: origin, Secret: secret, Client: client, Dialer: &websocket.Dialer{HandshakeTimeout: 10 * time.Second, TLSClientConfig: tlsConfig, Subprotocols: []string{"binary"}}}
	server := &http.Server{Addr: listen, Handler: gateway, ReadHeaderTimeout: 5 * time.Second, MaxHeaderBytes: 32 << 10}
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	go func() {
		<-ctx.Done()
		shutdown, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = server.Shutdown(shutdown)
	}()
	fmt.Printf("VM console gateway listening on %s\n", listen)
	if err := server.ListenAndServe(); err != http.ErrServerClosed {
		return err
	}
	return nil
}
