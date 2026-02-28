package storage

import (
	"fmt"
	"os"
	"path/filepath"
	"time"
)

type AtomicWriter struct{}

func (AtomicWriter) WriteWithBackup(path string, content []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("mkdir: %w", err)
	}

	if _, err := os.Stat(path); err == nil {
		backupPath := fmt.Sprintf("%s.bak.%d", path, time.Now().UTC().Unix())
		if err := copyFile(path, backupPath); err != nil {
			return fmt.Errorf("backup: %w", err)
		}
	}

	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, content, perm); err != nil {
		return fmt.Errorf("write tmp: %w", err)
	}
	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("rename: %w", err)
	}
	return nil
}

func copyFile(src, dst string) error {
	data, err := os.ReadFile(src)
	if err != nil {
		return err
	}
	return os.WriteFile(dst, data, 0o640)
}
