//go:build !linux

package system

type ProcessLock struct{}

func AcquireAgentProcessLock() (*ProcessLock, error) { return &ProcessLock{}, nil }
func (l *ProcessLock) Release() error                { return nil }
