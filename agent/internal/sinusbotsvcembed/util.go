package sinusbotsvcembed

import (
	"bytes"
	"fmt"
	"os/exec"
	"strings"
)

func runCommand(name string, args ...string) error {
	cmd := exec.Command(name, args...)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		msg := strings.TrimSpace(stderr.String())
		if msg != "" {
			return fmt.Errorf("%s: %s", err, msg)
		}
		return err
	}
	return nil
}

func systemctlAction(serviceName, action string) error {
	args := []string{action}
	if action != "daemon-reload" {
		if !strings.HasSuffix(serviceName, ".service") {
			serviceName = serviceName + ".service"
		}
		args = append(args, serviceName)
	}
	return runCommand("systemctl", args...)
}

func serviceStatus(serviceName string) string {
	if !strings.HasSuffix(serviceName, ".service") {
		serviceName = serviceName + ".service"
	}
	cmd := exec.Command("systemctl", "is-active", serviceName)
	output, err := cmd.Output()
	if err != nil {
		return "error"
	}
	state := strings.TrimSpace(string(output))
	switch state {
	case "active":
		return "running"
	case "inactive":
		return "stopped"
	default:
		if state == "" {
			return "error"
		}
		return state
	}
}

func systemdUnitTemplate(serviceName, user, workingDir, readWritePath, startCommand, startParams string, cpuLimit, ramLimit int) string {
	command := strings.TrimSpace(startCommand)
	if startParams != "" && !strings.Contains(startCommand, startParams) {
		command = strings.TrimSpace(command + " " + startParams)
	}
	limits := buildSystemdLimits(cpuLimit, ramLimit)
	return fmt.Sprintf(`[Unit]
Description=Easy-Wi Instance %s
After=network.target
StartLimitIntervalSec=60
StartLimitBurst=3

[Service]
Type=simple
User=%s
WorkingDirectory=%s
Environment=HOME=%s
Environment=XDG_CONFIG_HOME=%s/.config
Environment=XDG_DATA_HOME=%s/.local/share
ExecStartPre=/usr/bin/test -d %s
ExecStart=%s
StandardInput=pipe
StandardOutput=journal
StandardError=journal
Restart=on-failure
RestartSec=10
UMask=0027
LimitNOFILE=10240
NoNewPrivileges=true
PrivateTmp=true
PrivateDevices=true
ProtectSystem=strict
ProtectHome=false
ReadWritePaths=%s
%s

[Install]
WantedBy=multi-user.target
`, serviceName, user, workingDir, workingDir, workingDir, workingDir, workingDir, command, readWritePath, limits)
}

func buildSystemdLimits(cpuLimit, ramLimit int) string {
	lines := []string{}
	if cpuLimit > 0 {
		lines = append(lines, fmt.Sprintf("CPUQuota=%d%%", cpuLimit))
	}
	if ramLimit > 0 {
		lines = append(lines,
			fmt.Sprintf("MemoryMax=%dM", ramLimit),
			"MemorySwapMax=0",
		)
	}
	return strings.Join(lines, "\n")
}
