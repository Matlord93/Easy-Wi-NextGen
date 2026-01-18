package main

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	ts3ConfigFile = "ts3server.ini"
)

func handleTs3Create(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "ts3_instance_id", "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	name := payloadValue(job.Payload, "name")
	voicePort := payloadValue(job.Payload, "voice_port", "voice_port")
	queryPort := payloadValue(job.Payload, "query_port", "query_port")
	filePort := payloadValue(job.Payload, "file_port", "file_port")
	dbMode := strings.ToLower(payloadValue(job.Payload, "db_mode"))
	dbHost := payloadValue(job.Payload, "db_host")
	dbPort := payloadValue(job.Payload, "db_port")
	dbName := payloadValue(job.Payload, "db_name")
	dbUsername := payloadValue(job.Payload, "db_username")
	dbPassword := payloadValue(job.Payload, "db_password")
	baseDir := payloadValue(job.Payload, "base_dir")
	startCommand := payloadValue(job.Payload, "exec_start", "start_command")
	installCommand := payloadValue(job.Payload, "install_command")
	serviceName := payloadValue(job.Payload, "service_name")

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
		{key: "voice_port", value: voicePort},
		{key: "query_port", value: queryPort},
		{key: "file_port", value: filePort},
		{key: "db_mode", value: dbMode},
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
		serviceName = fmt.Sprintf("ts3-%s", instanceID)
	}
	if startCommand == "" {
		startCommand = "./ts3server"
	}

	osUsername := buildTs3Username(customerID, instanceID)
	instanceDir := filepath.Join(baseDir, osUsername)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, instanceDir); err != nil {
		return failureResult(job.ID, err)
	}

	configPath := filepath.Join(instanceDir, ts3ConfigFile)
	if err := ensureInstanceDir(instanceDir); err != nil {
		return failureResult(job.ID, err)
	}

	if installCommand != "" {
		templateValues := buildInstanceTemplateValues(instanceDir, "", []int{}, job.Payload)
		renderedInstallCommand, err := renderTemplateStrict(installCommand, templateValues)
		if err != nil {
			return failureResult(job.ID, err)
		}
		installWithDir := fmt.Sprintf("cd %s && %s", instanceDir, renderedInstallCommand)
		_, err = runCommandOutputAsUserWithLogs(osUsername, installWithDir, job.ID, logSender)
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("install command failed: %w", err))
		}
	}

	config := buildTs3Config(ts3Config{
		name:       name,
		voicePort:  voicePort,
		queryPort:  queryPort,
		filePort:   filePort,
		dbMode:     dbMode,
		dbHost:     dbHost,
		dbPort:     dbPort,
		dbName:     dbName,
		dbUsername: dbUsername,
		dbPassword: dbPassword,
	})
	if err := os.WriteFile(configPath, []byte(config), instanceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write config: %w", err))
	}

	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := os.Chown(instanceDir, uid, gid); err != nil {
		return failureResult(job.ID, fmt.Errorf("chown %s: %w", instanceDir, err))
	}
	if err := os.Chown(configPath, uid, gid); err != nil {
		return failureResult(job.ID, fmt.Errorf("chown %s: %w", configPath, err))
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, instanceDir, startCommand, "", 0, 0)
	if err := os.WriteFile(unitPath, []byte(unitContent), instanceFileMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("write systemd unit: %w", err))
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return failureResult(job.ID, err)
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName
	diagnostics["instance_dir"] = instanceDir
	diagnostics["config_path"] = configPath
	diagnostics["db_mode"] = dbMode

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

func handleTs3Start(job jobs.Job) (jobs.Result, func() error) {
	return handleTs3ServiceAction(job, "start")
}

func handleTs3Stop(job jobs.Job) (jobs.Result, func() error) {
	return handleTs3ServiceAction(job, "stop")
}

func handleTs3Restart(job jobs.Job) (jobs.Result, func() error) {
	return handleTs3ServiceAction(job, "restart")
}

func handleTs3Update(job jobs.Job) (jobs.Result, func() error) {
	updateCommand := payloadValue(job.Payload, "update_command")
	if updateCommand != "" {
		instanceDir := ts3InstanceDir(job)
		username := ts3Username(job)
		if instanceDir == "" || username == "" {
			return failureResult(job.ID, fmt.Errorf("missing instance_dir or username for update"))
		}
		templateValues := buildInstanceTemplateValues(instanceDir, "", []int{}, job.Payload)
		renderedUpdateCommand, err := renderTemplateStrict(updateCommand, templateValues)
		if err != nil {
			return failureResult(job.ID, err)
		}
		command := fmt.Sprintf("cd %s && %s", instanceDir, renderedUpdateCommand)
		if err := runCommandAsUser(username, command); err != nil {
			return failureResult(job.ID, fmt.Errorf("update command failed: %w", err))
		}
	}
	return handleTs3ServiceAction(job, "restart")
}

