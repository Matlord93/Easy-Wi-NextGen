package main

import (
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var (
	buildIDRegex                 = regexp.MustCompile(`(?i)build(?:[_\s-]?id)?[:\s]+([0-9]+)`)
	versionRegex                 = regexp.MustCompile(`(?i)version[:\s]+([0-9a-zA-Z._-]+)`)
	jsonLineRegex                = regexp.MustCompile(`\{.*\}`)
	forceInstallDirRegex         = regexp.MustCompile(`(?i)(\+force_install_dir\s+)(\"[^\"]+\"|'[^']+'|\S+)`)
	forceInstallDirPresenceRegex = regexp.MustCompile(`(?i)\+force_install_dir\b`)
	steamcmdInjectRegex          = regexp.MustCompile(`(?i)(^|\s)([^\s]*steamcmd(?:\.sh|\.exe)?)(\s)`)
	steamcmdCommandRegex         = regexp.MustCompile(`(^|\s)(/var/lib/easywi/game/steamcmd/steamcmd\.sh|/usr/local/bin/steamcmd|steamcmd)(\s|$)`)
	steamcmdArchiveURL           = "https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz"
	wineBootstrapRegex           = regexp.MustCompile(`(?is)^\s*(?:bash\s+-lc\s+)?["']?\s*set\s+-e\s*;\s*if\s+!\s+command\s+-v\s+wine\b.*?\bfi\s*;\s*`)
	chownRecursiveFn             = func(path string, uid, gid int) error { return os.Chown(path, uid, gid) }
)

const steamCmdRetryLimit = 3

func handleSniperInstall(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	return handleSniperAction(job, "install", logSender)
}

func handleSniperUpdate(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	return handleSniperAction(job, "update", logSender)
}

func handleSniperSharedUpdate(job jobs.Job, logSender JobLogSender) (jobs.Result, func() error) {
	baseDir := payloadValue(job.Payload, "base_dir")
	if baseDir == "" {
		baseDir = defaultInstanceBaseDir()
	}
	sharedKey := strings.TrimSpace(payloadValue(job.Payload, "shared_key"))
	if sharedKey == "" {
		var err error
		sharedKey, err = buildSharedKey(job.Payload)
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("SHARED_KEY_INVALID: %w", err))
		}
	}
	if strings.TrimSpace(sharedKey) == "" {
		return failureResult(job.ID, errors.New("SHARED_KEY_INVALID: shared_key empty"))
	}
	sharedServer := sharedServerDir(baseDir, sharedKey)
	manifestPath := sharedManifestPath(baseDir, sharedKey)
	lockRelease, err := acquireSharedStorageLockWithTimeout(sharedLockPath(baseDir, sharedKey), 2*time.Minute)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("SHARED_LOCK_TIMEOUT: %w", err))
	}
	defer lockRelease()

	mf, err := readSharedManifest(manifestPath)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("SHARED_MANIFEST_INVALID: %w", err))
	}
	if mf.SharedKey != "" && strings.TrimSpace(mf.SharedKey) != sharedKey {
		return failureResult(job.ID, fmt.Errorf("SHARED_MANIFEST_INVALID: manifest shared_key mismatch (%s != %s)", mf.SharedKey, sharedKey))
	}
	if _, err := os.Stat(sharedServer); err != nil {
		if os.IsNotExist(err) {
			return failureResult(job.ID, fmt.Errorf("SHARED_SERVER_MISSING: %s", sharedServer))
		}
		return failureResult(job.ID, fmt.Errorf("SHARED_SERVER_MISSING: %w", err))
	}
	if mf.Status == "installing" || mf.Status == "updating" {
		return failureResult(job.ID, fmt.Errorf("SHARED_MANIFEST_INVALID: shared server busy with status=%s", mf.Status))
	}
	mf.Status = "updating"
	mf.FailureReason = ""
	_ = writeSharedManifest(manifestPath, *mf)
	if logSender != nil {
		logSender.Send(job.ID, []string{fmt.Sprintf("SHARED_UPDATE_STARTED shared_key=%s shared_server=%s", sharedKey, sharedServer)}, nil)
	}

	updateCommand := payloadValue(job.Payload, "update_command")
	if strings.TrimSpace(updateCommand) == "" {
		err := errors.New("SHARED_UPDATE_FAILED: missing update_command")
		_ = markSharedManifestFailed(manifestPath, err)
		return failureResult(job.ID, err)
	}
	if logSender != nil {
		logSender.Send(job.ID, []string{fmt.Sprintf("Shared update may affect running instances using shared_key=%s", sharedKey)}, nil)
	}
	osUsername := buildInstanceUsername(payloadValue(job.Payload, "customer_id"), payloadValue(job.Payload, "instance_id"))
	templateValues := buildInstanceTemplateValues(sharedServer, payloadValue(job.Payload, "required_ports"), parsePayloadPorts(job.Payload), job.Payload)
	renderedCommand, err := renderTemplateStrict(updateCommand, templateValues)
	if err != nil {
		_ = markSharedManifestFailed(manifestPath, err)
		return failureResult(job.ID, fmt.Errorf("SHARED_UPDATE_FAILED: %w", err))
	}
	renderedCommand = normalizeSteamCmdInstallDir(renderedCommand, sharedServer)
	shellCmd := fmt.Sprintf("cd %s && %s", sharedServer, renderedCommand)
	output, err := runCommandOutputAsUserWithLogs(osUsername, shellCmd, job.ID, logSender)
	if err != nil {
		_ = markSharedManifestFailed(manifestPath, err)
		return failureResult(job.ID, fmt.Errorf("SHARED_UPDATE_FAILED: %w", err))
	}

	now := time.Now().UTC().Format(time.RFC3339)
	mf.LastSuccessfulUpdateAt = now
	mf.Status = "ready"
	mf.FailureReason = ""
	_ = writeSharedManifest(manifestPath, *mf)

	return jobs.Result{JobID: job.ID, Status: "success", Completed: time.Now().UTC(), Output: map[string]string{
		"message":                   "shared update completed",
		"shared_key":                sharedKey,
		"shared_result":             "SHARED_UPDATE_SUCCESS",
		"shared_status":             "ready",
		"last_successful_update_at": now,
		"shared_server_path":        sharedServer,
		"manifest_path":             manifestPath,
		"install_log":               trimOutput(output, 4000),
	}}, nil
}

