package statusagent

import (
	"bytes"
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

type ServerConfig struct {
	ServerID int    `json:"server_id"`
	Host     string `json:"host"`
	Port     int    `json:"port"`
	Type     string `json:"type"`
	Poll     int    `json:"poll_interval_seconds"`
}

type CheckResult struct {
	ServerID      int            `json:"server_id"`
	ObservedAt    string         `json:"observed_at"`
	Reachable     bool           `json:"reachable"`
	Status        string         `json:"status"`
	LatencyMS     *int           `json:"latency_ms,omitempty"`
	Map           *string        `json:"map,omitempty"`
	PlayersOnline *int           `json:"players_online,omitempty"`
	PlayersMax    *int           `json:"players_max,omitempty"`
	Raw           map[string]any `json:"raw,omitempty"`
	Error         *string        `json:"error,omitempty"`
}

type client struct {
	baseURL, agentID, secret string
	http                     *http.Client
}

func Run(ctx context.Context) error {
	c := &client{baseURL: strings.TrimRight(env("STATUS_BACKEND_URL", "http://127.0.0.1"), "/"), agentID: env("STATUS_AGENT_ID", "status-agent"), secret: env("STATUS_SHARED_SECRET", ""), http: &http.Client{Timeout: 5 * time.Second}}
	if c.secret == "" {
		return fmt.Errorf("STATUS_SHARED_SECRET required")
	}
	servers, err := c.pullServers(ctx)
	if err != nil {
		return err
	}
	results := make([]CheckResult, 0, len(servers))
	for _, s := range servers {
		r := checkServer(s)
		results = append(results, r)
	}
	return c.pushBatch(ctx, results)
}

func checkServer(s ServerConfig) CheckResult {
	started := time.Now()
	r := CheckResult{ServerID: s.ServerID, ObservedAt: time.Now().UTC().Format(time.RFC3339), Status: "offline"}
	ok := false
	switch {
	case strings.Contains(s.Type, "minecraft_bedrock"):
		ok = udpPing(s.Host, s.Port, []byte{0x01})
	case strings.Contains(s.Type, "minecraft"):
		ok = tcpPing(s.Host, s.Port)
	case strings.Contains(s.Type, "fivem"):
		u := url.URL{Scheme: "http", Host: net.JoinHostPort(s.Host, strconv.Itoa(s.Port)), Path: "/players.json"}
		ok = httpPing(u.String(), &r)
	case strings.Contains(s.Type, "factorio"):
		ok = udpPing(s.Host, s.Port, []byte{0x01})
	case strings.Contains(s.Type, "terraria"):
		ok = tcpPing(s.Host, s.Port)
	default:
		ok = a2sPing(s.Host, s.Port, &r)
	}
	lat := int(time.Since(started).Milliseconds())
	r.LatencyMS = &lat
	r.Reachable = ok
	if ok {
		r.Status = "online"
	}
	return r
}

func a2sPing(host string, port int, r *CheckResult) bool {
	addr := net.JoinHostPort(host, strconv.Itoa(port))
	conn, err := net.DialTimeout("udp", addr, 2*time.Second)
	if err != nil {
		e := err.Error()
		r.Error = &e
		return false
	}
	defer func() { _ = conn.Close() }()
	_ = conn.SetDeadline(time.Now().Add(2 * time.Second))
	payload := append([]byte{0xFF, 0xFF, 0xFF, 0xFF}, []byte("TSource Engine Query\x00")...)
	if _, err = conn.Write(payload); err != nil {
		e := err.Error()
		r.Error = &e
		return false
	}
	buf := make([]byte, 1400)
	n, err := conn.Read(buf)
	if err != nil || n < 8 {
		if err != nil {
			e := err.Error()
			r.Error = &e
		}
		return false
	}
	if i := bytes.IndexByte(buf[6:n], 0x00); i > 0 {
		m := string(buf[6 : 6+i])
		r.Map = &m
	}
	if n > 14 {
		p := int(buf[12])
		mx := int(buf[13])
		r.PlayersOnline = &p
		r.PlayersMax = &mx
	}
	return true
}

func tcpPing(host string, port int) bool {
	conn, err := net.DialTimeout("tcp", net.JoinHostPort(host, strconv.Itoa(port)), 3*time.Second)
	if err != nil {
		return false
	}
	_ = conn.Close()
	return true
}
func udpPing(host string, port int, p []byte) bool {
	conn, err := net.DialTimeout("udp", net.JoinHostPort(host, strconv.Itoa(port)), 2*time.Second)
	if err != nil {
		return false
	}
	defer func() { _ = conn.Close() }()
	_ = conn.SetDeadline(time.Now().Add(2 * time.Second))
	_, _ = conn.Write(p)
	b := make([]byte, 64)
	_, err = conn.Read(b)
	return err == nil
}
func httpPing(url string, r *CheckResult) bool {
	c := http.Client{Timeout: 4 * time.Second}
	resp, err := c.Get(url)
	if err != nil {
		return false
	}
	defer func() { _ = resp.Body.Close() }()
	var players []any
	_ = json.NewDecoder(resp.Body).Decode(&players)
	p := len(players)
	r.PlayersOnline = &p
	return resp.StatusCode < 400
}

func (c *client) pullServers(ctx context.Context) ([]ServerConfig, error) {
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, c.baseURL+"/api/agent/servers", nil)
	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer func() { _ = resp.Body.Close() }()
	var payload struct {
		Items []ServerConfig `json:"items"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&payload); err != nil {
		return nil, err
	}
	return payload.Items, nil
}
func (c *client) pushBatch(ctx context.Context, items []CheckResult) error {
	body, _ := json.Marshal(map[string]any{"items": items})
	ts := strconv.FormatInt(time.Now().Unix(), 10)
	h := hmac.New(sha256.New, []byte(c.secret))
	h.Write(body)
	sig := base64.StdEncoding.EncodeToString(h.Sum(nil))
	req, _ := http.NewRequestWithContext(ctx, http.MethodPost, c.baseURL+"/api/agent/status-batch", bytes.NewReader(body))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Agent-Id", c.agentID)
	req.Header.Set("X-Timestamp", ts)
	req.Header.Set("X-Signature", sig)
	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode >= 300 {
		b, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("push failed: %s", string(b))
	}
	return nil
}

func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}

func init() { _ = binary.MaxVarintLen64 }
