package main

import (
	"bufio"
	"context"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"reflect"
	"strconv"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/jobs"
)

func TestVoiceActionsUseRealTeamspeakCommands(t *testing.T) {
	for _, provider := range []string{"ts3", "ts6"} {
		for _, action := range []string{"start", "stop", "restart"} {
			t.Run(provider+"/"+action, func(t *testing.T) {
				listener, err := net.Listen("tcp", "127.0.0.1:0")
				if err != nil {
					t.Fatal(err)
				}
				defer func() { _ = listener.Close() }()
				commands := make(chan []string, 1)
				go func() {
					conn, err := listener.Accept()
					if err != nil {
						commands <- nil
						return
					}
					defer func() { _ = conn.Close() }()
					_ = conn.SetDeadline(time.Now().Add(5 * time.Second))
					_, _ = fmt.Fprintln(conn, "error id=0 msg=ok")
					scanner := bufio.NewScanner(conn)
					seen := []string{}
					for scanner.Scan() {
						line := scanner.Text()
						if !strings.HasPrefix(line, "login ") {
							seen = append(seen, line)
						}
						_, _ = fmt.Fprintln(conn, "error id=0 msg=ok")
					}
					commands <- seen
				}()
				// TS6 retains the existing SSH-first/TCP-fallback implementation.
				unavailable, err := net.Listen("tcp", "127.0.0.1:0")
				if err != nil {
					t.Fatal(err)
				}
				sshPort := unavailable.Addr().(*net.TCPAddr).Port
				_ = unavailable.Close()
				job := jobs.Job{ID: "voice-integration", Payload: map[string]any{
					"provider_type": provider, "sid": "17", "query_bind_ip": "127.0.0.1",
					"query_port":     strconv.Itoa(listener.Addr().(*net.TCPAddr).Port),
					"query_ssh_port": strconv.Itoa(sshPort), "admin_username": "serveradmin", "admin_password": "fixture-only",
				}}
				result, _ := handleVoiceAction(job, action)
				if result.Status != "success" {
					t.Fatalf("action failed: %#v", result)
				}
				want := []string{"server" + action + " sid=17"}
				if action == "restart" {
					want = []string{"serverstop sid=17", "serverstart sid=17"}
				}
				if got := <-commands; !reflect.DeepEqual(want, got) {
					t.Fatalf("commands=%v, want %v", got, want)
				}
				if _, changed := job.Payload["action"]; changed {
					t.Fatal("handler mutated caller payload")
				}
			})
		}
	}
}

func TestVoiceActionCannotSucceedWithoutServerBinding(t *testing.T) {
	for _, provider := range []string{"ts3", "ts6"} {
		result, _ := handleVoiceAction(jobs.Job{ID: "old-incomplete-job", Payload: map[string]any{"provider_type": provider}}, "start")
		if result.Status != "failed" {
			t.Fatalf("incomplete %s job succeeded", provider)
		}
	}
}

func TestVoiceHttpProbeRejectsUnprovenOnlineStatus(t *testing.T) {
	for _, fixture := range []struct {
		name   string
		status int
		body   string
	}{
		{"missing", 404, `{}`}, {"empty", 204, ``}, {"no-status", 200, `{}`},
		{"unknown-status", 200, `{"status":"bogus"}`}, {"invalid-count", 200, `{"status":"online","players_online":-1}`},
	} {
		t.Run(fixture.name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
				w.WriteHeader(fixture.status)
				_, _ = w.Write([]byte(fixture.body))
			}))
			defer server.Close()
			result, err := newVoiceQueryGovernor().callServer(context.Background(), voiceTarget{endpoint: server.URL}, "fixture")
			if err == nil {
				t.Fatalf("invalid result accepted: %#v", result)
			}
		})
	}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte(`{"status":"online"}`)) }))
	defer server.Close()
	result, err := newVoiceQueryGovernor().callServer(context.Background(), voiceTarget{endpoint: server.URL}, "fixture")
	if err != nil {
		t.Fatal(err)
	}
	if result["players_online"] != "" || result["players_max"] != "" {
		t.Fatalf("unknown counts presented as measured: %#v", result)
	}
}
