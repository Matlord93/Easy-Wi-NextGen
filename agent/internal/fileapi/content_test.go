package fileapi

import (
	"bytes"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func newContentTestServer(t *testing.T, baseDir string) *Server {
	t.Helper()
	srv, err := NewServer(Config{AgentID: "a1", Secret: "s1", BaseDirs: []string{baseDir}})
	if err != nil {
		t.Fatalf("new server: %v", err)
	}
	return srv
}

func decodeErrorCode(t *testing.T, rr *httptest.ResponseRecorder) string {
	t.Helper()
	var payload map[string]any
	if err := json.Unmarshal(rr.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	errorPayload, _ := payload["error"].(map[string]any)
	code, _ := errorPayload["code"].(string)
	return code
}

func TestContentReadRejectsBinaryFile(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(instanceRoot, "binary.dat"), []byte{0x00, 0x01, 0x02}, 0o644); err != nil {
		t.Fatalf("write file: %v", err)
	}

	srv := newContentTestServer(t, base)
	req := httptest.NewRequest(http.MethodGet, "/content?path=binary.dat", nil)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleContentRead(rr, req, "instance-1")

	if rr.Code != http.StatusUnsupportedMediaType {
		t.Fatalf("expected 415, got %d", rr.Code)
	}
	if code := decodeErrorCode(t, rr); code != errorBinaryFile {
		t.Fatalf("expected %s, got %s", errorBinaryFile, code)
	}
}

func TestContentReadRejectsTooLargeFile(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	big := bytes.Repeat([]byte("a"), int(maxEditableFileBytes)+1)
	if err := os.WriteFile(filepath.Join(instanceRoot, "big.txt"), big, 0o644); err != nil {
		t.Fatalf("write file: %v", err)
	}

	srv := newContentTestServer(t, base)
	req := httptest.NewRequest(http.MethodGet, "/content?path=big.txt", nil)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleContentRead(rr, req, "instance-1")

	if rr.Code != http.StatusRequestEntityTooLarge {
		t.Fatalf("expected 413, got %d", rr.Code)
	}
	if code := decodeErrorCode(t, rr); code != errorFileTooLarge {
		t.Fatalf("expected %s, got %s", errorFileTooLarge, code)
	}
}

func TestContentWriteRejectsEtagMismatch(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(instanceRoot, "config.txt"), []byte("initial"), 0o644); err != nil {
		t.Fatalf("write file: %v", err)
	}

	srv := newContentTestServer(t, base)
	body := strings.NewReader(`{"path":"config.txt","content":"changed","etag":"wrong"}`)
	req := httptest.NewRequest(http.MethodPut, "/content", body)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleContentWrite(rr, req, "instance-1")

	if rr.Code != http.StatusConflict {
		t.Fatalf("expected 409, got %d", rr.Code)
	}
	if code := decodeErrorCode(t, rr); code != errorEtagMismatch {
		t.Fatalf("expected %s, got %s", errorEtagMismatch, code)
	}
}

func TestContentReadRejectsTraversal(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}

	srv := newContentTestServer(t, base)
	req := httptest.NewRequest(http.MethodGet, "/content?path=../secret.txt", nil)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleContentRead(rr, req, "instance-1")

	if rr.Code != http.StatusBadRequest {
		t.Fatalf("expected 400, got %d", rr.Code)
	}
	if code := decodeErrorCode(t, rr); code != errorPathOutsideRoot {
		t.Fatalf("expected %s, got %s", errorPathOutsideRoot, code)
	}
}
