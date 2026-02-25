//go:build windows

package metrics

import (
	"fmt"
	"os"
	"os/exec"
	"strconv"
	"strings"

	"github.com/shirou/gopsutil/v4/cpu"
	"github.com/shirou/gopsutil/v4/disk"
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
	return nil, fmt.Errorf("process sample not supported on windows")
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