func markSharedManifestFailed(manifestPath string, reason error) error {
	mf, err := readSharedManifest(manifestPath)
	if err != nil {
		return err
	}
	mf.Status = "failed"
	mf.FailureReason = reason.Error()
	return writeSharedManifest(manifestPath, *mf)
}

func evaluateSharedInstallReuse(manifestPath, installTargetDir, action, command string, sharedSpecs []sharedPathSpec, payload map[string]any, sharedKey string) (sharedManifest, bool, error) {
	hash := fmt.Sprintf("%x", sha256.Sum256([]byte(command)))
	now := time.Now().UTC().Format(time.RFC3339)
	mf := sharedManifest{
		SharedKey:          sharedKey,
		TemplateID:         payloadValue(payload, "template_id"),
		TemplateName:       payloadValue(payload, "template_slug", "template_key", "game_key", "template_name"),
		CreatedAt:          now,
		UpdatedAt:          now,
		InstallCommandHash: hash,
		SharedPaths:        sharedSpecs,
	}
	if ex, err := readSharedManifest(manifestPath); err == nil {
		mf = *ex
	}
	if action == "install" && mf.Status == "ready" && mf.InstallCommandHash == hash {
		if _, err := os.Stat(installTargetDir); err == nil {
			return mf, true, nil
		}
	}
	if action == "update" {
		mf.Status = "updating"
	} else {
		mf.Status = "installing"
	}
	if err := writeSharedManifest(manifestPath, mf); err != nil {
		return mf, false, err
	}
	return mf, false, nil
}

