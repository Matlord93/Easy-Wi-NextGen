// Browser integration fixture: real WebSocket/RFB gateway, synthetic desktops.
// Not included by go test ./... or used by the production agent.
package main

import (
	"encoding/binary"
	"encoding/json"
	"flag"
	"io"
	"log"
	"net/http"
	"net/http/httptest"
	"net/http/httputil"
	"net/url"
	"os"
	"strings"
	"sync"
	"time"

	"easywi/agent/internal/vmconsole"
	"github.com/gorilla/websocket"
)

func main() {
	address := flag.String("listen", "127.0.0.1:8006", "preview address")
	panel := flag.String("panel", "http://127.0.0.1:8007", "isolated PHP preview")
	state := flag.String("state", "/tmp/vm-console-preview.json", "state file")
	flag.Parse()
	var mu sync.Mutex
	counts := map[string]int{}
	backendURL := ""
	save := func() {
		data, _ := json.Marshal(map[string]any{"upstream": backendURL, "counts": counts})
		_ = os.WriteFile(*state, data, 0600)
	}
	backend := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		kind := "proxmox"
		password := true
		expected := "PVEAPIToken=panel@pve!test=synthetic-pve-secret"
		if strings.HasPrefix(r.URL.Path, "/v1/kvm/console/") {
			kind = "kvm"
			password = false
			expected = "Bearer synthetic-agent-secret"
		}
		if r.Header.Get("Authorization") != expected {
			http.Error(w, "denied", 403)
			return
		}
		ws, err := (&websocket.Upgrader{CheckOrigin: func(*http.Request) bool { return true }}).Upgrade(w, r, nil)
		if err != nil {
			return
		}
		defer ws.Close()
		stream := &vmconsole.Stream{Conn: ws}
		mu.Lock()
		counts[kind+"_connections"]++
		save()
		mu.Unlock()
		_ = ws.SetReadDeadline(time.Now().Add(2 * time.Minute))
		_, _ = stream.Write([]byte("RFB 003.008\n"))
		if _, err := io.ReadFull(stream, make([]byte, 12)); err != nil {
			return
		}
		auth := byte(1)
		if password {
			auth = 2
		}
		_, _ = stream.Write([]byte{1, auth})
		if _, err := io.ReadFull(stream, make([]byte, 1)); err != nil {
			return
		}
		if password {
			_, _ = stream.Write([]byte("0123456789abcdef"))
			if _, err := io.ReadFull(stream, make([]byte, 16)); err != nil {
				return
			}
		}
		_, _ = stream.Write(make([]byte, 4))
		if _, err := io.ReadFull(stream, make([]byte, 1)); err != nil {
			return
		}
		name := "EasyWI " + kind + " test console"
		init := make([]byte, 24)
		binary.BigEndian.PutUint16(init[0:2], 320)
		binary.BigEndian.PutUint16(init[2:4], 200)
		copy(init[4:20], []byte{32, 24, 0, 1, 0, 255, 0, 255, 0, 255, 16, 8, 0, 0, 0, 0})
		binary.BigEndian.PutUint32(init[20:24], uint32(len(name)))
		_, _ = stream.Write(append(init, []byte(name)...))
		sent := false
		for {
			typ := make([]byte, 1)
			if _, err := io.ReadFull(stream, typ); err != nil {
				return
			}
			switch typ[0] {
			case 0:
				if _, err := io.ReadFull(stream, make([]byte, 19)); err != nil {
					return
				}
			case 2:
				head := make([]byte, 3)
				if _, err := io.ReadFull(stream, head); err != nil {
					return
				}
				n := int64(binary.BigEndian.Uint16(head[1:])) * 4
				if _, err := io.CopyN(io.Discard, stream, n); err != nil {
					return
				}
			case 3:
				if _, err := io.ReadFull(stream, make([]byte, 9)); err != nil {
					return
				}
				if !sent {
					frame := make([]byte, 16+320*200*4)
					binary.BigEndian.PutUint16(frame[2:4], 1)
					binary.BigEndian.PutUint16(frame[8:10], 320)
					binary.BigEndian.PutUint16(frame[10:12], 200)
					for y := 0; y < 200; y++ {
						for x := 0; x < 320; x++ {
							offset := 16 + (y*320+x)*4
							frame[offset] = byte(100 + x/3)
							frame[offset+1] = byte(30 + y/2)
							frame[offset+2] = byte(20 + x/5)
						}
					}
					_, _ = stream.Write(frame)
					sent = true
					mu.Lock()
					counts[kind+"_frames"]++
					save()
					mu.Unlock()
				}
			case 4:
				if _, err := io.ReadFull(stream, make([]byte, 7)); err != nil {
					return
				}
				mu.Lock()
				counts[kind+"_keys"]++
				save()
				mu.Unlock()
			case 5:
				if _, err := io.ReadFull(stream, make([]byte, 5)); err != nil {
					return
				}
				mu.Lock()
				counts[kind+"_mouse"]++
				save()
				mu.Unlock()
			case 6:
				head := make([]byte, 7)
				if _, err := io.ReadFull(stream, head); err != nil {
					return
				}
				size := binary.BigEndian.Uint32(head[3:])
				if size > 1<<20 {
					return
				}
				if _, err := io.CopyN(io.Discard, stream, int64(size)); err != nil {
					return
				}
			default:
				return
			}
		}
	}))
	defer backend.Close()
	backendURL = backend.URL
	save()
	g := &vmconsole.Gateway{PanelURL: *panel, Origin: "http://" + *address, Secret: "synthetic-console-gateway-secret-for-tests-only", Client: &http.Client{Timeout: 5 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}, Dialer: &websocket.Dialer{TLSClientConfig: backend.Client().Transport.(*http.Transport).TLSClientConfig}, Recheck: time.Second}
	target, _ := url.Parse(*panel)
	proxy := httputil.NewSingleHostReverseProxy(target)
	mux := http.NewServeMux()
	mux.Handle("/vm-console/ws", g)
	mux.Handle("/", proxy)
	log.Fatal(http.ListenAndServe(*address, mux))
}
