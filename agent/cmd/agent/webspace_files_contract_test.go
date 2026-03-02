package main

import (
	"encoding/base64"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestWebspaceFilesContractCRUDAndErrors(t *testing.T) {
	root := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, "public"), 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}

	writeJob := jobs.Job{ID: "1", Payload: map[string]any{"root_path": root, "path": "public", "name": "index.html", "content_base64": base64.StdEncoding.EncodeToString([]byte("ok"))}}
	writeResult, _ := handleWebspaceFileWrite(writeJob)
	if writeResult.Status != "success" {
		t.Fatalf("write failed: %#v", writeResult.Output)
	}

	readResult, _ := handleWebspaceFileRead(jobs.Job{ID: "2", Payload: map[string]any{"root_path": root, "path": "public", "name": "index.html"}})
	if readResult.Status != "success" {
		t.Fatalf("read failed: %#v", readResult.Output)
	}
	decoded, _ := base64.StdEncoding.DecodeString(readResult.Output["content_base64"])
	if string(decoded) != "ok" {
		t.Fatalf("unexpected content: %q", string(decoded))
	}

	listResult, _ := handleWebspaceFilesList(jobs.Job{ID: "3", Payload: map[string]any{"root_path": root, "path": "public"}})
	if listResult.Status != "success" || !strings.Contains(listResult.Output["entries"], "index.html") {
		t.Fatalf("list failed: %#v", listResult.Output)
	}

	delResult, _ := handleWebspaceFileDelete(jobs.Job{ID: "4", Payload: map[string]any{"root_path": root, "path": "public", "name": "index.html"}})
	if delResult.Status != "success" {
		t.Fatalf("delete failed: %#v", delResult.Output)
	}
}

func TestWebspaceFilesContractPathTraversalAndSymlinkEscape(t *testing.T) {
	root := t.TempDir()
	outside := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, "public"), 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(outside, "x.txt"), []byte("x"), 0o644); err != nil {
		t.Fatalf("write: %v", err)
	}
	if err := os.Symlink(outside, filepath.Join(root, "public", "escape")); err != nil {
		t.Fatalf("symlink: %v", err)
	}

	result, _ := handleWebspaceFileRead(jobs.Job{ID: "5", Payload: map[string]any{"root_path": root, "path": "public/escape", "name": "x.txt"}})
	if result.Status != "failed" || result.Output["error_code"] != "path_invalid" {
		t.Fatalf("expected path_invalid envelope, got %#v", result.Output)
	}
}

func TestWebspaceFilesContractACLAndSizeLimit(t *testing.T) {
	root := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, "public"), 0o755); err != nil {
		t.Fatalf("mkdir: %v", err)
	}

	aclResult, _ := handleWebspaceFileWrite(jobs.Job{ID: "6", Payload: map[string]any{"root_path": root, "path": "public", "name": "a.txt", "acl": "ro", "content_base64": base64.StdEncoding.EncodeToString([]byte("a"))}})
	if aclResult.Status != "failed" || aclResult.Output["error_code"] != "acl_denied" {
		t.Fatalf("expected acl_denied, got %#v", aclResult.Output)
	}

	tooBig := base64.StdEncoding.EncodeToString([]byte(strings.Repeat("a", 2048)))
	sizeResult, _ := handleWebspaceFileWrite(jobs.Job{ID: "7", Payload: map[string]any{"root_path": root, "path": "public", "name": "big.txt", "max_bytes": "1024", "content_base64": tooBig}})
	if sizeResult.Status != "failed" || sizeResult.Output["error_code"] != "size_limit_exceeded" {
		t.Fatalf("expected size limit failure, got %#v", sizeResult.Output)
	}
}