func handleSniperAction(job jobs.Job, action string, logSender JobLogSender) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	customerID := payloadValue(job.Payload, "customer_id")
	steamAppID := payloadValue(job.Payload, "steam_app_id")
	installCommand := payloadValue(job.Payload, "install_command")
	updateCommand := payloadValue(job.Payload, "update_command")
	baseDir := payloadValue(job.Payload, "base_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	startParams := payloadValue(job.Payload, "start_params")
	requiredPortsRaw := payloadValue(job.Payload, "required_ports")
	templateKey := payloadValue(job.Payload, "template_key", "game_key")
	cpuLimitValue := payloadValue(job.Payload, "cpu_limit")
	ramLimitValue := payloadValue(job.Payload, "ram_limit")
	autostart := parsePayloadBool(payloadValue(job.Payload, "autostart", "auto_start"), true)

	missing := missingValues([]requiredValue{
		{key: "instance_id", value: instanceID},
		{key: "customer_id", value: customerID},
		{key: "start_params", value: startParams},
		{key: "cpu_limit", value: cpuLimitValue},
		{key: "ram_limit", value: ramLimitValue},
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
		baseDir = defaultInstanceBaseDir()
	}
	useSharedStorage := parsePayloadBool(payloadValue(job.Payload, "use_shared_storage"), false)
	sharedSpecs, err := parseSharedPathSpecs(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	sharedEnabled := useSharedStorage && len(sharedSpecs) > 0
	if serviceName == "" {
		serviceName = fmt.Sprintf("gs-%s", instanceID)
	}

	osUsername := buildInstanceUsername(customerID, instanceID)
	instanceDir := fmt.Sprintf("%s/%s", strings.TrimRight(baseDir, "/"), osUsername)
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, instanceDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureBaseDir(baseDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureInstanceDir(instanceDir); err != nil {
		return failureResult(job.ID, err)
	}
	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := os.Chown(instanceDir, uid, gid); err != nil {
		return failureResult(job.ID, fmt.Errorf("chown %s: %w", instanceDir, err))
	}
	if err := os.Chmod(instanceDir, instanceDirMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("chmod %s: %w", instanceDir, err))
	}

	allocatedPorts := parsePayloadPorts(job.Payload)
	templateValues := buildInstanceTemplateValues(instanceDir, requiredPortsRaw, allocatedPorts, job.Payload)

	cpuLimit, err := parsePositiveInt(cpuLimitValue, "cpu_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}
	ramLimit, err := parsePositiveInt(ramLimitValue, "ram_limit")
	if err != nil {
		return failureResult(job.ID, err)
	}

	var command string
	var steamCmdExecPath string
	if action == "install" {
		command = installCommand
	} else {
		command = updateCommand
	}

	usesSteamCmd := false
	if command == "" {
		steamCmdExecPath = "$STEAMCMD_EXEC"
		command = buildSteamCmdCommand(steamCmdExecPath, instanceDir, steamAppID, action == "install")
		usesSteamCmd = true
	}

	if command == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "no install or update command configured"},
			Completed: time.Now().UTC(),
		}, nil
	}

	renderedCommand, err := renderTemplateStrict(command, templateValues)
	if err != nil {
		return failureResult(job.ID, err)
	}

	command = renderedCommand
	command = stripWineBootstrap(command)
	command = normalizeSteamCmdInstallDir(command, instanceDir)

	if !usesSteamCmd && steamcmdCommandRegex.MatchString(command) {
		usesSteamCmd = true
		steamCmdExecPath = "$STEAMCMD_EXEC"
		command = replaceSteamCmdExecutable(command, steamCmdExecPath)
	}

	installTargetDir := instanceDir
	sharedKey := ""
	if sharedEnabled {
		k, err := buildSharedKey(job.Payload)
		if err != nil {
			return failureResult(job.ID, err)
		}
		sharedKey = k
		commandWorkDir, prepErr := prepareSharedStoragePermissions(baseDir, sharedKey, osUsername)
		if prepErr != nil {
			return failureResult(job.ID, prepErr)
		}
		installTargetDir = commandWorkDir
		if logSender != nil && job.ID != "" {
			logSender.Send(job.ID, []string{
				fmt.Sprintf("shared_root created/prepared: %s", filepath.Join(baseDir, "Shared")),
				fmt.Sprintf("shared_server owner set: %s", installTargetDir),
				fmt.Sprintf("command_work_dir: %s", installTargetDir),
				fmt.Sprintf("osUsername: %s", osUsername),
			}, nil)
		}
		if err := os.MkdirAll(installTargetDir, instanceDirMode); err != nil {
			return failureResult(job.ID, err)
		}
	}

	command = normalizeSteamCmdInstallDir(command, installTargetDir)
	commandWorkDir := instanceDir
	if sharedEnabled {
		commandWorkDir = installTargetDir
	}
	installSnippet := ""
	postInstallSnippet := ""
	if usesSteamCmd {
		steamCmdDir := instanceDirSteamCmdDir(commandWorkDir)
		installSnippet = steamCmdInstallSnippet(steamCmdDir)
		postInstallSnippet = steamCmdClientSnippet(steamCmdDir, installTargetDir)
	}
	shellCmd := buildSniperInstallShellCommand(commandWorkDir, command, installSnippet, postInstallSnippet)

	sharedResult := ""
	sharedManifestFile := ""
	if sharedEnabled {
		lockRelease, err := acquireSharedStorageLockWithTimeout(sharedLockPath(baseDir, sharedKey), 2*time.Minute)
		if err != nil {
			return failureResult(job.ID, err)
		}
		defer lockRelease()
		manifestPath := sharedManifestPath(baseDir, sharedKey)
		sharedManifestFile = manifestPath
		_, reuse, err := evaluateSharedInstallReuse(manifestPath, installTargetDir, action, command, sharedSpecs, job.Payload, sharedKey)
		if err != nil {
			return failureResult(job.ID, fmt.Errorf("shared manifest prepare failed: %w", err))
		}
		if reuse {
			sharedResult = "SHARED_INSTALL_REUSED"
		}
	}
	markSharedFailure := func(err error) {
		if sharedEnabled && sharedManifestFile != "" && err != nil {
			_ = markSharedManifestFailed(sharedManifestFile, err)
		}
	}

	if logSender != nil && job.ID != "" {
		maskedInstall := maskSensitiveValues(command, templateValues)
		logSender.Send(job.ID, []string{
			fmt.Sprintf("sniper %s starting (steam_app_id=%s uses_steamcmd=%t command=%s)", action, steamAppID, usesSteamCmd, maskedInstall),
		}, nil)
	}

	output := ""
	if !sharedEnabled || action != "install" || sharedResult != "SHARED_INSTALL_REUSED" {
		var err error
		output, err = runCommandOutputAsUserWithLogs(osUsername, shellCmd, job.ID, logSender)
		if err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, err)
		}
	}
	if usesSteamCmd {
		for attempts := 0; attempts < steamCmdRetryLimit && shouldRetrySteamCmd(output, steamAppID); attempts++ {
			retryOutput, retryErr := runCommandOutputAsUserWithLogs(osUsername, shellCmd, job.ID, logSender)
			if retryErr != nil {
				markSharedFailure(retryErr)
				return failureResult(job.ID, retryErr)
			}
			if retryOutput != "" {
				if output != "" {
					output = output + "\n" + retryOutput
				} else {
					output = retryOutput
				}
			}
		}
	}
	if sharedEnabled {
		if err := copyNonSharedFromServer(installTargetDir, instanceDir, sharedSpecs); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, fmt.Errorf("SHARED_INSTALL_FAILED: %w", err))
		}
		if err := applySharedPaths(instanceDir, sharedKey, sharedSpecs); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, fmt.Errorf("INSTANCE_LINK_FAILED: %w", err))
		}
		manifestPath := sharedManifestPath(baseDir, sharedKey)
		if mf, err := readSharedManifest(manifestPath); err == nil {
			mf.Status = "ready"
			mf.FailureReason = ""
			now := time.Now().UTC().Format(time.RFC3339)
			if action == "install" {
				mf.LastSuccessfulInstallAt = now
			}
			if action == "update" {
				mf.LastSuccessfulUpdateAt = now
				sharedResult = "SHARED_INSTALL_UPDATED"
			}
			if sharedResult == "" {
				sharedResult = "SHARED_INSTALL_CREATED"
			}
			_ = writeSharedManifest(manifestPath, *mf)
		}
	}
	if usesSteamCmd {
		if err := steamCmdInstallError(output, steamAppID); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, err)
		}
	}

	renderedStartParams, err := renderTemplateStrict(startParams, templateValues)
	if err != nil {
		markSharedFailure(err)
		return failureResult(job.ID, err)
	}
	startScriptPath, err := writeStartScript(instanceDir, renderedStartParams)
	if err != nil {
		markSharedFailure(err)
		return failureResult(job.ID, err)
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, instanceDir, startScriptPath, "", cpuLimit, ramLimit)
	if err := os.WriteFile(unitPath, []byte(unitContent), instanceFileMode); err != nil {
		markSharedFailure(err)
		return failureResult(job.ID, fmt.Errorf("write systemd unit: %w", err))
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		markSharedFailure(err)
		return failureResult(job.ID, err)
	}
	if autostart {
		_ = runCommand("systemctl", "enable", serviceName)
	}
	if action != "install" {
		if err := validateBinaryExists(instanceDir, renderedStartParams); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, err)
		}
		if err := runCommand("systemctl", "start", serviceName); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, err)
		}
		if err := ensureServiceActive(serviceName); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, err)
		}
	}

	maskedCommand := maskSensitiveValues(renderedStartParams, templateValues)
	log.Printf("instance=%s template=%s start_command=%s start_script_path=%s", instanceID, templateKey, maskedCommand, startScriptPath)

	buildID, version := extractBuildInfo(output)
	resultOutput := map[string]string{
		"message":           "sniper " + action + " completed",
		"start_script_path": startScriptPath,
		"service_name":      serviceName,
		"cpu_limit":         strconv.Itoa(cpuLimit),
		"ram_limit":         strconv.Itoa(ramLimit),
		"autostart":         strconv.FormatBool(autostart),
	}
	if trimmed := trimOutput(output, 4000); trimmed != "" {
		resultOutput["install_log"] = trimmed
	}
	if buildID != "" {
		resultOutput["build_id"] = buildID
	}
	if version != "" {
		resultOutput["version"] = version
	}
	if sharedResult != "" {
		resultOutput["shared_result"] = sharedResult
		resultOutput["shared_key"] = sharedKey
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    resultOutput,
		Completed: time.Now().UTC(),
	}, nil
}

