//go:build !linux

package system

import "os"

func preserveAgentUpdateOwnership(_ string, _ *os.File) error { return nil }

func EnsureInstalledAgentExecutable(_ string) error { return nil }
