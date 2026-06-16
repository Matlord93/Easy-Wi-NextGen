package main

import (
	"crypto/rand"
	"crypto/rsa"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net"
	"net/url"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

type orchestratorResult struct {
	status        string
	logText       string
	errorText     string
	resultPayload map[string]any
}

type ts6ConfigOptions struct {
	licenseAccepted      bool
	voiceIP              []string
	defaultVoicePort     int
	filetransferPort     int
	filetransferIP       []string
	queryBindIP          string
	queryHttpEnable      bool
	queryHttpPort        int
	queryHttpsEnable     bool
	queryHttpsPort       int
	queryAdminPass       string
	workingDirectory     string
	httpsCertificatePath string
	httpsPrivateKeyPath  string
}

var teamspeakVersionRegex = regexp.MustCompile(`\d+\.\d+(?:\.\d+)*(?:[A-Za-z])?(?:-[0-9A-Za-z]+(?:\.[0-9A-Za-z]+)*)?`)

const defaultSinusbotTs3ClientURL = "https://files.teamspeak-services.com/releases/client/3.6.2/TeamSpeak3-Client-linux_amd64-3.6.2.run"

func handleOrchestratorJob(job jobs.Job) orchestratorResult {
	switch job.Type {
	case "ts3.install":
		return handleTs3NodeInstall(job)
	case "ts3.service.action":
		return handleServiceAction(job)
	case "ts3.status":
		return handleServiceStatus(job)
	case "ts3.instance.create":
		result, afterSubmit := handleTs3Create(job, nil)
		return convertJobResult(result, afterSubmit)
	case "ts3.instance.action":
		return handleTs3InstanceAction(job)
	case "ts6.install":
		return handleTs6NodeInstall(job)
	case "ts6.service.action":
		return handleServiceAction(job)
	case "ts6.status":
		return handleServiceStatus(job)
	case "ts6.instance.create":
		return handleTs6InstanceCreate(job)
	case "ts6.instance.action":
		return handleTs6InstanceAction(job)
	case "sinusbot.install":
		return handleSinusbotInstall(job)
	case "sinusbot.service.action":
		return handleServiceAction(job)
	case "sinusbot.status":
		return handleServiceStatus(job)
	case "ts3.virtual.create":
		return handleTs3VirtualCreate(job)
	case "ts3.virtual.action":
		return handleTs3VirtualAction(job)
	case "ts3.virtual.token.rotate":
		return handleTs3VirtualTokenRotate(job)
	case "ts6.virtual.create", "ts6.virtual.action", "ts6.virtual.token.rotate":
		switch job.Type {
		case "ts6.virtual.create":
			return handleTs6VirtualCreate(job)
		case "ts6.virtual.action":
			return handleTs6VirtualAction(job)
		case "ts6.virtual.token.rotate":
			return handleTs6VirtualTokenRotate(job)
		default:
			return orchestratorResult{
				status:    "failed",
				errorText: fmt.Sprintf("unsupported job type: %s", job.Type),
			}
		}
	case "ts6.virtual.list":
		return handleTs6VirtualList(job)
	case "ts3.virtual.list":
		return handleTs3VirtualList(job)
	case "ts3.virtual.servergroup.list":
		return handleTs3ServerGroupList(job)
	case "ts6.virtual.servergroup.list":
		return handleTs6ServerGroupList(job)
	case "ts3.virtual.summary":
		return handleTs3VirtualSummary(job)
	case "ts6.virtual.summary":
		return handleTs6VirtualSummary(job)
	case "ts3.virtual.ban.list":
		return handleTs3VirtualBanList(job)
	case "ts6.virtual.ban.list":
		return handleTs6VirtualBanList(job)
	case "ts3.virtual.ban.remove", "ts3.virtual.ban.delete":
		return handleTs3VirtualBanRemove(job)
	case "ts6.virtual.ban.remove", "ts6.virtual.ban.delete":
		return handleTs6VirtualBanRemove(job)
	case "ts3.virtual.channel.list":
		return handleTs3VirtualChannelList(job)
	case "ts6.virtual.channel.list":
		return handleTs6VirtualChannelList(job)
	case "ts3.virtual.client.list":
		return handleTs3VirtualClientList(job)
	case "ts6.virtual.client.list":
		return handleTs6VirtualClientList(job)
	case "ts3.virtual.client.kick":
		return handleTs3VirtualClientKick(job)
	case "ts6.virtual.client.kick":
		return handleTs6VirtualClientKick(job)
	case "ts3.virtual.client.poke":
		return handleTs3VirtualClientPoke(job)
	case "ts6.virtual.client.poke":
		return handleTs6VirtualClientPoke(job)
	case "ts3.virtual.client.ban":
		return handleTs3VirtualClientBan(job)
	case "ts6.virtual.client.ban":
		return handleTs6VirtualClientBan(job)
	case "ts3.virtual.log.view":
		return handleTs3VirtualLogView(job)
	case "ts6.virtual.log.view":
		return handleTs6VirtualLogView(job)
	case "ts3.virtual.snapshot.create":
		return handleTs3VirtualSnapshot(job)
	case "ts6.virtual.snapshot.create":
		return handleTs6VirtualSnapshot(job)
	case "ts3.virtual.snapshot.restore":
		return handleTs3VirtualSnapshotRestore(job)
	case "ts6.virtual.snapshot.restore":
		return handleTs6VirtualSnapshotRestore(job)
	case "ts3.viewer.snapshot", "ts6.viewer.snapshot":
		return handleViewerSnapshot(job)
	case "admin.ssh_key.store":
		return handleAdminSshKeyStore(job)
	default:
		return orchestratorResult{
			status:    "failed",
			errorText: fmt.Sprintf("unsupported job type: %s", job.Type),
		}
	}
}

func handleTs3InstanceAction(job jobs.Job) orchestratorResult {
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	switch action {
	case "start":
		result, afterSubmit := handleTs3Start(job)
		return convertJobResult(result, afterSubmit)
	case "stop":
		result, afterSubmit := handleTs3Stop(job)
		return convertJobResult(result, afterSubmit)
	case "restart":
		result, afterSubmit := handleTs3Restart(job)
		return convertJobResult(result, afterSubmit)
	case "update":
		result, afterSubmit := handleTs3Update(job)
		return convertJobResult(result, afterSubmit)
	case "backup":
		result, afterSubmit := handleTs3Backup(job)
		return convertJobResult(result, afterSubmit)
	case "restore":
		result, afterSubmit := handleTs3Restore(job)
		return convertJobResult(result, afterSubmit)
	case "token_reset":
		result, afterSubmit := handleTs3TokenReset(job)
		return convertJobResult(result, afterSubmit)
	case "slots":
		result, afterSubmit := handleTs3SlotsSet(job)
		return convertJobResult(result, afterSubmit)
	case "logs":
		result, afterSubmit := handleTs3LogsExport(job)
		return convertJobResult(result, afterSubmit)
	default:
		return orchestratorResult{
			status:    "failed",
			errorText: fmt.Sprintf("unsupported ts3 action: %s", action),
		}
	}
}

var allowedServiceActions = map[string]bool{
	"start": true, "stop": true, "restart": true,
	"reload": true, "enable": true, "disable": true,
}

var serviceNameRegex = regexp.MustCompile(`^[a-zA-Z0-9._@-]{1,128}$`)

func handleServiceAction(job jobs.Job) orchestratorResult {
	serviceName := payloadValue(job.Payload, "service_name")
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	if serviceName == "" || action == "" {
		return orchestratorResult{
			status:    "failed",
			errorText: "missing service_name or action",
		}
	}
	if !allowedServiceActions[action] {
		return orchestratorResult{status: "failed", errorText: "invalid action: must be start, stop, restart, reload, enable, or disable"}
	}
	if !serviceNameRegex.MatchString(serviceName) {
		return orchestratorResult{status: "failed", errorText: "invalid service_name: contains disallowed characters"}
	}

	if runtime.GOOS == "windows" {
		output, err := runCommandOutput("sc", action, serviceName)
		if err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error(), logText: trimOutput(output, 4000)}
		}
		return orchestratorResult{
			status:  "success",
			logText: trimOutput(output, 4000),
			resultPayload: map[string]any{
				"running": action != "stop",
			},
		}
	}

	if err := runCommand("systemctl", action, serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	status := action != "stop"
	return orchestratorResult{
		status:        "success",
		resultPayload: map[string]any{"running": status},
	}
}

