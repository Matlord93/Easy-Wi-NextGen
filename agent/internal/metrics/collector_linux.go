//go:build linux

package metrics

import (
	"bufio"
	"fmt"
	"os"
	"strconv"
	"strings"
	"syscall"
)

func diskUsage(path string) (uint64, uint64, float64, error) {
	var stat syscall.Statfs_t
	if err := syscall.Statfs(path, &stat); err != nil {
		return 0, 0, 0, err
	}

	total := stat.Blocks * uint64(stat.Bsize)
	free := stat.Bfree * uint64(stat.Bsize)
	if total == 0 {
		return 0, 0, 0, fmt.Errorf("invalid disk total")
	}

	usedPercent := float64(total-free) / float64(total) * 100
	return total, free, usedPercent, nil
}

func networkCounters() (uint64, uint64, error) {
	file, err := os.Open("/proc/net/dev")
	if err != nil {
		return 0, 0, err
	}
	defer file.Close()

	var sent uint64
	var recv uint64

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if strings.HasPrefix(line, "Inter-") || strings.HasPrefix(line, "face") || line == "" {
			continue
		}
		parts := strings.Split(line, ":")
		if len(parts) != 2 {
			continue
		}
		fields := strings.Fields(strings.TrimSpace(parts[1]))
		if len(fields) < 16 {
			continue
		}
		recvValue, err := strconv.ParseUint(fields[0], 10, 64)
		if err == nil {
			recv += recvValue
		}
		sentValue, err := strconv.ParseUint(fields[8], 10, 64)
		if err == nil {
			sent += sentValue
		}
	}

	return sent, recv, nil
}
