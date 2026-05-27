package main

import (
	"os"
	"os/user"
	"path/filepath"
	"strings"
	"testing"
)

func TestResolveSniperUserHomeAndGameDir(t *testing.T) {
	t.Run("home path", func(t *testing.T) {
		home, game, err := resolveSniperUserHomeAndGameDir("/home/gs225", "gs225")
		if err != nil {
			t.Fatal(err)
		}
		if home != "/home/gs225" || game != "/home/gs225/game" {
			t.Fatalf("got %s %s", home, game)
		}
	})
	t.Run("game path", func(t *testing.T) {
		home, game, err := resolveSniperUserHomeAndGameDir("/home/gs225/game", "gs225")
		if err != nil {
			t.Fatal(err)
		}
		if home != "/home/gs225" || game != "/home/gs225/game" {
			t.Fatalf("got %s %s", home, game)
		}
	})
}

func TestSharedPrepareUsesGameDir(t *testing.T) {
	base := t.TempDir()
	shared := filepath.Join(base, "Shared", "1", "server")
	gameDir := filepath.Join(base, "home", "gs225", "game")
	must := func(err error) {
		if err != nil {
			t.Fatal(err)
		}
	}
	must(os.MkdirAll(filepath.Join(shared, "game/bin/linuxsteamrt64"), 0o755))
	must(os.MkdirAll(filepath.Join(shared, "game/platform"), 0o755))
	must(os.MkdirAll(filepath.Join(shared, "game/core"), 0o755))
	must(os.MkdirAll(filepath.Join(shared, "game/csgo/cfg"), 0o755))
	must(os.MkdirAll(filepath.Join(shared, "game/csgo_community_addons"), 0o755))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/pak01_000.vpk"), []byte("x"), 0o644))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/pak01_dir.vpk"), []byte("x"), 0o644))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/cfg/server.cfg"), []byte("cfg"), 0o644))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/gameinfo.gi"), []byte("gi"), 0o644))
	must(os.WriteFile(filepath.Join(shared, "game/cs2.sh"), []byte("#!/bin/sh\nexit 0\n"), 0o755))
	must(os.WriteFile(filepath.Join(shared, "game/bin/linuxsteamrt64/cs2"), []byte("bin"), 0o755))
	must(os.MkdirAll(gameDir, 0o755))

	origBind, origOverlay, origCheck := bindMountFn, overlayMountFn, mountCheckFn
	defer func() { bindMountFn, overlayMountFn, mountCheckFn = origBind, origOverlay, origCheck }()
	bindMountFn = func(source, target string) error { return os.MkdirAll(target, 0o755) }
	overlayMountFn = func(lowerdir, upperdir, workdir, merged string) error { return os.MkdirAll(merged, 0o755) }
	mountCheckFn = func(source, target string) (bool, error) { return true, nil }
	specs := []sharedPathSpec{{Source: "game/bin", Target: "bin", Mode: "bind", ReadOnly: true}, {Source: "game/platform", Target: "platform", Mode: "bind", ReadOnly: true}, {Source: "game/core", Target: "core", Mode: "bind", ReadOnly: true}, {Source: "game/csgo", Target: "csgo", Mode: "overlay", ReadOnly: true, Exclude: []string{"cfg", "gameinfo.gi"}}, {Source: "game/csgo_community_addons", Target: "csgo_community_addons", Mode: "bind", ReadOnly: true}}
	must(copyNonSharedFromServer(shared, gameDir, specs))
	must(applySharedPaths(gameDir, shared, specs))

	if info, err := os.Stat(filepath.Join(gameDir, "bin")); err != nil || !info.IsDir() {
		t.Fatalf("bin mount target missing")
	}
	if info, err := os.Stat(filepath.Join(gameDir, "platform")); err != nil || !info.IsDir() {
		t.Fatalf("platform mount target missing")
	}
	if info, err := os.Stat(filepath.Join(gameDir, "core")); err != nil || !info.IsDir() {
		t.Fatalf("core mount target missing")
	}
	if info, err := os.Stat(filepath.Join(gameDir, "csgo")); err != nil || !info.IsDir() {
		t.Fatalf("csgo runtime dir missing")
	}
	if info, err := os.Stat(filepath.Join(gameDir, "csgo_community_addons")); err != nil || !info.IsDir() {
		t.Fatalf("addons mount target missing")
	}
	if _, err := os.Stat(filepath.Join(base, "home", "gs225", "core")); !os.IsNotExist(err) {
		t.Fatalf("unexpected /home/gs225/core")
	}
	if _, err := os.Stat(filepath.Join(base, "home", "gs225", "csgo")); !os.IsNotExist(err) {
		t.Fatalf("unexpected /home/gs225/csgo")
	}
}