func handleServiceStatus(job jobs.Job) orchestratorResult {
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" {
		return orchestratorResult{status: "failed", errorText: "missing service_name"}
	}
	installedVersion := resolveTeamspeakVersionForStatus(job)
	if job.Type == "sinusbot.status" {
		if version := extractVersionFromDownloadURL(payloadValue(job.Payload, "download_url")); version != "" {
			installedVersion = version
		}
	}
	if runtime.GOOS == "windows" {
		output, err := runCommandOutput("sc", "query", serviceName)
		if err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error(), logText: trimOutput(output, 4000)}
		}
		resultPayload := map[string]any{
			"running": strings.Contains(strings.ToUpper(output), "RUNNING"),
		}
		if installedVersion != "" {
			resultPayload["installed_version"] = installedVersion
		}
		return orchestratorResult{
			status:        "success",
			logText:       trimOutput(output, 4000),
			resultPayload: resultPayload,
		}
	}

	err := runCommand("systemctl", "is-active", "--quiet", serviceName)
	resultPayload := map[string]any{"running": err == nil}
	if installedVersion != "" {
		resultPayload["installed_version"] = installedVersion
	}
	if job.Type == "sinusbot.status" {
		ts3Info := resolveSinusbotTs3ClientStatus(job)
		if len(ts3Info) > 0 {
			resultPayload["dependencies"] = ts3Info
		}
	}
	return orchestratorResult{
		status:        "success",
		resultPayload: resultPayload,
	}
}

func resolveTeamspeakVersionForStatus(job jobs.Job) string {
	if job.Type != "ts3.status" && job.Type != "ts6.status" {
		return ""
	}

	downloadURL := payloadValue(job.Payload, "download_url")
	if version := extractVersionFromDownloadURL(downloadURL); version != "" {
		return version
	}

	return ""
}

// validateDownloadURL checks that a download URL is safe to use in shell-interpolated contexts
// such as PowerShell -Command strings. It rejects URLs with schemes other than http/https and
// any characters that could break out of a double-quoted PowerShell argument.
func validateDownloadURL(rawURL string) error {
	parsed, err := url.Parse(rawURL)
	if err != nil {
		return fmt.Errorf("invalid download_url: %w", err)
	}
	if parsed.Scheme != "http" && parsed.Scheme != "https" {
		return fmt.Errorf("download_url must use http or https scheme")
	}
	if strings.ContainsAny(rawURL, "\"\n\r`$;|&") {
		return fmt.Errorf("download_url contains disallowed characters")
	}
	return nil
}

func extractVersionFromDownloadURL(downloadURL string) string {
	if downloadURL == "" {
		return ""
	}

	base := downloadURL
	parentDir := ""
	if parsedURL, err := url.Parse(downloadURL); err == nil && parsedURL.Path != "" {
		base = path.Base(parsedURL.Path)
		parentDir = path.Base(path.Dir(parsedURL.Path))
	}

	// Strip known archive extensions so the regex doesn't absorb them into the version string.
	stripped := base
	for _, suffix := range []string{".tar.bz2", ".tar.xz", ".tar.gz", ".tbz2", ".tgz", ".txz", ".zip"} {
		if strings.HasSuffix(strings.ToLower(stripped), suffix) {
			stripped = stripped[:len(stripped)-len(suffix)]
			break
		}
	}

	if version := teamspeakVersionRegex.FindString(stripped); version != "" {
		return version
	}

	// New TS6 release archives don't include the version in the filename; fall back to
	// the parent path segment which GitHub uses for the release tag (e.g. "v6.0.0-beta10").
	if parentDir != "" && parentDir != "." && parentDir != "/" {
		if version := teamspeakVersionRegex.FindString(parentDir); version != "" {
			return version
		}
	}

	return ""
}

func fallbackVersion(version string) string {
	if version == "" {
		return "unknown"
	}
	return version
}

func handleTs3NodeInstall(job jobs.Job) orchestratorResult {
	installDir := payloadValue(job.Payload, "install_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	downloadURL := payloadValue(job.Payload, "download_url")
	downloadFilename := payloadValue(job.Payload, "download_filename")
	instanceName := payloadValue(job.Payload, "instance_name")
	queryPort := payloadValue(job.Payload, "query_port")
	voicePort := payloadValue(job.Payload, "voice_port")
	filePort := payloadValue(job.Payload, "file_port")
	voiceIP := payloadValue(job.Payload, "voice_ip")
	queryIP := payloadValue(job.Payload, "query_ip")
	fileIP := payloadValue(job.Payload, "filetransfer_ip")
	licensePath := payloadValue(job.Payload, "licensepath", "license_path")
	adminPassword := payloadValue(job.Payload, "admin_password", "serveradmin_password")
	adminPassword = ensureTs3AdminPassword(adminPassword)

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
	}
	if err := validateDownloadURL(downloadURL); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if err := ensureInstanceDir(installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if instanceName == "" {
		instanceName = "ts3"
	}
	if voicePort == "" {
		voicePort = "9987"
	}
	if queryPort == "" {
		queryPort = "10011"
	}
	if filePort == "" {
		filePort = "30033"
	}

	configPath := filepath.Join(installDir, ts3ConfigFile)
	config := buildTs3Config(ts3Config{
		name:          instanceName,
		voiceIP:       voiceIP,
		licensePath:   licensePath,
		adminPassword: adminPassword,
		voicePort:     voicePort,
		queryPort:     queryPort,
		queryIP:       queryIP,
		filePort:      filePort,
		fileIP:        fileIP,
	})

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "ts3server.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := writeFile(configPath, config); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		serviceCommand := fmt.Sprintf("\"%s\" inifile=ts3server.ini license_accepted=1 serveradmin_password=%s", exePath, adminPassword)
		if err := runCommand("sc", "create", serviceName, "binPath=", serviceCommand); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		return orchestratorResult{status: "success", resultPayload: map[string]any{"installed_version": fallbackVersion(extractVersionFromDownloadURL(downloadURL))}}
	}

	serviceUser := "ts3"
	if err := ensureGroup(serviceUser); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureUser(serviceUser, serviceUser, installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	archivePath, err := downloadArchiveForInstall(installDir, downloadURL, downloadFilename, "ts3server.tar.bz2")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := extractArchive(archivePath, downloadURL, installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := chownRecursiveToUser(installDir, serviceUser); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if err := writeFile(configPath, config); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	envFilePath := filepath.Join("/etc/easywi/systemd", fmt.Sprintf("%s.env", serviceName))
	if err := os.MkdirAll(filepath.Dir(envFilePath), 0o700); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := writeFileWithMode(envFilePath, fmt.Sprintf("TS3_ADMIN_PASSWORD=%s\n", adminPassword), 0o600); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	startCommand := "/home/teamspeak3/ts3server inifile=ts3server.ini license_accepted=1 serveradmin_password=${TS3_ADMIN_PASSWORD}"
	unitContent := systemdUnitTemplateWithEnvFile(serviceName, serviceUser, installDir, installDir, startCommand, "", envFilePath, 0, 0)
	if err := writeFile(unitPath, unitContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"installed_version": fallbackVersion(extractVersionFromDownloadURL(downloadURL)),
			"running":           true,
		},
	}
}

