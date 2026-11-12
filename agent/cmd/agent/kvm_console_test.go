package main

import (
	"encoding/xml"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/vmconsole"
	"github.com/gorilla/websocket"
)

func TestKVMConsoleUsesOnlyPrivateManagedSocket(t *testing.T) {
	f := kvmNewFixture(t)
	root, err := os.MkdirTemp("/tmp", "kvnc-")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(root) })
	t.Setenv("EASYWI_KVM_ROOT", root)
	f.e, err = newKVMEngine(f.e.ctx)
	if err != nil {
		t.Fatal(err)
	}
	kvmAssertSuccess(t, f.create())
	socketDir := filepath.Join(root, "domain-test")
	if err := os.Mkdir(socketDir, 0700); err != nil {
		t.Fatal(err)
	}
	originalRoots := kvmConsoleRoots
	kvmConsoleRoots = func() []string { return []string{root} }
	t.Cleanup(func() { kvmConsoleRoots = originalRoots })
	path := filepath.Join(socketDir, "vnc.sock")
	var d kvmDomain
	if err := xml.Unmarshal(f.domains[kvmTestVM], &d); err != nil {
		t.Fatal(err)
	}
	d.Devices.Graphics[0].Listen.Socket = path
	f.domains[kvmTestVM], _ = xml.Marshal(d)
	listener, err := net.Listen("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	defer listener.Close()
	received := make(chan string, 1)
	go func() {
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		defer conn.Close()
		_ = conn.SetDeadline(time.Now().Add(3 * time.Second))
		_, _ = conn.Write([]byte("RFB 003.008\n"))
		data := make([]byte, 4)
		if _, err := io.ReadFull(conn, data); err == nil {
			received <- string(data)
		}
	}()
	server := httptest.NewServer(withBearerAuth("agent-secret", handleKVMConsoleHTTP))
	defer server.Close()
	url := strings.Replace(server.URL, "http:", "ws:", 1) + "/v1/kvm/console/" + kvmTestVM
	_, response, err := websocket.DefaultDialer.Dial(url, http.Header{"Authorization": []string{"Bearer wrong"}})
	if err == nil || response.StatusCode != 401 {
		t.Fatal("missing agent authorization accepted")
	}
	_, response, err = websocket.DefaultDialer.Dial(url, http.Header{"Authorization": []string{"Bearer agent-secret"}, "Origin": []string{"https://attacker.test"}})
	if err == nil || response.StatusCode != 403 {
		t.Fatal("browser origin accepted directly by agent")
	}
	ws, _, err := websocket.DefaultDialer.Dial(url+"?socket=/etc/shadow", http.Header{"Authorization": []string{"Bearer agent-secret"}})
	if err != nil {
		t.Fatal(err)
	}
	defer ws.Close()
	stream := &vmconsole.Stream{Conn: ws}
	banner := make([]byte, 12)
	if _, err := io.ReadFull(stream, banner); err != nil || string(banner) != "RFB 003.008\n" {
		t.Fatalf("socket data unavailable: %v", err)
	}
	_, _ = stream.Write([]byte("keys"))
	select {
	case value := <-received:
		if value != "keys" {
			t.Fatal("input changed")
		}
	case <-time.After(time.Second):
		t.Fatal("input not relayed")
	}
}
func TestKVMConsolePreparationRequiresStoppedVM(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	f.states[kvmTestVM] = "running"
	r := f.job("vm.console.configure", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
	if r.status != "failed" || !strings.Contains(r.errorText, "shut off") {
		t.Fatal("running VM redefined for console")
	}
}
