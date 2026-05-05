package main

import (
	"context"
	"encoding/json"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/config"
	"easywi/agent/internal/fileapi"
	"easywi/agent/internal/gamesvcembed"
	"easywi/agent/internal/sinusbotsvcembed"
)

type mailCheckResult struct {
	Key            string         `json:"key"`
	Title          string         `json:"title"`
	Category       string         `json:"category"`
	Status         string         `json:"status"`
	Message        string         `json:"message,omitempty"`
	Details        map[string]any `json:"details,omitempty"`
	Recommendation string         `json:"recommendation,omitempty"`
	Probe          string         `json:"probe,omitempty"`
	Timestamp      time.Time      `json:"timestamp"`
}

type mailHealthSnapshot struct {
	GeneratedAt time.Time         `json:"generated_at"`
	Overall     string            `json:"overall"`
	Checks      []mailCheckResult `json:"checks"`
}

var lastMailHealthSnapshot = mailHealthSnapshot{}

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
		checks := collectMailHealthChecks()
		ok := true
		for _, value := range checks {
			if !value.Ok {
				ok = false
				break
			}
		}
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"ok": ok,
			"capabilities": map[string]bool{
				"mail_domain": true,
				"mailbox":     true,
				"mail_alias":  true,
			},
			"checks": checks,
		})
	})
	mux.HandleFunc("/v1/mail/health/report", func(w http.ResponseWriter, r *http.Request) {
		runNow := r.URL.Query().Get("refresh") == "1"
		snapshot := getMailHealthSnapshot(runNow)
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(snapshot)
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

func getMailHealthSnapshot(force bool) mailHealthSnapshot {
	if !force && !lastMailHealthSnapshot.GeneratedAt.IsZero() && time.Since(lastMailHealthSnapshot.GeneratedAt) < 15*time.Minute {
		return lastMailHealthSnapshot
	}
	snapshot := runStructuredMailHealthChecks()
	lastMailHealthSnapshot = snapshot
	return snapshot
}

func runStructuredMailHealthChecks() mailHealthSnapshot {
	now := time.Now().UTC()
	checks := []mailCheckResult{
		checkFromLegacy("dovecot_active", "Dovecot service aktiv", "dovecot", serviceActiveCheck("dovecot"), "systemctl is-active dovecot"),
		checkFromLegacy("postfix_active", "Postfix service aktiv", "postfix", serviceActiveCheck("postfix"), "systemctl is-active postfix"),
		checkFromLegacy("smtp_587_starttls", "SMTP 587 STARTTLS", "tls", commandExpectationCheck("openssl", []string{"s_client", "-starttls", "smtp", "-connect", "127.0.0.1:587", "-servername", readMailHostname()}, "BEGIN CERTIFICATE"), "openssl s_client -starttls smtp -connect 127.0.0.1:587"),
		checkFromLegacy("smtp_587_auth_advertised", "SMTP 587 AUTH", "auth", commandExpectationCheck("openssl", []string{"s_client", "-starttls", "smtp", "-connect", "127.0.0.1:587", "-crlf", "-quiet"}, "AUTH PLAIN LOGIN"), "openssl s_client -starttls smtp -connect 127.0.0.1:587"),
		checkFromLegacy("imap_993_tls", "IMAPS 993 TLS", "tls", commandExpectationCheck("openssl", []string{"s_client", "-connect", "127.0.0.1:993", "-servername", readMailHostname()}, "BEGIN CERTIFICATE"), "openssl s_client -connect 127.0.0.1:993"),
		checkFromLegacy("imap_login_probe", "Dovecot IMAP/Auth probe", "auth", imapLoginProbeCheck(), "doveconf -n"),
		checkFromLegacy("local_virtual_delivery", "Virtuelle Zustellung aktiv", "delivery", lmtpOrVirtualDeliveryCheck(), "postconf -h virtual_transport"),
		checkFromLegacy("outbound_port_25_google", "Outbound Port 25 (Gmail)", "relay", outboundPort25Check("gmail-smtp-in.l.google.com:25"), "nc -4 -vz gmail-smtp-in.l.google.com 25"),
		checkFromLegacy("outbound_port_25_webde", "Outbound Port 25 (web.de)", "relay", outboundPort25Check("mx-ha02.web.de:25"), "nc -4 -vz mx-ha02.web.de 25"),
		checkFromLegacy("dkim_status", "DKIM Status", "security", dkimStatusCheck(), "test -d /etc/opendkim/keys"),
	}
	overall := "ok"
	for _, c := range checks {
		if c.Status == "error" {
			overall = "error"
			break
		}
		if c.Status == "warning" && overall != "error" {
			overall = "warning"
		}
	}
	return mailHealthSnapshot{GeneratedAt: now, Overall: overall, Checks: checks}
}

func checkFromLegacy(key, title, category string, legacy mailHealthCheck, probe string) mailCheckResult {
	status := "ok"
	if !legacy.Ok {
		status = "error"
	}
	if strings.EqualFold(legacy.Level, "warning") {
		status = "warning"
	}
	return mailCheckResult{
		Key:       key,
		Title:     title,
		Category:  category,
		Status:    status,
		Message:   legacy.Message,
		Probe:     probe,
		Timestamp: time.Now().UTC(),
	}
}

type mailHealthCheck struct {
	Ok      bool   `json:"ok"`
	Message string `json:"message,omitempty"`
	Level   string `json:"level,omitempty"`
}

func collectMailHealthChecks() map[string]mailHealthCheck {
	checks := map[string]mailHealthCheck{
		"postfix_installed":  commandHealthCheck("postfix"),
		"dovecot_installed":  commandHealthCheck("dovecot"),
		"postmap_available":  commandHealthCheck("postmap"),
		"postfix_map_file":   fileHealthCheck("/etc/postfix/virtual_mailboxes"),
		"postfix_domain_map": fileHealthCheck("/etc/postfix/virtual_domains"),
		"postfix_alias_map":  fileHealthCheck("/etc/postfix/virtual_aliases"),
		"dovecot_users_file": fileHealthCheck("/etc/dovecot/users"),
		"maildir_writable":   writableDirCheck("/var/mail/vhosts"),
		"postfix_active":     serviceActiveCheck("postfix"),
		"dovecot_active":     serviceActiveCheck("dovecot"),
	}
	for _, port := range []string{"25", "465", "587", "110", "143", "993", "995"} {
		key := "port_listen_" + port
		checks[key] = listeningPortCheck(port)
	}
	checks["smtp_587_starttls"] = commandExpectationCheck("openssl", []string{"s_client", "-starttls", "smtp", "-connect", "127.0.0.1:587", "-servername", readMailHostname()}, "BEGIN CERTIFICATE")
	checks["smtp_587_auth_advertised"] = commandExpectationCheck("openssl", []string{"s_client", "-starttls", "smtp", "-connect", "127.0.0.1:587", "-crlf", "-quiet"}, "AUTH PLAIN LOGIN")
	checks["imap_993_tls"] = commandExpectationCheck("openssl", []string{"s_client", "-connect", "127.0.0.1:993", "-servername", readMailHostname()}, "BEGIN CERTIFICATE")
	checks["imap_login_probe"] = imapLoginProbeCheck()
	checks["postfix_virtual_recipient_config"] = postfixVirtualRecipientConfigCheck()
	checks["local_virtual_delivery"] = lmtpOrVirtualDeliveryCheck()
	checks["outbound_port_25_google"] = outboundPort25Check("gmail-smtp-in.l.google.com:25")
	checks["outbound_port_25_webde"] = outboundPort25Check("mx-ha02.web.de:25")
	checks["dkim_status"] = dkimStatusCheck()
	return checks
}

func readMailHostname() string {
	v := strings.TrimSpace(commandOutput("postconf", "-h", "myhostname"))
	if v == "" {
		return "localhost"
	}
	return v
}

func commandExpectationCheck(name string, args []string, expected string) mailHealthCheck {
	out, err := runCommandOutput(name, args...)
	if err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	if expected != "" && !strings.Contains(strings.ToUpper(out), strings.ToUpper(expected)) {
		return mailHealthCheck{Ok: false, Message: "expected token missing: " + expected}
	}
	return mailHealthCheck{Ok: true}
}

func postfixVirtualRecipientConfigCheck() mailHealthCheck {
	out, err := runCommandOutput("postconf", "-n")
	if err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	required := []string{"virtual_mailbox_domains", "virtual_mailbox_maps", "virtual_alias_maps"}
	for _, token := range required {
		if !strings.Contains(out, token) {
			return mailHealthCheck{Ok: false, Message: "missing " + token}
		}
	}
	return mailHealthCheck{Ok: true}
}

func lmtpOrVirtualDeliveryCheck() mailHealthCheck {
	out, err := runCommandOutput("postconf", "-h", "virtual_transport")
	if err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	v := strings.TrimSpace(out)
	if v == "" {
		return mailHealthCheck{Ok: false, Message: "virtual_transport is empty"}
	}
	return mailHealthCheck{Ok: true, Message: v}
}

func imapLoginProbeCheck() mailHealthCheck {
	if _, err := os.Stat("/etc/dovecot/users"); err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	if out, err := runCommandOutput("doveconf", "-n"); err != nil || (!strings.Contains(out, "uid =") || !strings.Contains(out, "gid =")) {
		return mailHealthCheck{Ok: false, Message: "dovecot uid/gid mapping invalid"}
	}
	return mailHealthCheck{Ok: true}
}

func outboundPort25Check(target string) mailHealthCheck {
	conn, err := net.DialTimeout("tcp4", target, 1500*time.Millisecond)
	if err != nil {
		return mailHealthCheck{Ok: true, Level: "warning", Message: "outbound tcp/25 blocked or filtered: " + err.Error()}
	}
	_ = conn.Close()
	return mailHealthCheck{Ok: true}
}

func dkimStatusCheck() mailHealthCheck {
	if _, err := os.Stat("/etc/opendkim/keys"); err == nil {
		return mailHealthCheck{Ok: true}
	}
	return mailHealthCheck{Ok: true, Level: "warning", Message: "DKIM not configured yet"}
}

func commandHealthCheck(name string) mailHealthCheck {
	if _, err := exec.LookPath(name); err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	return mailHealthCheck{Ok: true}
}

func fileHealthCheck(path string) mailHealthCheck {
	if _, err := os.Stat(path); err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	return mailHealthCheck{Ok: true}
}

func writableDirCheck(path string) mailHealthCheck {
	if err := os.MkdirAll(path, 0o750); err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	testFile := filepath.Join(path, ".easywi-healthcheck")
	if err := os.WriteFile(testFile, []byte("ok"), 0o600); err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	_ = os.Remove(testFile)
	return mailHealthCheck{Ok: true}
}

func serviceActiveCheck(name string) mailHealthCheck {
	if _, err := exec.LookPath("systemctl"); err != nil {
		return mailHealthCheck{Ok: false, Message: "systemctl not available"}
	}
	if _, err := runCommandOutput("systemctl", "is-active", name); err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	return mailHealthCheck{Ok: true}
}

func listeningPortCheck(port string) mailHealthCheck {
	conn, err := net.DialTimeout("tcp", "127.0.0.1:"+port, 300*time.Millisecond)
	if err != nil {
		return mailHealthCheck{Ok: false, Message: err.Error()}
	}
	_ = conn.Close()
	return mailHealthCheck{Ok: true}
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
