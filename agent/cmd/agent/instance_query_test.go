package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net"
	"strconv"
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
			"query_type": "a2s",
			"host":       "127.0.0.1",
			"query_port": "99999",
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

	if got := normalizeQueryDialHost("  example.com  "); got != "example.com" {
		t.Fatalf("normalizeQueryDialHost(example.com)=%q, want example.com", got)
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
	if resp.ErrorCode != "CONNECTION_REFUSED" {
		t.Fatalf("error code=%s", resp.ErrorCode)
	}
}

func TestResolveQueryDialHost(t *testing.T) {
	host := resolveQueryDialHost("", "10.10.10.5", "203.0.113.9", "true")
	if host != "10.10.10.5" {
		t.Fatalf("host=%q", host)
	}

	host = resolveQueryDialHost("", "", "203.0.113.9", "false")
	if host != "203.0.113.9" {
		t.Fatalf("host=%q", host)
	}
}

func TestQueryA2SInfoHandlesChallenge(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer conn.Close()

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
	defer conn.Close()

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
	defer conn.Close()
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
			"query_type": "a2s",
			"host":       "127.0.0.1",
			"game_port":  "27015",
		},
	}

	result, _ := handleInstanceQueryCheck(job)
	if result.Status != "success" {
		t.Fatalf("status=%s", result.Status)
	}
}
