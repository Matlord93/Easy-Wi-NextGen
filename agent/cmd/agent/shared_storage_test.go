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
	_ = os.MkdirAll(instanceDir, 0o755)
	if err := os.MkdirAll(instanceDir, 0o755); err != nil {
		t.Fatal(err)
	}

	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)

	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
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
	err := applySharedPaths(instanceDir, "", []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err == nil {
		t.Fatalf("expected missing shared root error")
	}
}

func TestApplySharedPathsExistingCorrectSymlinkUntouched(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	shared := filepath.Join(base, "Shared", "1", "server", "maps")
	_ = os.MkdirAll(shared, 0o755)
	_ = os.MkdirAll(instanceDir, 0o755)
	target := filepath.Join(instanceDir, "maps")
	if err := os.Symlink(shared, target); err != nil {
		t.Fatal(err)
	}
	before, _ := os.Readlink(target)
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
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
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err == nil {
		t.Fatalf("expected wrong symlink rejection")
	}
}

func TestApplySharedPathsExistingEmptyDirReplaced(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	_ = os.MkdirAll(filepath.Join(instanceDir, "maps"), 0o755)
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
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

	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
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
	if filepath.Clean(linked) != filepath.Clean(filepath.Join(base, "Shared", "1", "server", "maps")) {
		t.Fatalf("unexpected symlink target %q", linked)
	}
	if _, err := os.Stat(filepath.Join(base, "Shared", "1", "server", "maps", "asset.dat")); err != nil {
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
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "hardlink", ReadOnly: true}})
	if err == nil {
		t.Fatalf("expected unsupported mode rejection")
	}
}

func TestApplySharedPathsRejectsReadOnlyFalse(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: false}})
	if err == nil {
		t.Fatalf("expected readonly=false rejection")
	}
}

func TestApplySharedPathsUsesProvidedSharedRootUppercase(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	sharedRoot := filepath.Join(base, "Shared", "1", "server")
	err := applySharedPaths(instanceDir, sharedRoot, []sharedPathSpec{{Source: "game/core", Target: "core", Mode: "symlink", ReadOnly: true}})
	if err != nil {
		t.Fatalf("applySharedPaths failed: %v", err)
	}
	linkTarget, err := os.Readlink(filepath.Join(instanceDir, "core"))
	if err != nil {
		t.Fatalf("readlink failed: %v", err)
	}
	expected := filepath.Join(base, "Shared", "1", "server", "game", "core")
	if filepath.Clean(linkTarget) != filepath.Clean(expected) {
		t.Fatalf("unexpected symlink target %q expected %q", linkTarget, expected)
	}
	if _, err := os.Stat(filepath.Join(base, "shared")); !os.IsNotExist(err) {
		t.Fatalf("did not expect lowercase shared dir to be created")
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

	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "game/csgo/pak01.vpk", Target: "game/csgo/pak01.vpk", Mode: "symlink", ReadOnly: true}})
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
	if filepath.Clean(linked) != filepath.Clean(filepath.Join(base, "Shared", "1", "server", "game", "csgo", "pak01.vpk")) {
		t.Fatalf("unexpected symlink target %q", linked)
	}
	seeded := filepath.Join(base, "Shared", "1", "server", "game", "csgo", "pak01.vpk")
	data, err := os.ReadFile(seeded)
	if err != nil {
		t.Fatalf("expected seeded file in shared storage: %v", err)
	}
	if string(data) != "seed-file" {
		t.Fatalf("unexpected seeded file content: %q", string(data))
	}
}

func TestApplySharedPathsSharedTreeCreatesRealDirAndSymlinks(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	source := filepath.Join(base, "Shared", "1", "server", "game", "csgo")
	_ = os.MkdirAll(filepath.Join(source, "cfg"), 0o755)
	_ = os.WriteFile(filepath.Join(source, "cfg", "server.cfg"), []byte("local"), 0o644)
	_ = os.WriteFile(filepath.Join(source, "gameinfo.gi"), []byte("gi"), 0o644)
	_ = os.WriteFile(filepath.Join(source, "pak01_000.vpk"), []byte("vpk"), 0o644)
	_ = os.WriteFile(filepath.Join(source, "pak01_001.vpk"), []byte("vpk"), 0o644)
	_ = os.WriteFile(filepath.Join(source, "pak01_dir.vpk"), []byte("vpk"), 0o644)
	_ = os.MkdirAll(filepath.Join(source, "maps"), 0o755)
	target := filepath.Join(instanceDir, "game", "csgo")
	_ = os.MkdirAll(filepath.Join(target, "cfg"), 0o755)
	_ = os.WriteFile(filepath.Join(target, "cfg", "local.cfg"), []byte("local"), 0o644)
	_ = os.WriteFile(filepath.Join(target, "gameinfo.gi"), []byte("local-gi"), 0o644)
	_ = os.WriteFile(filepath.Join(target, "pak01_000.vpk"), []byte("old-local-vpk"), 0o644)
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "game/csgo", Target: "game/csgo", Mode: "shared_tree", ReadOnly: true, Exclude: []string{"cfg", "gameinfo.gi"}}})
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if info, _ := os.Lstat(target); info.Mode()&os.ModeSymlink != 0 {
		t.Fatalf("expected real target directory")
	}
	if info, _ := os.Lstat(filepath.Join(target, "cfg")); info.Mode()&os.ModeSymlink != 0 {
		t.Fatalf("cfg must stay local")
	}
	if info, _ := os.Lstat(filepath.Join(target, "gameinfo.gi")); info.Mode()&os.ModeSymlink != 0 {
		t.Fatalf("gameinfo.gi must stay local")
	}
	for _, name := range []string{"pak01_000.vpk", "pak01_001.vpk", "pak01_dir.vpk"} {
		vpkPath := filepath.Join(target, name)
		info, err := os.Lstat(vpkPath)
		if err != nil {
			t.Fatalf("expected %s: %v", name, err)
		}
		if info.Mode()&os.ModeSymlink == 0 {
			t.Fatalf("%s should be symlink", name)
		}
		linkTarget, err := os.Readlink(vpkPath)
		if err != nil {
			t.Fatalf("readlink %s: %v", name, err)
		}
		expected := filepath.Join(base, "Shared", "1", "server", "game", "csgo", name)
		if filepath.Clean(linkTarget) != filepath.Clean(expected) {
			t.Fatalf("unexpected symlink target for %s: %q != %q", name, linkTarget, expected)
		}
	}
	if info, _ := os.Lstat(filepath.Join(target, "maps")); info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("maps should be symlink")
	}
	if _, err := os.Stat(filepath.Join(target, "cfg", "server.cfg")); err != nil {
		t.Fatalf("excluded cfg should be copied locally from shared source: %v", err)
	}
	matches, err := filepath.Glob(target + ".instance-backup*")
	if err != nil {
		t.Fatalf("glob backup paths: %v", err)
	}
	if len(matches) == 0 {
		t.Fatalf("expected existing local tree backup for conflict")
	}
}

