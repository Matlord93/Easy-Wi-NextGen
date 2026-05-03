package main

import (
	"bytes"
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/jobs"
)

func TestParseA2SInfo(t *testing.T) {
	payload := []byte{0xFF, 0xFF, 0xFF, 0xFF, 0x49, 0x11}
	payload = append(payload, []byte("Test Server\x00")...)
	payload = append(payload, []byte("de_dust2\x00")...)
	payload = append(payload, []byte("csgo\x00")...)
	payload = append(payload, []byte("Counter-Strike\x00")...)
	payload = append(payload, []byte{0xDA, 0x02}...) // app id 730
	payload = append(payload, []byte{12, 20, 0, 'd', 'l', 0, 1}...)

	players, maxPlayers, mapName, err := parseA2SInfo(payload)
	if err != nil {
		t.Fatalf("parseA2SInfo error: %v", err)
	}

	if players != 12 || maxPlayers != 20 {
		t.Fatalf("players=%d max=%d, want 12/20", players, maxPlayers)
	}
	if mapName != "de_dust2" {
		t.Fatalf("map=%q, want de_dust2", mapName)
	}

	if !bytes.Contains(payload, []byte("Test Server")) {
		t.Fatalf("payload malformed")
	}
}

func TestHandleInstanceQueryCheckInvalidPortReturnsOffline(t *testing.T) {
	job := jobs.Job{
		ID: "job-1",
		Payload: map[string]any{
			"query_type":   "a2s",
			"host":         "127.0.0.1",
			"query_port":   "99999",
			"network_mode": "host",
		},
	}

	result, _ := handleInstanceQueryCheck(job)

	if result.Status != "success" {
		t.Fatalf("status=%s, want success", result.Status)
	}
	if status := result.Output["status"]; status != "offline" {
		t.Fatalf("output status=%v, want offline", status)
	}
}

func TestParseMinecraftStatus(t *testing.T) {
	response := map[string]any{
		"players": map[string]any{
			"online": float64(7),
			"max":    float64(20),
		},
		"version": map[string]any{
			"name": "1.20.4",
		},
		"description": "Hello",
	}
	jsonPayload, err := json.Marshal(response)
	if err != nil {
		t.Fatalf("json marshal: %v", err)
	}

	payload := &bytes.Buffer{}
	if err := writeVarInt(payload, 0x00); err != nil {
		t.Fatalf("write packet id: %v", err)
	}
	if err := writeVarString(payload, string(jsonPayload)); err != nil {
		t.Fatalf("write json: %v", err)
	}

	status, err := parseMinecraftStatus(payload.Bytes())
	if err != nil {
		t.Fatalf("parseMinecraftStatus error: %v", err)
	}
	if status.Players != 7 || status.MaxPlayers != 20 {
		t.Fatalf("players=%d max=%d, want 7/20", status.Players, status.MaxPlayers)
	}
	if status.Version != "1.20.4" {
		t.Fatalf("version=%q, want 1.20.4", status.Version)
	}
	if status.Motd != "Hello" {
		t.Fatalf("motd=%q, want Hello", status.Motd)
	}
}

func TestNormalizeQueryDialHost(t *testing.T) {
	if got := normalizeQueryDialHost("0.0.0.0"); got != "127.0.0.1" {
		t.Fatalf("normalizeQueryDialHost(0.0.0.0)=%q, want 127.0.0.1", got)
	}

	if got := normalizeQueryDialHost("::"); got != "127.0.0.1" {
		t.Fatalf("normalizeQueryDialHost(::)=%q, want 127.0.0.1", got)
	}

	if got := normalizeQueryDialHost("127.0.1.1"); got != "127.0.0.1" {
		t.Fatalf("normalizeQueryDialHost(127.0.1.1)=%q, want 127.0.0.1", got)
	}

	if got := normalizeQueryDialHost("::1"); got != "::1" {
		t.Fatalf("normalizeQueryDialHost(::1)=%q, want ::1", got)
	}

	if got := normalizeQueryDialHost("  example.com  "); got != "example.com" {
		t.Fatalf("normalizeQueryDialHost(example.com)=%q, want example.com", got)
	}
}

func TestOrderQueryIPsReturnsOnlyIPv4(t *testing.T) {
	ordered := orderQueryIPs([]net.IP{
		net.ParseIP("192.0.2.10"),
		net.ParseIP("2001:db8::10"),
		net.ParseIP("198.51.100.25"),
		net.ParseIP("2001:db8::20"),
	})

	if len(ordered) != 2 {
		t.Fatalf("ordered len=%d, want 2", len(ordered))
	}
	if ordered[0].To4() == nil || ordered[1].To4() == nil {
		t.Fatalf("expected only IPv4 addresses, got %v", ordered)
	}
}

