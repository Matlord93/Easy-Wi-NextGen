package fileapi

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
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

func TestListWarnsAndIncludesInvalidUTF8Filename(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(instanceRoot, "ok.txt"), []byte("ok"), 0o644); err != nil {
		t.Fatalf("write valid file: %v", err)
	}
	invalidName := string([]byte{0xff, 'b', 'a', 'd'})
	if err := os.WriteFile(filepath.Join(instanceRoot, invalidName), []byte("bad"), 0o644); err != nil {
		t.Fatalf("write invalid file: %v", err)
	}

	srv := newContentTestServer(t, base)
	req := httptest.NewRequest(http.MethodGet, "/files", nil)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleList(rr, req, "instance-1")

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var payload listResponse
	if err := json.Unmarshal(rr.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if len(payload.Entries) != 2 {
		t.Fatalf("expected valid and invalid file entries, got %#v", payload.Entries)
	}
	var invalidEntry *fileEntry
	for i := range payload.Entries {
		if !payload.Entries[i].NameValidUTF8 {
			invalidEntry = &payload.Entries[i]
		}
	}
	if invalidEntry == nil || invalidEntry.ActionsSupported {
		t.Fatalf("expected invalid UTF-8 entry with actions disabled, got %#v", payload.Entries)
	}
	if len(payload.Warnings) != 1 || payload.Warnings[0].Code != "INVALID_UTF8_FILENAME" {
		t.Fatalf("expected invalid UTF-8 warning, got %#v", payload.Warnings)
	}
}

func TestListSupportsPaginationForLargeDirectories(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	for _, name := range []string{"a.txt", "b.txt", "c.txt"} {
		if err := os.WriteFile(filepath.Join(instanceRoot, name), []byte(name), 0o644); err != nil {
			t.Fatalf("write %s: %v", name, err)
		}
	}

	srv := newContentTestServer(t, base)
	req := httptest.NewRequest(http.MethodGet, "/files?limit=2&offset=1", nil)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleList(rr, req, "instance-1")

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var payload listResponse
	if err := json.Unmarshal(rr.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if payload.Total != 3 || payload.Offset != 1 || payload.Limit != 2 || !payload.Truncated {
		t.Fatalf("unexpected pagination metadata: %#v", payload)
	}
	if len(payload.Entries) != 2 || payload.Entries[0].Name != "b.txt" || payload.Entries[1].Name != "c.txt" {
		t.Fatalf("unexpected paged entries: %#v", payload.Entries)
	}
}

func TestListHandlesUmlautsSpacesAndSpecialCharacters(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	for _, name := range []string{"äöü-test.txt", "leer zeichen test.txt", "special !@#$%^&()[]{}.txt"} {
		if err := os.WriteFile(filepath.Join(instanceRoot, name), []byte(name), 0o644); err != nil {
			t.Fatalf("write %s: %v", name, err)
		}
	}

	payload := listForTest(t, base, instanceRoot, "/files")
	if len(payload.Warnings) != 0 {
		t.Fatalf("did not expect warnings for valid special names: %#v", payload.Warnings)
	}
	got := map[string]bool{}
	for _, entry := range payload.Entries {
		got[entry.Name] = entry.NameValidUTF8 && entry.ActionsSupported && entry.MetadataAvailable
	}
	for _, name := range []string{"äöü-test.txt", "leer zeichen test.txt", "special !@#$%^&()[]{}.txt"} {
		if !got[name] {
			t.Fatalf("expected valid listed entry %q in %#v", name, payload.Entries)
		}
	}
}

func TestListHandlesEmptyDirectory(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}

	payload := listForTest(t, base, instanceRoot, "/files")
	if payload.Total != 0 || len(payload.Entries) != 0 || payload.Truncated {
		t.Fatalf("unexpected empty listing payload: %#v", payload)
	}
}

func TestListHandlesDeepDirectoryStructure(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	deep := filepath.Join(instanceRoot, "one", "two", "three", "four")
	if err := os.MkdirAll(deep, 0o755); err != nil {
		t.Fatalf("mkdir deep: %v", err)
	}
	if err := os.WriteFile(filepath.Join(deep, "deep-file.txt"), []byte("ok"), 0o644); err != nil {
		t.Fatalf("write deep file: %v", err)
	}

	payload := listForTest(t, base, instanceRoot, "/files?path=one/two/three/four")
	if len(payload.Entries) != 1 || payload.Entries[0].Name != "deep-file.txt" {
		t.Fatalf("unexpected deep listing: %#v", payload.Entries)
	}
}

