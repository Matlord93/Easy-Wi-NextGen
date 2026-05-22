package main

import (
	"crypto/sha256"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/jobs"
)

func TestParseSharedPathSpecs_Empty(t *testing.T) {
	specs, err := parseSharedPathSpecs(map[string]any{})
	if err != nil || len(specs) != 0 {
		t.Fatalf("expected empty specs without error, got specs=%v err=%v", specs, err)
	}
}

func TestApplySharedPaths_CreatesSymlink(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	if err := os.MkdirAll(instanceDir, 0o755); err != nil {
		t.Fatal(err)
	}

	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)

	err := applySharedPaths(instanceDir, "minecraft", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err != nil {
		t.Fatalf("applySharedPaths failed: %v", err)
	}
	info, err := os.Lstat(filepath.Join(instanceDir, "maps"))
	if err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("expected symlink")
	}
}

func TestApplySharedPaths_RejectsTraversal(t *testing.T) {
	err := applyOneSharedPath("/tmp/i", "/tmp/s", sharedPathSpec{Source: "../bad", Target: "maps", Mode: "symlink"})
	if err == nil {
		t.Fatalf("expected traversal rejection")
	}
}

func TestApplySharedPaths_RejectsSensitive(t *testing.T) {
	err := applyOneSharedPath("/tmp/i", "/tmp/s", sharedPathSpec{Source: "config", Target: "config", Mode: "symlink", ReadOnly: true})
	if err == nil {
		t.Fatalf("expected sensitive path rejection")
	}
}

func TestValidateSharedRelativePathRejectsAbsoluteDotAndEmpty(t *testing.T) {
	for _, in := range []string{"", ".", "/abs/path"} {
		if _, err := validateSharedRelativePath(in); err == nil {
			t.Fatalf("expected invalid path for %q", in)
		}
	}
}

func TestApplySharedPathsRejectsInvalidTemplateID(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	err := applySharedPaths(instanceDir, "../evil", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err == nil {
		t.Fatalf("expected invalid template_id error")
	}
}

func TestApplySharedPathsExistingCorrectSymlinkUntouched(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	shared := filepath.Join(base, "shared", "minecraft", "maps")
	_ = os.MkdirAll(shared, 0o755)
	_ = os.MkdirAll(instanceDir, 0o755)
	target := filepath.Join(instanceDir, "maps")
	if err := os.Symlink(shared, target); err != nil {
		t.Fatal(err)
	}
	before, _ := os.Readlink(target)
	err := applySharedPaths(instanceDir, "minecraft", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	after, _ := os.Readlink(target)
	if before != after {
		t.Fatalf("expected symlink unchanged")
	}
}

func TestApplySharedPathsExistingWrongSymlinkRejected(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	_ = os.MkdirAll(filepath.Join(base, "elsewhere"), 0o755)
	_ = os.MkdirAll(instanceDir, 0o755)
	if err := os.Symlink(filepath.Join(base, "elsewhere"), filepath.Join(instanceDir, "maps")); err != nil {
		t.Fatal(err)
	}
	err := applySharedPaths(instanceDir, "minecraft", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err == nil {
		t.Fatalf("expected wrong symlink rejection")
	}
}

func TestApplySharedPathsExistingEmptyDirReplaced(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	_ = os.MkdirAll(filepath.Join(instanceDir, "maps"), 0o755)
	err := applySharedPaths(instanceDir, "minecraft", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	info, err := os.Lstat(filepath.Join(instanceDir, "maps"))
	if err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("expected replacement by symlink")
	}
}

func TestApplySharedPathsSeedsSharedFromExistingTarget(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	targetPath := filepath.Join(instanceDir, "maps")
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	_ = os.MkdirAll(targetPath, 0o755)
	if err := os.WriteFile(filepath.Join(targetPath, "asset.dat"), []byte("seed"), 0o644); err != nil {
		t.Fatal(err)
	}

	err := applySharedPaths(instanceDir, "minecraft", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	info, err := os.Lstat(targetPath)
	if err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("expected target path to become symlink")
	}
	linked, err := os.Readlink(targetPath)
	if err != nil {
		t.Fatal(err)
	}
	if filepath.Clean(linked) != filepath.Clean(filepath.Join(base, "shared", "minecraft", "maps")) {
		t.Fatalf("unexpected symlink target %q", linked)
	}
	if _, err := os.Stat(filepath.Join(base, "shared", "minecraft", "maps", "asset.dat")); err != nil {
		t.Fatalf("expected seeded asset in shared storage: %v", err)
	}
}

func TestUniqueBackupPathIsUnique(t *testing.T) {
	base := t.TempDir()
	path := filepath.Join(base, "maps")
	first, err := uniqueBackupPath(path, ".instance-backup")
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(first, []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	second, err := uniqueBackupPath(path, ".instance-backup")
	if err != nil {
		t.Fatal(err)
	}
	if first == second {
		t.Fatalf("expected unique backup names")
	}
}

func TestApplySharedPathsRejectsUnsupportedMode(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	err := applySharedPaths(instanceDir, "minecraft", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "hardlink", ReadOnly: true}})
	if err == nil {
		t.Fatalf("expected unsupported mode rejection")
	}
}

func TestApplySharedPathsRejectsReadOnlyFalse(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	err := applySharedPaths(instanceDir, "minecraft", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: false}})
	if err == nil {
		t.Fatalf("expected readonly=false rejection")
	}
}

