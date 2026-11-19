package main

import (
	"fmt"
	"strconv"
)

// Apply persistent systemd resource properties before publishing new start
// parameters. In particular, an existing unit must have JVM native headroom
// before its start script can receive a heap equal to the purchased RAM.
func syncInstanceRuntimeLimits(serviceName string, payload map[string]any) error {
	properties := []string{}
	if raw := payloadValue(payload, "cpu_limit"); raw != "" {
		cpu, err := parsePositiveInt(raw, "cpu_limit")
		if err != nil {
			return err
		}
		properties = append(properties, "CPUQuota="+strconv.Itoa(cpu)+"%")
	}
	if raw := payloadValue(payload, "ram_limit"); raw != "" {
		ram, err := parsePositiveInt(raw, "ram_limit")
		if err != nil {
			return err
		}
		properties = append(properties, "MemoryMax="+strconv.Itoa(ram)+"M", "MemorySwapMax=0")
	}
	if len(properties) == 0 {
		return nil
	}
	if serviceName == "" {
		return fmt.Errorf("service_name is required to update resource limits")
	}
	// Without --runtime, systemd persists the override for subsequent host boots.
	args := append([]string{"set-property", "--", serviceName}, properties...)
	if err := runCommand("systemctl", args...); err != nil {
		return fmt.Errorf("update service resource limits before start: %w", err)
	}
	return nil
}