func TestListHandlesBrokenSymlink(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symlink behavior differs on Windows")
	}
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if err := os.Symlink(filepath.Join(instanceRoot, "missing-target"), filepath.Join(instanceRoot, "broken-link")); err != nil {
		t.Fatalf("symlink: %v", err)
	}

	payload := listForTest(t, base, instanceRoot, "/files")
	if len(payload.Entries) != 1 || !payload.Entries[0].IsSymlink || !payload.Entries[0].LinkBroken {
		t.Fatalf("expected broken symlink metadata, got %#v", payload.Entries)
	}
	if len(payload.Warnings) != 1 || payload.Warnings[0].Code != "BROKEN_SYMLINK" {
		t.Fatalf("expected broken symlink warning, got %#v", payload.Warnings)
	}
}

func TestListLargeDirectoryUsesPagination(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	for i := 0; i < 120; i++ {
		name := fmt.Sprintf("file-%03d.txt", i)
		if err := os.WriteFile(filepath.Join(instanceRoot, name), []byte(name), 0o644); err != nil {
			t.Fatalf("write %s: %v", name, err)
		}
	}

	payload := listForTest(t, base, instanceRoot, "/files?limit=25&offset=50")
	if payload.Total != 120 || payload.Offset != 50 || payload.Limit != 25 || !payload.Truncated || len(payload.Entries) != 25 {
		t.Fatalf("unexpected pagination payload: total=%d offset=%d limit=%d truncated=%t entries=%d", payload.Total, payload.Offset, payload.Limit, payload.Truncated, len(payload.Entries))
	}
	if payload.Entries[0].Name != "file-050.txt" || payload.Entries[24].Name != "file-074.txt" {
		t.Fatalf("unexpected pagination entries: first=%s last=%s", payload.Entries[0].Name, payload.Entries[24].Name)
	}
}

func TestListPermissionDeniedReturnsClearError(t *testing.T) {
	if os.Geteuid() == 0 {
		t.Skip("root can bypass directory permissions")
	}
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	privateDir := filepath.Join(instanceRoot, "private")
	if err := os.MkdirAll(privateDir, 0o700); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if err := os.Chmod(privateDir, 0o000); err != nil {
		t.Fatalf("chmod: %v", err)
	}
	defer os.Chmod(privateDir, 0o700)

	srv := newContentTestServer(t, base)
	req := httptest.NewRequest(http.MethodGet, "/files?path=private", nil)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleList(rr, req, "instance-1")

	if rr.Code != http.StatusForbidden {
		t.Fatalf("expected 403 for permission denied, got %d: %s", rr.Code, rr.Body.String())
	}
	if code := decodeErrorCode(t, rr); code != errorPermissionDenied {
		t.Fatalf("expected %s, got %s", errorPermissionDenied, code)
	}
}

func TestListHandlesVeryLongFilename(t *testing.T) {
	base := t.TempDir()
	instanceRoot := filepath.Join(base, "instance")
	if err := os.MkdirAll(instanceRoot, 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	longName := strings.Repeat("a", 240) + ".txt"
	if err := os.WriteFile(filepath.Join(instanceRoot, longName), []byte("ok"), 0o644); err != nil {
		t.Fatalf("write long file: %v", err)
	}

	payload := listForTest(t, base, instanceRoot, "/files")
	if len(payload.Entries) != 1 || payload.Entries[0].Name != longName || !payload.Entries[0].NameValidUTF8 {
		t.Fatalf("unexpected long-name listing: %#v", payload.Entries)
	}
}

func listForTest(t *testing.T, base string, instanceRoot string, target string) listResponse {
	t.Helper()
	srv := newContentTestServer(t, base)
	req := httptest.NewRequest(http.MethodGet, target, nil)
	req.Header.Set(headerServerRoot, instanceRoot)
	rr := httptest.NewRecorder()

	srv.handleList(rr, req, "instance-1")

	if rr.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d: %s", rr.Code, rr.Body.String())
	}
	var payload listResponse
	if err := json.Unmarshal(rr.Body.Bytes(), &payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	return payload
}
