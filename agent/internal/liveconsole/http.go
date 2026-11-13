package liveconsole

import (
	"bufio"
	"context"
	"encoding/json"
	"github.com/gorilla/websocket"
	"net"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

type Server struct {
	Agent, Secret string
	SocketPath    func(string) string
	Dial          func(context.Context, string) (net.Conn, error)
	mu            sync.Mutex
	active        int
	used          map[string]int64
}

func (s *Server) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Cache-Control", "no-store")
	id := strings.TrimPrefix(r.URL.Path, "/v1/console/live/")
	if r.Method != "GET" || id == "" || strings.Trim(id, "0123456789") != "" || len(id) > 18 {
		http.NotFound(w, r)
		return
	}
	origin := r.Header.Get("Origin")
	if !websocket.IsWebSocketUpgrade(r) {
		http.Error(w, "WebSocket required", http.StatusBadRequest)
		return
	}
	c, err := verifyTicket(strings.TrimPrefix(r.Header.Get("Authorization"), "Bearer "), s.Secret, s.Agent, id, origin, time.Now())
	if err != nil {
		http.Error(w, "Console access denied", http.StatusForbidden)
		return
	}
	seq, _ := strconv.ParseUint(r.URL.Query().Get("cursor"), 10, 64)
	session := r.URL.Query().Get("session")
	if len(session) > 32 {
		http.Error(w, "Invalid cursor", http.StatusBadRequest)
		return
	}
	now := time.Now().Unix()
	s.mu.Lock()
	if s.used == nil {
		s.used = make(map[string]int64)
	}
	for key, exp := range s.used {
		if exp <= now {
			delete(s.used, key)
		}
	}
	if _, seen := s.used[c.Nonce]; seen || s.active >= 128 || len(s.used) >= 512 {
		s.mu.Unlock()
		http.Error(w, "Console capacity or authorization denied", http.StatusTooManyRequests)
		return
	}
	s.used[c.Nonce] = c.Expires
	s.active++
	s.mu.Unlock()
	defer func() { s.mu.Lock(); s.active--; s.mu.Unlock() }()
	ctx, cancel := context.WithDeadline(r.Context(), time.Unix(c.Until, 0))
	defer cancel()
	dial := s.Dial
	if dial == nil {
		dial = DialLocal
	}
	dialCtx, stopDial := context.WithTimeout(ctx, 2*time.Second)
	local, err := dial(dialCtx, s.SocketPath(id)+".output")
	stopDial()
	if err != nil {
		http.Error(w, "Live console unavailable; restart this game with the updated wrapper", http.StatusServiceUnavailable)
		return
	}
	defer func() { _ = local.Close() }()
	_ = local.SetWriteDeadline(time.Now().Add(2 * time.Second))
	if json.NewEncoder(local).Encode(Resume{session, seq}) != nil {
		http.Error(w, "Live console unavailable", http.StatusServiceUnavailable)
		return
	}
	scanner := bufio.NewScanner(local)
	scanner.Buffer(make([]byte, 8192), 16384)
	_ = local.SetReadDeadline(time.Now().Add(3 * time.Second))
	var hello Frame
	if !scanner.Scan() || json.Unmarshal(scanner.Bytes(), &hello) != nil || hello.Type != "status" || hello.Instance != id || !noncePattern.MatchString(hello.Session) {
		http.Error(w, "Console endpoint identity mismatch", http.StatusServiceUnavailable)
		return
	}
	_ = local.SetReadDeadline(time.Time{})
	upgrader := websocket.Upgrader{ReadBufferSize: 1024, WriteBufferSize: 8192, CheckOrigin: func(req *http.Request) bool { return req.Header.Get("Origin") == c.Origin }, EnableCompression: false}
	conn, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	defer func() { _ = conn.Close() }()
	conn.SetReadLimit(512)
	closeOnCancel := context.AfterFunc(ctx, func() { _ = local.Close(); _ = conn.Close() })
	defer closeOnCancel()
	_ = conn.SetReadDeadline(time.Now().Add(45 * time.Second))
	conn.SetPongHandler(func(string) error { return conn.SetReadDeadline(time.Now().Add(45 * time.Second)) })
	go func() {
		defer cancel()
		if _, _, err := conn.ReadMessage(); err != nil {
			return
		}
		_ = conn.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(websocket.ClosePolicyViolation, "Output connection is read-only"), time.Now().Add(time.Second))
	}()
	_ = conn.SetWriteDeadline(time.Now().Add(3 * time.Second))
	if conn.WriteJSON(hello) != nil {
		return
	}
	for scanner.Scan() {
		var f Frame
		if json.Unmarshal(scanner.Bytes(), &f) != nil {
			return
		}
		if f.Type == "ping" {
			if conn.WriteControl(websocket.PingMessage, nil, time.Now().Add(3*time.Second)) != nil {
				return
			}
		}
		_ = conn.SetWriteDeadline(time.Now().Add(3 * time.Second))
		if conn.WriteJSON(f) != nil {
			return
		}
	}
	_ = conn.WriteControl(websocket.CloseMessage, websocket.FormatCloseMessage(websocket.CloseGoingAway, "Console reconnect"), time.Now().Add(time.Second))
}
