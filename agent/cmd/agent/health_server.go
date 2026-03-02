package main

import (
	"context"
	"encoding/json"
	"log"
	"net/http"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/config"
	"easywi/agent/internal/fileapi"
	"easywi/agent/internal/gamesvcembed"
	"easywi/agent/internal/sinusbotsvcembed"
)

func startServiceServer(ctx context.Context, cfg config.Config) {
	listen := strings.TrimSpace(cfg.ServiceListen)
	if listen == "" || strings.EqualFold(listen, "off") || strings.EqualFold(listen, "disabled") {
		return
	}

	fileServer, err := fileapi.NewServer(fileapi.Config{
		AgentID:        cfg.AgentID,
		Secret:         cfg.Secret,
		BaseDir:        cfg.FileBaseDir,
		BaseDirs:       cfg.FileBaseDirs,
		CacheSize:      cfg.FileCacheSize,
		MaxSkew:        cfg.FileMaxSkew,
		ReadTimeout:    cfg.FileReadTimeout,
		WriteTimeout:   cfg.FileWriteTimeout,
		IdleTimeout:    cfg.FileIdleTimeout,
		MaxUploadBytes: cfg.FileMaxUploadMB * 1024 * 1024,
		Version:        cfg.Version,
	})
	if err != nil {
		log.Fatalf("init file api: %v", err)
	}

	gameServer := gamesvcembed.NewServer(gamesvcembed.Config{})
	sinusbotServer := sinusbotsvcembed.NewServer(sinusbotsvcembed.Config{
		AgentID: cfg.AgentID,
		Secret:  cfg.Secret,
	})

	mux := http.NewServeMux()
	mux.Handle("/v1/servers/", fileServer.Handler())
	mux.HandleFunc("/v1/webspaces/", makeWebspaceCompatHandler(fileServer.Handler(), cfg.FileBaseDir))
	mux.HandleFunc("/v1/mail/domains", mailMutationHandler("mail_domain"))
	mux.HandleFunc("/v1/mail/mailboxes", mailMutationHandler("mailbox"))
	mux.HandleFunc("/v1/mail/mailboxes/password", mailMutationHandler("mailbox_password"))
	mux.HandleFunc("/v1/mail/aliases", mailMutationHandler("mail_alias"))
	mux.HandleFunc("/v1/mail/reload", mailMutationHandler("mail_reload"))
	mux.HandleFunc("/v1/agent/mail/metrics", handleMailMetricsHTTP)
	mux.Handle("/health", fileServer.Handler())
	mux.Handle("/healthz", fileServer.Handler())

	mux.HandleFunc("/v1/webspace/health", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"ok":         true,
			"agent_root": strings.TrimSpace(cfg.FileBaseDir),
			"capabilities": map[string]bool{
				"webspace_files": true,
				"webspace_apply": true,
				"canonical_root": true,
			},
		})
	})
	mux.HandleFunc("/v1/mail/health", func(w http.ResponseWriter, _ *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"ok": true,
			"capabilities": map[string]bool{
				"mail_domain": true,
				"mailbox":     true,
				"mail_alias":  true,
			},
		})
	})
	mux.Handle("/ports/check-free", gameServer.Handler())
	mux.Handle("/instance/render-config", gameServer.Handler())
	mux.Handle("/instance/start", gameServer.Handler())
	mux.Handle("/instance/stop", gameServer.Handler())
	mux.Handle("/instance/status", gameServer.Handler())
	mux.HandleFunc("/v1/access/capabilities", func(w http.ResponseWriter, r *http.Request) {
		if handled := handleAccessCapabilitiesHTTP(w, r); handled {
			return
		}
		writeJSONError(w, http.StatusNotFound, "NOT_FOUND", "not found")
	})
	mux.HandleFunc("/v1/instances/", handleInstanceQueryHTTP)
	mux.Handle("/internal/sinusbot/instances", sinusbotServer.Handler())
	mux.Handle("/internal/sinusbot/instances/", sinusbotServer.Handler())

	httpServer := &http.Server{
		Addr:              listen,
		Handler:           withTraceContext(mux),
		ReadTimeout:       cfg.FileReadTimeout,
		WriteTimeout:      cfg.FileWriteTimeout,
		IdleTimeout:       cfg.FileIdleTimeout,
		ReadHeaderTimeout: 5 * time.Second,
	}

	go func() {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = httpServer.Shutdown(shutdownCtx)
	}()

	go func() {
		log.Printf("agent service listening on %s", listen)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Printf("agent service failed: %v", err)
		}
	}()
}

func makeWebspaceCompatHandler(delegate http.Handler, agentRoot string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		path := strings.TrimPrefix(r.URL.Path, "/v1/webspaces/")
		parts := strings.Split(path, "/")
		if len(parts) < 2 || strings.TrimSpace(parts[0]) == "" || parts[1] != "files" {
			http.NotFound(w, r)
			return
		}

		webspaceID := strings.TrimSpace(parts[0])
		if strings.Contains(webspaceID, "..") {
			writeJSONError(w, http.StatusBadRequest, "INVALID_WEBSPACE_ID", "invalid webspace id")
			return
		}

		action := ""
		if len(parts) > 2 {
			action = parts[2]
		}

		mappedPath := "/v1/servers/webspace-" + webspaceID + "/files"
		switch action {
		case "", "list":
			if r.Method != http.MethodGet {
				writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
				return
			}
		case "upload":
			mappedPath += "/upload"
			if r.Method != http.MethodPost {
				writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
				return
			}
		case "download":
			mappedPath += "/download"
			if r.Method != http.MethodGet {
				writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
				return
			}
		case "mkdir":
			mappedPath += "/mkdir"
		case "rename":
			mappedPath += "/rename"
		case "read":
			mappedPath += "/read"
			if r.Method != http.MethodGet {
				writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
				return
			}
		case "write":
			mappedPath += "/write"
			if r.Method != http.MethodPut && r.Method != http.MethodPost {
				writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
				return
			}
		case "delete":
			mappedPath += "/delete"
			r.Method = http.MethodPost
		default:
			writeJSONError(w, http.StatusNotFound, "UNKNOWN_ACTION", "unknown action")
			return
		}

		if strings.TrimSpace(r.Header.Get("X-Server-Root")) == "" && strings.TrimSpace(agentRoot) != "" {
			r.Header.Set("X-Server-Root", filepath.Clean(agentRoot))
		}

		r.URL.Path = mappedPath
		delegate.ServeHTTP(w, r)
	}
}

func mailMutationHandler(capability string) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			writeJSONError(w, http.StatusMethodNotAllowed, "METHOD_NOT_ALLOWED", "method not allowed")
			return
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"ok":         true,
			"capability": capability,
			"status":     "accepted",
		})
	}
}

func writeJSONError(w http.ResponseWriter, code int, errorCode, message string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	_ = json.NewEncoder(w).Encode(map[string]any{
		"ok":         false,
		"error_code": errorCode,
		"message":    message,
	})
}
