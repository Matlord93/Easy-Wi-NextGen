package main

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestAdapterSelection(t *testing.T) {
	cases := []struct {
		backend string
		want    string
	}{
		{"", "*main.PlaceholderAdapter"},
		{"placeholder", "*main.PlaceholderAdapter"},
		{"native_sdk", "*main.NativeSDKAdapter"},
		{"client_library", "*main.ClientLibraryAdapter"},
		{"disabled", "*main.DisabledAdapter"},
		{"unknown", "*main.PlaceholderAdapter"},
	}
	for _, tc := range cases {
		got := NewTeamspeakClientAdapter(tc.backend, "")
		if gotType := fmt.Sprintf("%T", got); gotType != tc.want {
			t.Fatalf("backend %q selected %s, want %s", tc.backend, gotType, tc.want)
		}
	}
}

func TestPlaceholderStatusNotReady(t *testing.T) {
	status, err := NewPlaceholderAdapter().Status(context.Background())
	if err != nil {
		t.Fatalf("status failed: %v", err)
	}
	if status.Ready || status.State == stateConnected {
		t.Fatalf("placeholder reported ready/connected: %#v", status)
	}
}

func TestNativeSDKAdapterWithoutSDKClearError(t *testing.T) {
	_, err := NewNativeSDKAdapter("").Connect(context.Background(), connectParams{})
	if err == nil {
		t.Fatal("expected native_sdk without SDK path to fail")
	}
	if !strings.Contains(err.Error(), "native_sdk") || !strings.Contains(err.Error(), "backend_path") {
		t.Fatalf("native_sdk error = %q, want backend/path context", err.Error())
	}
}

func TestClientLibraryAdapterWithoutLibraryClearError(t *testing.T) {
	_, err := NewClientLibraryAdapter("").Connect(context.Background(), connectParams{})
	if err == nil {
		t.Fatal("expected client_library without library path to fail")
	}
	if !strings.Contains(err.Error(), "client_library") || !strings.Contains(err.Error(), "backend_path") {
		t.Fatalf("client_library error = %q, want backend/path context", err.Error())
	}
}

func TestValidateBackendPathRejectsRelativePath(t *testing.T) {
	if err := validateBackendPath("relative/path"); err == nil {
		t.Fatal("expected relative backend_path to be rejected")
	}
}

func TestValidateBackendPathRejectsNonExecutable(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "not-executable")
	if err := os.WriteFile(path, []byte("binary"), 0o644); err != nil {
		t.Fatalf("setup: %v", err)
	}
	if err := validateBackendPath(path); err == nil {
		t.Fatal("expected non-executable backend_path to be rejected")
	}
}

func TestValidateBackendPathAcceptsExecutable(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "ts-helper")
	if err := os.WriteFile(path, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatalf("setup: %v", err)
	}
	if err := validateBackendPath(path); err != nil {
		t.Fatalf("expected executable backend_path to be accepted, got: %v", err)
	}
}

func TestValidateClientBinaryNameRejectsSinusbot(t *testing.T) {
	if err := validateClientBinaryName("/opt/sinusbot/sinusbot"); err == nil {
		t.Fatal("expected sinusbot to be rejected")
	}
}

func TestValidateClientBinaryNameRejectsTs3AudioBot(t *testing.T) {
	if err := validateClientBinaryName("/opt/ts3audiobot/TS3AudioBot"); err == nil {
		t.Fatal("expected ts3audiobot to be rejected")
	}
}

func TestValidateClientBinaryNameAcceptsOther(t *testing.T) {
	if err := validateClientBinaryName("/opt/ts-client-helper/ts-bridge"); err != nil {
		t.Fatalf("expected allowed binary to pass, got: %v", err)
	}
}

// writeMockBridge writes an executable shell script that speaks the bridge NDJSON
// protocol. If failConnect is true the connect response has ok:false.
func writeMockBridge(t *testing.T, failConnect bool) string {
	t.Helper()
	dir := t.TempDir()
	path := filepath.Join(dir, "mock-ts-bridge")
	var connectResp string
	if failConnect {
		connectResp = `{"ok":false,"error":"mock connect failed"}`
	} else {
		connectResp = `{"ok":true,"state":"connected","client_id":"mock-client-001","ready":true}`
	}
	script := "#!/bin/sh\n" +
		"while IFS= read -r line; do\n" +
		"case \"$line\" in\n" +
		"*disconnect*|*shutdown*) echo '{\"ok\":true}' ; exit 0 ;;\n" +
		"*reconnect*) echo '{\"ok\":true,\"state\":\"connected\",\"client_id\":\"mock-reconnect-id\",\"ready\":true}' ;;\n" +
		"*connect*) echo '" + connectResp + "' ;;\n" +
		"*join_channel*) echo '{\"ok\":true,\"channel_id\":\"ch-99\"}' ;;\n" +
		"*leave_channel*) echo '{\"ok\":true}' ;;\n" +
		"*set_nickname*) echo '{\"ok\":true}' ;;\n" +
		"*send_opus_frame*) echo '{\"ok\":true}' ;;\n" +
		"*status*) echo '{\"ok\":true,\"state\":\"connected\",\"client_id\":\"mock-client-001\",\"ready\":true}' ;;\n" +
		"*) echo '{\"ok\":true}' ;;\n" +
		"esac\n" +
		"done\n"
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writeMockBridge: %v", err)
	}
	return path
}