func handleTs6NodeInstall(job jobs.Job) orchestratorResult {
	installDir := payloadValue(job.Payload, "install_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	downloadURL := payloadValue(job.Payload, "download_url")
	downloadFilename := payloadValue(job.Payload, "download_filename")
	acceptLicense := parseBool(payloadValue(job.Payload, "accept_license"), true)
	voiceIP := parseStringList(payloadValue(job.Payload, "voice_ip"), "0.0.0.0")
	defaultVoicePort := parseInt(payloadValue(job.Payload, "default_voice_port"), 9987)
	filetransferPort := parseInt(payloadValue(job.Payload, "filetransfer_port"), 30033)
	filetransferIP := parseStringList(payloadValue(job.Payload, "filetransfer_ip"), "0.0.0.0")
	queryBindIP := payloadValue(job.Payload, "query_bind_ip")
	queryHttpsEnable := parseBool(payloadValue(job.Payload, "query_https_enable"), true)
	queryHttpsPort := parseInt(payloadValue(job.Payload, "query_https_port"), 10443)
	adminPassword := payloadValue(job.Payload, "admin_password")
	if !hostHasIPv6() {
		voiceIP = removeUnspecifiedIPv6(voiceIP, []string{"0.0.0.0"})
		filetransferIP = removeUnspecifiedIPv6(filetransferIP, []string{"0.0.0.0"})
		if strings.TrimSpace(queryBindIP) == "::" {
			queryBindIP = ""
		}
	}

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
	}
	if err := validateDownloadURL(downloadURL); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if err := ensureInstanceDir(installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "tsserver.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := runCommand("sc", "create", serviceName, "binPath=", exePath); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		return orchestratorResult{status: "success", resultPayload: map[string]any{"installed_version": fallbackVersion(extractVersionFromDownloadURL(downloadURL))}}
	}

	serviceUser := "ts6"
	if err := ensureGroup(serviceUser); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureUser(serviceUser, serviceUser, installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	archivePath, err := downloadArchiveForInstall(installDir, downloadURL, downloadFilename, "teamspeak6-server-linux-amd64.tar.xz")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	extractionDir := ts6ArchiveExtractionDir(installDir, archivePath)
	if extractionDir != installDir {
		if err := ensureInstanceDir(extractionDir); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	}
	if err := extractArchiveWithoutStrip(archivePath, downloadURL, extractionDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	resolvedInstallDir, err := resolveTs6InstallDir(installDir, archivePath, extractionDir)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := chownRecursiveToUser(resolvedInstallDir, serviceUser); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	uid, gid, err := lookupIDs(serviceUser, serviceUser)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureTs6SshHostKey(resolvedInstallDir, uid, gid); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	certPath, keyPath, err := ensureTs6HttpsCertificate(resolvedInstallDir, uid, gid)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	configPath := filepath.Join(resolvedInstallDir, "tsserver.yaml")
	configContent := buildTs6Config(ts6ConfigOptions{
		licenseAccepted:      acceptLicense,
		voiceIP:              voiceIP,
		defaultVoicePort:     defaultVoicePort,
		filetransferPort:     filetransferPort,
		filetransferIP:       filetransferIP,
		queryBindIP:          queryBindIP,
		queryHttpEnable:      true,
		queryHttpPort:        10080,
		queryHttpsEnable:     queryHttpsEnable,
		queryHttpsPort:       queryHttpsPort,
		queryAdminPass:       adminPassword,
		workingDirectory:     resolvedInstallDir,
		httpsCertificatePath: certPath,
		httpsPrivateKeyPath:  keyPath,
	})
	if err := writeFile(configPath, configContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := os.Chown(configPath, uid, gid); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, serviceUser, resolvedInstallDir, resolvedInstallDir, filepath.Join(resolvedInstallDir, "tsserver"), fmt.Sprintf("--accept-license --config-file %s", configPath), 0, 0)
	if err := writeFile(unitPath, unitContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := waitForTs6QueryListeners(queryBindIP, queryHttpsPort, 10080, 10022, 60*time.Second); err != nil {
		diagnostics := map[string]any{}
		for key, value := range collectServiceDiagnostics(serviceName) {
			diagnostics[key] = value
		}
		diagnostics["query_readiness_error"] = err.Error()
		return orchestratorResult{status: "failed", errorText: err.Error(), resultPayload: diagnostics}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"installed_version": fallbackVersion(extractVersionFromDownloadURL(downloadURL)),
			"install_dir":       resolvedInstallDir,
			"running":           true,
		},
	}
}

func handleSinusbotInstall(job jobs.Job) orchestratorResult {
	installDir := payloadValue(job.Payload, "install_dir")
	instanceRoot := payloadValue(job.Payload, "instance_root")
	serviceName := payloadValue(job.Payload, "service_name")
	downloadURL := payloadValue(job.Payload, "download_url")
	downloadFilename := payloadValue(job.Payload, "download_filename")
	serviceUser := payloadValue(job.Payload, "service_user")
	webBindIP := payloadValue(job.Payload, "web_bind_ip")
	webPortBase := payloadValue(job.Payload, "web_port_base")
	adminPassword := payloadValue(job.Payload, "admin_password")
	ts3ClientInstall := parseBool(payloadValue(job.Payload, "ts3_client_install", "install_ts3_client"), true)
	ts3ClientDownloadURL := payloadValue(job.Payload, "ts3_client_download_url")
	if ts3ClientDownloadURL == "" {
		ts3ClientDownloadURL = defaultSinusbotTs3ClientURL
	}

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
	}
	if err := validateDownloadURL(downloadURL); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if serviceUser == "" {
		serviceUser = "sinusbot"
	}
	if serviceUser == "root" {
		return orchestratorResult{status: "failed", errorText: "service_user cannot be root"}
	}
	if webBindIP == "" {
		webBindIP = "0.0.0.0"
	}
	if webPortBase == "" {
		webPortBase = "8087"
	}

	if err := ensureInstanceDir(installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if instanceRoot != "" {
		if err := ensureInstanceDir(instanceRoot); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	}

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "sinusbot.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := runCommand("sc", "create", serviceName, "binPath=", exePath); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		return orchestratorResult{status: "success", resultPayload: map[string]any{"installed_version": "unknown"}}
	}

	if err := ensureGroup(serviceUser); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureUser(serviceUser, serviceUser, installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if err := installSinusbotDependencies(); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	archivePath, err := downloadArchiveForInstall(installDir, downloadURL, downloadFilename, "sinusbot.tar.bz2")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := extractArchiveWithoutStrip(archivePath, downloadURL, installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureExecutable(filepath.Join(installDir, "sinusbot")); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := chownRecursiveToUser(installDir, serviceUser); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if instanceRoot != "" {
		if err := chownRecursiveToUser(instanceRoot, serviceUser); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	}

	ts3ClientPath := ""
	ts3ClientInstalled := false
	ts3ClientVersion := ""
	if ts3ClientInstall {
		installedPath, err := installSinusbotTs3Client(installDir, ts3ClientDownloadURL, serviceUser)
		if err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		ts3ClientPath = installedPath
		ts3ClientInstalled = true
		ts3ClientVersion = extractVersionFromDownloadURL(ts3ClientDownloadURL)
	}

	if err := ensureSinusbotConfig(installDir, serviceUser, webPortBase, webBindIP, ts3ClientPath); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	startCommand := filepath.Join(installDir, "sinusbot")
	if adminPassword != "" {
		startCommand = fmt.Sprintf("%s --override-password=%s", startCommand, quotePOSIXShellArg(adminPassword))
	}
	unitContent := systemdUnitTemplate(serviceName, serviceUser, installDir, installDir, startCommand, "", 0, 0)
	if err := writeFile(unitPath, unitContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"installed_version": fallbackVersion(extractVersionFromDownloadURL(downloadURL)),
			"running":           true,
			"dependencies": map[string]any{
				"ts3_client_installed": ts3ClientInstalled,
				"ts3_client_version":   ts3ClientVersion,
				"ts3_client_path":      ts3ClientPath,
			},
		},
	}
}

func waitForTs6QueryListeners(queryBindIP string, queryHTTPSPort, queryHTTPPort, querySSHPort int, timeout time.Duration) error {
	queryIP := normalizeQueryConnectIP(queryBindIP)
	if queryIP == "" {
		queryIP = "127.0.0.1"
	}
	ports := []int{querySSHPort, queryHTTPSPort, queryHTTPPort}
	deadline := time.Now().Add(timeout)
	var lastErr error
	for {
		for _, port := range ports {
			if port <= 0 {
				continue
			}
			address := net.JoinHostPort(queryIP, fmt.Sprintf("%d", port))
			conn, err := net.DialTimeout("tcp", address, time.Second)
			if err == nil {
				_ = conn.Close()
				return nil
			}
			lastErr = fmt.Errorf("%s: %w", address, err)
		}
		if time.Now().After(deadline) {
			break
		}
		time.Sleep(2 * time.Second)
	}
	if lastErr != nil {
		return fmt.Errorf("TS6 query listeners did not become reachable within %s: %w", timeout, lastErr)
	}
	return fmt.Errorf("TS6 query listeners did not become reachable within %s", timeout)
}

func ts6ArchiveExtractionDir(installDir, archivePath string) string {
	archiveRoot := archiveRootDirectoryName(archivePath)
	if archiveRoot != "" && filepath.Base(filepath.Clean(installDir)) == archiveRoot {
		return filepath.Dir(filepath.Clean(installDir))
	}
	return installDir
}

func resolveTs6InstallDir(installDir, archivePath, extractionDir string) (string, error) {
	if hasTs6ServerBinary(installDir) {
		return installDir, nil
	}

	archiveRoot := archiveRootDirectoryName(archivePath)
	if archiveRoot != "" {
		candidate := filepath.Join(extractionDir, archiveRoot)
		if hasTs6ServerBinary(candidate) {
			return candidate, nil
		}
	}

	entries, err := os.ReadDir(extractionDir)
	if err != nil {
		return "", fmt.Errorf("inspect TS6 extraction directory: %w", err)
	}
	for _, entry := range entries {
		if !entry.IsDir() {
			continue
		}
		candidate := filepath.Join(extractionDir, entry.Name())
		if hasTs6ServerBinary(candidate) {
			return candidate, nil
		}
	}

	return "", fmt.Errorf("ts6 binary not found after extracting %s without stripping directories", filepath.Base(archivePath))
}

func hasTs6ServerBinary(dir string) bool {
	info, err := os.Stat(filepath.Join(dir, "tsserver"))
	return err == nil && !info.IsDir()
}

func archiveRootDirectoryName(archivePath string) string {
	base := filepath.Base(archivePath)
	lower := strings.ToLower(base)
	for _, suffix := range []string{".tar.xz", ".tar.gz", ".tar.bz2", ".txz", ".tgz", ".tbz2", ".tar", ".zip"} {
		if strings.HasSuffix(lower, suffix) {
			return base[:len(base)-len(suffix)]
		}
	}
	return ""
}

func installSinusbotDependencies() error {
	family, err := detectOSFamily()
	if err != nil {
		return err
	}
	packages := sinusbotPackages(family)
	if len(packages) == 0 {
		return nil
	}
	output := &strings.Builder{}
	if err := installPackages(family, packages, output); err != nil {
		return err
	}
	return nil
}

func sinusbotPackages(family string) []string {
	switch family {
	case "debian":
		libegl := debianFirstAvailablePackage("libegl1-mesa", "libegl1")
		libasound := debianFirstAvailablePackage("libasound2", "libasound2t64")
		return []string{
			"curl",
			"ca-certificates",
			"bzip2",
			"libatomic1",
			"libevent-2.1-7",
			"libqt5gui5",
			"libqt5widgets5",
			"libqt5network5",
			"libqt5dbus5",
			"libqt5core5a",
			"libstdc++6",
			"libxcb-keysyms1",
			"libxcb-image0",
			"libxcb-shm0",
			"libxcb-icccm4",
			"libxcb-sync1",
			"libxcb-render-util0",
			"libxcb-xinerama0",
			"libxcb-xkb1",
			"libxkbcommon-x11-0",
			"screen",
			"x11vnc",
			"xvfb",
			"libfontconfig1",
			"libxtst6",
			"libxcursor1",
			"psmisc",
			"libglib2.0-0",
			"less",
			"python3",
			"iproute2",
			"dbus",
			"libnss3",
			libegl,
			"x11-xkb-utils",
			libasound,
			"libxcomposite1",
			"libxi6",
			"libpci3",
			"libxslt1.1",
			"libxkbcommon0",
			"libxss1",
			"nano",
		}
	case "rhel":
		return []string{
			"curl",
			"ca-certificates",
			"bzip2",
			"screen",
			"x11vnc",
			"xvfb",
			"libXcursor",
			"libXtst",
			"glib2",
			"psmisc",
			"less",
			"python3",
			"iproute",
			"dbus",
			"nss",
			"mesa-libEGL",
			"xorg-x11-xkb-utils",
			"alsa-lib",
			"libXcomposite",
			"libXi",
			"pciutils-libs",
			"libxslt",
			"libxkbcommon",
			"libXScrnSaver",
			"nano",
		}
	}
	return nil
}

func debianFirstAvailablePackage(candidates ...string) string {
	if len(candidates) == 0 {
		return ""
	}
	if !commandExists("apt-cache") {
		return candidates[0]
	}
	for _, candidate := range candidates {
		if candidate == "" {
			continue
		}
		if debianPackageHasCandidate(candidate) {
			return candidate
		}
	}
	return candidates[0]
}

func debianPackageHasCandidate(name string) bool {
	if name == "" {
		return false
	}
	output, err := exec.Command("apt-cache", "policy", name).Output()
	if err != nil {
		return false
	}
	policy := string(output)
	if strings.Contains(policy, "Candidate: (none)") {
		return false
	}
	return strings.Contains(policy, "Candidate:")
}

func ensureSinusbotConfig(installDir, serviceUser, webPortBase, webBindIP, ts3Path string) error {
	configPath := filepath.Join(installDir, "config.ini")
	if _, err := os.Stat(configPath); os.IsNotExist(err) {
		distPath := filepath.Join(installDir, "config.ini.dist")
		if info, err := os.Stat(distPath); err == nil {
			if err := copyFile(distPath, configPath, info.Mode()); err != nil {
				return err
			}
		} else {
			configContent := fmt.Sprintf("ListenPort = %s\nListenHost = \"%s\"\nTS3Path = \"\"\nYoutubeDLPath = \"\"\n", webPortBase, webBindIP)
			if err := writeFile(configPath, configContent); err != nil {
				return err
			}
		}
	}

	raw, err := os.ReadFile(configPath)
	if err != nil {
		return err
	}
	content := string(raw)
	content = ensureIniValue(content, "ListenPort", webPortBase)
	content = ensureIniValue(content, "ListenHost", fmt.Sprintf("\"%s\"", webBindIP))
	ts3Value := "\"\""
	if ts3Path != "" {
		ts3Value = fmt.Sprintf("\"%s\"", ts3Path)
	}
	content = ensureIniValue(content, "TS3Path", ts3Value)
	content = ensureIniValue(content, "YoutubeDLPath", "\"\"")

	if err := writeFile(configPath, content); err != nil {
		return err
	}
	if err := chownRecursiveToUser(configPath, serviceUser); err != nil {
		return err
	}
	return nil
}

func ensureIniValue(content, key, value string) string {
	line := fmt.Sprintf("%s = %s", key, value)
	re := regexp.MustCompile(`(?m)^\s*` + regexp.QuoteMeta(key) + `\s*=.*$`)
	if re.MatchString(content) {
		return re.ReplaceAllString(content, line)
	}
	if !strings.HasSuffix(content, "\n") {
		content += "\n"
	}
	return content + line + "\n"
}

func installSinusbotTs3Client(installDir, downloadURL, serviceUser string) (string, error) {
	if downloadURL == "" {
		return "", fmt.Errorf("missing ts3 client download URL")
	}
	archivePath, err := downloadArchiveForInstall(installDir, downloadURL, "", "TeamSpeak3-Client-linux_amd64-3.6.2.run")
	if err != nil {
		return "", err
	}
	if err := runCommand("chmod", "+x", archivePath); err != nil {
		return "", err
	}

	ts3ClientDir := filepath.Join(installDir, "TeamSpeak3-Client-linux_amd64")
	if err := os.MkdirAll(ts3ClientDir, 0o755); err != nil {
		return "", err
	}

	if err := runCommand(archivePath, "--accept", "--target", ts3ClientDir, "--quiet"); err != nil {
		if err := runCommand(archivePath, "--quiet", "--target", ts3ClientDir); err != nil {
			if err := runCommand(archivePath, "--accept", "--target", ts3ClientDir); err != nil {
				if err := runCommand("bash", archivePath, "--target", ts3ClientDir); err != nil {
					return "", err
				}
			}
		}
	}

	glxPath := filepath.Join(ts3ClientDir, "xcbglintegrations", "libqxcb-glx-integration.so")
	if err := os.Remove(glxPath); err != nil && !os.IsNotExist(err) {
		return "", err
	}

	pluginsDir := filepath.Join(ts3ClientDir, "plugins")
	if err := os.MkdirAll(pluginsDir, 0o755); err != nil {
		return "", err
	}
	pluginSource := filepath.Join(installDir, "plugin", "libsoundbot_plugin.so")
	pluginInfo, err := os.Stat(pluginSource)
	if err != nil {
		return "", err
	}
	if err := copyFile(pluginSource, filepath.Join(pluginsDir, "libsoundbot_plugin.so"), pluginInfo.Mode()); err != nil {
		return "", err
	}

	if err := chownRecursiveToUser(ts3ClientDir, serviceUser); err != nil {
		return "", err
	}
	return filepath.Join(ts3ClientDir, "ts3client_linux_amd64"), nil
}

func resolveSinusbotTs3ClientStatus(job jobs.Job) map[string]any {
	installDir := payloadValue(job.Payload, "install_dir", "install_path")
	if installDir == "" {
		return nil
	}
	ts3ClientPath := filepath.Join(installDir, "TeamSpeak3-Client-linux_amd64", "ts3client_linux_amd64")
	_, err := os.Stat(ts3ClientPath)
	installed := err == nil
	version := extractVersionFromDownloadURL(payloadValue(job.Payload, "ts3_client_download_url"))

	result := map[string]any{
		"ts3_client_installed": installed,
	}
	if installed {
		result["ts3_client_path"] = ts3ClientPath
	}
	if version != "" {
		result["ts3_client_version"] = version
	}
	return result
}

func handleViewerSnapshot(job jobs.Job) orchestratorResult {
	sid := payloadValue(job.Payload, "sid")
	if sid == "" {
		return orchestratorResult{status: "failed", errorText: "missing sid"}
	}

	withClient := withTs3Client
	if strings.HasPrefix(job.Type, "ts6.") {
		withClient = withTs6Client
	}

	var server map[string]string
	var channelLines []string
	var clientLines []string
	err := withClient(job.Payload, func(client *ts3QueryClient) error {
		if _, err := client.command(fmt.Sprintf("use sid=%s", sid)); err != nil {
			return err
		}
		serverInfo, err := client.command("serverinfo")
		if err != nil {
			return err
		}
		server = serverInfo
		channelLines, err = client.commandLines("channellist")
		if err != nil {
			return err
		}
		clientLines, err = client.commandLines("clientlist")
		return err
	})
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"server": map[string]any{
				"sid":    sid,
				"name":   firstNonEmpty(server["virtualserver_name"], fmt.Sprintf("TS Server %s", sid)),
				"online": parseOptionalInt(server["virtualserver_clientsonline"]),
				"max":    parseOptionalInt(server["virtualserver_maxclients"]),
			},
			"channels":     parseQueryList(channelLines),
			"clients":      parseQueryList(clientLines),
			"generated_at": time.Now().UTC().Format(time.RFC3339),
		},
	}
}