func prepareSharedStoragePermissions(baseDir, sharedKey, osUsername string) (string, error) {
	sharedRoot := filepath.Join(baseDir, "Shared")
	sharedKeyRoot := sharedRootFor(baseDir, sharedKey)
	sharedServer := sharedServerDir(baseDir, sharedKey)
	sharedSteam := filepath.Join(sharedServer, ".steam")
	locksDir := filepath.Join(sharedRoot, ".locks")
	for _, dir := range []string{
		sharedRoot,
		sharedKeyRoot,
		sharedServer,
		sharedSteam,
		filepath.Join(sharedSteam, "sdk32"),
		filepath.Join(sharedSteam, "sdk64"),
		filepath.Join(sharedServer, ".steamcmd"),
		locksDir,
	} {
		if err := os.MkdirAll(dir, instanceDirMode); err != nil {
			return "", fmt.Errorf("prepare shared dir %s: %w", dir, err)
		}
		if err := os.Chmod(dir, 0o755); err != nil {
			return "", fmt.Errorf("chmod shared dir %s: %w", dir, err)
		}
	}
	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return "", fmt.Errorf("lookup shared owner %s: %w", osUsername, err)
	}
	if err := filepath.WalkDir(sharedKeyRoot, func(path string, d os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		return chownRecursiveFn(path, uid, gid)
	}); err != nil {
		return "", fmt.Errorf("chown shared root %s: %w", sharedKeyRoot, err)
	}
	return sharedServer, nil
}

