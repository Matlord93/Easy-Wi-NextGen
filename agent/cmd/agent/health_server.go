package main

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"log"
	"net/http"
	"net/url"
	"os"
	"strings"
	"time"

	"easywi/agent/internal/config"
)

type healthResponse struct {
	Status        string `json:"status"`
	Version       string `json:"version"`
	FilesvcStatus string `json:"filesvc_status"`
	FilesvcURL    string `json:"filesvc_url,omitempty"`
	TimeUTC       string `json:"time_utc"`
}

func startHealthServer(ctx context.Context, cfg config.Config) {
	listen := strings.TrimSpace(cfg.HealthListen)
	if listen == "" || strings.EqualFold(listen, "off") || strings.EqualFold(listen, "disabled") {
		return
	}

	mux := http.NewServeMux()
	handler := func(w http.ResponseWriter, _ *http.Request) {
		filesvcURL := resolveFilesvcHealthURL()
		filesvcStatus := "unknown"
		if filesvcURL != "" {
			filesvcStatus = probeHealthURL(filesvcURL)
		}

		payload := healthResponse{
			Status:        "ok",
			Version:       cfg.Version,
			FilesvcStatus: filesvcStatus,
			FilesvcURL:    sanitizeURL(filesvcURL),
			TimeUTC:       time.Now().UTC().Format(time.RFC3339),
		}
		respondJSON(w, http.StatusOK, payload)
	}
	mux.HandleFunc("/health", handler)
	mux.HandleFunc("/healthz", handler)

	srv := &http.Server{
		Addr:              listen,
		Handler:           mux,
		ReadHeaderTimeout: 5 * time.Second,
	}

	go func() {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = srv.Shutdown(shutdownCtx)
	}()

	go func() {
		log.Printf("agent health listening on %s", listen)
		if err := srv.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Printf("agent health server failed: %v", err)
		}
	}()
}

func resolveFilesvcHealthURL() string {
	if value := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_HEALTH_URL")); value != "" {
		return value
	}
	if value := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_URL")); value != "" {
		return strings.TrimRight(value, "/") + "/health"
	}

	host := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_HOST"))
	port := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_PORT"))
	scheme := strings.TrimSpace(os.Getenv("EASYWI_FILESVC_SCHEME"))
	if host == "" {
		return ""
	}
	if port == "" {
		port = "8444"
	}
	if scheme == "" {
		scheme = "https"
	}
	return scheme + "://" + host + ":" + port + "/health"
}

func probeHealthURL(rawURL string) string {
	parsed, err := url.Parse(rawURL)
	if err != nil {
		return "invalid_url"
	}

	transport := http.DefaultTransport.(*http.Transport).Clone()
	if parsed.Scheme == "https" && isLocalhost(parsed.Hostname()) {
		transport.TLSClientConfig = &tls.Config{InsecureSkipVerify: true}
	}
	client := &http.Client{Timeout: 3 * time.Second, Transport: transport}

	resp, err := client.Get(parsed.String())
	if err != nil {
		return "unreachable"
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 200 && resp.StatusCode < 300 {
		return "ok"
	}
	return "bad_status"
}

func sanitizeURL(rawURL string) string {
	parsed, err := url.Parse(rawURL)
	if err != nil {
		return ""
	}
	parsed.User = nil
	return parsed.String()
}

func isLocalhost(host string) bool {
	if host == "" {
		return false
	}
	if host == "localhost" || host == "127.0.0.1" {
		return true
	}
	if ipParts := strings.Split(host, "."); len(ipParts) == 4 {
		if ipParts[0] == "127" {
			return true
		}
	}
	return false
}

func respondJSON(w http.ResponseWriter, status int, payload interface{}) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	if payload == nil {
		return
	}
	enc := json.NewEncoder(w)
	enc.SetEscapeHTML(true)
	_ = enc.Encode(payload)
}
