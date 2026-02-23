package main

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestResolveConfigApplyPathBlocksTraversal(t *testing.T) {
	root := t.TempDir()
	_, _, err := resolveConfigApplyPath(root, filepath.Join(root, "..", "evil.cfg"))
	if err == nil {
		t.Fatal("expected traversal error")
	}
}

func TestWriteConfigAtomicallyWritesFile(t *testing.T) {
	root := t.TempDir()
	target := filepath.Join(root, "cfg", "server.cfg")
	stats, err := writeConfigAtomically(target, []byte("hostname test\n"), true)
	if err != nil {
		t.Fatalf("write failed: %v", err)
	}
	if stats.Bytes == 0 {
		t.Fatalf("expected bytes written")
	}
	content, _ := os.ReadFile(target)
	if !strings.Contains(string(content), "hostname test") {
		t.Fatalf("unexpected content")
	}
}

func TestConfigApplyRejectsBinary(t *testing.T) {
	root := t.TempDir()
	req := httptest.NewRequest(http.MethodPost, "/v1/instances/1/configs/apply", strings.NewReader(`{"instance_root":"`+root+`","path":"`+filepath.Join(root, "a.cfg")+`","content":"a\u0000b"}`))
	w := httptest.NewRecorder()
	handled := handleInstanceConfigApplyHTTP(w, req, "1")
	if !handled {
		t.Fatal("expected handled")
	}
	if !strings.Contains(w.Body.String(), "BINARY_NOT_ALLOWED") {
		t.Fatalf("expected binary rejection: %s", w.Body.String())
	}
}