func stripWineBootstrap(command string) string {
	trimmed := strings.TrimSpace(command)
	if trimmed == "" {
		return command
	}
	stripped := wineBootstrapRegex.ReplaceAllString(trimmed, "")
	if stripped == trimmed {
		candidate := strings.ReplaceAll(trimmed, `\"`, `"`)
		marker := "if ! command -v wine"
		if idx := strings.Index(strings.ToLower(candidate), marker); idx >= 0 {
			prefixStart := idx
			if b := strings.LastIndex(candidate[:idx], "bash -lc"); b >= 0 {
				prefixStart = b
			}
			if end := strings.Index(strings.ToLower(candidate[idx:]), "fi; fi;"); end >= 0 {
				endPos := idx + end + len("fi; fi;")
				candidate = candidate[:prefixStart] + candidate[endPos:]
			}
		}
		stripped = candidate
	}
	if strings.HasSuffix(stripped, "\"") && strings.Count(trimmed, "\"")%2 == 1 {
		stripped = strings.TrimSuffix(stripped, "\"")
	}
	if strings.HasSuffix(stripped, "'") && strings.Count(trimmed, "'")%2 == 1 {
		stripped = strings.TrimSuffix(stripped, "'")
	}
	if stripped == "" {
		return trimmed
	}
	if strings.Contains(strings.ToLower(trimmed), "bash -lc") {
		quoteCount := strings.Count(stripped, `"`)
		if quoteCount%2 == 1 {
			stripped = strings.TrimSuffix(stripped, `"`)
		}
	}
	return stripped
}

