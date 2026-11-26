package api

import (
	"context"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestFinishKVMReinstallationRequiresPanelAcknowledgement(t *testing.T) {
	for _, tc := range []struct {
		name    string
		status  int
		body    string
		missing bool
	}{
		{"acknowledged", http.StatusOK, `{"status":"ok"}`, false},
		{"unexpected success page", http.StatusOK, `<html>Login</html>`, false},
		{"job deleted", http.StatusNotFound, `{"error":"Job not found."}`, true},
		{"proxy error", http.StatusNotFound, `<html>Not found</html>`, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
				w.WriteHeader(tc.status)
				_, _ = io.WriteString(w, tc.body)
			}))
			defer server.Close()
			client, err := NewClient(server.URL, "kvm-agent", "secret", "test")
			if err != nil {
				t.Fatal(err)
			}
			err = client.FinishAgentJob(context.Background(), "kvm-agent", "job-1", "success", "", "", map[string]any{"action": "vm.reinstall"})
			if (err == nil) != (tc.name == "acknowledged") || errors.Is(err, ErrAgentJobNotFound) != tc.missing {
				t.Fatalf("unexpected finish response: %v", err)
			}
		})
	}
}