func TestClientLibraryAdapterConnectJoinSendReconnect(t *testing.T) {
	mockPath := writeMockBridge(t, false)
	adapter := NewClientLibraryAdapter(mockPath)
	ctx := context.Background()

	clientID, err := adapter.Connect(ctx, connectParams{Host: "ts.example.com", Port: 9987})
	if err != nil {
		t.Fatalf("Connect: %v", err)
	}
	if clientID == "" {
		t.Fatal("Connect: expected non-empty clientID")
	}

	channelID, err := adapter.JoinChannel(ctx, "42", "")
	if err != nil {
		t.Fatalf("JoinChannel: %v", err)
	}
	if channelID == "" {
		t.Fatal("JoinChannel: expected non-empty channelID")
	}

	if err := adapter.SendOpusFrame(ctx, []byte("opusdata"), 20); err != nil {
		t.Fatalf("SendOpusFrame: %v", err)
	}

	reconnectID, err := adapter.Reconnect(ctx)
	if err != nil {
		t.Fatalf("Reconnect: %v", err)
	}
	if reconnectID == "" {
		t.Fatal("Reconnect: expected non-empty clientID")
	}

	if err := adapter.Disconnect(ctx); err != nil {
		t.Fatalf("Disconnect: %v", err)
	}
}

func TestNativeSDKAdapterConnectStatus(t *testing.T) {
	mockPath := writeMockBridge(t, false)
	adapter := NewNativeSDKAdapter(mockPath)
	ctx := context.Background()

	clientID, err := adapter.Connect(ctx, connectParams{Host: "ts.example.com", Port: 9987})
	if err != nil {
		t.Fatalf("Connect: %v", err)
	}
	if clientID == "" {
		t.Fatal("Connect: expected non-empty clientID")
	}

	status, err := adapter.Status(ctx)
	if err != nil {
		t.Fatalf("Status: %v", err)
	}
	if !status.Ready {
		t.Fatalf("Status: expected ready=true after connect, got %#v", status)
	}
	if status.State != stateConnected {
		t.Fatalf("Status: expected state=connected, got %q", status.State)
	}

	if err := adapter.Shutdown(ctx); err != nil {
		t.Fatalf("Shutdown: %v", err)
	}
}

func TestProcessBackedAdapterStatusWhenDisconnected(t *testing.T) {
	adapter := NewClientLibraryAdapter("/nonexistent/path/ignored")
	status, err := adapter.Status(context.Background())
	if err != nil {
		t.Fatalf("Status: %v", err)
	}
	if status.Ready {
		t.Fatal("Status: disconnected adapter must not report ready=true")
	}
	if status.State == stateConnected {
		t.Fatal("Status: disconnected adapter must not report state=connected")
	}
}

func TestProcessBackedAdapterConnectFailsGracefully(t *testing.T) {
	mockPath := writeMockBridge(t, true)
	adapter := NewClientLibraryAdapter(mockPath)
	ctx := context.Background()

	_, err := adapter.Connect(ctx, connectParams{Host: "ts.example.com", Port: 9987})
	if err == nil {
		t.Fatal("expected Connect to fail when subprocess returns ok:false")
	}
	if !strings.Contains(err.Error(), "mock connect failed") {
		t.Fatalf("Connect error = %q, want 'mock connect failed'", err.Error())
	}

	// after failed connect the adapter must report not-ready
	status, statusErr := adapter.Status(context.Background())
	if statusErr != nil {
		t.Fatalf("Status after failed connect: %v", statusErr)
	}
	if status.Ready {
		t.Fatal("Status: adapter must not report ready after failed connect")
	}
}

func TestProcessBackedAdapterSetNicknameLeaveChannel(t *testing.T) {
	mockPath := writeMockBridge(t, false)
	adapter := NewClientLibraryAdapter(mockPath)
	ctx := context.Background()

	if _, err := adapter.Connect(ctx, connectParams{Host: "ts.example.com", Port: 9987}); err != nil {
		t.Fatalf("Connect: %v", err)
	}
	if err := adapter.SetNickname(ctx, "BotNick"); err != nil {
		t.Fatalf("SetNickname: %v", err)
	}
	if _, err := adapter.JoinChannel(ctx, "10", "pw"); err != nil {
		t.Fatalf("JoinChannel: %v", err)
	}
	if err := adapter.LeaveChannel(ctx); err != nil {
		t.Fatalf("LeaveChannel: %v", err)
	}
	if err := adapter.Authenticate(ctx); err != nil {
		t.Fatalf("Authenticate: %v", err)
	}
	_ = adapter.Disconnect(ctx)
}

func TestClientLibraryAdapterRejectsSinusbotPath(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sinusbot")
	if err := os.WriteFile(path, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatalf("setup: %v", err)
	}
	adapter := NewClientLibraryAdapter(path)
	_, err := adapter.Connect(context.Background(), connectParams{})
	if err == nil {
		t.Fatal("expected sinusbot binary to be rejected")
	}
	if !strings.Contains(err.Error(), "sinusbot") {
		t.Fatalf("error should mention sinusbot, got: %q", err.Error())
	}
}
