//go:build linux

package system

import (
	"fmt"
	"os"
	"path/filepath"

	"golang.org/x/sys/unix"
)

const defaultAgentLockPath = "/run/easywi-agent/easywi-agent.lock"

type ProcessLock struct {
	file *os.File
	path string
}

func AcquireAgentProcessLock() (*ProcessLock, error) {
	path := os.Getenv("EASYWI_AGENT_LOCK_PATH")
	if path == "" {
		path = defaultAgentLockPath
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return nil, fmt.Errorf("create lock directory: %w", err)
	}
	file, err := os.OpenFile(path, os.O_CREATE|os.O_RDWR, 0o644)
	if err != nil {
		return nil, fmt.Errorf("open lock file %s: %w", path, err)
	}
	if err := unix.Flock(int(file.Fd()), unix.LOCK_EX|unix.LOCK_NB); err != nil {
		_ = file.Close()
		if err == unix.EWOULDBLOCK || err == unix.EAGAIN {
			return nil, fmt.Errorf("another easywi-agent process is already running (lock %s is held)", path)
		}
		return nil, fmt.Errorf("lock %s: %w", path, err)
	}
	if err := file.Truncate(0); err != nil {
		_ = unix.Flock(int(file.Fd()), unix.LOCK_UN)
		_ = file.Close()
		return nil, fmt.Errorf("truncate lock file: %w", err)
	}
	if _, err := fmt.Fprintf(file, "%d\n", os.Getpid()); err != nil {
		_ = unix.Flock(int(file.Fd()), unix.LOCK_UN)
		_ = file.Close()
		return nil, fmt.Errorf("write lock file: %w", err)
	}
	return &ProcessLock{file: file, path: path}, nil
}

func (l *ProcessLock) Release() error {
	if l == nil || l.file == nil {
		return nil
	}
	err := unix.Flock(int(l.file.Fd()), unix.LOCK_UN)
	if closeErr := l.file.Close(); err == nil && closeErr != nil {
		err = closeErr
	}
	l.file = nil
	return err
}
