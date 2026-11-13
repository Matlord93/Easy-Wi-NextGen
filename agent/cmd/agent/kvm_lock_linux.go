//go:build linux

package main

import (
	"context"
	"errors"
	"golang.org/x/sys/unix"
	"os"
	"time"
)

func lockKVM(ctx context.Context, path string) (func(), error) {
	// O_NOFOLLOW also protects the lock against replacement by a symlink.
	fd, err := unix.Open(path, unix.O_CREAT|unix.O_RDWR|unix.O_NOFOLLOW|unix.O_CLOEXEC, 0600)
	if err != nil {
		return nil, err
	}
	f := os.NewFile(uintptr(fd), path)
	// Do not keep queued agent goroutines waiting behind a long image import.
	deadline := time.NewTimer(2 * time.Second)
	defer deadline.Stop()
	tick := time.NewTicker(50 * time.Millisecond)
	defer tick.Stop()
	for {
		err = unix.Flock(fd, unix.LOCK_EX|unix.LOCK_NB)
		if err == nil {
			return func() { _ = unix.Flock(fd, unix.LOCK_UN); _ = f.Close() }, nil
		}
		if !errors.Is(err, unix.EWOULDBLOCK) {
			_ = f.Close()
			return nil, err
		}
		select {
		case <-ctx.Done():
			_ = f.Close()
			return nil, ctx.Err()
		case <-deadline.C:
			_ = f.Close()
			return nil, errors.New("KVM host busy; retry after the running operation finishes")
		case <-tick.C:
		}
	}
}
