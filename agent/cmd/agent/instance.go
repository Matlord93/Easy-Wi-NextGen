package main

import (
	"encoding/json"
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
	baseDirMode      = 0o755
	instanceDirMode  = 0o750
	instanceFileMode = 0o640
)

func handleInstanceCreate(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	startParams := payloadValue(job.Payload, "start_params")
	cpuLimitValue := payloadValue(job.Payload, "cpu_limit")
	ramLimitValue := payloadValue(job.Payload, "ram_limit")
	diskLimitValue := payloadValue(job.Payload, "disk_limit")
	requiredPortsRaw := payloadValue(job.Payload, "required_ports")
	portBlockPortsRaw := payloadValue(job.Payload, "port_block_ports", "ports")
	baseDir := payloadValue(job.Payload, "base_dir")
	startCommand := payloadValue(job.Payload, "exec_start", "start_command")
	serviceName := payloadValue(job.Payload, "service_name")
	autostart := parsePayloadBool(payloadValue(job.Payload, "autostart", "auto_start"), true)

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
		{key: "start_params", value: startParams},
		{key: "cpu_limit", value: cpuLimitValue},
		{key: "ram_limit", value: ramLimitValue},
		{key: "disk_limit", value: diskLimitValue},
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
		baseDir = "/home"
	}
	if serviceName == "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}
	if startCommand == "" {
		startCommand = startParams
	}

	cpuLimit, err := parsePositiveInt(cpuLimitValue, "cpu_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	ramLimit, err := parsePositiveInt(ramLimitValue, "ram_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	diskLimit, err := parsePositiveInt(diskLimitValue, "disk_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	osUsername := buildInstanceUsername(customerID, instanceID)
	instanceDir := filepath.Join(baseDir, osUsername)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, instanceDir); err != nil {
		return failureResult(job.ID, err)
	}

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

	templateValues := buildInstanceTemplateValues(instanceDir, requiredPortsRaw, allocatedPorts, job.Payload)
	renderedStartParams, err := renderTemplateStrict(startParams, templateValues)
	if err != nil {
		return failureResult(job.ID, err)
	}
	startScriptPath, err := writeStartScript(instanceDir, renderedStartParams)
	if err != nil {
		return failureResult(job.ID, err)
	}
	startCommand = startScriptPath
	startParams = ""

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, instanceDir, startCommand, startParams, cpuLimit, ramLimit)
	if err := os.WriteFile(unitPath, []byte(unitContent), instanceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write systemd unit: %w", err))
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return failureResult(job.ID, err)
	}
	if autostart {
		if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
			return failureResult(job.ID, err)
		}
	} else {
		if err := runCommand("systemctl", "start", serviceName); err != nil {
			return failureResult(job.ID, err)
		}
	}
	if err := ensureServiceActive(serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: mergeDiagnostics(map[string]string{
			"os_username":       osUsername,
			"instance_dir":      instanceDir,
			"data_dir":          dataDir,
			"logs_dir":          logsDir,
			"config_dir":        configDir,
			"service_name":      serviceName,
			"cpu_limit":         strconv.Itoa(cpuLimit),
			"ram_limit":         strconv.Itoa(ramLimit),
			"disk_limit":        strconv.Itoa(diskLimit),
			"autostart":         strconv.FormatBool(autostart),
			"allocated_ports":   strings.Join(intSliceToStrings(allocatedPorts), ","),
			"required_ports":    requiredPortsRaw,
			"start_script_path": startScriptPath,
		}, diagnostics),
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
	if err := ensureServiceActive(serviceName); err != nil {
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
	if err := ensureServiceActive(serviceName); err != nil {
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

func handleInstanceReinstall(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	baseDir := payloadValue(job.Payload, "base_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	startParams := payloadValue(job.Payload, "start_params")
	startCommand := payloadValue(job.Payload, "exec_start", "start_command")
	installCommand := payloadValue(job.Payload, "install_command")
	requiredPortsRaw := payloadValue(job.Payload, "required_ports")
	portBlockPortsRaw := payloadValue(job.Payload, "port_block_ports", "ports")
	cpuLimitValue := payloadValue(job.Payload, "cpu_limit")
	ramLimitValue := payloadValue(job.Payload, "ram_limit")
	diskLimitValue := payloadValue(job.Payload, "disk_limit")
	backupOld := strings.EqualFold(payloadValue(job.Payload, "backup_old", "backup"), "true")
	autostart := parsePayloadBool(payloadValue(job.Payload, "autostart", "auto_start"), true)

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
		{key: "cpu_limit", value: cpuLimitValue},
		{key: "ram_limit", value: ramLimitValue},
		{key: "disk_limit", value: diskLimitValue},
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
		baseDir = "/home"
	}
	if serviceName == "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}
	if startCommand == "" {
		startCommand = startParams
	}

	var allocatedPorts []int
	if portBlockPortsRaw != "" {
		ports, err := parsePorts(portBlockPortsRaw)
		if err != nil {
			return failureResult(job.ID, err)
		}
		allocatedPorts = ports
	}

	cpuLimit, err := parsePositiveInt(cpuLimitValue, "cpu_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	ramLimit, err := parsePositiveInt(ramLimitValue, "ram_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	diskLimit, err := parsePositiveInt(diskLimitValue, "disk_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	osUsername := buildInstanceUsername(customerID, instanceID)
	instanceDir := filepath.Join(baseDir, osUsername)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, instanceDir); err != nil {
		return failureResult(job.ID, err)
	}

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

	templateValues := buildInstanceTemplateValues(instanceDir, requiredPortsRaw, allocatedPorts, job.Payload)
	renderedStartParams, err := renderTemplateStrict(startParams, templateValues)
	if err != nil {
		return failureResult(job.ID, err)
	}
	startScriptPath, err := writeStartScript(instanceDir, renderedStartParams)
	if err != nil {
		return failureResult(job.ID, err)
	}
	startCommand = startScriptPath
	startParams = ""

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, instanceDir, startCommand, startParams, cpuLimit, ramLimit)
	if err := os.WriteFile(unitPath, []byte(unitContent), instanceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write systemd unit: %w", err))
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)

	if installCommand != "" {
		renderedInstallCommand, err := renderTemplateStrict(installCommand, templateValues)
		if err != nil {
			return failureResult(job.ID, err)
		}
		installWithDir := fmt.Sprintf("cd %s && %s", instanceDir, renderedInstallCommand)
		installOutput, err := runCommandOutputAsUserWithLogs(osUsername, installWithDir, job.ID, logSender)
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("install command failed: %w", err))
		}
		diagnostics["install_log"] = trimOutput(installOutput, 4000)
	}
	if err := validateBinaryExists(instanceDir, renderedStartParams); err != nil {
		return failureResult(job.ID, err)
	}

	if autostart {
		_ = runCommand("systemctl", "enable", serviceName)
	}
	if err := runCommand("systemctl", "start", serviceName); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureServiceActive(serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics["service_name"] = serviceName
	diagnostics["instance_dir"] = instanceDir
	diagnostics["data_dir"] = dataDir
	diagnostics["logs_dir"] = logsDir
	diagnostics["config_dir"] = configDir
	diagnostics["backup_old"] = strconv.FormatBool(backupOld)
	diagnostics["cpu_limit"] = strconv.Itoa(cpuLimit)
	diagnostics["ram_limit"] = strconv.Itoa(ramLimit)
	diagnostics["disk_limit"] = strconv.Itoa(diskLimit)
	diagnostics["autostart"] = strconv.FormatBool(autostart)
	diagnostics["start_script_path"] = startScriptPath

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

func ensureBaseDir(path string) error {
	if err := os.MkdirAll(path, baseDirMode); err != nil {
		return fmt.Errorf("create base dir %s: %w", path, err)
	}
	if err := os.Chmod(path, baseDirMode); err != nil {
		return fmt.Errorf("chmod base dir %s: %w", path, err)
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

func systemdUnitTemplate(serviceName, user, workingDir, readWritePath, startCommand, startParams string, cpuLimit, ramLimit int) string {
	command := strings.TrimSpace(startCommand)
	if startParams != "" && !strings.Contains(startCommand, startParams) {
		command = strings.TrimSpace(command + " " + startParams)
	}
	limits := buildSystemdLimits(cpuLimit, ramLimit)
	return fmt.Sprintf(`[Unit]
Description=Easy-Wi Instance %s
After=network.target

[Service]
Type=simple
User=%s
WorkingDirectory=%s
Environment=HOME=%s
Environment=XDG_CONFIG_HOME=%s/.config
Environment=XDG_DATA_HOME=%s/.local/share
ExecStartPre=/usr/bin/test -d %s
ExecStart=%s
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

func intSliceToStrings(values []int) []string {
	parts := make([]string, 0, len(values))
	for _, value := range values {
		parts = append(parts, strconv.Itoa(value))
	}
	return parts
}

func buildInstanceTemplateValues(instanceDir, requiredPortsRaw string, allocatedPorts []int, payload map[string]any) map[string]string {
	values := map[string]string{
		"INSTANCE_DIR": instanceDir,
		"INSTALL_DIR":  instanceDir,
	}

	for key, value := range parseEnvVars(payload) {
		if value == "" {
			continue
		}
		values[key] = value
	}

	for key, value := range parseSecrets(payload) {
		if value == "" {
			continue
		}
		values[key] = value
	}

	applyPortReservations(values, payload)

	portLabels := parsePortLabels(requiredPortsRaw)
	for idx, label := range portLabels {
		if idx >= len(allocatedPorts) {
			break
		}
		placeholder := "PORT_" + strings.ToUpper(label)
		values[placeholder] = strconv.Itoa(allocatedPorts[idx])
	}

	return values
}

func parseEnvVars(payload map[string]any) map[string]string {
	raw, ok := payload["env_vars"]
	if !ok || raw == nil {
		return map[string]string{}
	}

	switch typed := raw.(type) {
	case map[string]any:
		values := map[string]string{}
		for key, value := range typed {
			if stringValue := payloadString(value); stringValue != "" {
				values[key] = stringValue
			}
		}
		return values
	case []any:
		return parseEnvVarEntries(typed)
	case string:
		if typed == "" {
			return map[string]string{}
		}
		var entries []map[string]any
		if err := json.Unmarshal([]byte(typed), &entries); err == nil {
			return parseEnvVarEntries(entriesToAny(entries))
		}
		var list []string
		if err := json.Unmarshal([]byte(typed), &list); err == nil {
			return parseEnvVarStrings(list)
		}
		return parseEnvVarStrings(strings.FieldsFunc(typed, func(r rune) bool {
			return r == '\n' || r == ',' || r == ';'
		}))
	default:
		stringValue := payloadString(raw)
		if stringValue == "" {
			return map[string]string{}
		}
		return parseEnvVarStrings(strings.FieldsFunc(stringValue, func(r rune) bool {
			return r == '\n' || r == ',' || r == ';'
		}))
	}
}

func parseSecrets(payload map[string]any) map[string]string {
	raw, ok := payload["secrets"]
	if !ok || raw == nil {
		return map[string]string{}
	}

	switch typed := raw.(type) {
	case map[string]any:
		values := map[string]string{}
		for key, value := range typed {
			if stringValue := payloadString(value); stringValue != "" {
				values[key] = stringValue
			}
		}
		return values
	case []any:
		return parseSecretEntries(typed)
	default:
		return map[string]string{}
	}
}

func parseSecretEntries(entries []any) map[string]string {
	values := map[string]string{}
	for _, entry := range entries {
		typed, ok := entry.(map[string]any)
		if !ok {
			continue
		}
		key := strings.TrimSpace(payloadString(typed["key"]))
		value := payloadString(typed["value"])
		if value == "" {
			value = payloadString(typed["placeholder"])
		}
		if key != "" {
			values[key] = value
		}
	}
	return values
}

func applyPortReservations(values map[string]string, payload map[string]any) {
	raw, ok := payload["port_reservations"]
	if !ok || raw == nil {
		return
	}

	entries, ok := raw.([]any)
	if !ok {
		return
	}

	for _, entry := range entries {
		typed, ok := entry.(map[string]any)
		if !ok {
			continue
		}
		role := strings.TrimSpace(payloadString(typed["role"]))
		if role == "" {
			role = strings.TrimSpace(payloadString(typed["name"]))
		}
		if role == "" {
			continue
		}
		portValue := payloadString(typed["port"])
		if portValue == "" {
			continue
		}
		placeholder := "PORT_" + strings.ToUpper(role)
		values[placeholder] = portValue
	}
}

func parseEnvVarEntries(entries []any) map[string]string {
	values := map[string]string{}
	for _, entry := range entries {
		switch typed := entry.(type) {
		case map[string]any:
			key := strings.TrimSpace(payloadString(typed["key"]))
			value := payloadString(typed["value"])
			if key == "" {
				for k, v := range typed {
					key = strings.TrimSpace(k)
					value = payloadString(v)
					break
				}
			}
			if key != "" {
				values[key] = value
			}
		case string:
			for k, v := range parseEnvVarStrings([]string{typed}) {
				values[k] = v
			}
		default:
			if stringValue := payloadString(typed); stringValue != "" {
				for k, v := range parseEnvVarStrings([]string{stringValue}) {
					values[k] = v
				}
			}
		}
	}
	return values
}

func entriesToAny(entries []map[string]any) []any {
	values := make([]any, 0, len(entries))
	for _, entry := range entries {
		values = append(values, entry)
	}
	return values
}

func parseEnvVarStrings(values []string) map[string]string {
	parsed := map[string]string{}
	for _, entry := range values {
		entry = strings.TrimSpace(entry)
		if entry == "" {
			continue
		}
		parts := strings.SplitN(entry, "=", 2)
		if len(parts) == 0 {
			continue
		}
		key := strings.TrimSpace(parts[0])
		if key == "" {
			continue
		}
		value := ""
		if len(parts) > 1 {
			value = strings.TrimSpace(parts[1])
		}
		parsed[key] = value
	}
	return parsed
}

func parsePortLabels(requiredPortsRaw string) []string {
	if requiredPortsRaw == "" {
		return []string{}
	}
	fields := strings.FieldsFunc(requiredPortsRaw, func(r rune) bool {
		return r == ',' || r == ';' || r == ' ' || r == '\n' || r == '\t'
	})
	labels := make([]string, 0, len(fields))
	for _, field := range fields {
		field = strings.TrimSpace(field)
		if field == "" {
			continue
		}
		label := strings.SplitN(field, "/", 2)[0]
		label = strings.TrimSpace(label)
		if label == "" {
			continue
		}
		labels = append(labels, label)
	}
	return labels
}

func parsePayloadPorts(payload map[string]any) []int {
	if raw, ok := payload["ports"]; ok {
		if ports := parsePortsFromValue(raw); len(ports) > 0 {
			return ports
		}
	}

	if raw, ok := payload["port_block_ports"]; ok {
		if ports := parsePortsFromValue(raw); len(ports) > 0 {
			return ports
		}
	}

	if portsRaw := payloadValue(payload, "port_block_ports", "ports"); portsRaw != "" {
		parsed, err := parsePorts(portsRaw)
		if err == nil {
			return parsed
		}
	}

	return []int{}
}

func parsePortsFromValue(value any) []int {
	switch typed := value.(type) {
	case []int:
		return typed
	case []any:
		ports := make([]int, 0, len(typed))
		for _, entry := range typed {
			portValue := payloadString(entry)
			if portValue == "" {
				continue
			}
			parsed, err := strconv.Atoi(portValue)
			if err != nil || parsed <= 0 || parsed > 65535 {
				continue
			}
			ports = append(ports, parsed)
		}
		return ports
	case string:
		trimmed := strings.TrimSpace(typed)
		if trimmed == "" {
			return []int{}
		}
		if strings.HasPrefix(trimmed, "[") {
			var parsed []int
			if err := json.Unmarshal([]byte(trimmed), &parsed); err == nil {
				return parsed
			}
		}
		if parsed, err := parsePorts(trimmed); err == nil {
			return parsed
		}
		return []int{}
	default:
		return []int{}
	}
}

func replaceInstanceTemplateTokens(value string, replacements map[string]string) string {
	if value == "" || len(replacements) == 0 {
		return value
	}
	replaced := value
	for key, replacement := range replacements {
		replaced = strings.ReplaceAll(replaced, "{{"+key+"}}", replacement)
	}
	return replaced
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

func mergeDiagnostics(output map[string]string, diagnostics map[string]string) map[string]string {
	if len(diagnostics) == 0 {
		return output
	}
	for key, value := range diagnostics {
		if value == "" {
			continue
		}
		output[key] = value
	}
	return output
}

func runCommandOutput(name string, args ...string) (string, error) {
	cmd := exec.Command(name, args...)
	output, err := StreamCommand(cmd, "", nil)
	if err != nil {
		return output, fmt.Errorf("%s %s failed: %w (%s)", name, strings.Join(args, " "), err, strings.TrimSpace(output))
	}
	return output, nil
}

func runCommandAsUser(username, command string) error {
	return runCommand("runuser", "-u", username, "--", "/bin/sh", "-c", command)
}

func runCommandOutputAsUser(username, command string) (string, error) {
	return runCommandOutput("runuser", "-u", username, "--", "/bin/sh", "-c", command)
}

func runCommandOutputAsUserWithLogs(username, command, jobID string, logSender JobLogSender) (string, error) {
	cmd := buildRunUserCommand(username, command)
	output, err := StreamCommand(cmd, jobID, logSender)
	if err != nil {
		return output, fmt.Errorf("command failed: %w", err)
	}
	return output, nil
}

func buildRunUserCommand(username, command string) *exec.Cmd {
	if shouldForceTTY(command) {
		return exec.Command("runuser", "-u", username, "--", "script", "-q", "-f", "-c", command, "/dev/null")
	}
	return exec.Command("runuser", "-u", username, "--", "stdbuf", "-oL", "-eL", "/bin/sh", "-c", command)
}

func trimOutput(value string, max int) string {
	value = strings.TrimSpace(value)
	if max <= 0 || len(value) <= max {
		return value
	}
	return value[len(value)-max:]
}

func splitLogLines(data []byte, atEOF bool) (advance int, token []byte, err error) {
	if atEOF && len(data) == 0 {
		return 0, nil, nil
	}
	for i, b := range data {
		if b == '\n' || b == '\r' {
			end := i
			advance = i + 1
			if b == '\r' && len(data) > i+1 && data[i+1] == '\n' {
				advance = i + 2
			}
			return advance, data[:end], nil
		}
	}
	if atEOF {
		return len(data), data, nil
	}
	return 0, nil, nil
}

func parsePositiveInt(value, key string) (int, error) {
	parsed, err := strconv.Atoi(value)
	if err != nil || parsed <= 0 {
		return 0, fmt.Errorf("%s must be a positive integer", key)
	}
	return parsed, nil
}

func parsePayloadBool(value string, defaultValue bool) bool {
	if value == "" {
		return defaultValue
	}
	switch strings.ToLower(strings.TrimSpace(value)) {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return defaultValue
	}
}

func ensureServiceActive(serviceName string) error {
	statusOutput, err := runCommandOutput("systemctl", "is-active", serviceName)
	if err != nil {
		return err
	}
	if strings.TrimSpace(statusOutput) != "active" {
		return fmt.Errorf("service %s not active (%s)", serviceName, strings.TrimSpace(statusOutput))
	}
	return nil
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
