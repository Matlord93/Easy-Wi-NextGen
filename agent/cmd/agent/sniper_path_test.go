package main

import (
	"os"
	"path/filepath"
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
	must(os.MkdirAll(filepath.Join(shared, "game/core"), 0o755))
	must(os.MkdirAll(filepath.Join(shared, "game/csgo/cfg"), 0o755))
	must(os.MkdirAll(filepath.Join(shared, "game/csgo_community_addons"), 0o755))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/pak01_000.vpk"), []byte("x"), 0o644))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/pak01_dir.vpk"), []byte("x"), 0o644))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/cfg/server.cfg"), []byte("cfg"), 0o644))
	must(os.WriteFile(filepath.Join(shared, "game/csgo/gameinfo.gi"), []byte("gi"), 0o644))
	must(os.MkdirAll(gameDir, 0o755))

	specs := []sharedPathSpec{{Source: "game/core", Target: "core", Mode: "symlink", ReadOnly: true}, {Source: "game/csgo", Target: "csgo", Mode: "shared_tree", ReadOnly: true, Exclude: []string{"cfg", "gameinfo.gi"}}, {Source: "game/csgo_community_addons", Target: "csgo_community_addons", Mode: "symlink", ReadOnly: true}}
	must(copyNonSharedFromServer(shared, gameDir, specs))
	must(applySharedPaths(gameDir, shared, specs))

	if info, err := os.Lstat(filepath.Join(gameDir, "core")); err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("core symlink missing")
	}
	if info, err := os.Stat(filepath.Join(gameDir, "csgo")); err != nil || !info.IsDir() {
		t.Fatalf("csgo dir missing")
	}
	if info, err := os.Lstat(filepath.Join(gameDir, "csgo/pak01_000.vpk")); err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("vpk symlink missing")
	}
	if _, err := os.Stat(filepath.Join(gameDir, "csgo/cfg/server.cfg")); err != nil {
		t.Fatalf("local cfg missing: %v", err)
	}
	if _, err := os.Stat(filepath.Join(gameDir, "csgo/gameinfo.gi")); err != nil {
		t.Fatalf("local gameinfo missing: %v", err)
	}
	if info, err := os.Lstat(filepath.Join(gameDir, "csgo_community_addons")); err != nil || info.Mode()&os.ModeSymlink == 0 {
		t.Fatalf("addons symlink missing")
	}
	if _, err := os.Stat(filepath.Join(base, "home", "gs225", "core")); !os.IsNotExist(err) {
		t.Fatalf("unexpected /home/gs225/core")
	}
	if _, err := os.Stat(filepath.Join(base, "home", "gs225", "csgo")); !os.IsNotExist(err) {
		t.Fatalf("unexpected /home/gs225/csgo")
	}
}