func TestSharedStorageBaseDirPrefersEnv(t *testing.T) {
	base := t.TempDir()
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	got := sharedStorageBaseDir("/home/gs211/game")
	if got != base {
		t.Fatalf("expected env base %q, got %q", base, got)
	}
}

func TestSharedStorageBaseDirFallsBackToInstanceParent(t *testing.T) {
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", "")
	got := sharedStorageBaseDir("/home/gs211/game")
	if got != "/home/gs211" {
		t.Fatalf("expected instance parent /home/gs211, got %q", got)
	}
}

func TestSensitiveSubpathBlocked(t *testing.T) {
	if !isSensitiveSharedPath("cfg/server.cfg") {
		t.Fatalf("expected cfg/server.cfg to be blocked")
	}
}

func TestApplySharedPathsSeedsSharedFromExistingFileTarget(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	targetPath := filepath.Join(instanceDir, "game", "csgo", "pak01.vpk")
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	if err := os.MkdirAll(filepath.Dir(targetPath), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(targetPath, []byte("seed-file"), 0o644); err != nil {
		t.Fatal(err)
	}

	err := applySharedPaths(instanceDir, "cs2", []sharedPathSpec{{Source: "game/csgo/pak01.vpk", Target: "game/csgo/pak01.vpk", Mode: "symlink", ReadOnly: true}})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}

	info, err := os.Lstat(targetPath)
	if err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("expected target file to become symlink")
	}
	linked, err := os.Readlink(targetPath)
	if err != nil {
		t.Fatal(err)
	}
	if filepath.Clean(linked) != filepath.Clean(filepath.Join(base, "shared", "cs2", "game", "csgo", "pak01.vpk")) {
		t.Fatalf("unexpected symlink target %q", linked)
	}
	seeded := filepath.Join(base, "shared", "cs2", "game", "csgo", "pak01.vpk")
	data, err := os.ReadFile(seeded)
	if err != nil {
		t.Fatalf("expected seeded file in shared storage: %v", err)
	}
	if string(data) != "seed-file" {
		t.Fatalf("unexpected seeded file content: %q", string(data))
	}
}

func TestBuildSharedKeyPrefersTemplateSlug(t *testing.T) {
	key, err := buildSharedKey(map[string]any{"template_slug": "CS2 Dedicated/Release"})
	if err != nil {
		t.Fatal(err)
	}
	if key != "cs2-dedicated-release" {
		t.Fatalf("unexpected key %q", key)
	}
}