func buildSniperInstallShellCommand(commandWorkDir, installCommand, installSnippet, postInstallSnippet string) string {
	return fmt.Sprintf(
		"export HOME=%[1]s; export XDG_DATA_HOME=%[1]s/.local/share; "+
			"mkdir -p %[1]s/.steam %[1]s/.local/share; "+
			"%[3]s"+
			"cd %[1]s && %[2]s; "+
			"%[4]s",
		commandWorkDir, installCommand, installSnippet, postInstallSnippet,
	)
}

func buildSteamCmdCommand(steamCmdPath, instanceDir, steamAppID string, validate bool) string {
	if steamAppID == "" {
		return ""
	}
	parts := []string{
		steamCmdPath,
		"+force_install_dir", instanceDir,
		"+login", "anonymous",
		"+app_update", steamAppID,
	}
	if validate {
		parts = append(parts, "validate")
	}
	parts = append(parts, "+quit")
	return strings.Join(parts, " ")
}

func instanceDirSteamCmdDir(instanceDir string) string {
	return filepath.Join(instanceDir, ".steamcmd")
}

func steamCmdInstallSnippet(steamCmdDir string) string {
	escapedDir := strings.ReplaceAll(steamCmdDir, "$", "$$")
	return fmt.Sprintf(
		"mkdir -p %[1]s; "+
			"if [ ! -x %[1]s/steamcmd.sh ] && [ ! -x %[1]s/linux64/steamcmd ] && [ ! -x %[1]s/linux32/steamcmd ]; then "+
			"archive=%[1]s/steamcmd_linux.tar.gz; "+
			"if command -v curl >/dev/null 2>&1; then "+
			"curl -fsSL %[2]q -o $archive; "+
			"elif command -v wget >/dev/null 2>&1; then "+
			"wget -qO $archive %[2]q; "+
			"else "+
			"echo \"steamcmd download failed: missing curl or wget\" >&2; exit 1; "+
			"fi; "+
			"tar -xzf $archive -C %[1]s; "+
			"rm -f $archive; "+
			"fi; "+
			"if [ -x %[1]s/steamcmd.sh ]; then "+
			"STEAMCMD_EXEC=%[1]s/steamcmd.sh; "+
			"elif [ -x %[1]s/linux64/steamcmd ]; then "+
			"STEAMCMD_EXEC=%[1]s/linux64/steamcmd; "+
			"elif [ -x %[1]s/linux32/steamcmd ]; then "+
			"STEAMCMD_EXEC=%[1]s/linux32/steamcmd; "+
			"else "+
			"echo \"steamcmd executable not found\" >&2; exit 1; "+
			"fi; "+
			"export STEAMCMD_EXEC; ",
		escapedDir,
		steamcmdArchiveURL,
	)
}

