//go:build linux

package metrics

import (
	"fmt"
	"os/exec"
	"strconv"
	"strings"
	"sync"
	"time"
)

type cpuDeltaEntry struct {
	usageNSec uint64
	at        time.Time
}

var (
	instanceCPUCacheMu sync.Mutex
	instanceCPUCache   = map[string]cpuDeltaEntry{}
)

func collectInstanceMetricsPlatform() ([]instanceMetricSample, bool, string) {
	units, err := listRunningInstanceUnits()
	if err != nil {
		return nil, true, "systemd_unavailable"
	}

	now := time.Now().UTC()
	samples := make([]instanceMetricSample, 0, len(units))
	for _, unit := range units {
		instanceID, ok := parseInstanceIDFromUnit(unit)
		if !ok {
			continue
		}
		sample := instanceMetricSample{InstanceID: instanceID, CollectedAt: now}
		props, err := showUnitProperties(unit)
		if err != nil {
			sample.ErrorCode = "systemd_query_failed"
			samples = append(samples, sample)
			continue
		}

		if usage, ok := parseUint64(props["CPUUsageNSec"]); ok {
			if cpu := computeCPUPercent(unit, usage, now); cpu != nil {
				sample.CPUPercent = cpu
			}
		}

		if memoryCurrent, ok := parseInt64(props["MemoryCurrent"]); ok {
			sample.MemCurrentBytes = &memoryCurrent
		}

		if tasksCurrent, ok := parseInt(props["TasksCurrent"]); ok {
			sample.TasksCurrent = &tasksCurrent
		}

		samples = append(samples, sample)
	}

	return samples, true, ""
}

func listRunningInstanceUnits() ([]string, error) {
	out, err := exec.Command(
		"systemctl",
		"list-units",
		"gs-*.service",
		"--type=service",
		"--state=running",
		"--no-legend",
		"--no-pager",
	).Output()
	if err != nil {
		return nil, err
	}

	lines := strings.Split(strings.TrimSpace(string(out)), "\n")
	units := make([]string, 0, len(lines))
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) == 0 {
			continue
		}
		if strings.HasPrefix(fields[0], "gs-") && strings.HasSuffix(fields[0], ".service") {
			units = append(units, fields[0])
		}
	}

	return units, nil
}

func parseInstanceIDFromUnit(unit string) (int, bool) {
	if !strings.HasPrefix(unit, "gs-") || !strings.HasSuffix(unit, ".service") {
		return 0, false
	}
	value := strings.TrimSuffix(strings.TrimPrefix(unit, "gs-"), ".service")
	if value == "" {
		return 0, false
	}
	id, err := strconv.Atoi(value)
	if err != nil || id <= 0 {
		return 0, false
	}
	return id, true
}

func showUnitProperties(unit string) (map[string]string, error) {
	out, err := exec.Command(
		"systemctl",
		"show",
		unit,
		"-p", "CPUUsageNSec",
		"-p", "MemoryCurrent",
		"-p", "TasksCurrent",
	).Output()
	if err != nil {
		return nil, err
	}

	props := map[string]string{}
	for _, line := range strings.Split(string(out), "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		key, value, found := strings.Cut(line, "=")
		if !found {
			continue
		}
		props[key] = value
	}
	if len(props) == 0 {
		return nil, fmt.Errorf("no properties for %s", unit)
	}

	return props, nil
}

func parseUint64(value string) (uint64, bool) {
	if value == "" || value == "[not set]" {
		return 0, false
	}
	parsed, err := strconv.ParseUint(value, 10, 64)
	if err != nil {
		return 0, false
	}
	return parsed, true
}

func parseInt64(value string) (int64, bool) {
	if value == "" || value == "[not set]" {
		return 0, false
	}
	parsed, err := strconv.ParseInt(value, 10, 64)
	if err != nil {
		return 0, false
	}
	if parsed < 0 {
		return 0, false
	}
	return parsed, true
}

func parseInt(value string) (int, bool) {
	if value == "" || value == "[not set]" {
		return 0, false
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return 0, false
	}
	if parsed < 0 {
		return 0, false
	}
	return parsed, true
}

func computeCPUPercent(unit string, usageNSec uint64, now time.Time) *float64 {
	instanceCPUCacheMu.Lock()
	defer instanceCPUCacheMu.Unlock()

	previous, ok := instanceCPUCache[unit]
	instanceCPUCache[unit] = cpuDeltaEntry{usageNSec: usageNSec, at: now}
	if !ok {
		return nil
	}
	if usageNSec < previous.usageNSec {
		return nil
	}
	elapsed := now.Sub(previous.at)
	if elapsed <= 0 {
		return nil
	}
	deltaUsage := usageNSec - previous.usageNSec
	percent := (float64(deltaUsage) / float64(elapsed.Nanoseconds())) * 100
	if percent < 0 {
		return nil
	}
	return &percent
}
