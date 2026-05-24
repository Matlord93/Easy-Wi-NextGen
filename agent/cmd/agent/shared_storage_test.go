package main

import (
	"crypto/sha256"
	"fmt"
	"os"
	"os/user"
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

func TestApplySharedPaths_LegacySymlinkRejected(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	if err := os.MkdirAll(instanceDir, 0o755); err != nil {
		t.Fatal(err)
	}

	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)

	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "symlink", ReadOnly: true}})
	if err == nil || !strings.Contains(err.Error(), "shared_runtime_legacy_removed") {
		t.Fatalf("expected legacy mode rejection, got %v", err)
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
	if err == nil || !strings.Contains(err.Error(), "shared_runtime_legacy_removed") {
		t.Fatalf("expected legacy mode rejection, got %v", err)
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
	if err == nil || !strings.Contains(err.Error(), "shared_runtime_legacy_removed") {
		t.Fatalf("expected legacy mode rejection, got %v", err)
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
	if err == nil || !strings.Contains(err.Error(), "shared_runtime_legacy_removed") {
		t.Fatalf("expected legacy mode rejection, got %v", err)
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
	if err == nil || !strings.Contains(err.Error(), "shared_runtime_legacy_removed") {
		t.Fatalf("expected legacy mode rejection, got %v", err)
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

func TestApplySharedPathsOverlayFailsWithoutPrivileges(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	t.Setenv("EASYWI_INSTANCE_BASE_DIR", base)
	source := filepath.Join(base, "Shared", "1", "server", "game", "csgo")
	_ = os.MkdirAll(filepath.Join(source, "cfg"), 0o755)
	target := filepath.Join(instanceDir, "csgo")
	_ = os.MkdirAll(target, 0o755)
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "game/csgo", Target: "csgo", Mode: "overlay", ReadOnly: true, Exclude: []string{"cfg", "gameinfo.gi"}}})
	if err == nil || !strings.Contains(err.Error(), "shared_runtime_overlay_failed") {
		t.Fatalf("expected overlay failure, got: %v", err)
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
	if err := copyNonSharedFromServer(shared, instance, []sharedPathSpec{{Source: "csgo", Target: "csgo", Mode: "overlay", Exclude: []string{"cfg", "gameinfo.gi"}}}); err != nil {
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
	if err := copyNonSharedFromServer(shared, instance, []sharedPathSpec{{Source: "game/csgo", Target: "csgo", Mode: "overlay", Exclude: []string{"cfg", "gameinfo.gi"}}}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(instance, "csgo", "cfg", "server.cfg")); err != nil {
		t.Fatalf("expected copied exclude from source path: %v", err)
	}
	if _, err := os.Stat(filepath.Join(instance, "csgo", "gameinfo.gi")); err != nil {
		t.Fatalf("expected copied exclude file from source path: %v", err)
	}
	if _, err := os.Stat(filepath.Join(instance, "csgo", "pak01_000.vpk")); err == nil {
		t.Fatalf(".vpk must not be copied from overlay source path")
	}
}

func TestChownInstanceTreeNoFollowUsesLchownForSymlink(t *testing.T) {
	base := t.TempDir()
	root := filepath.Join(base, "inst")
	if err := os.MkdirAll(root, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(root, "a.txt"), []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(filepath.Join(base, "target"), filepath.Join(root, "link")); err != nil {
		t.Fatal(err)
	}
	origChown, origLchown := osChownFn, osLchownFn
	defer func() { osChownFn, osLchownFn = origChown, origLchown }()
	chownCalls := 0
	lchownCalls := 0
	osChownFn = func(name string, uid, gid int) error { chownCalls++; return nil }
	osLchownFn = func(name string, uid, gid int) error { lchownCalls++; return nil }
	if err := chownInstanceTreeNoFollow(root, "root"); err != nil {
		t.Fatal(err)
	}
	if chownCalls == 0 || lchownCalls == 0 {
		t.Fatalf("expected chown and lchown calls, got %d/%d", chownCalls, lchownCalls)
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

func TestResolveSharedLockPathsUsesPerSharedServerLocks(t *testing.T) {
	base := t.TempDir()
	lockDir, lockPath := resolveSharedLockPaths(base, "1")
	expectedDir := filepath.Join(base, "Shared", "1", "server", ".locks")
	expectedPath := filepath.Join(expectedDir, "1.lock")
	if lockDir != expectedDir {
		t.Fatalf("unexpected lock dir: %s", lockDir)
	}
	if lockPath != expectedPath {
		t.Fatalf("unexpected lock path: %s", lockPath)
	}
	if strings.Contains(lockPath, filepath.Join("Shared", ".locks")) {
		t.Fatalf("lock path must not use global shared lock base: %s", lockPath)
	}
	if filepath.Dir(lockPath) != lockDir {
		t.Fatalf("lock path parent mismatch: %s vs %s", filepath.Dir(lockPath), lockDir)
	}
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

func TestSniperSharedUpdateCommandFailureMarksJobFailed(t *testing.T) {
	base := t.TempDir()
	key := "minecraft"
	root := sharedRootFor(base, key)
	serverDir := filepath.Join(root, "server")
	if err := os.MkdirAll(serverDir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := writeSharedManifest(sharedManifestPath(base, key), sharedManifest{SharedKey: key, TemplateID: "1", Status: "ready"}); err != nil {
		t.Fatal(err)
	}

	job := jobs.Job{ID: "4", Payload: map[string]any{
		"base_dir":       base,
		"shared_key":     key,
		"update_command": "exit 7",
	}}
	res, _ := handleSniperSharedUpdate(job, nil)
	if res.Status != "failed" {
		t.Fatalf("expected failed, got %s", res.Status)
	}
	if strings.Contains(res.Output["message"], "shared update completed") {
		t.Fatalf("must not report shared update completed on failure: %#v", res.Output)
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

func TestWriteSteamCmdRunScript(t *testing.T) {
	base := t.TempDir()
	path := filepath.Join(base, ".update", "shared_update_1.txt")
	currentUser, _ := user.Current()
	username := ""
	if currentUser != nil {
		username = currentUser.Username
	}
	if err := writeSteamCmdRunScript(path, "/home/Shared/1/server", "anonymous", "730", username, true); err != nil {
		t.Fatal(err)
	}
	body, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	content := string(body)
	for _, expected := range []string{
		"force_install_dir /home/Shared/1/server",
		"login anonymous",
		"app_update 730",
		"quit",
	} {
		if !strings.Contains(content, expected) {
			t.Fatalf("missing %q in runscript: %s", expected, content)
		}
	}
	if stat, err := os.Stat(filepath.Dir(path)); err != nil || stat.Mode().Perm() != 0o755 {
		t.Fatalf("expected .update dir with 0755, got err=%v mode=%v", err, stat.Mode().Perm())
	}
	if stat, err := os.Stat(path); err != nil || stat.Mode().Perm() != 0o644 {
		t.Fatalf("expected runscript with 0644, got err=%v mode=%v", err, stat.Mode().Perm())
	}
}

func TestWriteSteamCmdRunScriptWithoutValidate(t *testing.T) {
	path := filepath.Join(t.TempDir(), ".update", "shared_update_3.txt")
	if err := writeSteamCmdRunScript(path, "/home/Shared/1/server", "anonymous", "730", "", false); err != nil {
		t.Fatal(err)
	}
	content, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(content), "validate") {
		t.Fatalf("expected no validate token in runscript: %s", string(content))
	}
}

func TestWriteSteamCmdRunScriptRequiresAppID(t *testing.T) {
	err := writeSteamCmdRunScript(filepath.Join(t.TempDir(), ".update", "shared_update_2.txt"), "/home/Shared/1/server", "anonymous", "", "", true)
	if err == nil || !strings.Contains(err.Error(), "missing_app_update") {
		t.Fatalf("expected missing_app_update, got %v", err)
	}
}

func TestShouldUseSharedStorage_SharedPathsAloneDoesNotActivate(t *testing.T) {
	payload := map[string]any{
		"shared_paths": []any{map[string]any{"source": "csgo", "target": "csgo", "mode": "symlink", "readonly": true}},
	}
	if shouldUseSharedStorage(payload, "instance_reinstall") {
		t.Fatalf("expected shared storage to remain disabled when only shared_paths are configured")
	}
}

func TestShouldUseSharedStorage_ActivatesForSharedFlagsAndModes(t *testing.T) {
	cases := []map[string]any{
		{"shared_enabled": true},
		{"install_mode": "shared"},
	}
	for i, payload := range cases {
		if !shouldUseSharedStorage(payload, "instance_reinstall") {
			t.Fatalf("expected shared storage to be active for case %d", i)
		}
	}
	if !shouldUseSharedStorage(map[string]any{}, "shared_reinstall") {
		t.Fatalf("expected shared action to activate shared validation")
	}
}

func TestParseSharedPathSpecs_DefaultModeIsNone(t *testing.T) {
	specs, err := parseSharedPathSpecs(map[string]any{
		"shared_paths": []any{map[string]any{"source": "maps", "target": "maps", "readonly": true}},
	})
	if err != nil {
		t.Fatal(err)
	}
	if got := specs[0].Mode; got != "none" {
		t.Fatalf("expected default mode none, got %q", got)
	}
}

func TestApplySharedPaths_BindUsesMountOps(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	origBind, origCheck := bindMountFn, mountCheckFn
	defer func() { bindMountFn, mountCheckFn = origBind, origCheck }()
	bindMountFn = func(source, target string) error { return nil }
	mountCheckFn = func(source, target string) (bool, error) { return true, nil }
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "bind", ReadOnly: true}})
	if err != nil {
		t.Fatalf("expected bind mount success via fake ops, got %v", err)
	}
	if info, statErr := os.Stat(filepath.Join(instanceDir, "maps")); statErr != nil || !info.IsDir() {
		t.Fatalf("expected bind target path prepared")
	}
}

func TestApplySharedPaths_OverlayUsesMountOps(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	origOverlay := overlayMountFn
	defer func() { overlayMountFn = origOverlay }()
	overlayMountFn = func(lowerdir, upperdir, workdir, merged string) error { return nil }
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "overlay", ReadOnly: true}})
	if err != nil {
		t.Fatalf("expected overlay mount success via fake ops, got %v", err)
	}
	if info, statErr := os.Stat(filepath.Join(instanceDir, "maps")); statErr != nil || !info.IsDir() {
		t.Fatalf("expected overlay merge target path prepared")
	}
}

func TestApplySharedPaths_LegacySymlinkRemovedEvenWhenExplicit(t *testing.T) {
	base := t.TempDir()
	instanceDir := filepath.Join(base, "servers", "s1")
	_ = os.MkdirAll(instanceDir, 0o755)
	err := applySharedPaths(instanceDir, filepath.Join(base, "Shared", "1", "server"), []sharedPathSpec{{Source: "maps", Target: "maps", Mode: "legacy_symlink", ReadOnly: true}})
	if err == nil || !strings.Contains(err.Error(), "shared_runtime_legacy_removed") {
		t.Fatalf("expected legacy_symlink rejection, got %v", err)
	}
}
