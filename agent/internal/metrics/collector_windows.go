//go:build windows

package metrics

import "fmt"

func diskUsage(path string) (uint64, uint64, float64, error) {
	return 0, 0, 0, fmt.Errorf("disk usage not supported on windows")
}

func networkCounters() (uint64, uint64, error) {
	return 0, 0, fmt.Errorf("network counters not supported on windows")
}
