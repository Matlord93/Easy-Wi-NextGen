package main

import (
	"bytes"
	"testing"

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
