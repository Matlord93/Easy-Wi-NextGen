package main

import (
	"fmt"
	"os"
	"path/filepath"
)

// Only shared ancestors are repaired here. systemd owns each private instance
// directory through RuntimeDirectory; broad recursive ownership changes would
// break isolation between game users.
func ensureConsoleRuntimeParents(root string) error {
	for _, dir := range []string{root, filepath.Join(root, "instances")} {
		if err := os.MkdirAll(dir, 0o751); err != nil {
			return fmt.Errorf("create console runtime parent %s: %w", dir, err)
		}
		info, err := os.Lstat(dir)
		if err != nil {
			return err
		}
		if !info.IsDir() {
			return fmt.Errorf("console runtime parent is not a directory: %s", dir)
		}
		if err := os.Chmod(dir, info.Mode().Perm()|0o001); err != nil {
			return fmt.Errorf("allow console runtime traversal %s: %w", dir, err)
		}
	}
	return nil
}
