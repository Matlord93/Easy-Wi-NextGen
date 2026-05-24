package main

import (
	"crypto/sha256"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var (
	buildIDRegex                     = regexp.MustCompile(`(?i)build(?:[_\s-]?id)?[:\s]+([0-9]+)`)
	versionRegex                     = regexp.MustCompile(`(?i)version[:\s]+([0-9a-zA-Z._-]+)`)
	jsonLineRegex                    = regexp.MustCompile(`\{.*\}`)
	forceInstallDirRegex             = regexp.MustCompile(`(?i)(\+force_install_dir\s+)(\"[^\"]+\"|'[^']+'|\S+)`)
	forceInstallDirPresenceRegex     = regexp.MustCompile(`(?i)\+force_install_dir\b`)
	steamcmdInjectRegex              = regexp.MustCompile(`(?i)(^|\s)([^\s]*steamcmd(?:\.sh|\.exe)?)(\s)`)
	steamcmdCommandRegex             = regexp.MustCompile(`(^|\s)(/var/lib/easywi/game/steamcmd/steamcmd\.sh|/usr/local/bin/steamcmd|steamcmd)(\s|$)`)
	steamcmdArchiveURL               = "https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz"
	wineBootstrapRegex               = regexp.MustCompile(`(?is)^\s*(?:bash\s+-lc\s+)?["']?\s*set\s+-e\s*;\s*if\s+!\s+command\s+-v\s+wine\b.*?\bfi\s*;\s*`)
	ansiControlRegex                 = regexp.MustCompile(`\x1b(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])`)
	steamLoginRegex                  = regexp.MustCompile(`(?i)\+login\s+("[^"]+"|'[^']+'|\S+)`)
	steamAppUpdateRegex              = regexp.MustCompile(`(?i)\+app_update\s+([0-9]+)`)
	steamCmdSuccessRegex             = regexp.MustCompile(`(?im)success!\s*app\s*['"]?([0-9]+)['"]?\s*(fully installed|already up to date)\.?\s*$`)
	chownRecursiveFn                 = func(path string, uid, gid int) error { return os.Chown(path, uid, gid) }
	ensureSharedGroupAndMembershipFn = ensureSharedGroupAndMembership
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
	renderedCommand = stripWineBootstrap(renderedCommand)
	renderedCommand = normalizeSteamCmdInstallDir(renderedCommand, sharedServer)

	usesSteamCmd := steamcmdCommandRegex.MatchString(renderedCommand)
	steamCmdExecPath := ""
	if usesSteamCmd {
		steamCmdExecPath = resolveSteamCmdExecPath(instanceDirSteamCmdDir(sharedServer))
		if steamCmdExecPath == "" {
			steamCmdExecPath = "$STEAMCMD_EXEC"
		}
		renderedCommand = replaceSteamCmdExecutable(renderedCommand, steamCmdExecPath)
		if err := validateSteamCmdCommand(renderedCommand); err != nil {
			_ = markSharedManifestFailed(manifestPath, err)
			if logSender != nil {
				logSender.Send(job.ID, []string{"shared update failed", fmt.Sprintf("reason=%s", err.Error()), "Job failed"}, nil)
			}
			return failureResult(job.ID, err)
		}
	}
	if logSender != nil {
		logSender.Send(job.ID, []string{
			fmt.Sprintf("shared_key=%s", sharedKey),
			fmt.Sprintf("shared_group=%s", sharedGroupName(sharedKey)),
			fmt.Sprintf("shared_server=%s", sharedServer),
			fmt.Sprintf("run_as_user=%s", osUsername),
		}, nil)
	}
	if _, prepErr := prepareSharedStoragePermissions(baseDir, sharedKey, osUsername); prepErr != nil {
		err := fmt.Errorf("shared_permissions_invalid: %w", prepErr)
		_ = markSharedManifestFailed(manifestPath, err)
		if logSender != nil {
			logSender.Send(job.ID, []string{"shared update failed", "reason=shared_permissions_invalid", "Job failed"}, nil)
		}
		return failureResult(job.ID, err)
	}
	installSnippet := ""
	postInstallSnippet := ""
	if usesSteamCmd {
		steamCmdDir := instanceDirSteamCmdDir(sharedServer)
		installSnippet = steamCmdInstallSnippet(steamCmdDir)
		postInstallSnippet = steamCmdClientSnippet(steamCmdDir, sharedServer)
	}
	shellCmd := buildSniperInstallShellCommand(sharedServer, renderedCommand, installSnippet, postInstallSnippet)
	runScriptPath := ""
	if usesSteamCmd {
		login := "anonymous"
		if m := steamLoginRegex.FindStringSubmatch(renderedCommand); len(m) > 1 {
			login = strings.Trim(strings.TrimSpace(m[1]), `"'`)
		}
		appID := strings.TrimSpace(payloadValue(job.Payload, "steam_app_id"))
		if m := steamAppUpdateRegex.FindStringSubmatch(renderedCommand); appID == "" && len(m) > 1 {
			appID = strings.TrimSpace(m[1])
		}
		runScriptPath = filepath.Join(sharedServer, ".update", fmt.Sprintf("shared_update_%s.txt", sanitizeJobToken(job.ID)))
		if err := writeSteamCmdRunScript(runScriptPath, sharedServer, login, appID, osUsername); err != nil {
			_ = markSharedManifestFailed(manifestPath, err)
			return failureResult(job.ID, fmt.Errorf("SHARED_UPDATE_FAILED: %w", err))
		}
		defer func() { _ = os.Remove(runScriptPath) }()
		shellCmd = buildSniperInstallShellCommand(sharedServer, fmt.Sprintf("%s +runscript %s", steamCmdExecPath, shellEscape(runScriptPath)), installSnippet, postInstallSnippet)
	}
	if usesSteamCmd && logSender != nil {
		logSender.Send(job.ID, []string{fmt.Sprintf("shared_update_command=%s", maskSensitiveValues(fmt.Sprintf("%s +runscript %s", steamCmdExecPath, runScriptPath), templateValues))}, nil)
		steamCmdStat, steamCmdStatErr := os.Stat(steamCmdExecPath)
		logSender.Send(job.ID, []string{
			fmt.Sprintf("steamcmd_path=%s", steamCmdExecPath),
			fmt.Sprintf("runscript_path=%s", runScriptPath),
			fmt.Sprintf("force_install_dir=%s", sharedServer),
			fmt.Sprintf("command_work_dir=%s", sharedServer),
			fmt.Sprintf("steamcmd_exists=%t", steamCmdStatErr == nil),
			fmt.Sprintf("steamcmd_executable=%t", steamCmdStatErr == nil && steamCmdStat.Mode()&0o111 != 0),
		}, nil)
		if stat, statErr := os.Stat(runScriptPath); statErr == nil && !stat.IsDir() {
			logSender.Send(job.ID, []string{"runscript_exists=true"}, nil)
			uid, gid := fileOwnerIDs(stat)
			logSender.Send(job.ID, []string{
				fmt.Sprintf("runscript_owner=%d", uid),
				fmt.Sprintf("runscript_group=%d", gid),
				fmt.Sprintf("runscript_permissions=%04o", stat.Mode().Perm()),
			}, nil)
		}
		readableByUser := isReadableByUser(osUsername, runScriptPath)
		logSender.Send(job.ID, []string{fmt.Sprintf("runscript_readable_by_user=%t", readableByUser)}, nil)
		if content, readErr := os.ReadFile(runScriptPath); readErr == nil {
			logSender.Send(job.ID, []string{"runscript_content_start", strings.TrimSpace(string(content)), "runscript_content_end"}, nil)
		}
		if !readableByUser {
			err = errors.New("runscript_not_readable")
			_ = markSharedManifestFailed(manifestPath, err)
			logSender.Send(job.ID, []string{"shared update failed", "reason=runscript_not_readable", "Job failed"}, nil)
			return failureResult(job.ID, err)
		}
		lockPath := sharedLockPath(baseDir, sharedKey)
		lockDetails, permErr := validateSharedUpdatePermissions(sharedServer, runScriptPath, steamCmdExecPath, lockPath, osUsername, sharedGroupName(sharedKey))
		logSender.Send(job.ID, []string{
			fmt.Sprintf("lock_dir=%s", lockDetails.LockDir),
			fmt.Sprintf("lock_path=%s", lockDetails.LockPath),
			fmt.Sprintf("lock_test_command=%s", lockDetails.LockTestCommand),
			fmt.Sprintf("effective_groups=%s", lockDetails.EffectiveGroups),
			fmt.Sprintf("shared_group_member=%t", lockDetails.SharedGroupMember),
			fmt.Sprintf("lock_test_exit_code=%d", lockDetails.ExitCode),
			fmt.Sprintf("lock_test_stdout=%s", lockDetails.Stdout),
			fmt.Sprintf("lock_test_stderr=%s", lockDetails.Stderr),
		}, nil)
		if permErr != nil {
			err = permErr
			_ = markSharedManifestFailed(manifestPath, err)
			logSender.Send(job.ID, []string{"shared update failed", fmt.Sprintf("reason=%s", err.Error()), "Job failed"}, nil)
			return failureResult(job.ID, err)
		}
	}
	if info, statErr := os.Stat(sharedServer); statErr != nil || !info.IsDir() {
		err = fmt.Errorf("SHARED_UPDATE_FAILED: shared server path not usable: %s", sharedServer)
		_ = markSharedManifestFailed(manifestPath, err)
		return failureResult(job.ID, err)
	}
	output, err := runCommandOutputAsUserWithLogs(osUsername, shellCmd, job.ID, logSender)
	if err != nil {
		reason := "steamcmd_command_failed"
		exitCode := "unknown"
		if strings.Contains(err.Error(), errCommandTimeout.Error()) || strings.Contains(err.Error(), errNoProgress.Error()) {
			reason = "steamcmd_no_progress_timeout"
			exitCode = "timeout"
		}
		if logSender != nil {
			logSender.Send(job.ID, []string{
				"shared update failed",
				fmt.Sprintf("reason=%s", reason),
				fmt.Sprintf("exit_code=%s", exitCode),
				fmt.Sprintf("last_output=%s", trimOutput(stripANSIString(output), 500)),
				fmt.Sprintf("shared update failed exit_error=%v run_as_user=%s steamcmd_path=%s shared_key=%s shared_server=%s", err, osUsername, steamCmdExecPath, sharedKey, sharedServer),
				"Job failed",
			}, nil)
		}
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

func sanitizeJobToken(jobID string) string {
	v := strings.Map(func(r rune) rune {
		if (r >= 'a' && r <= 'z') || (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == '_' || r == '-' {
			return r
		}
		return '_'
	}, strings.TrimSpace(jobID))
	if v == "" {
		return "unknown"
	}
	return v
}

func writeSteamCmdRunScript(path, installDir, login, appID, username string) error {
	if strings.TrimSpace(appID) == "" {
		return errors.New("missing_app_update")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return fmt.Errorf("mkdir runscript dir: %w", err)
	}
	content := fmt.Sprintf("force_install_dir %s\nlogin %s\napp_update %s\nquit\n", installDir, login, appID)
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		return err
	}
	if username != "" {
		if u, err := user.Lookup(username); err == nil {
			uid, _ := strconv.Atoi(u.Uid)
			gid, _ := strconv.Atoi(u.Gid)
			_ = os.Chown(filepath.Dir(path), uid, gid)
			_ = os.Chown(path, uid, gid)
		}
	}
	return nil
}

func isReadableByUser(username, path string) bool {
	cmd := exec.Command("runuser", "-u", username, "--", "test", "-r", path)
	return cmd.Run() == nil
}

func shellEscape(v string) string {
	return "'" + strings.ReplaceAll(v, "'", "'\"'\"'") + "'"
}

func validateSteamCmdCommand(command string) error {
	lower := strings.ToLower(command)
	if !strings.Contains(lower, "+force_install_dir") {
		return fmt.Errorf("missing_force_install_dir")
	}
	if !strings.Contains(lower, "+app_update") {
		return fmt.Errorf("missing_app_update")
	}
	if !strings.Contains(lower, "+quit") {
		return fmt.Errorf("missing_quit")
	}
	return nil
}

func resolveSteamCmdExecPath(steamCmdDir string) string {
	for _, candidate := range []string{
		filepath.Join(steamCmdDir, "steamcmd.sh"),
		filepath.Join(steamCmdDir, "linux64", "steamcmd"),
		filepath.Join(steamCmdDir, "linux32", "steamcmd"),
	} {
		if info, err := os.Stat(candidate); err == nil && !info.IsDir() {
			return candidate
		}
	}
	return ""
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
	defaultHomeDir := fmt.Sprintf("%s/%s", strings.TrimRight(baseDir, "/"), osUsername)
	payloadInstallPath := strings.TrimSpace(payloadValue(job.Payload, "install_path", "instance_dir"))
	if payloadInstallPath == "" {
		payloadInstallPath = defaultHomeDir
	}
	userHomeDir, instanceDir, err := resolveSniperUserHomeAndGameDir(payloadInstallPath, osUsername)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureGroup(osUsername); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureUser(osUsername, osUsername, userHomeDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureBaseDir(baseDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureInstanceDir(userHomeDir); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureInstanceDir(instanceDir); err != nil {
		return failureResult(job.ID, err)
	}
	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := os.Chown(userHomeDir, uid, gid); err != nil {
		return failureResult(job.ID, fmt.Errorf("chown %s: %w", userHomeDir, err))
	}
	if err := os.Chown(instanceDir, uid, gid); err != nil {
		return failureResult(job.ID, fmt.Errorf("chown %s: %w", instanceDir, err))
	}
	if err := os.Chmod(userHomeDir, instanceDirMode); err != nil {
		return failureResult(job.ID, fmt.Errorf("chmod %s: %w", userHomeDir, err))
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

	if logSender != nil && job.ID != "" {
		logSender.Send(job.ID, []string{
			fmt.Sprintf("shared_enabled=%t", sharedEnabled),
			fmt.Sprintf("shared_key=%s", sharedKey),
			fmt.Sprintf("user_home_dir=%s", userHomeDir),
			fmt.Sprintf("game_dir=%s", instanceDir),
			fmt.Sprintf("shared_server_dir=%s", installTargetDir),
			fmt.Sprintf("instance_dir=%s", instanceDir),
		}, nil)
		for i, sp := range sharedSpecs {
			logSender.Send(job.ID, []string{fmt.Sprintf("shared_paths[%d]: source=%s target=%s mode=%s exclude=%v", i, sp.Source, sp.Target, sp.Mode, sp.Exclude)}, nil)
		}
	}

	command = normalizeSteamCmdInstallDir(command, installTargetDir)
	commandWorkDir := instanceDir
	if sharedEnabled {
		commandWorkDir = installTargetDir
	}
	if logSender != nil && job.ID != "" {
		logSender.Send(job.ID, []string{fmt.Sprintf("command_work_dir=%s", commandWorkDir)}, nil)
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
		if err := applySharedPaths(instanceDir, installTargetDir, sharedSpecs); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, fmt.Errorf("INSTANCE_LINK_FAILED: %w", err))
		}
		if err := prepareSteamClientRuntimeLinks(installTargetDir, instanceDir, osUsername); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, fmt.Errorf("STEAM_RUNTIME_LINK_FAILED: %w", err))
		}
		if err := ensureCS2StartScript(instanceDir, installTargetDir, osUsername); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, fmt.Errorf("STARTSCRIPT_MISSING: %w", err))
		}
		if err := validateSharedInstanceLayout(instanceDir, installTargetDir, sharedSpecs); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, fmt.Errorf("SHARED_INSTANCE_PREPARE_FAILED: %w", err))
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
		steamCmdSuccess := steamCmdInstallSucceeded(output, steamAppID)
		steamCmdReason := "missing_success_confirmation"
		if steamCmdSuccess {
			steamCmdReason = "success_message_detected"
		}
		log.Printf("steamcmd_exit_code=0 steamcmd_success_detected=%t steamcmd_success_reason=%s", steamCmdSuccess, steamCmdReason)
		if !steamCmdInstallSucceeded(output, steamAppID) && !steamCmdHasRealError(output) && logSender != nil && job.ID != "" {
			logSender.Send(job.ID, []string{"STEAMCMD_INSTALL_CONFIRMATION_MISSING_BUT_EXIT_ZERO"}, nil)
		}
		if err := steamCmdInstallError(output, steamAppID); err != nil {
			markSharedFailure(err)
			return failureResult(job.ID, fmt.Errorf("steamcmd_failed: %w", err))
		}
	}

	renderedStartParams, err := renderTemplateStrict(startParams, templateValues)
	if err != nil {
		markSharedFailure(err)
		return failureResult(job.ID, err)
	}
	renderedStartParams = normalizeRenderedStartCommand(renderedStartParams, instanceDir)
	resolvedScriptPath, scriptExists, scriptExecutable, scriptErr := validateGameScriptPath(instanceDir, renderedStartParams)
	log.Printf("game_dir=%s resolved_script_path=%s script_exists=%t script_executable=%t shared_enabled=%t shared_key=%s", instanceDir, resolvedScriptPath, scriptExists, scriptExecutable, sharedEnabled, sharedKey)
	if scriptErr != nil {
		markSharedFailure(scriptErr)
		return failureResult(job.ID, scriptErr)
	}
	startScriptPath, err := writeStartScript(userHomeDir, renderedStartParams)
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
			log.Printf("service_health_status=unhealthy service_name=%s", serviceName)
			return failureResult(job.ID, fmt.Errorf("service_unhealthy_after_update: %w", err))
		}
		log.Printf("service_health_status=healthy service_name=%s", serviceName)
	}

	if err := chownInstanceTreeNoFollow(instanceDir, osUsername); err != nil {
		markSharedFailure(err)
		return failureResult(job.ID, err)
	}
	if err := chownInstanceTreeNoFollow(userHomeDir, osUsername); err != nil {
		markSharedFailure(err)
		return failureResult(job.ID, err)
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


func resolveGameScriptPath(gameDir string) string {
	return filepath.Clean(filepath.Join(gameDir, "cs2.sh"))
}

func normalizeRenderedStartCommand(startCommand, gameDir string) string {
	if strings.TrimSpace(startCommand) == "" {
		return startCommand
	}
	resolved := resolveGameScriptPath(gameDir)
	badPath := filepath.Clean(filepath.Join(gameDir, "game", "cs2.sh"))
	if badPath != resolved {
		startCommand = strings.ReplaceAll(startCommand, badPath, resolved)
	}
	return strings.ReplaceAll(startCommand, "//", "/")
}

func validateGameScriptPath(gameDir, startCommand string) (string, bool, bool, error) {
	resolved := resolveGameScriptPath(gameDir)
	cleanResolved := filepath.Clean(resolved)
	if strings.Contains(cleanResolved, string(os.PathSeparator)+"game"+string(os.PathSeparator)+"game"+string(os.PathSeparator)) || strings.Contains(cleanResolved, string(os.PathSeparator)+"server"+string(os.PathSeparator)+"server"+string(os.PathSeparator)) {
		return cleanResolved, false, false, fmt.Errorf("invalid_game_script_path resolved_path=%s", cleanResolved)
	}
	if !strings.Contains(startCommand, cleanResolved) {
		return cleanResolved, false, false, fmt.Errorf("invalid_game_script_path resolved_path=%s", cleanResolved)
	}
	st, err := os.Stat(cleanResolved)
	if err != nil {
		if os.IsNotExist(err) {
			return cleanResolved, false, false, fmt.Errorf("invalid_game_script_path resolved_path=%s", cleanResolved)
		}
		return cleanResolved, false, false, err
	}
	execOk := st.Mode()&0o111 != 0
	if !execOk {
		return cleanResolved, true, false, fmt.Errorf("invalid_game_script_path resolved_path=%s", cleanResolved)
	}
	return cleanResolved, true, true, nil
}
func resolveSniperUserHomeAndGameDir(payloadInstallPath string, osUsername string) (string, string, error) {
	installPath := filepath.Clean(strings.TrimSpace(payloadInstallPath))
	if installPath == "" || !filepath.IsAbs(installPath) {
		return "", "", fmt.Errorf("invalid install_path")
	}
	userHome := filepath.Clean(filepath.Join("/home", osUsername))
	gameDir := ""
	if strings.EqualFold(filepath.Base(installPath), "game") {
		userHome = filepath.Dir(installPath)
		gameDir = installPath
	} else if installPath == userHome {
		gameDir = filepath.Join(installPath, "game")
	} else {
		gameDir = filepath.Join(installPath, "game")
	}
	rel, err := filepath.Rel(userHome, gameDir)
	if err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(os.PathSeparator)) {
		return "", "", fmt.Errorf("game dir must be inside user home")
	}
	return userHome, gameDir, nil
}

