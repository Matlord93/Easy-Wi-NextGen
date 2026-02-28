package http

import (
	"encoding/json"
	"net/http"
)

type ReadinessChecker interface {
	CheckServices() map[string]bool
}

type Server struct {
	checker ReadinessChecker
}

func NewServer(checker ReadinessChecker) *Server {
	return &Server{checker: checker}
}

func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/health", func(w http.ResponseWriter, _ *http.Request) {
		_ = json.NewEncoder(w).Encode(map[string]any{"ok": true})
	})
	mux.HandleFunc("/ready", func(w http.ResponseWriter, _ *http.Request) {
		services := map[string]bool{}
		if s.checker != nil {
			services = s.checker.CheckServices()
		}
		ok := true
		for _, v := range services {
			if !v {
				ok = false
				break
			}
		}
		if !ok {
			w.WriteHeader(http.StatusServiceUnavailable)
		}
		_ = json.NewEncoder(w).Encode(map[string]any{"ok": ok, "services": services})
	})
	return mux
}