func steamCmdClientSnippet(steamCmdDir, instanceDir string) string {
	escapedDir := strings.ReplaceAll(steamCmdDir, "$", "$$")
	escapedInstance := strings.ReplaceAll(instanceDir, "$", "$$")
	return fmt.Sprintf(
		"if [ -d %[1]s ]; then "+
			"mkdir -p %[2]s/.steam/sdk32 %[2]s/.steam/sdk64; "+
			"if [ -f %[1]s/linux32/steamclient.so ]; then "+
			"cp -f %[1]s/linux32/steamclient.so %[2]s/.steam/sdk32/steamclient.so; "+
			"fi; "+
			"if [ -f %[1]s/linux64/steamclient.so ]; then "+
			"cp -f %[1]s/linux64/steamclient.so %[2]s/.steam/sdk64/steamclient.so; "+
			"fi; "+
			"fi; ",
		escapedDir,
		escapedInstance,
	)
}

func replaceSteamCmdExecutable(command, steamCmdPath string) string {
	if command == "" {
		return ""
	}
	escapedPath := strings.ReplaceAll(steamCmdPath, "$", "$$")
	return steamcmdCommandRegex.ReplaceAllString(command, "${1}"+escapedPath+"${3}")
}

func normalizeSteamCmdInstallDir(command, instanceDir string) string {
	if command == "" || instanceDir == "" {
		return command
	}

	normalized := strings.ReplaceAll(command, "{{INSTANCE_DIR}}", instanceDir)
	normalized = strings.ReplaceAll(normalized, "{{INSTALL_DIR}}", instanceDir)

	escapedDir := strings.ReplaceAll(instanceDir, "$", "$$")
	if forceInstallDirPresenceRegex.MatchString(normalized) {
		normalized = forceInstallDirRegex.ReplaceAllString(normalized, "${1}"+escapedDir)
	}
	if !forceInstallDirPresenceRegex.MatchString(normalized) && steamcmdInjectRegex.MatchString(normalized) {
		normalized = steamcmdInjectRegex.ReplaceAllString(normalized, "${1}${2} +force_install_dir "+escapedDir+"${3}")
	}
	return normalized
}

func shouldRetrySteamCmd(output string, steamAppID string) bool {
	if output == "" {
		return false
	}
	lower := strings.ToLower(output)
	if !strings.Contains(lower, "update complete") {
		return false
	}
	if steamCmdInstallSucceeded(output, steamAppID) || strings.Contains(lower, "fully installed") {
		return false
	}
	return true
}

func steamCmdInstallSucceeded(output string, steamAppID string) bool {
	if output == "" {
		return false
	}
	if steamAppID != "" {
		appPattern := regexp.MustCompile(fmt.Sprintf(`(?i)success!\s+app\s+'?%s'?`, regexp.QuoteMeta(steamAppID)))
		return appPattern.MatchString(output)
	}
	return strings.Contains(strings.ToLower(output), "success! app")
}

func steamCmdInstallFailedLine(output string) string {
	if output == "" {
		return ""
	}
	for _, line := range strings.Split(output, "\n") {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" {
			continue
		}
		if strings.Contains(strings.ToLower(trimmed), "error! failed to install app") {
			return trimmed
		}
	}
	return ""
}

func steamCmdInstallError(output, steamAppID string) error {
	if steamCmdInstallSucceeded(output, steamAppID) {
		return nil
	}
	if line := steamCmdInstallFailedLine(output); line != "" {
		return fmt.Errorf("%s", line)
	}
	return fmt.Errorf("steamcmd finished without app install confirmation")
}

func extractBuildInfo(output string) (string, string) {
	trimmed := strings.TrimSpace(output)
	if trimmed == "" {
		return "", ""
	}

	lines := strings.Split(trimmed, "\n")
	for i := len(lines) - 1; i >= 0; i-- {
		line := strings.TrimSpace(lines[i])
		if line == "" {
			continue
		}
		if jsonLineRegex.MatchString(line) {
			match := jsonLineRegex.FindString(line)
			var data map[string]any
			if err := json.Unmarshal([]byte(match), &data); err == nil {
				buildID, _ := data["build_id"].(string)
				version, _ := data["version"].(string)
				return buildID, version
			}
		}
		break
	}

	buildID := ""
	version := ""

	if match := buildIDRegex.FindStringSubmatch(output); len(match) > 1 {
		buildID = match[1]
	}

	if match := versionRegex.FindStringSubmatch(output); len(match) > 1 {
		version = match[1]
	}

	return buildID, version
}