func ensureCS2StartScript(gameDir, sharedServer, osUsername string) error {
	src := filepath.Join(sharedServer, "game", "cs2.sh")
	if _, err := os.Stat(src); err != nil {
		if os.IsNotExist(err) {
			return fmt.Errorf("missing %s", src)
		}
		return err
	}
	dst := filepath.Join(gameDir, "cs2.sh")
	if err := copyPathRecursive(src, dst); err != nil {
		return err
	}
	if err := os.Chmod(dst, 0o755); err != nil {
		return err
	}
	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err == nil {
		_ = os.Chown(dst, uid, gid)
	}
	return nil
}

func validateSharedInstanceLayout(gameDir, sharedServer string, specs []sharedPathSpec) error {
	for _, sp := range specs {
		target := filepath.Join(gameDir, sp.Target)
		if _, err := os.Lstat(target); err != nil {
			return fmt.Errorf("missing shared target %s: %w", target, err)
		}
	}
	requiredSymlinks := []string{"bin", "platform", "core", "csgo_community_addons"}
	for _, rel := range requiredSymlinks {
		p := filepath.Join(gameDir, rel)
		info, err := os.Lstat(p)
		if err != nil {
			return err
		}
		if info.Mode()&os.ModeSymlink == 0 {
			return fmt.Errorf("%s must be symlink", rel)
		}
	}
	if info, err := os.Stat(filepath.Join(gameDir, "csgo")); err != nil || !info.IsDir() {
		return fmt.Errorf("csgo must be a directory")
	}
	binExe := filepath.Join(gameDir, "bin", "linuxsteamrt64", "cs2")
	if info, err := os.Stat(binExe); err != nil || info.Mode()&0o111 == 0 {
		return fmt.Errorf("CS2_BINARY_MISSING: %s", binExe)
	}
	cs2 := filepath.Join(gameDir, "cs2.sh")
	if info, err := os.Stat(cs2); err != nil || info.Mode()&0o111 == 0 {
		return fmt.Errorf("STARTSCRIPT_MISSING: %s", cs2)
	}
	sdk64 := filepath.Join(gameDir, ".steam", "sdk64", "steamclient.so")
	if _, err := os.Stat(sdk64); err != nil {
		return fmt.Errorf("STEAMCLIENT_SDK64_MISSING: %s", sdk64)
	}
	sdk32Shared, _ := resolveSteamClientSource(sharedServer, "32")
	if sdk32Shared != "" {
		sdk32 := filepath.Join(gameDir, ".steam", "sdk32", "steamclient.so")
		if _, err := os.Stat(sdk32); err != nil {
			return fmt.Errorf("STEAMCLIENT_SDK32_MISSING: %s", sdk32)
		}
	}
	_ = sharedServer
	return nil
}

