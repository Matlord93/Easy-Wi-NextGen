package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
)

var minecraftBuildMu sync.Mutex

func spigotCachePath(payload map[string]any) string {
	root := minecraftArtifactCacheRoot()
	revision := payloadValue(payload, "minecraft_source_revision")
	if revision != "" {
		revision = ":" + revision
	}
	key := sha256.Sum256([]byte(payloadValue(payload, "minecraft_version") + ":" + payloadValue(payload, "minecraft_sha256") + ":" + payloadValue(payload, "minecraft_java_version") + ":" + runtime.GOOS + ":" + runtime.GOARCH + revision))
	return filepath.Join(root, "spigot-"+hex.EncodeToString(key[:])+".jar")
}

func restoreSpigotCache(cache, target string, payload map[string]any) bool {
	expected, err := os.ReadFile(cache + ".sha256")
	if err != nil {
		return false
	}
	actual, err := fileSHA256(cache)
	if err != nil || strings.TrimSpace(string(expected)) != actual {
		return false
	}
	if _, err := minecraftJarRequiredJava(cache); err != nil {
		return false
	}
	return copyFile(cache, target, 0644) == nil
}

func publishSpigotCache(cache, source string) error {
	root := filepath.Dir(cache)
	if err := os.MkdirAll(root, 0755); err != nil {
		return err
	}
	if err := os.Chmod(root, 0755); err != nil {
		return err
	}
	f, err := os.CreateTemp(root, ".spigot-")
	if err != nil {
		return err
	}
	path := f.Name()
	if err := f.Close(); err != nil {
		return err
	}
	defer func() { _ = os.Remove(path) }()
	if err := copyFile(source, path, 0644); err != nil {
		return err
	}
	if err := os.Chmod(path, 0644); err != nil {
		return err
	}
	sum, err := fileSHA256(path)
	if err != nil {
		return err
	}
	if err := os.Rename(path, cache); err != nil {
		return err
	}
	return os.WriteFile(cache+".sha256", []byte(sum+"\n"), 0644)
}

func runMinecraftBuildTools(ctx context.Context, user, home, command string) (string, error) {
	uid, _, err := lookupIDs(user, user)
	if err != nil {
		return "", err
	}
	var cmd *exec.Cmd
	if os.Geteuid() == uid {
		cmd = exec.CommandContext(ctx, "/bin/sh", "-c", command)
	} else {
		base := buildRunUserCommand(user, command)
		cmd = exec.CommandContext(ctx, base.Path, base.Args[1:]...)
	}
	cmd.Env = append(os.Environ(), "MAVEN_OPTS="+os.Getenv("MAVEN_OPTS")+" -Dmaven.repo.local="+filepath.Join(home, "m2")+" -Duser.home="+home)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return string(output), fmt.Errorf("buildTools command: %w", err)
	}
	return string(output), nil
}

func minecraftArtifactCacheRoot() string {
	root := strings.TrimSpace(os.Getenv("EASYWI_MINECRAFT_ARTIFACT_CACHE_DIR"))
	if root == "" {
		root = "/var/cache/easywi-minecraft-artifacts"
	}
	return root
}

func prepareSpigotWorkspace() (string, string, string, error) {
	builder := strings.TrimSpace(os.Getenv("EASYWI_MINECRAFT_BUILD_USER"))
	if builder == "" {
		builder = "easywi-minecraft-build"
	}
	home := filepath.Join(minecraftArtifactCacheRoot(), "build-home")
	if err := ensureGroup(builder); err != nil {
		return "", "", "", err
	}
	if err := ensureUser(builder, builder, home); err != nil {
		return "", "", "", err
	}
	uid, gid, err := lookupIDs(builder, builder)
	if err != nil {
		return "", "", "", err
	}
	for _, path := range []string{home, filepath.Join(home, "work"), filepath.Join(home, "m2")} {
		if err := os.MkdirAll(path, 0700); err != nil {
			return "", "", "", err
		}
		if err := os.Chown(path, uid, gid); err != nil {
			return "", "", "", err
		}
		if err := os.Chmod(path, 0700); err != nil {
			return "", "", "", err
		}
	}
	return builder, home, filepath.Join(home, "work"), nil
}
