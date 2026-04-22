package gamesvcembed

import (
	"path/filepath"
	"testing"
)

func TestSafeJoinAllowsDotSegmentsInsideRoot(t *testing.T) {
	base := t.TempDir()
	path, err := safeJoin(base, "configs/../server.cfg")
	if err != nil {
		t.Fatalf("safeJoin returned error: %v", err)
	}

	expected := filepath.Join(base, "server.cfg")
	if path != expected {
		t.Fatalf("expected %s, got %s", expected, path)
	}
}

func TestSafeJoinRejectsTraversal(t *testing.T) {
	base := t.TempDir()
	if _, err := safeJoin(base, "../../etc/passwd"); err == nil {
		t.Fatal("expected traversal error, got nil")
	}
}