func resolveSteamClientSource(sharedServer, arch string) (string, bool) {
	var paths []string
	if arch == "64" {
		paths = []string{".steam/sdk64/steamclient.so", "Steam/linux64/steamclient.so", "linux64/steamclient.so"}
	} else {
		paths = []string{".steam/sdk32/steamclient.so", "Steam/linux32/steamclient.so", "linux32/steamclient.so"}
	}
	for _, rel := range paths {
		p := filepath.Join(sharedServer, rel)
		if st, err := os.Stat(p); err == nil && !st.IsDir() {
			return p, true
		}
	}
	return "", false
}

func prepareSteamClientRuntimeLinks(sharedServerDir, gameDir, osUsername string) error {
	sdk64Source, ok := resolveSteamClientSource(sharedServerDir, "64")
	if !ok {
		return fmt.Errorf("STEAMCLIENT_SDK64_MISSING: no shared source in %s", sharedServerDir)
	}
	sdk32Source, has32 := resolveSteamClientSource(sharedServerDir, "32")
	for _, rel := range []string{".steam", ".steam/sdk64", ".steam/sdk32"} {
		if err := os.MkdirAll(filepath.Join(gameDir, rel), instanceDirMode); err != nil {
			return err
		}
	}
	linkTargets := map[string]string{filepath.Join(gameDir, ".steam", "sdk64", "steamclient.so"): sdk64Source}
	if has32 {
		linkTargets[filepath.Join(gameDir, ".steam", "sdk32", "steamclient.so")] = sdk32Source
	}
	uid, gid, idErr := lookupIDs(osUsername, osUsername)
	for dst, src := range linkTargets {
		_ = os.RemoveAll(dst)
		if err := os.Symlink(src, dst); err != nil {
			return err
		}
		if idErr == nil {
			_ = osLchownFn(dst, uid, gid)
		}
	}
	return nil
}

