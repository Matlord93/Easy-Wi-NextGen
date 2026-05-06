package main

import (
	"encoding/json"
	"errors"
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

func TestWriteConfigAtomicallyForWindowsReplacesExistingTargetAfterBackup(t *testing.T) {
	root := t.TempDir()
	target := filepath.Join(root, "server.cfg")
	if err := os.WriteFile(target, []byte("old"), 0o640); err != nil {
		t.Fatal(err)
	}

	originalRename := renameConfigFile
	originalRemove := removeConfigFile
	t.Cleanup(func() {
		renameConfigFile = originalRename
		removeConfigFile = originalRemove
	})

	renameCalls := 0
	renameConfigFile = func(oldPath, newPath string) error {
		renameCalls++
		if renameCalls == 1 {
			return errors.New("Cannot create a file when that file already exists")
		}
		return os.Rename(oldPath, newPath)
	}
	removeConfigFile = os.Remove

	stats, err := writeConfigAtomicallyForOS(target, []byte("new"), false, "windows")
	if err != nil {
		t.Fatalf("write failed: %v", err)
	}
	if renameCalls != 2 {
		t.Fatalf("expected initial rename plus retry, got %d", renameCalls)
	}
	if stats.BackupPath == "" {
		t.Fatal("expected fallback backup path")
	}
	backupContent, err := os.ReadFile(stats.BackupPath)
	if err != nil {
		t.Fatalf("backup missing: %v", err)
	}
	if string(backupContent) != "old" {
		t.Fatalf("unexpected backup content: %q", backupContent)
	}
	content, err := os.ReadFile(target)
	if err != nil {
		t.Fatal(err)
	}
	if string(content) != "new" {
		t.Fatalf("unexpected target content: %q", content)
	}
}

func TestWriteConfigAtomicallyForWindowsReportsLockedTarget(t *testing.T) {
	root := t.TempDir()
	target := filepath.Join(root, "server.cfg")
	if err := os.WriteFile(target, []byte("old"), 0o640); err != nil {
		t.Fatal(err)
	}

	originalRename := renameConfigFile
	originalRemove := removeConfigFile
	t.Cleanup(func() {
		renameConfigFile = originalRename
		removeConfigFile = originalRemove
	})

	renameConfigFile = func(oldPath, newPath string) error {
		return errors.New("Cannot create a file when that file already exists")
	}
	removeConfigFile = func(path string) error {
		return errors.New("The process cannot access the file because it is being used by another process")
	}

	_, err := writeConfigAtomicallyForOS(target, []byte("new"), true, "windows")
	if err == nil {
		t.Fatal("expected locked file error")
	}
	if got := mapConfigErr(err); got != "FILE_LOCKED" {
		t.Fatalf("expected FILE_LOCKED, got %s (%v)", got, err)
	}
}

func TestWriteConfigAtomicallyForWindowsReportsRenameRetryFailure(t *testing.T) {
	root := t.TempDir()
	target := filepath.Join(root, "server.cfg")
	if err := os.WriteFile(target, []byte("old"), 0o640); err != nil {
		t.Fatal(err)
	}

	originalRename := renameConfigFile
	originalRemove := removeConfigFile
	t.Cleanup(func() {
		renameConfigFile = originalRename
		removeConfigFile = originalRemove
	})

	renameConfigFile = func(oldPath, newPath string) error {
		return errors.New("rename failed")
	}
	removeConfigFile = os.Remove

	_, err := writeConfigAtomicallyForOS(target, []byte("new"), true, "windows")
	if err == nil {
		t.Fatal("expected rename failure")
	}
	if got := mapConfigErr(err); got != "WINDOWS_RENAME_FAILED" {
		t.Fatalf("expected WINDOWS_RENAME_FAILED, got %s (%v)", got, err)
	}
	content, readErr := os.ReadFile(target)
	if readErr != nil {
		t.Fatal(readErr)
	}
	if string(content) != "old" {
		t.Fatalf("expected backup restore after retry failure, got %q", content)
	}
}

func TestConfigApplyRejectsBinary(t *testing.T) {
	root := t.TempDir()
	body, err := json.Marshal(map[string]string{
		"instance_root": root,
		"path":          filepath.Join(root, "a.cfg"),
		"content":       "a\x00b",
	})
	if err != nil {
		t.Fatalf("marshal request: %v", err)
	}
	req := httptest.NewRequest(http.MethodPost, "/v1/instances/1/configs/apply", strings.NewReader(string(body)))
	w := httptest.NewRecorder()
	handled := handleInstanceConfigApplyHTTP(w, req, "1")
	if !handled {
		t.Fatal("expected handled")
	}
	if !strings.Contains(w.Body.String(), "BINARY_NOT_ALLOWED") {
		t.Fatalf("expected binary rejection: %s", w.Body.String())
	}
}

func TestResolveConfigApplyRelativePathSupportsLinuxAndWindows(t *testing.T) {
	linuxTarget, err := resolveConfigApplyRelativePath("/srv/instances/gs1", "cfg/server.cfg", "linux")
	if err != nil {
		t.Fatalf("linux resolve failed: %v", err)
	}
	if linuxTarget != "/srv/instances/gs1/cfg/server.cfg" {
		t.Fatalf("unexpected linux target: %s", linuxTarget)
	}

	windowsTarget, err := resolveConfigApplyRelativePath(`C:\EasyWI\instances\gs1`, `cfg\server.cfg`, "windows")
	if err != nil {
		t.Fatalf("windows resolve failed: %v", err)
	}
	if windowsTarget != "C:/EasyWI/instances/gs1/cfg/server.cfg" {
		t.Fatalf("unexpected windows target: %s", windowsTarget)
	}
}

func TestConfigPathWithinRootIsCaseInsensitiveForWindows(t *testing.T) {
	if !configPathWithinRoot(`C:/EASYWI/Instances/GS1/cfg/server.cfg`, `c:/easywi/instances/gs1`, "windows") {
		t.Fatal("expected windows containment to be case-insensitive")
	}
	if configPathWithinRoot(`D:/easywi/instances/gs1/cfg/server.cfg`, `C:/easywi/instances/gs1`, "windows") {
		t.Fatal("expected different windows drive to be outside root")
	}
}

func TestResolveConfigApplyRelativePathBlocksAbsoluteOutsideRoot(t *testing.T) {
	if _, err := resolveConfigApplyRelativePath("/srv/instances/gs1", "/etc/passwd", "linux"); err == nil {
		t.Fatal("expected linux absolute path to be blocked")
	}
	if _, err := resolveConfigApplyRelativePath(`C:\EasyWI\instances\gs1`, `D:\outside\server.cfg`, "windows"); err == nil {
		t.Fatal("expected windows absolute path to be blocked")
	}
}