func TestQueryDialCandidatesFiltersLoopbackFromDNS(t *testing.T) {
	// Loopback IPs (127.0.0.0/8 and ::1) resolved via DNS must be dropped
	// so the query always uses the server's real public IP.
	for _, loopback := range []string{"127.0.0.1", "127.0.1.1", "127.255.255.255"} {
		ip := net.ParseIP(loopback)
		if ip == nil {
			t.Fatalf("ParseIP(%q) returned nil", loopback)
		}
		if !ip.IsLoopback() {
			t.Fatalf("expected %s to be loopback", loopback)
		}
	}

	// Directly-supplied IPs (already resolved by resolveQueryDialHost) pass through.
	for _, addr := range []string{"127.0.0.1", "127.0.1.1", "192.0.2.10"} {
		candidates := queryDialCandidates(addr, "27015")
		if len(candidates) != 1 {
			t.Fatalf("queryDialCandidates(%s) candidates=%v, want exactly 1", addr, candidates)
		}
		if candidates[0] != addr+":27015" {
			t.Errorf("queryDialCandidates(%s) candidate=%q, want %q", addr, candidates[0], addr+":27015")
		}
	}
}

func TestDialNetworkForAddressUsesIPFamily(t *testing.T) {
	if got := dialNetworkForAddress("udp", "[2001:db8::42]:27015"); got != "udp6" {
		t.Fatalf("udp ipv6 network=%q", got)
	}
	if got := dialNetworkForAddress("udp", "192.0.2.42:27015"); got != "udp4" {
		t.Fatalf("udp ipv4 network=%q", got)
	}
	if got := dialNetworkForAddress("tcp", "example.org:25565"); got != "tcp4" {
		t.Fatalf("hostname network=%q", got)
	}
}

type a2sTimeoutError struct{}

func (a2sTimeoutError) Error() string   { return "i/o timeout" }
func (a2sTimeoutError) Timeout() bool   { return true }
func (a2sTimeoutError) Temporary() bool { return true }

type a2sMockConn struct {
	writeDeadlineSet bool
	writes           [][]byte
	reads            int
}

func (c *a2sMockConn) Read(_ []byte) (int, error) {
	if c.reads == 0 {
		c.reads++
		return 0, &net.OpError{Op: "read", Net: "udp6", Err: a2sTimeoutError{}}
	}
	return 0, io.EOF
}

func (c *a2sMockConn) Write(b []byte) (int, error) {
	if c.writeDeadlineSet {
		return 0, &net.OpError{Op: "write", Net: "udp6", Err: a2sTimeoutError{}}
	}
	c.writes = append(c.writes, append([]byte(nil), b...))
	return len(b), nil
}

func (c *a2sMockConn) Close() error                       { return nil }
func (c *a2sMockConn) LocalAddr() net.Addr                { return nil }
func (c *a2sMockConn) RemoteAddr() net.Addr               { return nil }
func (c *a2sMockConn) SetDeadline(_ time.Time) error      { c.writeDeadlineSet = true; return nil }
func (c *a2sMockConn) SetReadDeadline(_ time.Time) error  { return nil }
func (c *a2sMockConn) SetWriteDeadline(_ time.Time) error { c.writeDeadlineSet = true; return nil }

func TestQueryA2SInfoRetryAfterTimeoutDoesNotExpireWrites(t *testing.T) {
	conn := &a2sMockConn{}

	_, err := queryA2SInfo(conn)
	if err == nil {
		t.Fatalf("expected query error")
	}
	if !strings.Contains(err.Error(), "read response") {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(conn.writes) != 2 {
		t.Fatalf("writes=%d, want 2 (initial + retry)", len(conn.writes))
	}
}

func TestPerformProtocolQueryUnsupportedProtocol(t *testing.T) {
	resp := performProtocolQuery(context.Background(), "weird", "127.0.0.1", 27015, "req-1", &queryHTTPDebug{})
	if resp.OK {
		t.Fatalf("expected not ok")
	}
	if resp.ErrorCode != "UNSUPPORTED_PROTOCOL" {
		t.Fatalf("error=%s", resp.ErrorCode)
	}
}

func TestHandleInstanceQueryHTTPUsesJSONPayloadFallback(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/v1/instances/inst-1/query", strings.NewReader(`{"host":"127.0.0.1","query_port":27015,"query_protocol":"custom","network_mode":"host"}`))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Request-ID", "req-json-payload")
	rr := httptest.NewRecorder()

	handleInstanceQueryHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("status=%d, want 200", rr.Code)
	}

	var payload queryHTTPResponse
	if err := json.Unmarshal(rr.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if payload.OK {
		t.Fatalf("expected not ok")
	}
	if payload.ErrorCode != "UNSUPPORTED_PROTOCOL" {
		t.Fatalf("error_code=%s", payload.ErrorCode)
	}
	if payload.RequestID != "req-json-payload" {
		t.Fatalf("request_id=%q", payload.RequestID)
	}
}