func prepareSharedStoragePermissions(baseDir, sharedKey, osUsername string) (string, error) {
	sharedRoot := filepath.Join(baseDir, "Shared")
	sharedKeyRoot := sharedRootFor(baseDir, sharedKey)
	sharedServer := sharedServerDir(baseDir, sharedKey)
	sharedSteam := filepath.Join(sharedServer, ".steam")
	locksDir := filepath.Join(sharedRoot, ".locks")
	sharedGroup := sharedGroupName(sharedKey)
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
		if err := os.Chmod(dir, 0o2775); err != nil {
			return "", fmt.Errorf("chmod shared dir %s: %w", dir, err)
		}
	}
	if err := ensureSharedGroupAndMembershipFn(sharedGroup, osUsername); err != nil {
		return "", err
	}
	if uid, gid, err := lookupIDs(osUsername, osUsername); err == nil {
		_ = chownRecursiveFn(sharedKeyRoot, uid, gid)
	}
	_ = runCommand("chgrp", "-R", sharedGroup, sharedKeyRoot)
	_ = runCommand("chmod", "g+s", sharedKeyRoot, sharedServer, filepath.Join(sharedServer, ".steamcmd"), filepath.Join(sharedServer, ".update"), locksDir)
	_ = runCommand("setfacl", "-R", "-m", "g:"+sharedGroup+":rwx", sharedServer)
	_ = runCommand("setfacl", "-R", "-d", "-m", "g:"+sharedGroup+":rwx", sharedServer)
	_ = filepath.WalkDir(sharedKeyRoot, func(path string, d os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return nil
		}
		if d.IsDir() {
			_ = os.Chmod(path, 0o2775)
		} else {
			// Preserve execute bit on files that already have it (e.g. steamcmd.sh).
			// chmod also updates the ACL mask, so stripping the execute bit here
			// would block group execute access even when setfacl grants rwx.
			info, infoErr := d.Info()
			if infoErr == nil && info.Mode()&0o111 != 0 {
				_ = os.Chmod(path, 0o775)
			} else {
				_ = os.Chmod(path, 0o664)
			}
		}
		return nil
	})
	return sharedServer, nil
}

