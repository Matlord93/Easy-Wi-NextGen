//go:build !linux

package system

func CleanupStaleAgentProcesses(service string) error { return nil }
