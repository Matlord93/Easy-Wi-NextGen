//go:build linux

package metrics

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
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

func processCount() (int, error) {
	entries, err := os.ReadDir("/proc")
	if err != nil {
		return 0, err
	}

	count := 0
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		if _, err := strconv.Atoi(entry.Name()); err == nil {
			count++
		}
	}
	return count, nil
}

func temperatureCelsius() (float64, error) {
	paths, err := filepath.Glob("/sys/class/thermal/thermal_zone*/temp")
	if err != nil {
		return 0, err
	}
	for _, path := range paths {
		raw, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		value := strings.TrimSpace(string(raw))
		if value == "" {
			continue
		}
		parsed, err := strconv.ParseFloat(value, 64)
		if err != nil {
			continue
		}
		if parsed > 1000 {
			return parsed / 1000, nil
		}
		return parsed, nil
	}
	return 0, fmt.Errorf("temperature unavailable")
}