func chownRecursiveToUser(path, username string) error {
	uid, gid, err := lookupIDs(username, username)
	if err != nil {
		return err
	}
	info, err := os.Stat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return nil
		}
		return fmt.Errorf("stat %s: %w", path, err)
	}
	if err := os.Chown(path, uid, gid); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("chown %s: %w", path, err)
	}
	if !info.IsDir() {
		return nil
	}
	if err := chownRecursive(path, uid, gid); err != nil {
		return err
	}
	return nil
}

func downloadArchive(destination, url string) error {
	if err := runCommand("curl", "-fL", "--retry", "3", "--retry-delay", "3", "-o", destination, url); err != nil {
		return err
	}
	return validateArchive(destination)
}

func downloadArchiveForInstall(installDir, downloadURL, downloadFilename, fallback string) (string, error) {
	filename := strings.TrimSpace(downloadFilename)
	if filename == "" {
		parsedURL, err := url.Parse(downloadURL)
		if err == nil {
			base := path.Base(parsedURL.Path)
			if base != "." && base != "/" {
				filename = base
			}
		}
	}
	if filename == "" {
		filename = fallback
	}

	archivePath := filepath.Join(installDir, filename)
	if err := downloadArchive(archivePath, downloadURL); err != nil {
		return "", err
	}

	return archivePath, nil
}