func sharedGroupName(sharedKey string) string {
	return "sharedsrv_" + sanitizeJobToken(sharedKey)
}

func ensureSharedGroupAndMembership(groupName, username string) error {
	if strings.TrimSpace(groupName) == "" || strings.TrimSpace(username) == "" {
		return errors.New("shared group or user missing")
	}
	_ = exec.Command("getent", "group", groupName).Run()
	if err := runCommand("groupadd", "-f", groupName); err != nil {
		return fmt.Errorf("group setup failed: %w", err)
	}
	if err := runCommand("usermod", "-a", "-G", groupName, username); err != nil {
		return fmt.Errorf("group membership setup failed: %w", err)
	}
	return nil
}

type sharedLockValidationDetails struct {
	LockDir           string
	LockPath          string
	LockTestCommand   string
	EffectiveGroups   string
	SharedGroup       string
	SharedGroupMember bool
	ExitCode          int
	Stdout            string
	Stderr            string
}

func validateSharedUpdatePermissions(sharedServer, runScriptPath, steamCmdExecPath, lockPath, username, sharedGroup string) (sharedLockValidationDetails, error) {
	details := sharedLockValidationDetails{
		LockDir:         filepath.Join(sharedServer, ".locks"),
		LockPath:        lockPath,
		SharedGroup:     sharedGroup,
		ExitCode:        -1,
		LockTestCommand: "test -d <lock_dir> && test -w <lock_dir> && touch <lock_path>.write_test && rm -f <lock_path>.write_test",
	}
	checks := [][]string{
		{"test", "-x", sharedServer},
		{"test", "-w", sharedServer},
		{"test", "-r", runScriptPath},
		{"test", "-x", steamCmdExecPath},
	}
	groupCmd := exec.Command("runuser", "-u", username, "--", "id", "-Gn")
	groupOut, groupErr := groupCmd.CombinedOutput()
	details.EffectiveGroups = strings.TrimSpace(string(groupOut))
	if groupErr != nil {
		details.Stderr = strings.TrimSpace(string(groupOut))
		if exitErr, ok := groupErr.(*exec.ExitError); ok {
			details.ExitCode = exitErr.ExitCode()
		}
		return details, fmt.Errorf("lock_command_invalid: resolve effective groups failed: %w", groupErr)
	}
	details.SharedGroupMember = strings.Contains(" "+details.EffectiveGroups+" ", " "+sharedGroup+" ")
	if !details.SharedGroupMember {
		return details, fmt.Errorf("not_member_of_shared_group: expected=%s effective_groups=%s", sharedGroup, details.EffectiveGroups)
	}
	for _, args := range checks {
		cmd := exec.Command("runuser", append([]string{"-u", username, "--"}, args...)...)
		if err := cmd.Run(); err != nil {
			return details, fmt.Errorf("permission check failed for %v: %w", args, err)
		}
	}
	if details.LockDir == "" {
		return details, errors.New("lock_command_invalid")
	}
	if err := os.MkdirAll(details.LockDir, 0o2775); err != nil {
		return details, fmt.Errorf("lock_dir_missing: %w", err)
	}
	lockProbe := details.LockPath + ".write_test"
	details.LockTestCommand = fmt.Sprintf("test -d %s && test -w %s && touch %s && rm -f %s", shellEscape(details.LockDir), shellEscape(details.LockDir), shellEscape(lockProbe), shellEscape(lockProbe))


	dirCmd := exec.Command("runuser", "-u", username, "--", "test", "-d", details.LockDir)
	if out, err := dirCmd.CombinedOutput(); err != nil {
		details.Stderr = strings.TrimSpace(string(out))
		if exitErr, ok := err.(*exec.ExitError); ok {
			details.ExitCode = exitErr.ExitCode()
		}
		return details, fmt.Errorf("lock_dir_missing: %w", err)
	}
	wCmd := exec.Command("runuser", "-u", username, "--", "test", "-w", details.LockDir)
	if out, err := wCmd.CombinedOutput(); err != nil {
		details.Stderr = strings.TrimSpace(string(out))
		if exitErr, ok := err.(*exec.ExitError); ok {
			details.ExitCode = exitErr.ExitCode()
		}
		return details, fmt.Errorf("lock_dir_not_writable: %w", err)
	}
	touchCmd := exec.Command("runuser", "-u", username, "--", "touch", lockProbe)
	if out, err := touchCmd.CombinedOutput(); err != nil {
		details.Stdout = strings.TrimSpace(string(out))
		details.Stderr = strings.TrimSpace(string(out))
		if exitErr, ok := err.(*exec.ExitError); ok {
			details.ExitCode = exitErr.ExitCode()
		}
		return details, fmt.Errorf("lock_test_failed: %w", err)
	}
	rmCmd := exec.Command("runuser", "-u", username, "--", "rm", "-f", lockProbe)
	if out, err := rmCmd.CombinedOutput(); err != nil {
		details.Stdout = strings.TrimSpace(string(out))
		details.Stderr = strings.TrimSpace(string(out))
		if exitErr, ok := err.(*exec.ExitError); ok {
			details.ExitCode = exitErr.ExitCode()
		}
		return details, fmt.Errorf("lock_test_failed: cleanup failed: %w", err)
	}
	details.ExitCode = 0
	return details, nil
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
	clean := stripANSIString(output)
	if clean == "" {
		return false
	}
	lower := strings.ToLower(clean)
	if !strings.Contains(lower, "update complete") {
		return false
	}
	if steamCmdInstallSucceeded(clean, steamAppID) || strings.Contains(lower, "fully installed") {
		return false
	}
	return true
}

