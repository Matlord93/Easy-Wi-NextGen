//go:build windows

package metrics

import (
	"fmt"
	"os"
	"strings"

	"github.com/shirou/gopsutil/v4/cpu"
	"github.com/shirou/gopsutil/v4/disk"
	"github.com/shirou/gopsutil/v4/mem"
	gonet "github.com/shirou/gopsutil/v4/net"
	"github.com/shirou/gopsutil/v4/process"
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
	return 0, fmt.Errorf("temperature not supported on windows")
}