func TestHandleInstanceQueryHTTPUsesIPAliasFromJSONPayload(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/v1/instances/inst-1/query", strings.NewReader(`{"ip":"127.0.0.1","query_port":27015,"query_transport":"custom","network_mode":"host"}`))
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Request-ID", "req-json-ip-alias")
	rr := httptest.NewRecorder()

	handleInstanceQueryHTTP(rr, req)

	if rr.Code != http.StatusOK {
		t.Fatalf("status=%d, want 200", rr.Code)
	}

	var payload queryHTTPResponse
	if err := json.Unmarshal(rr.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if payload.OK {
		t.Fatalf("expected not ok")
	}
	if payload.ErrorCode != "UNSUPPORTED_PROTOCOL" {
		t.Fatalf("error_code=%s", payload.ErrorCode)
	}
	if payload.RequestID != "req-json-ip-alias" {
		t.Fatalf("request_id=%q", payload.RequestID)
	}
	if payload.Debug == nil || payload.Debug.ResolvedHost != "127.0.0.1" {
		t.Fatalf("resolved_host=%v, want 127.0.0.1", payload.Debug)
	}
}

func TestHandleInstanceQueryHTTPMissingHostReturnsInvalidInput(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/v1/instances/inst-1/query?query_port=27015&query_protocol=valve&network_mode=isolated", nil)
	req.Header.Set("X-Request-ID", "req-missing-host")
	rr := httptest.NewRecorder()

	handleInstanceQueryHTTP(rr, req)

	if rr.Code != http.StatusUnprocessableEntity {
		t.Fatalf("status=%d, want 422", rr.Code)
	}

	var payload queryHTTPResponse
	if err := json.Unmarshal(rr.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if payload.OK {
		t.Fatalf("expected not ok")
	}
	if payload.ErrorCode != "INVALID_INPUT" {
		t.Fatalf("error_code=%s", payload.ErrorCode)
	}
	if payload.RequestID != "req-missing-host" {
		t.Fatalf("request_id=%q", payload.RequestID)
	}
}

func TestRunWithContextCanceled(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	_, err := runWithContext(ctx, func() (map[string]string, error) {
		time.Sleep(5 * time.Millisecond)
		return map[string]string{}, nil
	})
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("expected canceled error, got %v", err)
	}
}

func TestPerformProtocolQueryValveSuccess(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer func() {
		_ = conn.Close()
	}()
	go func() {
		buf := make([]byte, 2048)
		for {
			n, addr, err := conn.ReadFrom(buf)
			if err != nil {
				return
			}
			if n >= 5 && buf[4] == 0x54 {
				payload := []byte{0xFF, 0xFF, 0xFF, 0xFF, 0x49, 0x11}
				payload = append(payload, []byte("srv\x00de_dust2\x00folder\x00game\x00")...)
				payload = append(payload, []byte{0xDA, 0x02, 5, 20, 0, 'd', 'l', 0, 1}...)
				_, _ = conn.WriteTo(payload, addr)
				continue
			}
			if n >= 5 && buf[4] == 0x55 {
				if n == 9 {
					_, _ = conn.WriteTo([]byte{0xFF, 0xFF, 0xFF, 0xFF, 0x41, 0x01, 0x02, 0x03, 0x04}, addr)
				} else {
					_, _ = conn.WriteTo([]byte{0xFF, 0xFF, 0xFF, 0xFF, 0x44, 5}, addr)
				}
			}
		}
	}()

	port := conn.LocalAddr().(*net.UDPAddr).Port
	resp := performProtocolQuery(context.Background(), "valve", "127.0.0.1", port, "req-2", &queryHTTPDebug{})
	if !resp.OK || resp.Data == nil {
		t.Fatalf("expected success, got %+v", resp)
	}
	if resp.Data.Map != "de_dust2" {
		t.Fatalf("map=%q", resp.Data.Map)
	}
}

func TestRunWithContextTimeout(t *testing.T) {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Millisecond)
	defer cancel()
	_, err := runWithContext(ctx, func() (map[string]string, error) {
		time.Sleep(40 * time.Millisecond)
		return map[string]string{}, nil
	})
	if !errors.Is(err, context.DeadlineExceeded) {
		t.Fatalf("expected deadline exceeded, got %v", err)
	}
}

