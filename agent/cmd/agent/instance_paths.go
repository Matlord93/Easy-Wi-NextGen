package main

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

func resolveGameserverDirectories(baseDir, installPath, username string) (string, string, error) {
	if !filepath.IsAbs(baseDir) {
		return "", "", fmt.Errorf("gameserver base directory must be absolute")
	}
	if installPath == "" {
		installPath = filepath.Join(baseDir, username)
	}
	if !filepath.IsAbs(installPath) {
		return "", "", fmt.Errorf("install_path must be absolute")
	}
	home, ok := gameserverHome(baseDir, installPath, username)
	if !ok {
		return "", "", fmt.Errorf("install_path must be inside the gameserver account directory")
	}
	if err := validateGameserverDirectoryPath(home, installPath); err != nil {
		return "", "", err
	}
	return home, filepath.Clean(installPath), nil
}

func gameserverHome(baseDir, installPath, username string) (string, bool) {
	if !filepath.IsAbs(baseDir) || !filepath.IsAbs(installPath) || username == "" || username != filepath.Base(username) || username == "." || username == ".." {
		return "", false
	}
	// Previously registered installs under /home remain associated with their original account directory.
	for _, base := range []string{baseDir, "/home"} {
		home := filepath.Join(base, username)
		relative, err := filepath.Rel(home, installPath)
		if err == nil && relative != ".." && !strings.HasPrefix(relative, ".."+string(filepath.Separator)) {
			return home, true
		}
	}
	return "", false
}

func validateGameserverDirectoryPath(home, installDir string) error {
	// The configured base itself can be a storage mount or an operator symlink.
	for path := filepath.Clean(installDir); ; path = filepath.Dir(path) {
		info, err := os.Lstat(path)
		if err == nil && !info.IsDir() {
			return fmt.Errorf("gameserver directory must not be a symlink or regular file: %s", path)
		}
		if err != nil && !os.IsNotExist(err) {
			return fmt.Errorf("inspect gameserver directory: %w", err)
		}
		if path == home {
			return nil
		}
		if path == filepath.Dir(path) {
			return fmt.Errorf("install_path must be inside the gameserver account directory")
		}
	}
}

func prepareGameserverBaseDir(baseDir string) error {
	info, err := os.Stat(baseDir)
	if os.IsNotExist(err) {
		return ensureBaseDir(baseDir)
	}
	if err != nil {
		return fmt.Errorf("inspect gameserver base directory: %w", err)
	}
	if !info.IsDir() {
		return fmt.Errorf("gameserver base path is not a directory: %s", baseDir)
	}
	return nil
}
