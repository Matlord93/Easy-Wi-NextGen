package fileapi

import (
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

func TestContentCreateOnlyPublishesAndPreservesExistingFile(t *testing.T) {
	root := t.TempDir()
	srv := newContentTestServer(t, root)
	call := func(content, etag string, create bool) *httptest.ResponseRecorder {
		body, err := json.Marshal(map[string]any{"path": "server.cfg", "content": content, "etag": etag, "create_only": create})
		if err != nil {
			t.Fatal(err)
		}
		req := httptest.NewRequest(http.MethodPut, "/content", strings.NewReader(string(body)))
		req.Header.Set(headerServerRoot, root)
		rr := httptest.NewRecorder()
		srv.handleContentWrite(rr, req, "source")
		return rr
	}
	if rr := call("hostname \"first\"\n", "*", true); rr.Code != http.StatusCreated {
		t.Fatalf("create: %d %s", rr.Code, rr.Body.String())
	}
	if rr := call("replacement", "*", true); rr.Code != http.StatusConflict {
		t.Fatalf("replace: %d %s", rr.Code, rr.Body.String())
	}
	if rr := call("legacy replacement", "*", false); rr.Code != http.StatusConflict {
		t.Fatalf("legacy ETag sentinel did not fail safely: %d", rr.Code)
	}
	content, err := os.ReadFile(filepath.Join(root, "server.cfg"))
	if err != nil {
		t.Fatal(err)
	}
	if string(content) != "hostname \"first\"\n" {
		t.Fatalf("existing file changed: %q", content)
	}
	if uid, gid, ok := fileOwnership(root); ok {
		actualUID, actualGID, owned := fileOwnership(filepath.Join(root, "server.cfg"))
		if !owned || actualUID != uid || actualGID != gid {
			t.Fatalf("ownership changed: %d:%d, expected %d:%d", actualUID, actualGID, uid, gid)
		}
	}
}

func TestContentCreateOnlyRequiresCompatibilitySentinel(t *testing.T) {
	root := t.TempDir()
	srv := newContentTestServer(t, root)
	req := httptest.NewRequest(http.MethodPut, "/content", strings.NewReader(`{"path":"server.cfg","content":"new","create_only":true}`))
	req.Header.Set(headerServerRoot, root)
	rr := httptest.NewRecorder()
	srv.handleContentWrite(rr, req, "source")
	if rr.Code != http.StatusBadRequest {
		t.Fatalf("missing sentinel: %d", rr.Code)
	}
	if _, err := os.Stat(filepath.Join(root, "server.cfg")); !os.IsNotExist(err) {
		t.Fatalf("invalid request created a file: %v", err)
	}
}

func TestAtomicCreateOnlyHasOneWinner(t *testing.T) {
	root := t.TempDir()
	target := filepath.Join(root, "server.cfg")
	contents := []string{strings.Repeat("A", 8192), strings.Repeat("B", 8192)}
	start := make(chan struct{})
	results := make(chan error, 2)
	var workers sync.WaitGroup
	for _, content := range contents {
		workers.Go(func() { <-start; results <- writeFileAtomicMode(target, strings.NewReader(content), 0o640, true) })
	}
	close(start)
	workers.Wait()
	close(results)
	successes, conflicts := 0, 0
	for err := range results {
		if err == nil {
			successes++
		} else if errors.Is(err, os.ErrExist) {
			conflicts++
		} else {
			t.Fatal(err)
		}
	}
	if successes != 1 || conflicts != 1 {
		t.Fatalf("successes=%d conflicts=%d", successes, conflicts)
	}
	actual, err := os.ReadFile(target)
	if err != nil {
		t.Fatal(err)
	}
	if string(actual) != contents[0] && string(actual) != contents[1] {
		t.Fatal("partially written configuration published")
	}
	leftovers, err := filepath.Glob(filepath.Join(root, ".fileapi-*"))
	if err != nil {
		t.Fatal(err)
	}
	if len(leftovers) != 0 {
		t.Fatalf("temporary files remain: %v", leftovers)
	}
}