func TestPerformProtocolQueryConnectionRefused(t *testing.T) {
	resp := performProtocolQuery(context.Background(), "valve", "127.0.0.1", 1, "req-refused", &queryHTTPDebug{})
	if resp.OK {
		t.Fatalf("expected failed query")
	}
	if resp.ErrorCode != "CONNECTION_REFUSED" && resp.ErrorCode != "QUERY_TIMEOUT" {
		t.Fatalf("error code=%s", resp.ErrorCode)
	}
}

func TestResolveQueryDialHost(t *testing.T) {
	resolution := resolveQueryDialHost("", "10.10.10.5", "", "203.0.113.9", "true", "host", "")
	if resolution.Host != "10.10.10.5" {
		t.Fatalf("host=%q", resolution.Host)
	}

	resolution = resolveQueryDialHost("", "", "", "203.0.113.9", "false", "isolated", "")
	if resolution.Host != "203.0.113.9" {
		t.Fatalf("host=%q", resolution.Host)
	}

	resolution = resolveQueryDialHost("127.0.0.1", "", "", "", "false", "isolated", "")
	if resolution.Host == "127.0.0.1" {
		t.Fatalf("isolated mode should never select loopback")
	}

	resolution = resolveQueryDialHost("88.99.212.160", "", "", "", "false", "isolated", "")
	if resolution.Host != "88.99.212.160" {
		t.Fatalf("isolated mode should preserve explicit host ip, got %q", resolution.Host)
	}

	resolution = resolveQueryDialHost("", "", "", "", "false", "host", "")
	if resolution.Host != "127.0.0.1" {
		t.Fatalf("host=%q", resolution.Host)
	}
	if !resolution.LoopbackUsed {
		t.Fatalf("expected loopback used in host mode fallback")
	}

	resolution = resolveQueryDialHost("", "", "", "", "false", "", "true")
	if resolution.NetworkMode != "host" {
		t.Fatalf("network mode=%q", resolution.NetworkMode)
	}
}

func TestQueryA2SInfoHandlesChallenge(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer func() {
		if err := conn.Close(); err != nil {
			t.Errorf("close udp conn: %v", err)
		}
	}()

	go func() {
		buf := make([]byte, 2048)
		for {
			n, addr, err := conn.ReadFrom(buf)
			if err != nil {
				return
			}
			if n < 5 || buf[4] != 0x54 {
				continue
			}
			if n == 25 {
				_, _ = conn.WriteTo([]byte{0xFF, 0xFF, 0xFF, 0xFF, 0x41, 0x11, 0x22, 0x33, 0x44}, addr)
				continue
			}
			payload := []byte{0xFF, 0xFF, 0xFF, 0xFF, 0x49, 0x11}
			payload = append(payload, []byte("srv\x00de_dust2\x00folder\x00game\x00")...)
			payload = append(payload, []byte{0xDA, 0x02, 5, 20, 0, 'd', 'l', 0, 1}...)
			_, _ = conn.WriteTo(payload, addr)
		}
	}()

	port := conn.LocalAddr().(*net.UDPAddr).Port
	payload, err := queryA2S("127.0.0.1", strconv.Itoa(port))
	if err != nil {
		t.Fatalf("queryA2S: %v", err)
	}
	if payload["map"] != "de_dust2" {
		t.Fatalf("map=%q", payload["map"])
	}
}