func extractArchive(archivePath, downloadURL, installDir string) error {
	archiveLower := strings.ToLower(archivePath)
	downloadLower := strings.ToLower(downloadURL)
	if strings.HasSuffix(archiveLower, ".zip") || strings.HasSuffix(downloadLower, ".zip") {
		return runCommand("unzip", "-o", archivePath, "-d", installDir)
	}
	switch {
	case strings.HasSuffix(archiveLower, ".tar.bz2"), strings.HasSuffix(archiveLower, ".tbz2"):
		return runCommand("tar", "-xjf", archivePath, "-C", installDir, "--strip-components=1")
	case strings.HasSuffix(archiveLower, ".tar.gz"), strings.HasSuffix(archiveLower, ".tgz"):
		return runCommand("tar", "-xzf", archivePath, "-C", installDir, "--strip-components=1")
	case strings.HasSuffix(archiveLower, ".tar.xz"), strings.HasSuffix(archiveLower, ".txz"):
		return runCommand("tar", "-xJf", archivePath, "-C", installDir, "--strip-components=1")
	default:
		return runCommand("tar", "-xf", archivePath, "-C", installDir, "--strip-components=1")
	}
}

func extractArchiveWithoutStrip(archivePath, downloadURL, installDir string) error {
	archiveLower := strings.ToLower(archivePath)
	downloadLower := strings.ToLower(downloadURL)
	if strings.HasSuffix(archiveLower, ".zip") || strings.HasSuffix(downloadLower, ".zip") {
		return runCommand("unzip", "-o", archivePath, "-d", installDir)
	}
	switch {
	case strings.HasSuffix(archiveLower, ".tar.bz2"), strings.HasSuffix(archiveLower, ".tbz2"):
		return runCommand("tar", "-xjf", archivePath, "-C", installDir)
	case strings.HasSuffix(archiveLower, ".tar.gz"), strings.HasSuffix(archiveLower, ".tgz"):
		return runCommand("tar", "-xzf", archivePath, "-C", installDir)
	case strings.HasSuffix(archiveLower, ".tar.xz"), strings.HasSuffix(archiveLower, ".txz"):
		return runCommand("tar", "-xJf", archivePath, "-C", installDir)
	default:
		return runCommand("tar", "-xf", archivePath, "-C", installDir)
	}
}

