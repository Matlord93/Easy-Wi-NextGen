package configrender

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

type Writer struct {
	stagingRoot string
}

type ActivationArtifact struct {
	ActivatedFiles []string
	BackupByFile   map[string]string
}

func NewWriter(stagingRoot string) *Writer {
	if strings.TrimSpace(stagingRoot) == "" {
		stagingRoot = "/etc"
	}
	return &Writer{stagingRoot: stagingRoot}
}

func (w *Writer) Stage(bundle RenderBundle) ([]FileSpec, error) {
	staged := make([]FileSpec, 0, len(bundle.Files))
	for _, file := range bundle.Files {
		stagePath := w.stagePath(file.Path)
		if err := os.MkdirAll(filepath.Dir(stagePath), 0o750); err != nil {
			return nil, &ApplyError{Class: ErrClassWrite, Service: file.Service, Code: "mkdir_staging", Message: fmt.Sprintf("create staging dir for %s: %v", stagePath, err)}
		}
		if err := os.WriteFile(stagePath, file.Body, 0o640); err != nil {
			return nil, &ApplyError{Class: ErrClassWrite, Service: file.Service, Code: "write_staging", Message: fmt.Sprintf("write staging file %s: %v", stagePath, err)}
		}
		staged = append(staged, FileSpec{Service: file.Service, Path: stagePath, Body: file.Body})
	}
	return staged, nil
}

func (w *Writer) BackupAndActivate(bundle RenderBundle) (ActivationArtifact, error) {
	artifact := ActivationArtifact{ActivatedFiles: make([]string, 0, len(bundle.Files)), BackupByFile: map[string]string{}}
	timestamp := time.Now().UTC().Format("20060102T150405Z")
	for _, file := range bundle.Files {
		stagedPath := w.stagePath(file.Path)
		if err := os.MkdirAll(filepath.Dir(file.Path), 0o750); err != nil {
			return artifact, &ApplyError{Class: ErrClassActivate, Service: file.Service, Code: "mkdir_active", Message: err.Error()}
		}
		if _, err := os.Stat(file.Path); err == nil {
			bakPath := fmt.Sprintf("%s.bak.%s", file.Path, timestamp)
			if err = copyFile(file.Path, bakPath); err != nil {
				return artifact, &ApplyError{Class: ErrClassActivate, Service: file.Service, Code: "backup_failed", Message: err.Error()}
			}
			artifact.BackupByFile[file.Path] = bakPath
		}
		if err := os.Rename(stagedPath, file.Path); err != nil {
			return artifact, &ApplyError{Class: ErrClassActivate, Service: file.Service, Code: "atomic_replace_failed", Message: err.Error()}
		}
		artifact.ActivatedFiles = append(artifact.ActivatedFiles, file.Path)
	}
	return artifact, nil
}

func (w *Writer) Rollback(artifact ActivationArtifact) error {
	for _, target := range artifact.ActivatedFiles {
		backup, ok := artifact.BackupByFile[target]
		if !ok || backup == "" {
			continue
		}
		if err := copyFile(backup, target); err != nil {
			return &ApplyError{Class: ErrClassRollback, Code: "rollback_restore_failed", Message: err.Error()}
		}
	}
	return nil
}

func (w *Writer) stagePath(activePath string) string {
	clean := strings.TrimPrefix(activePath, "/")
	return filepath.Join(w.stagingRoot, "staging", clean)
}

func copyFile(src, dst string) error {
	content, err := os.ReadFile(src)
	if err != nil {
		return err
	}
	return os.WriteFile(dst, content, 0o640)
}