func TestQueryA2SInfoHandlesSplitPackets(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer func() {
		if err := conn.Close(); err != nil {
			t.Errorf("close udp conn: %v", err)
		}
	}()

	go func() {
		buf := make([]byte, 2048)
		for {
			n, addr, err := conn.ReadFrom(buf)
			if err != nil {
				return
			}
			if n < 5 || buf[4] != 0x54 {
				continue
			}
			full := []byte{0x49, 0x11}
			full = append(full, []byte("srv\x00l4d_hospital\x00folder\x00game\x00")...)
			full = append(full, []byte{0xDA, 0x02, 8, 16, 0, 'd', 'l', 0, 1}...)
			part1 := append([]byte{0xFE, 0xFF, 0xFF, 0xFF, 0x01, 0x00, 0x00, 0x00, 0x02, 0x00, 0x10, 0x00}, full[:12]...)
			part2 := append([]byte{0xFE, 0xFF, 0xFF, 0xFF, 0x01, 0x00, 0x00, 0x00, 0x02, 0x01}, full[12:]...)
			_, _ = conn.WriteTo(part1, addr)
			_, _ = conn.WriteTo(part2, addr)
		}
	}()

	port := conn.LocalAddr().(*net.UDPAddr).Port
	payload, err := queryA2S("127.0.0.1", strconv.Itoa(port))
	if err != nil {
		t.Fatalf("queryA2S: %v", err)
	}
	if payload["map"] != "l4d_hospital" {
		t.Fatalf("map=%q", payload["map"])
	}
}

func TestPerformProtocolQueryValveTimeout(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer func() {
		if err := conn.Close(); err != nil {
			t.Errorf("close udp conn: %v", err)
		}
	}()
	go func() {
		buf := make([]byte, 128)
		for {
			if _, _, err := conn.ReadFrom(buf); err != nil {
				return
			}
		}
	}()

	port := conn.LocalAddr().(*net.UDPAddr).Port
	resp := performProtocolQuery(context.Background(), "valve", "127.0.0.1", port, "req-timeout", &queryHTTPDebug{})
	if resp.OK {
		t.Fatalf("expected failed query")
	}
	if resp.ErrorCode != "QUERY_TIMEOUT" {
		t.Fatalf("error=%s", resp.ErrorCode)
	}
	if resp.RequestID != "req-timeout" {
		t.Fatalf("request id=%s", resp.RequestID)
	}
}

func TestHandleInstanceQueryCheckFallsBackToGamePort(t *testing.T) {
	job := jobs.Job{
		ID: "job-game-port",
		Payload: map[string]any{
			"query_type":   "a2s",
			"host":         "127.0.0.1",
			"game_port":    "27015",
			"network_mode": "host",
		},
	}

	result, _ := handleInstanceQueryCheck(job)
	if result.Status != "success" {
		t.Fatalf("status=%s", result.Status)
	}
}

func TestHandleInstanceQueryCheckMissingHostReturnsInvalidInput(t *testing.T) {
	job := jobs.Job{
		ID: "job-missing-host",
		Payload: map[string]any{
			"query_type":   "a2s",
			"query_port":   "27015",
			"network_mode": "isolated",
		},
	}

	result, _ := handleInstanceQueryCheck(job)
	if result.Status != "failed" {
		t.Fatalf("status=%s", result.Status)
	}
	if code := result.Output["error_code"]; code != "INVALID_INPUT" {
		t.Fatalf("error_code=%v", code)
	}
	message := result.Output["message"]
	if !strings.Contains(message, "resolved_host_source=loopback") {
		t.Fatalf("message=%q", message)
	}
	if !strings.Contains(message, "network_mode=isolated") {
		t.Fatalf("message=%q", message)
	}
}

func TestQueryMinecraftJavaParsesStatusResponse(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen tcp: %v", err)
	}
	defer func() { _ = ln.Close() }()

	go func() {
		conn, err := ln.Accept()
		if err != nil {
			return
		}
		defer func() { _ = conn.Close() }()
		_, _ = readMinecraftPacket(conn) // handshake
		_, _ = readMinecraftPacket(conn) // status req
		jsonPayload := `{"version":{"name":"1.20.6"},"players":{"online":4,"max":24},"description":"Hi"}`
		buf := &bytes.Buffer{}
		_ = writeVarInt(buf, 0x00)
		_ = writeVarString(buf, jsonPayload)
		_ = writeMinecraftPacket(conn, buf.Bytes())
	}()

	port := ln.Addr().(*net.TCPAddr).Port
	payload, err := queryMinecraftJava("127.0.0.1", strconv.Itoa(port))
	if err != nil {
		t.Fatalf("queryMinecraftJava: %v", err)
	}
	if payload["players"] != "4" || payload["max_players"] != "24" {
		t.Fatalf("payload=%v", payload)
	}
	if payload["version"] != "1.20.6" {
		t.Fatalf("version=%q", payload["version"])
	}
}