func stripANSIString(output string) string {
	return ansiControlRegex.ReplaceAllString(output, "")
}

func steamCmdInstallSucceeded(output string, steamAppID string) bool {
	clean := stripANSIString(output)
	if clean == "" {
		return false
	}
	matches := steamCmdSuccessRegex.FindAllStringSubmatch(clean, -1)
	if len(matches) > 0 {
		if steamAppID == "" {
			return true
		}
		for _, match := range matches {
			if len(match) > 1 && strings.TrimSpace(match[1]) == strings.TrimSpace(steamAppID) {
				return true
			}
		}
	}
	return false
}

func steamCmdHasRealError(output string) bool {
	lower := strings.ToLower(stripANSIString(output))
	errorMarkers := []string{
		"error!",
		"failed",
		"no subscription",
		"invalid platform",
		"disk write failure",
		"content file locked",
		"missing file privileges",
		"timeout",
		"state is 0x602",
	}
	for _, marker := range errorMarkers {
		if strings.Contains(lower, marker) {
			return true
		}
	}
	return false
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
	clean := stripANSIString(output)
	if steamCmdInstallSucceeded(clean, steamAppID) {
		return nil
	}
	if line := steamCmdInstallFailedLine(clean); line != "" {
		return fmt.Errorf("%s", line)
	}
	if steamCmdHasRealError(clean) {
		return fmt.Errorf("steamcmd finished with detected error markers")
	}
	log.Printf("STEAMCMD_INSTALL_CONFIRMATION_MISSING_BUT_EXIT_ZERO")
	return fmt.Errorf("steamcmd_failed: missing success confirmation")
}

func extractBuildInfo(output string) (string, string) {
	clean := stripANSIString(output)
	trimmed := strings.TrimSpace(clean)
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

	if match := buildIDRegex.FindStringSubmatch(clean); len(match) > 1 {
		buildID = match[1]
	}

	if match := versionRegex.FindStringSubmatch(clean); len(match) > 1 {
		version = match[1]
	}

	return buildID, version
}