func TestValidateExpectedStartExecutablePathRequiresExecutable(t *testing.T) {
	base := t.TempDir()
	gameDir := filepath.Join(base, "home", "gs226", "game")
	if err := os.MkdirAll(gameDir, 0o755); err != nil {
		t.Fatal(err)
	}
	script := filepath.Join(gameDir, "cs2.sh")
	if err := os.WriteFile(script, []byte("#!/bin/sh\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	exists, executable, err := validateExpectedStartExecutablePath(gameDir, script, false)
	if err != nil || !exists || executable {
		t.Fatalf("expected non-executable script to fail, exists=%t executable=%t err=%v", exists, executable, err)
	}

	if err := os.Chmod(script, 0o755); err != nil {
		t.Fatal(err)
	}
	exists, executable, err = validateExpectedStartExecutablePath(gameDir, script, false)
	if err != nil || !exists || !executable {
		t.Fatalf("expected valid executable script, exists=%t executable=%t err=%v", exists, executable, err)
	}
}

func TestIsCS2TemplateDetection(t *testing.T) {
	if !isCS2Template(map[string]any{"steam_app_id": "730"}, nil) {
		t.Fatalf("expected cs2 by steam app id")
	}
	if isCS2Template(map[string]any{"uses_steamcmd": true, "steam_app_id": "258550", "game_key": "rust"}, nil) {
		t.Fatalf("rust steamcmd template must not be cs2")
	}
}

func TestResolveExpectedStartExecutable(t *testing.T) {
	gameDir := "/home/gs227/game"
	p, mode, required, err := resolveExpectedStartExecutable(gameDir, "", "/home/gs21/game/srcds_run -game left4dead2 -port 27015", "", map[string]any{"game_key": "l4d2"})
	if err != nil || p != "/home/gs21/game/srcds_run" || mode != "direct_binary" || !required {
		t.Fatalf("l4d2 absolute expected direct binary, got path=%s mode=%s required=%t err=%v", p, mode, required, err)
	}
	p, mode, required, err = resolveExpectedStartExecutable(gameDir, "", "./srcds_run -game left4dead2", "", map[string]any{"game_key": "l4d2"})
	if err != nil || p != "/home/gs227/game/srcds_run" || mode != "direct_binary" || !required {
		t.Fatalf("relative expected direct binary, got path=%s mode=%s required=%t err=%v", p, mode, required, err)
	}
	p, mode, required, err = resolveExpectedStartExecutable(gameDir, "", "", "", map[string]any{"game_key": "rust"})
	if err != nil || p == "/home/gs227/game/start.sh" {
		t.Fatalf("must not fallback to start.sh, got path=%s mode=%s required=%t err=%v", p, mode, required, err)
	}
	p, mode, required, err = resolveExpectedStartExecutable(gameDir, "", "./server_binary", "/home/gs227/game/start.sh", map[string]any{"start_script": "start.sh"})
	if err != nil || p != "/home/gs227/game/start.sh" || mode != "generated_script" || !required {
		t.Fatalf("generated script expected, got path=%s mode=%s required=%t err=%v", p, mode, required, err)
	}
	p, mode, required, err = resolveExpectedStartExecutable(gameDir, "/bin/bash -lc \"echo test && ./server\"", "", "", map[string]any{})
	if err != nil || p != "" || mode != "shell_command" || required {
		t.Fatalf("expected shell command mode, got path=%s mode=%s required=%t err=%v", p, mode, required, err)
	}
}

func TestValidateExpectedStartExecutablePathGenericScript(t *testing.T) {
	base := t.TempDir()
	gameDir := filepath.Join(base, "home", "gs227", "game")
	if err := os.MkdirAll(gameDir, 0o755); err != nil {
		t.Fatal(err)
	}
	script := filepath.Join(gameDir, "start.sh")
	if err := os.WriteFile(script, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	exists, executable, err := validateExpectedStartExecutablePath(gameDir, script, false)
	if err != nil || !exists || !executable {
		t.Fatalf("expected generic start.sh to validate, exists=%t executable=%t err=%v", exists, executable, err)
	}
}

func TestValidateSharedUpdatePermissionsHasConcreteReasonForInvalidUser(t *testing.T) {
	base := t.TempDir()
	sharedServer := filepath.Join(base, "Shared", "1", "server")
	runScript := filepath.Join(sharedServer, ".update", "run.txt")
	steamCmd := filepath.Join(sharedServer, ".steamcmd", "steamcmd.sh")
	lockPath := filepath.Join(sharedServer, ".locks", "shared_update.lock")
	if err := os.MkdirAll(sharedServer, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(runScript), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(steamCmd), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(runScript, []byte("quit\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(steamCmd, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	_, err := validateSharedUpdatePermissions(sharedServer, runScript, steamCmd, lockPath, "user-does-not-exist", "sharedsrv_1")
	if err == nil {
		t.Fatalf("expected error")
	}
	if !strings.Contains(err.Error(), "lock_command_invalid") {
		t.Fatalf("unexpected error: %v", err)
	}
}

func TestValidateSharedUpdatePermissionsExposesLockPaths(t *testing.T) {
	base := t.TempDir()
	sharedServer := filepath.Join(base, "Shared", "1", "server")
	runScript := filepath.Join(sharedServer, ".update", "run.txt")
	steamCmd := filepath.Join(sharedServer, ".steamcmd", "steamcmd.sh")
	lockPath := filepath.Join(sharedServer, ".locks", "shared_update.lock")
	if err := os.MkdirAll(sharedServer, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(runScript), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(steamCmd), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(runScript, []byte("quit\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(steamCmd, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	current, err := user.Current()
	if err != nil {
		t.Fatal(err)
	}
	details, _ := validateSharedUpdatePermissions(sharedServer, runScript, steamCmd, lockPath, current.Username, "sharedsrv_1")
	if details.LockDir != filepath.Join(sharedServer, ".locks") {
		t.Fatalf("lock dir mismatch: %s", details.LockDir)
	}
	if details.LockPath != lockPath {
		t.Fatalf("lock path mismatch: %s", details.LockPath)
	}
	if !details.LockPathMatches {
		t.Fatalf("expected lock path to match lock dir")
	}
}

func TestValidateSharedUpdatePermissionsFailsOnLockPathMismatch(t *testing.T) {
	base := t.TempDir()
	sharedServer := filepath.Join(base, "Shared", "1", "server")
	runScript := filepath.Join(sharedServer, ".update", "run.txt")
	steamCmd := filepath.Join(sharedServer, ".steamcmd", "steamcmd.sh")
	lockPath := filepath.Join(base, "Shared", ".locks", "1.lock")
	if err := os.MkdirAll(sharedServer, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(runScript), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.MkdirAll(filepath.Dir(steamCmd), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(runScript, []byte("quit\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(steamCmd, []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	details, err := validateSharedUpdatePermissions(sharedServer, runScript, steamCmd, lockPath, "user-does-not-exist", "sharedsrv_1")
	if err == nil || !strings.Contains(err.Error(), "lock_path_mismatch") {
		t.Fatalf("expected lock_path_mismatch, got err=%v", err)
	}
	if details.LockPathMatches {
		t.Fatalf("expected lock path mismatch details")
	}
	if !details.EffectiveGroupsCheckSkipped {
		t.Fatalf("expected effective groups check to be skipped on lock path mismatch")
	}
	if details.SharedGroupChecked {
		t.Fatalf("shared group membership should not be marked checked")
	}
	if details.LockTestCommand == "" || strings.Contains(details.LockTestCommand, "<lock_dir>") || strings.Contains(details.LockTestCommand, "<lock_path>") {
		t.Fatalf("expected concrete lock test command, got %q", details.LockTestCommand)
	}
}