func validateArchive(path string) (err error) {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}
	if info.Size() == 0 {
		return fmt.Errorf("downloaded archive is empty")
	}
	file, err := os.Open(path)
	if err != nil {
		return err
	}
	defer func() {
		if closeErr := file.Close(); closeErr != nil && err == nil {
			err = closeErr
		}
	}()

	buffer := make([]byte, 512)
	n, err := file.Read(buffer)
	if err != nil && !errors.Is(err, io.EOF) {
		return err
	}
	header := buffer[:n]

	snippet := strings.ToLower(string(header))
	if strings.Contains(snippet, "<!doctype") || strings.Contains(snippet, "<html") {
		return fmt.Errorf("downloaded archive looks like HTML; check the download URL for authentication or redirects")
	}

	// Verify magic bytes match the declared archive format to catch truncated or mis-served downloads.
	pathLower := strings.ToLower(path)
	switch {
	case strings.HasSuffix(pathLower, ".tar.xz") || strings.HasSuffix(pathLower, ".txz"):
		// xz magic: FD 37 7A 58 5A 00
		if n < 6 || string(header[:6]) != "\xfd7zXZ\x00" {
			return fmt.Errorf("downloaded file is not a valid xz archive (bad magic bytes); check the download URL")
		}
	case strings.HasSuffix(pathLower, ".tar.bz2") || strings.HasSuffix(pathLower, ".tbz2"):
		// bz2 magic: 42 5A 68 = "BZh"
		if n < 3 || string(header[:3]) != "BZh" {
			return fmt.Errorf("downloaded file is not a valid bz2 archive (bad magic bytes); check the download URL")
		}
	case strings.HasSuffix(pathLower, ".tar.gz") || strings.HasSuffix(pathLower, ".tgz"):
		// gzip magic: 1F 8B
		if n < 2 || header[0] != 0x1f || header[1] != 0x8b {
			return fmt.Errorf("downloaded file is not a valid gzip archive (bad magic bytes); check the download URL")
		}
	}

	return nil
}

func buildTs6Config(options ts6ConfigOptions) string {
	workingDirectory := strings.TrimSpace(options.workingDirectory)
	if workingDirectory == "" {
		workingDirectory = "/home/teamspeak6"
	}
	adminPassword := options.queryAdminPass
	certificatePath := strings.TrimSpace(options.httpsCertificatePath)
	if certificatePath == "" {
		certificatePath = ts6HTTPSCertificateFilename
	}
	privateKeyPath := strings.TrimSpace(options.httpsPrivateKeyPath)
	if privateKeyPath == "" {
		privateKeyPath = ts6HTTPSPrivateKeyFilename
	}
	return fmt.Sprintf(`server:
  license-path: .
  default-voice-port: 9987
  voice-ip:
    - 0.0.0.0
  log-path: logs
  log-append: 0
  no-default-virtual-server: 0
  filetransfer-port: 30033
  filetransfer-ip:
    - 0.0.0.0
  accept-license: accept
  crashdump-path: crashdumps

  database:
    plugin: sqlite3
    sql-path: %s/sql/
    sql-create-path: %s/sql/create_sqlite/
    client-keep-days: 30
    config:
      skip-integrity-check: 0
      host: 127.0.0.1
      port: 5432
      socket: ""
      timeout: 10
      name: teamspeak
      username: ""
      password: ""
      connections: 10
      log-queries: 0

  query:
    pool-size: 2
    log-timing: 3600
    ip-allow-list: query_ip_allowlist.txt
    ip-block-list: query_ip_denylist.txt
    admin-password: %q
    log-commands: 0
    skip-brute-force-check: 0
    buffer-mb: 20
    documentation-path: serverquerydocs
    timeout: 300

    http:
      enable: 1
      port: 10080
      ip:
      - 127.0.0.1

    https:
      enable: 1
      port: 10443
      ip:
      - 127.0.0.1
      certificate: %q
      private-key: %q

    ssh:
      enable: 1
      port: 10022
      ip:
      - 0.0.0.0
      rsa-key: ssh_host_rsa_key
`, workingDirectory, workingDirectory, adminPassword, certificatePath, privateKeyPath)
}

const (
	ts6HTTPSCertificateFilename = "query_https.crt"
	ts6HTTPSPrivateKeyFilename  = "query_https.key"
)

