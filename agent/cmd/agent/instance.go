package main

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	instanceDirMode  = 0o750
	instanceFileMode = 0o640
)

func handleInstanceCreate(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	startParams := payloadValue(job.Payload, "start_params")
	requiredPortsRaw := payloadValue(job.Payload, "required_ports")
	portBlockPortsRaw := payloadValue(job.Payload, "port_block_ports", "ports")
	baseDir := payloadValue(job.Payload, "base_dir")
	startCommand := payloadValue(job.Payload, "exec_start", "start_command")
	serviceName := payloadValue(job.Payload, "service_name")

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
		{key: "start_params", value: startParams},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if baseDir == "" {
		baseDir = "/srv/gameservers"
	}
	if serviceName == "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}
	if startCommand == "" {
		startCommand = startParams
	}

	osUsername := buildInstanceUsername(customerID, instanceID)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, baseDir); err != nil {
		return failureResult(job.ID, err)
	}

	instanceDir := filepath.Join(baseDir, osUsername)
	dataDir := filepath.Join(instanceDir, "data")
	logsDir := filepath.Join(instanceDir, "logs")
	configDir := filepath.Join(instanceDir, "config")

	for _, dir := range []string{instanceDir, dataDir, logsDir, configDir} {
		if err := ensureInstanceDir(dir); err != nil {
			return failureResult(job.ID, err)
		}
	}

	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return failureResult(job.ID, err)
	}
	for _, dir := range []string{instanceDir, dataDir, logsDir, configDir} {
		if err := os.Chown(dir, uid, gid); err != nil {
			return failureResult(job.ID, fmt.Errorf("chown %s: %w", dir, err))
		}
		if err := os.Chmod(dir, instanceDirMode); err != nil {
			return failureResult(job.ID, fmt.Errorf("chmod %s: %w", dir, err))
		}
	}

	allocatedPorts, err := parsePorts(portBlockPortsRaw)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if len(allocatedPorts) == 0 {
		allocatedPorts, err = allocateCustomerPorts(customerID)
		if err != nil {
			return failureResult(job.ID, err)
		}
	}

	if err := openPorts(allocatedPorts); err != nil {
		return failureResult(job.ID, err)
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, startCommand, startParams)
	if err := os.WriteFile(unitPath, []byte(unitContent), instanceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write systemd unit: %w", err))
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return failureResult(job.ID, err)
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"os_username":     osUsername,
			"instance_dir":    instanceDir,
			"data_dir":        dataDir,
			"logs_dir":        logsDir,
			"config_dir":      configDir,
			"service_name":    serviceName,
			"allocated_ports": strings.Join(intSliceToStrings(allocatedPorts), ","),
			"required_ports":  requiredPortsRaw,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceStart(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" && instanceID != "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
	})
	if len(missing) > 0 && serviceName == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := runCommand("systemctl", "start", serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceStop(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" && instanceID != "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
	})
	if len(missing) > 0 && serviceName == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := runCommand("systemctl", "stop", serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceRestart(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" && instanceID != "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
	})
	if len(missing) > 0 && serviceName == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := runCommand("systemctl", "restart", serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceReinstall(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	baseDir := payloadValue(job.Payload, "base_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	startParams := payloadValue(job.Payload, "start_params")
	startCommand := payloadValue(job.Payload, "exec_start", "start_command")
	installCommand := payloadValue(job.Payload, "install_command")
	backupOld := strings.EqualFold(payloadValue(job.Payload, "backup_old", "backup"), "true")

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if baseDir == "" {
		baseDir = "/srv/gameservers"
	}
	if serviceName == "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}
	if startCommand == "" {
		startCommand = startParams
	}

	osUsername := buildInstanceUsername(customerID, instanceID)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, baseDir); err != nil {
		return failureResult(job.ID, err)
	}

	instanceDir := filepath.Join(baseDir, osUsername)
	dataDir := filepath.Join(instanceDir, "data")
	logsDir := filepath.Join(instanceDir, "logs")
	configDir := filepath.Join(instanceDir, "config")
	var backupPath string
	var configStash string
	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return failureResult(job.ID, err)
	}

	_ = runCommand("systemctl", "stop", serviceName)

	if _, err := os.Stat(instanceDir); err == nil {
		if backupOld {
			backupPath = fmt.Sprintf("%s.backup.%d", instanceDir, time.Now().UTC().Unix())
			if err := os.Rename(instanceDir, backupPath); err != nil {
				return failureResult(job.ID, fmt.Errorf("backup instance dir: %w", err))
			}
		} else {
			configPath := filepath.Join(instanceDir, "config")
			if _, err := os.Stat(configPath); err == nil {
				stash, err := os.MkdirTemp("", "easywi-config-")
				if err != nil {
					return failureResult(job.ID, fmt.Errorf("create config stash: %w", err))
				}
				if err := copyDir(configPath, stash); err != nil {
					return failureResult(job.ID, err)
				}
				configStash = stash
			}
			if err := os.RemoveAll(instanceDir); err != nil {
				return failureResult(job.ID, fmt.Errorf("remove instance dir: %w", err))
			}
		}
	}

	for _, dir := range []string{instanceDir, dataDir, logsDir, configDir} {
		if err := ensureInstanceDir(dir); err != nil {
			return failureResult(job.ID, err)
		}
	}

	if backupPath != "" {
		if err := copyDir(filepath.Join(backupPath, "config"), configDir); err != nil {
			return failureResult(job.ID, err)
		}
	}
	if configStash != "" {
		if err := copyDir(configStash, configDir); err != nil {
			return failureResult(job.ID, err)
		}
		if err := os.RemoveAll(configStash); err != nil {
			return failureResult(job.ID, fmt.Errorf("remove config stash: %w", err))
		}
	}
	for _, dir := range []string{instanceDir, dataDir, logsDir, configDir} {
		if err := os.Chown(dir, uid, gid); err != nil {
			return failureResult(job.ID, fmt.Errorf("chown %s: %w", dir, err))
		}
		if err := os.Chmod(dir, instanceDirMode); err != nil {
			return failureResult(job.ID, fmt.Errorf("chmod %s: %w", dir, err))
		}
	}
	if err := chownRecursive(configDir, uid, gid); err != nil {
		return failureResult(job.ID, err)
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, startCommand, startParams)
	if err := os.WriteFile(unitPath, []byte(unitContent), instanceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write systemd unit: %w", err))
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return failureResult(job.ID, err)
	}

	if installCommand != "" {
		installWithDir := fmt.Sprintf("cd %s && %s", instanceDir, installCommand)
		if err := runCommand("su", "-s", "/bin/sh", "-c", installWithDir, osUsername); err != nil {
			return failureResult(job.ID, fmt.Errorf("install command failed: %w", err))
		}
	}

	if err := runCommand("systemctl", "start", serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName
	diagnostics["instance_dir"] = instanceDir
	diagnostics["data_dir"] = dataDir
	diagnostics["logs_dir"] = logsDir
	diagnostics["config_dir"] = configDir
	diagnostics["backup_old"] = strconv.FormatBool(backupOld)

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

func buildInstanceUsername(customerID, instanceID string) string {
	sanitizedInstance := sanitizeIdentifier(instanceID)
	if len(sanitizedInstance) > 8 {
		sanitizedInstance = sanitizedInstance[:8]
	}
	return fmt.Sprintf("gs%s%s", customerID, sanitizedInstance)
}

func sanitizeIdentifier(value string) string {
	value = strings.ToLower(value)
	var builder strings.Builder
	for _, r := range value {
		if (r >= 'a' && r <= 'z') || (r >= '0' && r <= '9') {
			builder.WriteRune(r)
		}
	}
	if builder.Len() == 0 {
		return "instance"
	}
	return builder.String()
}

func ensureInstanceDir(path string) error {
	if err := os.MkdirAll(path, instanceDirMode); err != nil {
		return fmt.Errorf("create dir %s: %w", path, err)
	}
	return nil
}

func allocateCustomerPorts(customerID string) ([]int, error) {
	basePort := 30000
	if override := os.Getenv("EASYWI_PORT_POOL_START"); override != "" {
		if parsed, err := strconv.Atoi(override); err == nil {
			basePort = parsed
		}
	}
	id, err := strconv.Atoi(customerID)
	if err != nil || id <= 0 {
		return nil, fmt.Errorf("invalid customer_id %s", customerID)
	}
	start := basePort + (id-1)*5
	ports := make([]int, 0, 5)
	for i := 0; i < 5; i++ {
		ports = append(ports, start+i)
	}
	return ports, nil
}

func systemdUnitTemplate(serviceName, user, workingDir, startCommand, startParams string) string {
	command := strings.TrimSpace(startCommand)
	if startParams != "" && !strings.Contains(startCommand, startParams) {
		command = strings.TrimSpace(command + " " + startParams)
	}
	return fmt.Sprintf(`[Unit]
Description=Easy-Wi Instance %s
After=network.target

[Service]
Type=simple
User=%s
WorkingDirectory=%s
ExecStart=%s
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
`, serviceName, user, workingDir, command)
}

func intSliceToStrings(values []int) []string {
	parts := make([]string, 0, len(values))
	for _, value := range values {
		parts = append(parts, strconv.Itoa(value))
	}
	return parts
}

func collectServiceDiagnostics(serviceName string) map[string]string {
	diagnostics := map[string]string{}
	statusOutput, err := runCommandOutput("systemctl", "is-active", serviceName)
	if err != nil {
		diagnostics["service_status"] = "unknown"
		diagnostics["service_status_error"] = err.Error()
	} else {
		diagnostics["service_status"] = strings.TrimSpace(statusOutput)
	}

	logOutput, err := runCommandOutput("journalctl", "-u", serviceName, "-n", "200", "--no-pager", "--output=cat")
	if err != nil {
		diagnostics["logs_error"] = err.Error()
	}
	diagnostics["logs_tail"] = trimOutput(logOutput, 4000)
	return diagnostics
}

func runCommandOutput(name string, args ...string) (string, error) {
	cmd := exec.Command(name, args...)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return string(output), fmt.Errorf("%s %s failed: %w (%s)", name, strings.Join(args, " "), err, strings.TrimSpace(string(output)))
	}
	return string(output), nil
}

func trimOutput(value string, max int) string {
	value = strings.TrimSpace(value)
	if max <= 0 || len(value) <= max {
		return value
	}
	return value[len(value)-max:]
}

func copyDir(source, target string) error {
	entries, err := os.ReadDir(source)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("read dir %s: %w", source, err)
	}
	if err := os.MkdirAll(target, instanceDirMode); err != nil {
		return fmt.Errorf("create dir %s: %w", target, err)
	}
	for _, entry := range entries {
		srcPath := filepath.Join(source, entry.Name())
		dstPath := filepath.Join(target, entry.Name())
		info, err := entry.Info()
		if err != nil {
			return fmt.Errorf("stat %s: %w", srcPath, err)
		}
		if entry.IsDir() {
			if err := copyDir(srcPath, dstPath); err != nil {
				return err
			}
			continue
		}
		if err := copyFile(srcPath, dstPath, info.Mode()); err != nil {
			return err
		}
	}
	return nil
}

func copyFile(source, target string, mode os.FileMode) error {
	srcFile, err := os.Open(source)
	if err != nil {
		return fmt.Errorf("open %s: %w", source, err)
	}
	defer srcFile.Close()

	dstFile, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, mode)
	if err != nil {
		return fmt.Errorf("open %s: %w", target, err)
	}
	defer dstFile.Close()

	if _, err := io.Copy(dstFile, srcFile); err != nil {
		return fmt.Errorf("copy %s: %w", source, err)
	}
	return nil
}

func chownRecursive(path string, uid, gid int) error {
	entries, err := os.ReadDir(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("read dir %s: %w", path, err)
	}
	for _, entry := range entries {
		entryPath := filepath.Join(path, entry.Name())
		if err := os.Chown(entryPath, uid, gid); err != nil {
			return fmt.Errorf("chown %s: %w", entryPath, err)
		}
		if entry.IsDir() {
			if err := chownRecursive(entryPath, uid, gid); err != nil {
				return err
			}
		}
	}
	return nil
}