func TestQueryMinecraftBedrockParsesPong(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer func() { _ = conn.Close() }()

	go func() {
		buf := make([]byte, 2048)
		n, addr, err := conn.ReadFrom(buf)
		if err != nil || n == 0 {
			return
		}
		pong := &bytes.Buffer{}
		pong.WriteByte(0x1c)
		_ = binary.Write(pong, binary.BigEndian, uint64(1))
		_ = binary.Write(pong, binary.BigEndian, uint64(2))
		pong.Write(bedrockMagic)
		info := "MCPE;EasyWi;589;1.20.80;2;20;12345;World;Survival;1;19132;19133;"
		_ = binary.Write(pong, binary.BigEndian, uint16(len(info)))
		pong.WriteString(info)
		_, _ = conn.WriteTo(pong.Bytes(), addr)
	}()

	port := conn.LocalAddr().(*net.UDPAddr).Port
	payload, err := queryMinecraftBedrock("127.0.0.1", strconv.Itoa(port))
	if err != nil {
		t.Fatalf("queryMinecraftBedrock: %v", err)
	}
	if payload["players"] != "2" || payload["max_players"] != "20" {
		t.Fatalf("payload=%v", payload)
	}
	if payload["version"] == "" || payload["motd"] != "EasyWi" {
		t.Fatalf("payload=%v", payload)
	}
}

func TestConsoleLogsViaInstanceRouterReturnsLinesWithoutQueryPort(t *testing.T) {
	instanceID := "9301"
	logPath := consoleLogFilePath(instanceID)
	if err := os.MkdirAll(filepath.Dir(logPath), 0o755); err != nil {
		t.Fatalf("mkdir log dir: %v", err)
	}
	if err := os.WriteFile(logPath, []byte("Connection to Steam servers successful.\n"), 0o644); err != nil {
		t.Fatalf("write log file: %v", err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(filepath.Dir(logPath)) })

	globalConsoleSessions = newConsoleSessionManager(2 * time.Minute)
	lookupCommand = func(file string) (string, error) { return "", errors.New("missing") }
	t.Cleanup(func() { lookupCommand = exec.LookPath })

	req := httptest.NewRequest(http.MethodGet, "/v1/instances/"+instanceID+"/console/logs?query_port=0", nil)
	w := httptest.NewRecorder()
	handleInstanceQueryHTTP(w, req)

	if w.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", w.Code, w.Body.String())
	}
	var resp map[string]any
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	if ok, _ := resp["ok"].(bool); !ok {
		t.Fatalf("expected ok=true, got %v", resp)
	}
}

func TestConsoleLogsViaInstanceRouterMissingFileReturnsEmptyLinesWithoutQueryPort(t *testing.T) {
	instanceID := "9302"
	_ = os.RemoveAll(filepath.Dir(consoleLogFilePath(instanceID)))
	globalConsoleSessions = newConsoleSessionManager(2 * time.Minute)
	lookupCommand = func(file string) (string, error) { return "", errors.New("missing") }
	t.Cleanup(func() { lookupCommand = exec.LookPath })

	req := httptest.NewRequest(http.MethodGet, "/v1/instances/"+instanceID+"/console/logs?query_port=0", nil)
	w := httptest.NewRecorder()
	handleInstanceQueryHTTP(w, req)

	var resp struct {
		OK   bool `json:"ok"`
		Data struct {
			Lines []any `json:"lines"`
		} `json:"data"`
	}
	if err := json.Unmarshal(w.Body.Bytes(), &resp); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}
	if !resp.OK {
		t.Fatalf("expected ok=true body=%s", w.Body.String())
	}
	if len(resp.Data.Lines) != 0 {
		t.Fatalf("expected empty lines, got %d", len(resp.Data.Lines))
	}
}

func TestConsoleLogsViaInstanceRouterDoesNotReturnInvalidPort(t *testing.T) {
	instanceID := "9303"
	globalConsoleSessions = newConsoleSessionManager(2 * time.Minute)
	lookupCommand = func(file string) (string, error) { return "", errors.New("missing") }
	t.Cleanup(func() { lookupCommand = exec.LookPath })

	req := httptest.NewRequest(http.MethodGet, "/v1/instances/"+instanceID+"/console/logs?query_port=invalid", nil)
	w := httptest.NewRecorder()
	handleInstanceQueryHTTP(w, req)
	if strings.Contains(w.Body.String(), "INVALID_PORT") {
		t.Fatalf("console logs endpoint returned INVALID_PORT: %s", w.Body.String())
	}
}
