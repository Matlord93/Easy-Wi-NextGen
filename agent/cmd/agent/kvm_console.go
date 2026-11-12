package main

import (
	"context"
	"encoding/xml"
	"fmt"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/vmconsole"
	"github.com/gorilla/websocket"
)

// Only the authenticated panel gateway can use this endpoint. It can reach a
// socket derived from a verified managed UUID, never a caller-supplied target.
func handleKVMConsoleHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Method != "GET" || r.Header.Get("Origin") != "" {
		http.Error(w, "console request denied", 403)
		return
	}
	id := strings.TrimPrefix(r.URL.Path, "/v1/kvm/console/")
	if !kvmUUID.MatchString(id) {
		http.NotFound(w, r)
		return
	}
	ctx, cancel := context.WithTimeout(r.Context(), 10*time.Minute)
	defer cancel()
	e, err := newKVMEngine(ctx)
	if err != nil {
		http.Error(w, "KVM unavailable", 503)
		return
	}
	var g kvmGuest
	if err := kvmReadJSON(e.guestFile(id, "manifest.json"), &g); err != nil || g.ID != id {
		http.NotFound(w, r)
		return
	}
	if err := e.verifyGuest(g); err != nil {
		http.Error(w, "managed VM identity unavailable", 409)
		return
	}
	out, err := e.virsh("dumpxml", id)
	if err != nil {
		http.Error(w, "VM unavailable", 409)
		return
	}
	var d kvmDomain
	if xml.Unmarshal(out, &d) != nil || e.verifyXML(g, out) != nil || !e.consoleConfigured(g, d) {
		http.Error(w, "VNC must be prepared while the VM is shut off", 409)
		return
	}
	path := d.Devices.Graphics[0].Listen.Socket
	if path == "" {
		path = d.Devices.Graphics[0].Socket
	}
	resolved, resolveErr := filepath.EvalSymlinks(path)
	if resolveErr != nil || !kvmAllowedConsolePath(resolved) {
		http.Error(w, "VM console not running", 409)
		return
	}
	info, err := os.Lstat(path)
	if err != nil || info.Mode()&os.ModeSocket == 0 || info.Mode().Perm()&0002 != 0 {
		http.Error(w, "VM console not running", 409)
		return
	}
	socket, err := (&net.Dialer{Timeout: 5 * time.Second}).DialContext(ctx, "unix", path)
	if err != nil {
		http.Error(w, "VM console unavailable", 503)
		return
	}
	defer socket.Close()
	deadline, _ := ctx.Deadline()
	_ = socket.SetDeadline(deadline)
	upgrader := websocket.Upgrader{Subprotocols: []string{"binary"}, CheckOrigin: func(r *http.Request) bool { return r.Header.Get("Origin") == "" }}
	ws, err := upgrader.Upgrade(w, r, nil)
	if err != nil {
		return
	}
	defer ws.Close()
	ws.SetReadLimit(1 << 20)
	_ = ws.SetReadDeadline(deadline)
	_ = ws.SetWriteDeadline(deadline)
	done := make(chan struct{})
	defer close(done)
	go func() {
		select {
		case <-ctx.Done():
			ws.Close()
			socket.Close()
		case <-done:
		}
	}()
	vmconsole.Relay(&vmconsole.Stream{Conn: ws}, socket)
}
func (e *kvmEngine) consoleConfigured(g kvmGuest, d kvmDomain) bool {
	if len(d.Devices.Graphics) != 1 {
		return false
	}
	v := d.Devices.Graphics[0]
	if v.Type != "vnc" || v.Listen.Type != "socket" || v.Port > 0 || v.Listen.Address != "" || v.Address != "" {
		return false
	}
	for _, p := range []string{v.Socket, v.Listen.Socket} {
		if p != "" && !kvmAllowedConsolePath(p) {
			return false
		}
	}
	return true
}

var kvmConsoleRoots = func() []string {
	roots := []string{"/var/lib/libvirt/qemu", "/run/libvirt/qemu", "/var/run/libvirt/qemu", fmt.Sprintf("/run/user/%d/libvirt/qemu", os.Getuid())}
	if home, err := os.UserHomeDir(); err == nil {
		roots = append(roots, filepath.Join(home, ".cache/libvirt/qemu"))
	}
	return roots
}

func kvmAllowedConsolePath(path string) bool {
	if !filepath.IsAbs(path) || filepath.Clean(path) != path || filepath.Base(path) != "vnc.sock" {
		return false
	}
	for _, root := range kvmConsoleRoots() {
		rel, err := filepath.Rel(root, path)
		if err == nil && rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator)) && rel != "." {
			return true
		}
	}
	return false
}

type kvmGraphics struct {
	Type    string `xml:"type,attr"`
	Socket  string `xml:"socket,attr,omitempty"`
	Port    int    `xml:"port,attr,omitempty"`
	Address string `xml:"listen,attr,omitempty"`
	Listen  struct {
		Type    string `xml:"type,attr"`
		Socket  string `xml:"socket,attr,omitempty"`
		Address string `xml:"address,attr,omitempty"`
	} `xml:"listen"`
}
