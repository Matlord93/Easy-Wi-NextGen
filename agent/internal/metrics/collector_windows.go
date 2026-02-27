//go:build windows

package metrics

import (
	"fmt"
	"os"
	"os/exec"
	"sort"
	"strconv"
	"strings"

	"github.com/shirou/gopsutil/v4/cpu"
	"github.com/shirou/gopsutil/v4/disk"
	"github.com/shirou/gopsutil/v4/host"
	"github.com/shirou/gopsutil/v4/mem"
	gonet "github.com/shirou/gopsutil/v4/net"
	"github.com/shirou/gopsutil/v4/process"
	"github.com/shirou/gopsutil/v4/sensors"
)

func diskUsage(path string) (uint64, uint64, float64, error) {
	usagePath := path
	if usagePath == "" || usagePath == "/" || strings.HasPrefix(usagePath, "/") {
		drive := strings.TrimSpace(os.Getenv("SystemDrive"))
		if drive == "" {
			drive = "C:"
		}
		usagePath = drive + `\`
	}

	usage, err := disk.Usage(usagePath)
	if err != nil {
		return 0, 0, 0, err
	}

	return usage.Total, usage.Free, usage.UsedPercent, nil
}

func networkCounters() (uint64, uint64, error) {
	counters, err := gonet.IOCounters(true)
	if err != nil {
		return 0, 0, err
	}

	var sent uint64
	var recv uint64
	for _, counter := range counters {
		sent += counter.BytesSent
		recv += counter.BytesRecv
	}

	return sent, recv, nil
}

func cpuUsagePercent() (float64, error) {
	values, err := cpu.Percent(0, false)
	if err != nil {
		return 0, err
	}
	if len(values) == 0 {
		return 0, fmt.Errorf("cpu percent unavailable")
	}
	return values[0], nil
}

func memoryUsage() (uint64, uint64, float64, error) {
	stat, err := mem.VirtualMemory()
	if err != nil {
		return 0, 0, 0, err
	}

	return stat.Total, stat.Available, stat.UsedPercent, nil
}

func processSample() ([]map[string]any, error) {
	procs, err := process.Processes()
	if err != nil {
		return nil, err
	}
	type procInfo struct {
		pid  int32
		name string
		rss  uint64
		vms  uint64
	}
	all := make([]procInfo, 0, len(procs))
	for _, proc := range procs {
		name, _ := proc.Name()
		mem, _ := proc.MemoryInfo()
		if mem == nil {
			continue
		}
		all = append(all, procInfo{pid: proc.Pid, name: name, rss: mem.RSS, vms: mem.VMS})
	}
	sort.Slice(all, func(i, j int) bool { return all[i].rss > all[j].rss })
	limit := processSampleLimit
	if len(all) < limit {
		limit = len(all)
	}
	out := make([]map[string]any, 0, limit)
	for _, proc := range all[:limit] {
		out = append(out, map[string]any{"pid": proc.pid, "name": proc.name, "rss": proc.rss, "vms": proc.vms})
	}
	return out, nil
}

func processCount() (int, error) {
	pids, err := process.Pids()
	if err != nil {
		return 0, err
	}
	return len(pids), nil
}

func temperatureCelsius() (float64, error) {
	if values, err := sensors.SensorsTemperatures(); err == nil {
		for _, sensor := range values {
			if sensor.Temperature > -50 && sensor.Temperature < 150 {
				return sensor.Temperature, nil
			}
		}
	}

	for _, shell := range []string{"powershell", "pwsh"} {
		path, err := exec.LookPath(shell)
		if err != nil {
			continue
		}
		cmd := exec.Command(path, "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-Command", `(Get-CimInstance -Namespace root/wmi -ClassName MSAcpi_ThermalZoneTemperature -ErrorAction SilentlyContinue | Select-Object -ExpandProperty CurrentTemperature)`)
		output, err := cmd.CombinedOutput()
		if err != nil {
			continue
		}
		if parsed, ok := parseWindowsWMITemperature(strings.TrimSpace(string(output))); ok {
			return parsed, nil
		}
	}

	return 0, fmt.Errorf("temperature unavailable on windows")
}

func parseWindowsWMITemperature(raw string) (float64, bool) {
	if raw == "" {
		return 0, false
	}

	for _, line := range strings.Split(raw, "\n") {
		value := strings.TrimSpace(line)
		if value == "" {
			continue
		}
		tenthsKelvin, err := strconv.ParseFloat(value, 64)
		if err != nil {
			continue
		}
		celsius := (tenthsKelvin / 10) - 273.15
		if celsius > -50 && celsius < 150 {
			return celsius, true
		}
	}

	return 0, false
}

func loadAverage() (map[string]float64, error) {
	return nil, fmt.Errorf("load average unavailable on windows")
}

func uptimeSeconds() (int64, error) {
	seconds, err := host.Uptime()
	if err != nil {
		return 0, err
	}
	return int64(seconds), nil
}
