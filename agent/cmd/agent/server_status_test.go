package main

import (
	"net"
	"strconv"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestHandleServerStatusCheckUsesA2SQuery(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen udp: %v", err)
	}
	defer func() { _ = conn.Close() }()

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
			payload := []byte{0xFF, 0xFF, 0xFF, 0xFF, 0x49, 0x11}
			payload = append(payload, []byte("srv\x00de_dust2\x00folder\x00game\x00")...)
			payload = append(payload, []byte{0xDA, 0x02, 5, 20, 0, 'd', 'l', 0, 1}...)
			_, _ = conn.WriteTo(payload, addr)
		}
	}()

	result, _ := handleServerStatusCheck(jobs.Job{
		ID: "job-a2s",
		Payload: map[string]any{
			"ip":         "127.0.0.1",
			"port":       strconv.Itoa(conn.LocalAddr().(*net.UDPAddr).Port),
			"query_type": "steam_a2s",
		},
	})

	if result.Status != "success" {
		t.Fatalf("status=%q output=%v", result.Status, result.Output)
	}
	if result.Output["status"] != "online" {
		t.Fatalf("output=%v", result.Output)
	}
	if result.Output["map"] != "de_dust2" {
		t.Fatalf("map=%q output=%v", result.Output["map"], result.Output)
	}
}

func TestHandleServerStatusCheckKeepsTCPFallback(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen tcp: %v", err)
	}
	defer func() { _ = ln.Close() }()

	go func() {
		conn, err := ln.Accept()
		if err == nil {
			_ = conn.Close()
		}
	}()

	result, _ := handleServerStatusCheck(jobs.Job{
		ID: "job-tcp",
		Payload: map[string]any{
			"ip":         "127.0.0.1",
			"port":       strconv.Itoa(ln.Addr().(*net.TCPAddr).Port),
			"query_type": "tcp",
		},
	})

	if result.Status != "success" {
		t.Fatalf("status=%q output=%v", result.Status, result.Output)
	}
	if result.Output["status"] != "online" {
		t.Fatalf("output=%v", result.Output)
	}
}