func TestAcquireSharedStorageLockWithTimeoutRemovesStale(t *testing.T) {
	base := t.TempDir()
	lock := filepath.Join(base, "x.lock")
	if err := os.WriteFile(lock, []byte("1\n1\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	release, err := acquireSharedStorageLockWithTimeout(lock, 10*time.Millisecond)
	if err != nil {
		t.Fatalf("expected stale lock cleanup: %v", err)
	}
	release()
}

func TestSharedManifestWriteReadRoundTrip(t *testing.T) {
	base := t.TempDir()
	path := filepath.Join(base, ".shared-manifest.json")
	in := sharedManifest{
		SharedKey:          "minecraft",
		TemplateID:         "42",
		Status:             "updating",
		InstallCommandHash: "abc",
		SharedPaths:        []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}},
	}
	if err := writeSharedManifest(path, in); err != nil {
		t.Fatal(err)
	}
	out, err := readSharedManifest(path)
	if err != nil {
		t.Fatal(err)
	}
	if out.SharedKey != in.SharedKey || out.Status != in.Status {
		t.Fatalf("unexpected manifest roundtrip: %#v", out)
	}
}

func TestSniperSharedUpdateFailsWhenManifestMissing(t *testing.T) {
	base := t.TempDir()
	job := jobs.Job{ID: "1", Payload: map[string]any{
		"base_dir":       base,
		"shared_key":     "minecraft",
		"update_command": "echo ok",
	}}
	res, _ := handleSniperSharedUpdate(job, nil)
	if res.Status != "failed" || !strings.Contains(res.Output["message"], "SHARED_MANIFEST_INVALID") {
		t.Fatalf("expected SHARED_MANIFEST_INVALID, got %#v", res.Output)
	}
}

func TestSniperSharedUpdateFailsWhenServerMissing(t *testing.T) {
	base := t.TempDir()
	key := "minecraft"
	manifest := sharedManifest{SharedKey: key, TemplateID: "1", Status: "ready"}
	if err := os.MkdirAll(sharedRootFor(base, key), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := writeSharedManifest(sharedManifestPath(base, key), manifest); err != nil {
		t.Fatal(err)
	}
	job := jobs.Job{ID: "2", Payload: map[string]any{"base_dir": base, "shared_key": key, "update_command": "echo ok"}}
	res, _ := handleSniperSharedUpdate(job, nil)
	if res.Status != "failed" || !strings.Contains(res.Output["message"], "SHARED_SERVER_MISSING") {
		t.Fatalf("expected SHARED_SERVER_MISSING, got %#v", res.Output)
	}
}

func TestSniperSharedUpdateMissingCommandSetsManifestFailed(t *testing.T) {
	base := t.TempDir()
	key := "minecraft"
	root := sharedRootFor(base, key)
	if err := os.MkdirAll(filepath.Join(root, "server"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := writeSharedManifest(sharedManifestPath(base, key), sharedManifest{SharedKey: key, TemplateID: "1", Status: "ready"}); err != nil {
		t.Fatal(err)
	}
	job := jobs.Job{ID: "3", Payload: map[string]any{"base_dir": base, "shared_key": key}}
	res, _ := handleSniperSharedUpdate(job, nil)
	if res.Status != "failed" {
		t.Fatalf("expected failed")
	}
	mf, err := readSharedManifest(sharedManifestPath(base, key))
	if err != nil {
		t.Fatal(err)
	}
	if mf.Status != "failed" {
		t.Fatalf("expected failed manifest, got %s", mf.Status)
	}
}

func TestEvaluateSharedInstallReuseReadyManifest(t *testing.T) {
	base := t.TempDir()
	manifestPath := filepath.Join(base, ".shared-manifest.json")
	serverDir := filepath.Join(base, "server")
	if err := os.MkdirAll(serverDir, 0o755); err != nil {
		t.Fatal(err)
	}
	command := "echo install"
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(command)))
	initial := sharedManifest{SharedKey: "minecraft", TemplateID: "1", Status: "ready", InstallCommandHash: hash}
	if err := writeSharedManifest(manifestPath, initial); err != nil {
		t.Fatal(err)
	}

	mf, reused, err := evaluateSharedInstallReuse(manifestPath, serverDir, "install", command, nil, map[string]any{"template_id": "1"}, "minecraft")
	if err != nil {
		t.Fatal(err)
	}
	if !reused {
		t.Fatalf("expected reused=true")
	}
	if mf.Status != "ready" {
		t.Fatalf("expected status ready, got %s", mf.Status)
	}
}