func handleTs3Backup(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "ts3_instance_id", "instance_id")
	instanceDir := ts3InstanceDir(job)
	if instanceDir == "" {
		return failureResult(job.ID, fmt.Errorf("missing instance directory"))
	}
	if instanceID == "" {
		return failureResult(job.ID, fmt.Errorf("missing instance id"))
	}

	backupPath := payloadValue(job.Payload, "backup_path")
	if backupPath == "" {
		backupDir := filepath.Join(filepath.Dir(instanceDir), "backups")
		if err := os.MkdirAll(backupDir, instanceDirMode); err != nil {
			return failureResult(job.ID, fmt.Errorf("create backup dir: %w", err))
		}
		backupPath = filepath.Join(backupDir, fmt.Sprintf("ts3-%s-%d.tar.gz", instanceID, time.Now().UTC().Unix()))
	}

	if err := runCommand("tar", "-czf", backupPath, "-C", instanceDir, "."); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"backup_path": backupPath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleTs3Restore(job jobs.Job) (jobs.Result, func() error) {
	instanceDir := ts3InstanceDir(job)
	restorePath := payloadValue(job.Payload, "restore_path")
	if instanceDir == "" {
		return failureResult(job.ID, fmt.Errorf("missing instance directory"))
	}
	if restorePath == "" {
		return failureResult(job.ID, fmt.Errorf("missing restore_path"))
	}

	serviceName := ts3ServiceName(job)
	if serviceName != "" {
		_ = runCommand("systemctl", "stop", serviceName)
	}

	if err := os.RemoveAll(instanceDir); err != nil {
		return failureResult(job.ID, fmt.Errorf("remove instance dir: %w", err))
	}
	if err := os.MkdirAll(instanceDir, instanceDirMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("create instance dir: %w", err))
	}
	if err := runCommand("tar", "-xzf", restorePath, "-C", instanceDir); err != nil {
		return failureResult(job.ID, err)
	}

	if serviceName != "" {
		if err := runCommand("systemctl", "start", serviceName); err != nil {
			return failureResult(job.ID, err)
		}
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"restore_path": restorePath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleTs3TokenReset(job jobs.Job) (jobs.Result, func() error) {
	token := make([]byte, 16)
	if _, err := rand.Read(token); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"token": hex.EncodeToString(token),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleTs3SlotsSet(job jobs.Job) (jobs.Result, func() error) {
	slots := payloadValue(job.Payload, "slots")
	if slots == "" {
		return failureResult(job.ID, fmt.Errorf("missing slots"))
	}

	configPath := ts3ConfigPath(job)
	if configPath == "" {
		return failureResult(job.ID, fmt.Errorf("missing config path"))
	}

	if err := upsertConfigValue(configPath, "virtualserver_maxclients", slots); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"slots": slots,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleTs3LogsExport(job jobs.Job) (jobs.Result, func() error) {
	serviceName := ts3ServiceName(job)
	if serviceName == "" {
		return failureResult(job.ID, fmt.Errorf("missing service_name"))
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

func handleTs3ServiceAction(job jobs.Job, action string) (jobs.Result, func() error) {
	serviceName := ts3ServiceName(job)
	if serviceName == "" {
		return failureResult(job.ID, fmt.Errorf("missing service_name"))
	}

	if err := runCommand("systemctl", action, serviceName); err != nil {
		return failureResult(job.ID, err)
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName
	diagnostics["action"] = action

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    diagnostics,
		Completed: time.Now().UTC(),
	}, nil
}

type ts3Config struct {
	name       string
	voicePort  string
	queryPort  string
	filePort   string
	dbMode     string
	dbHost     string
	dbPort     string
	dbName     string
	dbUsername string
	dbPassword string
}

func buildTs3Config(cfg ts3Config) string {
	lines := []string{
		"machine_id=" + cfg.name,
		"default_voice_port=" + cfg.voicePort,
		"query_port=" + cfg.queryPort,
		"filetransfer_port=" + cfg.filePort,
	}
	switch cfg.dbMode {
	case "mysql":
		lines = append(lines,
			"dbplugin=ts3db_mysql",
			"dbhost="+cfg.dbHost,
			"dbport="+cfg.dbPort,
			"dbusername="+cfg.dbUsername,
			"dbpassword="+cfg.dbPassword,
			"dbname="+cfg.dbName,
		)
	default:
		lines = append(lines, "dbplugin=ts3db_sqlite3")
	}
	lines = append(lines, "license_accepted=1")
	return strings.Join(lines, "\n") + "\n"
}

func buildTs3Username(customerID, instanceID string) string {
	sanitized := sanitizeIdentifier(instanceID)
	if len(sanitized) > 8 {
		sanitized = sanitized[:8]
	}
	return fmt.Sprintf("ts%s%s", customerID, sanitized)
}

func ts3InstanceDir(job jobs.Job) string {
	baseDir := payloadValue(job.Payload, "base_dir")
	if baseDir == "" {
		baseDir = "/home"
	}
	username := ts3Username(job)
	if username == "" {
		return ""
	}
	return filepath.Join(baseDir, username)
}

func ts3ConfigPath(job jobs.Job) string {
	instanceDir := ts3InstanceDir(job)
	if instanceDir == "" {
		return ""
	}
	return filepath.Join(instanceDir, ts3ConfigFile)
}

func ts3ServiceName(job jobs.Job) string {
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName != "" {
		return serviceName
	}
	instanceID := payloadValue(job.Payload, "ts3_instance_id", "instance_id")
	if instanceID == "" {
		return ""
	}
	return fmt.Sprintf("ts3-%s", instanceID)
}

func ts3Username(job jobs.Job) string {
	customerID := payloadValue(job.Payload, "customer_id")
	instanceID := payloadValue(job.Payload, "ts3_instance_id", "instance_id")
	if customerID == "" || instanceID == "" {
		return ""
	}
	return buildTs3Username(customerID, instanceID)
}

func upsertConfigValue(path, key, value string) error {
	content, err := os.ReadFile(path)
	if err != nil {
		return fmt.Errorf("read config: %w", err)
	}
	lines := strings.Split(string(content), "\n")
	found := false
	for i, line := range lines {
		if strings.HasPrefix(line, key+"=") {
			lines[i] = key + "=" + value
			found = true
			break
		}
	}
	if !found {
		lines = append(lines, key+"="+value)
	}
	return os.WriteFile(path, []byte(strings.Join(lines, "\n")), instanceFileMode)
}