func ensureTs6HttpsCertificate(installDir string, uid, gid int) (string, string, error) {
	certPath := filepath.Join(installDir, ts6HTTPSCertificateFilename)
	keyPath := filepath.Join(installDir, ts6HTTPSPrivateKeyFilename)
	if ts6FileExists(certPath) && ts6FileExists(keyPath) {
		return ts6HTTPSCertificateFilename, ts6HTTPSPrivateKeyFilename, nil
	}

	privateKey, err := rsa.GenerateKey(rand.Reader, 2048)
	if err != nil {
		return "", "", fmt.Errorf("generate TS6 HTTPS private key: %w", err)
	}
	serialNumber, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
	if err != nil {
		return "", "", fmt.Errorf("generate TS6 HTTPS certificate serial: %w", err)
	}
	now := time.Now().UTC()
	template := x509.Certificate{
		SerialNumber: serialNumber,
		Subject: pkix.Name{
			CommonName: "Easy-WI TeamSpeak 6 Query",
		},
		NotBefore:             now.Add(-time.Hour),
		NotAfter:              now.AddDate(10, 0, 0),
		KeyUsage:              x509.KeyUsageKeyEncipherment | x509.KeyUsageDigitalSignature,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		DNSNames:              []string{"localhost"},
		IPAddresses:           []net.IP{net.ParseIP("127.0.0.1")},
	}
	certDER, err := x509.CreateCertificate(rand.Reader, &template, &template, &privateKey.PublicKey, privateKey)
	if err != nil {
		return "", "", fmt.Errorf("create TS6 HTTPS certificate: %w", err)
	}

	certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certDER})
	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "RSA PRIVATE KEY", Bytes: x509.MarshalPKCS1PrivateKey(privateKey)})
	if err := os.WriteFile(certPath, certPEM, 0o644); err != nil {
		return "", "", fmt.Errorf("write TS6 HTTPS certificate: %w", err)
	}
	if err := os.WriteFile(keyPath, keyPEM, 0o600); err != nil {
		return "", "", fmt.Errorf("write TS6 HTTPS private key: %w", err)
	}
	if err := chownIfSupported(certPath, uid, gid); err != nil {
		return "", "", err
	}
	if err := chownIfSupported(keyPath, uid, gid); err != nil {
		return "", "", err
	}
	return ts6HTTPSCertificateFilename, ts6HTTPSPrivateKeyFilename, nil
}

func ts6FileExists(path string) bool {
	info, err := os.Stat(path)
	return err == nil && !info.IsDir()
}

func chownIfSupported(path string, uid, gid int) error {
	if runtime.GOOS == "windows" {
		return nil
	}
	return os.Chown(path, uid, gid)
}

func ensureTs6SshHostKey(installDir string, uid, gid int) error {
	keyPath := filepath.Join(installDir, "ssh_host_rsa_key")
	if _, err := os.Stat(keyPath); err == nil {
		return nil
	} else if !os.IsNotExist(err) {
		return err
	}
	if err := runCommand("ssh-keygen", "-t", "rsa", "-b", "4096", "-f", keyPath, "-N", ""); err != nil {
		return err
	}
	if err := os.Chmod(keyPath, 0o600); err != nil {
		return err
	}
	if err := chownIfSupported(keyPath, uid, gid); err != nil {
		return err
	}
	pubPath := keyPath + ".pub"
	if _, err := os.Stat(pubPath); err == nil {
		if err := os.Chmod(pubPath, 0o644); err != nil {
			return err
		}
		if err := chownIfSupported(pubPath, uid, gid); err != nil {
			return err
		}
	} else if !os.IsNotExist(err) {
		return err
	}
	return nil
}

func removeUnspecifiedIPv6(values []string, fallback []string) []string {
	filtered := make([]string, 0, len(values))
	for _, value := range values {
		if strings.TrimSpace(value) != "::" {
			filtered = append(filtered, value)
		}
	}
	if len(filtered) == 0 {
		return fallback
	}
	return filtered
}

func getPublicIPv6Addresses() []string {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return nil
	}
	var result []string
	for _, addr := range addrs {
		var ip net.IP
		switch value := addr.(type) {
		case *net.IPNet:
			ip = value.IP
		case *net.IPAddr:
			ip = value.IP
		}
		if ip == nil || ip.To4() != nil {
			continue
		}
		if ip.IsLoopback() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() {
			continue
		}
		if ip.IsGlobalUnicast() {
			result = append(result, ip.String())
		}
	}
	return result
}

func hostHasIPv6() bool {
	addrs, err := net.InterfaceAddrs()
	if err != nil {
		return false
	}
	for _, addr := range addrs {
		var ip net.IP
		switch value := addr.(type) {
		case *net.IPNet:
			ip = value.IP
		case *net.IPAddr:
			ip = value.IP
		}
		if ip == nil || ip.To4() != nil {
			continue
		}
		if ip.IsLoopback() || ip.IsLinkLocalUnicast() || ip.IsLinkLocalMulticast() {
			continue
		}
		if ip.IsGlobalUnicast() {
			return true
		}
	}
	return false
}

func convertJobResult(result jobs.Result, afterSubmit func() error) orchestratorResult {
	if afterSubmit != nil {
		_ = afterSubmit()
	}
	status := "success"
	if result.Status != "success" {
		status = "failed"
	}
	payload := map[string]any{}
	for key, value := range result.Output {
		payload[key] = value
	}
	return orchestratorResult{
		status:        status,
		errorText:     result.Output["message"],
		resultPayload: payload,
	}
}

func writeFile(path string, content string) error {
	return os.WriteFile(path, []byte(content), instanceFileMode)
}

func writeFileWithMode(path, content string, mode os.FileMode) error {
	return os.WriteFile(path, []byte(content), mode)
}

