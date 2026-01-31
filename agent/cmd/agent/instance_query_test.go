package main

import (
	"bytes"
	"encoding/json"
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
