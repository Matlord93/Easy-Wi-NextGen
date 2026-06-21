package musicbotruntime

import (
	"context"
	"encoding/json"
	"net"
	"path/filepath"
	"testing"
	"time"
)

func TestRuntimeControlServerStatusAndPlayback(t *testing.T) {
	t.Parallel()
	dir := t.TempDir()
	rt, err := New(Config{InstanceID: "1", CustomerID: "2", ServiceName: "musicbot-test", InstallPath: dir, DataDir: filepath.Join(dir, "data"), LogDir: filepath.Join(dir, "logs"), PluginDir: filepath.Join(dir, "plugins")}, discardWriter{})
	if err != nil {
		t.Fatalf("New() error = %v", err)
	}
	defer rt.Close()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	if err := rt.StartControlServer(ctx); err != nil {
		t.Fatalf("StartControlServer() error = %v", err)
	}
	addr := filepath.Join(dir, "control.sock")
	var conn net.Conn
	for i := 0; i < 20; i++ {
		conn, err = net.Dial("unix", addr)
		if err == nil {
			break
		}
		time.Sleep(10 * time.Millisecond)
	}
	if err != nil {
		t.Fatalf("dial control socket: %v", err)
	}
	defer conn.Close()
	if _, err := conn.Write([]byte(`{"command":"volume","args":{"value":33}}` + "\n")); err != nil {
		t.Fatalf("write command: %v", err)
	}
	var response commandResponse
	if err := json.NewDecoder(conn).Decode(&response); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if !response.OK {
		t.Fatalf("response = %#v", response)
	}
	status := rt.HandleCommand(`{"command":"status"}`)
	playback := status.Payload["playback"].(PlaybackState)
	if playback.Volume != 33 {
		t.Fatalf("volume = %d", playback.Volume)
	}
}