func handleTs6InstanceCreate(job jobs.Job) orchestratorResult {
	instanceID := payloadValue(job.Payload, "instance_id")
	serviceName := payloadValue(job.Payload, "service_name")
	installDir := payloadValue(job.Payload, "install_dir")

	if instanceID == "" || serviceName == "" {
		return orchestratorResult{status: "failed", errorText: "missing instance_id or service_name"}
	}
	if installDir == "" {
		installDir = "/var/lib/ts6"
	}

	baseDir := payloadValue(job.Payload, "base_dir")
	if baseDir == "" {
		baseDir = "/var/lib/ts6/instances"
	}
	instanceDir := path.Join(baseDir, instanceID)

	osUsername := fmt.Sprintf("ts6i%s", sanitizeIdentifier(instanceID))
	if err := ensureGroup(osUsername); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureUser(osUsername, osUsername, instanceDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := ensureInstanceDir(instanceDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	voicePort := parseInt(payloadValue(job.Payload, "voice_port"), 9987)
	queryHttpsPort := parseInt(payloadValue(job.Payload, "query_https_port"), 10443)
	filetransferPort := parseInt(payloadValue(job.Payload, "filetransfer_port"), 30033)

	uid, gid, err := lookupIDs(osUsername, osUsername)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := os.Chown(instanceDir, uid, gid); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("chown instance dir: %v", err)}
	}
	if err := ensureTs6SshHostKey(instanceDir, uid, gid); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	certPath, keyPath, err := ensureTs6HttpsCertificate(instanceDir, uid, gid)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	configPath := filepath.Join(instanceDir, "tsserver.yaml")
	configContent := buildTs6Config(ts6ConfigOptions{
		licenseAccepted:      true,
		voiceIP:              []string{"0.0.0.0"},
		defaultVoicePort:     voicePort,
		filetransferPort:     filetransferPort,
		filetransferIP:       []string{"0.0.0.0"},
		queryHttpsEnable:     true,
		queryHttpsPort:       queryHttpsPort,
		workingDirectory:     instanceDir,
		httpsCertificatePath: certPath,
		httpsPrivateKeyPath:  keyPath,
	})
	if err := writeFile(configPath, configContent); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("write config: %v", err)}
	}
	if err := os.Chown(configPath, uid, gid); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("chown config: %v", err)}
	}

	binaryPath := filepath.Join(installDir, "tsserver")
	if _, err := os.Stat(binaryPath); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("ts6 binary not found at %s: %v", binaryPath, err)}
	}

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, osUsername, instanceDir, instanceDir,
		binaryPath, fmt.Sprintf("--accept-license --config-file %s", configPath), 0, 0)
	if err := writeFile(unitPath, unitContent); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("write unit: %v", err)}
	}
	if err := runCommand("systemctl", "daemon-reload"); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := runCommand("systemctl", "enable", "--now", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName
	diagnostics["instance_dir"] = instanceDir

	return orchestratorResult{status: "success", resultPayload: map[string]any{
		"service_name": serviceName,
		"instance_dir": instanceDir,
		"running":      true,
	}}
}

func handleTs6InstanceAction(job jobs.Job) orchestratorResult {
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	switch action {
	case "start", "stop", "restart":
		return handleTs6ServiceControlAction(job, action)
	case "update":
		return handleTs6InstanceUpdate(job)
	case "backup":
		return handleTs6InstanceBackup(job)
	case "restore":
		return handleTs6InstanceRestore(job)
	default:
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("unsupported ts6 action: %s", action)}
	}
}

func handleTs6ServiceControlAction(job jobs.Job, action string) orchestratorResult {
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" {
		instanceID := payloadValue(job.Payload, "instance_id")
		if instanceID == "" {
			return orchestratorResult{status: "failed", errorText: "missing service_name or instance_id"}
		}
		serviceName = fmt.Sprintf("ts6-%s", instanceID)
	}

	if err := runCommand("systemctl", action, serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	diagnostics := collectServiceDiagnostics(serviceName)
	diagnostics["service_name"] = serviceName
	diagnostics["action"] = action

	return orchestratorResult{status: "success", resultPayload: map[string]any{
		"service_name": serviceName,
		"action":       action,
		"running":      action != "stop",
	}}
}

func handleTs6InstanceUpdate(job jobs.Job) orchestratorResult {
	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" {
		instanceID := payloadValue(job.Payload, "instance_id")
		if instanceID != "" {
			serviceName = fmt.Sprintf("ts6-%s", instanceID)
		}
	}

	updateCommand := payloadValue(job.Payload, "update_command")
	if updateCommand != "" {
		instanceDir := ts6InstanceDirFromJob(job)
		if instanceDir != "" {
			templateValues := buildInstanceTemplateValues(instanceDir, instanceDir, "", []int{}, job.Payload)
			rendered, err := renderTemplateStrict(updateCommand, templateValues)
			if err != nil {
				return orchestratorResult{status: "failed", errorText: err.Error()}
			}
			cmd := fmt.Sprintf("cd %s && %s", shellEscape(instanceDir), rendered)
			if err := runCommandAsUser("ts6", cmd); err != nil {
				return orchestratorResult{status: "failed", errorText: fmt.Sprintf("update command failed: %v", err)}
			}
		}
	}

	if serviceName == "" {
		return orchestratorResult{status: "failed", errorText: "missing service_name or instance_id"}
	}
	if err := runCommand("systemctl", "restart", serviceName); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{status: "success", resultPayload: map[string]any{
		"service_name": serviceName,
		"running":      true,
	}}
}

func handleTs6InstanceBackup(job jobs.Job) orchestratorResult {
	instanceID := payloadValue(job.Payload, "instance_id")
	instanceDir := ts6InstanceDirFromJob(job)
	if instanceDir == "" {
		return orchestratorResult{status: "failed", errorText: "missing instance_dir or could not resolve instance directory"}
	}

	backupPath := payloadValue(job.Payload, "backup_path")
	if backupPath == "" {
		backupDir := filepath.Join(filepath.Dir(instanceDir), "backups")
		if err := os.MkdirAll(backupDir, instanceDirMode); err != nil {
			return orchestratorResult{status: "failed", errorText: fmt.Sprintf("create backup dir: %v", err)}
		}
		backupPath = filepath.Join(backupDir, fmt.Sprintf("ts6-%s-%d.tar.gz", instanceID, time.Now().UTC().Unix()))
	}

	if err := runCommand("tar", "-czf", backupPath, "-C", instanceDir, "."); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	return orchestratorResult{status: "success", resultPayload: map[string]any{"backup_path": backupPath}}
}

func handleTs6InstanceRestore(job jobs.Job) orchestratorResult {
	instanceDir := ts6InstanceDirFromJob(job)
	restorePath := payloadValue(job.Payload, "restore_path")
	serviceName := payloadValue(job.Payload, "service_name")

	if instanceDir == "" {
		return orchestratorResult{status: "failed", errorText: "missing instance_dir or could not resolve instance directory"}
	}
	if restorePath == "" {
		return orchestratorResult{status: "failed", errorText: "missing restore_path"}
	}

	if serviceName != "" {
		_ = runCommand("systemctl", "stop", serviceName)
	}

	if err := os.RemoveAll(instanceDir); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("remove instance dir: %v", err)}
	}
	if err := os.MkdirAll(instanceDir, instanceDirMode); err != nil {
		return orchestratorResult{status: "failed", errorText: fmt.Sprintf("recreate instance dir: %v", err)}
	}
	if err := runCommand("tar", "-xzf", restorePath, "-C", instanceDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if serviceName != "" {
		if err := runCommand("systemctl", "start", serviceName); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
	}

	return orchestratorResult{status: "success", resultPayload: map[string]any{
		"restore_path": restorePath,
		"running":      serviceName != "",
	}}
}

// ts6InstanceDirFromJob resolves the TS6 instance working directory from the job payload.
// It uses 'instance_dir' if present; otherwise reads the WorkingDirectory from the systemd unit.
func ts6InstanceDirFromJob(job jobs.Job) string {
	if dir := payloadValue(job.Payload, "instance_dir"); dir != "" {
		return dir
	}

	serviceName := payloadValue(job.Payload, "service_name")
	if serviceName == "" {
		instanceID := payloadValue(job.Payload, "instance_id")
		if instanceID == "" {
			return ""
		}
		serviceName = fmt.Sprintf("ts6-%s", instanceID)
	}

	unitPath := filepath.Join("/etc/systemd/system", serviceName+".service")
	data, err := os.ReadFile(unitPath)
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(data), "\n") {
		if strings.HasPrefix(line, "WorkingDirectory=") {
			return strings.TrimPrefix(line, "WorkingDirectory=")
		}
	}
	return ""
}