func TestCopyNonSharedFromServerSharedTreeCopiesOnlyExcludes(t *testing.T) {
	shared := t.TempDir()
	instance := filepath.Join(t.TempDir(), "inst")
	_ = os.MkdirAll(filepath.Join(shared, "csgo", "cfg"), 0o755)
	_ = os.WriteFile(filepath.Join(shared, "csgo", "cfg", "server.cfg"), []byte("x"), 0o644)
	_ = os.WriteFile(filepath.Join(shared, "csgo", "gameinfo.gi"), []byte("x"), 0o644)
	_ = os.WriteFile(filepath.Join(shared, "csgo", "pak01_000.vpk"), []byte("x"), 0o644)
	_ = os.WriteFile(filepath.Join(shared, "csgo", "pak01_dir.vpk"), []byte("x"), 0o644)
	if err := copyNonSharedFromServer(shared, instance, []sharedPathSpec{{Source: "csgo", Target: "csgo", Mode: "shared_tree", Exclude: []string{"cfg", "gameinfo.gi"}}}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(instance, "csgo", "cfg", "server.cfg")); err != nil {
		t.Fatalf("expected copied exclude: %v", err)
	}
	if _, err := os.Stat(filepath.Join(instance, "csgo", "gameinfo.gi")); err != nil {
		t.Fatalf("expected copied exclude file: %v", err)
	}
	for _, name := range []string{"pak01_000.vpk", "pak01_dir.vpk"} {
		if _, err := os.Stat(filepath.Join(instance, "csgo", name)); err == nil {
			t.Fatalf("non-excluded file %s must not be copied", name)
		}
	}
}

func TestCopyNonSharedFromServerSharedTreeUsesSourcePathNotTargetPath(t *testing.T) {
	shared := t.TempDir()
	instance := filepath.Join(t.TempDir(), "inst")
	_ = os.MkdirAll(filepath.Join(shared, "game", "csgo", "cfg"), 0o755)
	_ = os.WriteFile(filepath.Join(shared, "game", "csgo", "cfg", "server.cfg"), []byte("x"), 0o644)
	_ = os.WriteFile(filepath.Join(shared, "game", "csgo", "gameinfo.gi"), []byte("x"), 0o644)
	_ = os.WriteFile(filepath.Join(shared, "game", "csgo", "pak01_000.vpk"), []byte("x"), 0o644)
	if err := copyNonSharedFromServer(shared, instance, []sharedPathSpec{{Source: "game/csgo", Target: "csgo", Mode: "shared_tree", Exclude: []string{"cfg", "gameinfo.gi"}}}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(instance, "game", "csgo", "cfg", "server.cfg")); err != nil {
		t.Fatalf("expected copied exclude from source path: %v", err)
	}
	if _, err := os.Stat(filepath.Join(instance, "game", "csgo", "gameinfo.gi")); err != nil {
		t.Fatalf("expected copied exclude file from source path: %v", err)
	}
	if _, err := os.Stat(filepath.Join(instance, "game", "csgo", "pak01_000.vpk")); err == nil {
		t.Fatalf(".vpk must not be copied from shared_tree source path")
	}
}

func TestParseSharedPathSpecsRejectsExcludeTraversal(t *testing.T) {
	_, err := parseSharedPathSpecs(map[string]any{"shared_paths": []any{map[string]any{
		"source": "game/csgo", "target": "csgo", "mode": "shared_tree", "readonly": true, "exclude": []any{"../cfg"},
	}}})
	if err == nil {
		t.Fatalf("expected traversal error")
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
