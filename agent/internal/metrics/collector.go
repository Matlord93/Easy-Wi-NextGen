package metrics

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"time"
)

const processSampleLimit = 10

// Collect gathers a snapshot of system metrics suitable for heartbeat payloads.
func Collect() map[string]any {
	snapshot := map[string]any{
		"collected_at": time.Now().UTC().Format(time.RFC3339),
	}

	if cpuPercent, err := cpuUsagePercent(); err == nil {
		snapshot["cpu"] = map[string]any{
			"percent": cpuPercent,
		}
	}

	if total, available, usedPercent, err := memoryUsage(); err == nil {
		snapshot["memory"] = map[string]any{
			"total":     total,
			"available": available,
			"used":      total - available,
			"percent":   usedPercent,
		}
	}

	if total, free, usedPercent, err := diskUsage("/"); err == nil {
		snapshot["disk"] = map[string]any{
			"total":   total,
			"free":    free,
			"used":    total - free,
			"percent": usedPercent,
		}
	}

	if sent, received, err := networkCounters(); err == nil {
		snapshot["net"] = map[string]any{
			"bytes_sent": sent,
			"bytes_recv": received,
		}
	}

	if processes, err := processSample(); err == nil {
		snapshot["processes"] = processes
	}

	return snapshot
}

func cpuUsagePercent() (float64, error) {
	file, err := os.Open("/proc/stat")
	if err != nil {
		return 0, err
	}
	defer file.Close()

	scanner := bufio.NewScanner(file)
	if !scanner.Scan() {
		return 0, fmt.Errorf("missing cpu line")
	}

	fields := strings.Fields(scanner.Text())
	if len(fields) < 5 {
		return 0, fmt.Errorf("invalid cpu line")
	}

	var total uint64
	for i := 1; i < len(fields); i++ {
		value, err := strconv.ParseUint(fields[i], 10, 64)
		if err != nil {
			return 0, err
		}
		total += value
	}

	idle, err := strconv.ParseUint(fields[4], 10, 64)
	if err != nil {
		return 0, err
	}
	if total == 0 {
		return 0, fmt.Errorf("invalid cpu total")
	}

	usage := float64(total-idle) / float64(total) * 100
	return usage, nil
}

func memoryUsage() (uint64, uint64, float64, error) {
	file, err := os.Open("/proc/meminfo")
	if err != nil {
		return 0, 0, 0, err
	}
	defer file.Close()

	var total uint64
	var available uint64

	scanner := bufio.NewScanner(file)
	for scanner.Scan() {
		line := scanner.Text()
		if strings.HasPrefix(line, "MemTotal:") {
			value, err := parseMeminfoValue(line)
			if err == nil {
				total = value
			}
		}
		if strings.HasPrefix(line, "MemAvailable:") {
			value, err := parseMeminfoValue(line)
			if err == nil {
				available = value
			}
		}
	}

	if total == 0 {
		return 0, 0, 0, fmt.Errorf("missing meminfo")
	}

	usedPercent := float64(total-available) / float64(total) * 100
	return total, available, usedPercent, nil
}

func parseMeminfoValue(line string) (uint64, error) {
	fields := strings.Fields(line)
	if len(fields) < 2 {
		return 0, fmt.Errorf("invalid meminfo line")
	}

	value, err := strconv.ParseUint(fields[1], 10, 64)
	if err != nil {
		return 0, err
	}

	return value * 1024, nil
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

func processSample() ([]map[string]any, error) {
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return nil, err
	}

	processes := make([]map[string]any, 0, processSampleLimit)
	for _, entry := range entries {
		if len(processes) >= processSampleLimit {
			break
		}
		if !entry.IsDir() {
			continue
		}
		pid, err := strconv.Atoi(entry.Name())
		if err != nil {
			continue
		}

		name, _ := os.ReadFile(filepath.Join("/proc", entry.Name(), "comm"))
		rss, vms, _ := readProcessMemory(entry.Name())

		processes = append(processes, map[string]any{
			"pid":  pid,
			"name": strings.TrimSpace(string(name)),
			"rss":  rss,
			"vms":  vms,
		})
	}

	return processes, nil
}

func readProcessMemory(pid string) (uint64, uint64, error) {
	data, err := os.ReadFile(filepath.Join("/proc", pid, "statm"))
	if err != nil {
		return 0, 0, err
	}

	fields := strings.Fields(string(data))
	if len(fields) < 2 {
		return 0, 0, fmt.Errorf("invalid statm")
	}

	pageSize := uint64(os.Getpagesize())
	vms, err := strconv.ParseUint(fields[0], 10, 64)
	if err != nil {
		return 0, 0, err
	}
	rss, err := strconv.ParseUint(fields[1], 10, 64)
	if err != nil {
		return 0, 0, err
	}

	return rss * pageSize, vms * pageSize, nil
}
