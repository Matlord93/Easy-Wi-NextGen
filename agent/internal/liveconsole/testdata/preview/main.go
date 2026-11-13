// Local integration fixture: real WebSocket handler and HMAC verification.
// Does not launch or discover production game servers.
package main

import (
	"context"
	"easywi/agent/internal/fileapi"
	"easywi/agent/internal/liveconsole"
	"encoding/json"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"time"
)

func main() {
	root := os.Args[1]
	secret := "synthetic-live-node-secret-for-test-only"
	socket := func(id string) string { return filepath.Join(root, id, "console.sock") }
	mux := http.NewServeMux()
	mux.Handle("/v1/console/live/", &liveconsole.Server{Agent: "live-preview", Secret: secret, SocketPath: socket})
	mux.HandleFunc("/v1/instances/1/console/command", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != "POST" {
			http.Error(w, "Method", 405)
			return
		}
		if _, err := fileapi.VerifyRequestSignature(r, fileapi.Config{AgentID: "live-preview", Secret: secret, MaxSkew: time.Minute}); err != nil {
			http.Error(w, "Denied", 403)
			return
		}
		var command struct {
			Command string `json:"command"`
		}
		if json.NewDecoder(r.Body).Decode(&command) != nil {
			http.Error(w, "Invalid", 400)
			return
		}
		ctx, cancel := context.WithTimeout(r.Context(), time.Second)
		defer cancel()
		conn, err := liveconsole.DialLocal(ctx, socket("1"))
		if err != nil {
			http.Error(w, "Unavailable", 409)
			return
		}
		defer conn.Close()
		var hello map[string]any
		_ = json.NewDecoder(conn).Decode(&hello)
		if hello["instance_id"] != "1" {
			http.Error(w, "Denied", 409)
			return
		}
		writeErr := json.NewEncoder(conn).Encode(map[string]string{"instance_id": "1", "command": command.Command})
		var ack struct {
			Accepted bool `json:"accepted"`
		}
		if writeErr != nil || json.NewDecoder(conn).Decode(&ack) != nil || !ack.Accepted {
			http.Error(w, "Unavailable", 409)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true,"data":{"accepted":true}}`))
	})
	log.Fatal(http.ListenAndServe("127.0.0.1:8011", mux))
}
