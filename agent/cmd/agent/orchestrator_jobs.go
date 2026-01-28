package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/url"
	"os"
	"path"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
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
	licenseAccepted  bool
	voiceIP          []string
	defaultVoicePort int
	filetransferPort int
	filetransferIP   []string
	queryBindIP      string
	queryHttpEnable  bool
	queryHttpPort    int
	queryHttpsEnable bool
	queryHttpsPort   int
	queryAdminPass   string
	workingDirectory string
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
		return orchestratorResult{
			status:        "success",
			resultPayload: map[string]any{"message": "ts6 instance create queued"},
		}
	case "ts6.instance.action":
		return handleServiceAction(job)
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

func handleServiceAction(job jobs.Job) orchestratorResult {
	serviceName := payloadValue(job.Payload, "service_name")
	action := strings.ToLower(payloadValue(job.Payload, "action"))
	if serviceName == "" || action == "" {
		return orchestratorResult{
			status:    "failed",
			errorText: "missing service_name or action",
		}
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

func extractVersionFromDownloadURL(downloadURL string) string {
	if downloadURL == "" {
		return ""
	}

	base := downloadURL
	if parsedURL, err := url.Parse(downloadURL); err == nil {
		if parsedURL.Path != "" {
			base = path.Base(parsedURL.Path)
		}
	}

	return teamspeakVersionRegex.FindString(base)
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

	if installDir == "" || serviceName == "" || downloadURL == "" {
		return orchestratorResult{status: "failed", errorText: "missing install_dir, service_name, or download_url"}
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
		name:        instanceName,
		voiceIP:     voiceIP,
		licensePath: licensePath,
		voicePort:   voicePort,
		queryPort:   queryPort,
		queryIP:     queryIP,
		filePort:    filePort,
		fileIP:      fileIP,
	})

	if runtime.GOOS == "windows" {
		exePath := filepath.Join(installDir, "ts3server.exe")
		if err := runCommand("powershell", "-Command", fmt.Sprintf("Invoke-WebRequest -UseBasicParsing -OutFile \"%s\" \"%s\"", exePath, downloadURL)); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		if err := writeFile(configPath, config); err != nil {
			return orchestratorResult{status: "failed", errorText: err.Error()}
		}
		serviceCommand := fmt.Sprintf("\"%s\" inifile=ts3server.ini license_accepted=1", exePath)
		if adminPassword != "" {
			serviceCommand = fmt.Sprintf("%s serveradmin_password=%s", serviceCommand, adminPassword)
		}
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

	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	startCommand := "./ts3server inifile=ts3server.ini license_accepted=1"
	if adminPassword != "" {
		startCommand = fmt.Sprintf("%s serveradmin_password=%s", startCommand, adminPassword)
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
		},
	}
}

func handleTs6NodeInstall(job jobs.Job) orchestratorResult {
	installDir := payloadValue(job.Payload, "install_dir")
	serviceName := payloadValue(job.Payload, "service_name")
	downloadURL := payloadValue(job.Payload, "download_url")
	downloadFilename := payloadValue(job.Payload, "download_filename")
	instanceName := payloadValue(job.Payload, "instance_name")
	acceptLicense := parseBool(payloadValue(job.Payload, "accept_license"), true)
	voiceIP := parseStringList(payloadValue(job.Payload, "voice_ip"), []string{"0.0.0.0"})
	defaultVoicePort := parseInt(payloadValue(job.Payload, "default_voice_port"), 9987)
	filetransferPort := parseInt(payloadValue(job.Payload, "filetransfer_port"), 30033)
	filetransferIP := parseStringList(payloadValue(job.Payload, "filetransfer_ip"), []string{"0.0.0.0"})
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

	archivePath, err := downloadArchiveForInstall(installDir, downloadURL, downloadFilename, "ts6server.tar.bz2")
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := extractArchive(archivePath, downloadURL, installDir); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := chownRecursiveToUser(installDir, serviceUser); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}

	if instanceName == "" {
		instanceName = "ts6"
	}
	configPath := filepath.Join(installDir, "tsserver.yaml")
	configContent := buildTs6Config(ts6ConfigOptions{
		licenseAccepted:  acceptLicense,
		voiceIP:          voiceIP,
		defaultVoicePort: defaultVoicePort,
		filetransferPort: filetransferPort,
		filetransferIP:   filetransferIP,
		queryBindIP:      queryBindIP,
		queryHttpEnable:  true,
		queryHttpPort:    10080,
		queryHttpsEnable: queryHttpsEnable,
		queryHttpsPort:   queryHttpsPort,
		queryAdminPass:   adminPassword,
		workingDirectory: installDir,
	})
	if err := writeFile(configPath, configContent); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	uid, gid, err := lookupIDs(serviceUser, serviceUser)
	if err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	if err := os.Chown(configPath, uid, gid); err != nil {
		return orchestratorResult{status: "failed", errorText: err.Error()}
	}
	unitPath := filepath.Join("/etc/systemd/system", fmt.Sprintf("%s.service", serviceName))
	unitContent := systemdUnitTemplate(serviceName, serviceUser, installDir, installDir, filepath.Join(installDir, "tsserver"), fmt.Sprintf("--accept-license --config-file %s", filepath.Join(installDir, "tsserver.yaml")), 0, 0)
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
		startCommand = fmt.Sprintf("%s --override-password=%s", startCommand, adminPassword)
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
		return []string{
			"curl",
			"ca-certificates",
			"bzip2",
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
			"libegl1-mesa",
			"x11-xkb-utils",
			"libasound2",
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

	command := fmt.Sprintf("%q --accept --target %q --quiet </dev/null >/dev/null 2>&1", archivePath, ts3ClientDir)
	if err := runCommand("bash", "-c", command); err != nil {
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
	return orchestratorResult{
		status: "success",
		resultPayload: map[string]any{
			"server": map[string]any{
				"sid":  sid,
				"name": fmt.Sprintf("TS Server %s", sid),
			},
			"channels":     []any{},
			"clients":      []any{},
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

func validateArchive(path string) error {
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
	defer file.Close()

	buffer := make([]byte, 512)
	n, err := file.Read(buffer)
	if err != nil && !errors.Is(err, io.EOF) {
		return err
	}
	snippet := strings.ToLower(string(buffer[:n]))
	if strings.Contains(snippet, "<!doctype") || strings.Contains(snippet, "<html") {
		return fmt.Errorf("downloaded archive looks like HTML; check the download URL for authentication or redirects")
	}
	return nil
}

func buildTs6Config(options ts6ConfigOptions) string {
	queryIPs := options.voiceIP
	if options.queryBindIP != "" {
		queryIPs = []string{options.queryBindIP}
	}
	httpEnabled := boolToInt(options.queryHttpEnable)
	httpsEnabled := boolToInt(options.queryHttpsEnable)
	acceptValue := "accept"
	if !options.licenseAccepted {
		acceptValue = "0"
	}
	return fmt.Sprintf(`server:
  license-path: .
  default-voice-port: %d
  voice-ip:
%s
  log-path: logs
  log-append: 0
  no-default-virtual-server: 0
  filetransfer-port: %d
  filetransfer-ip:
%s
  accept-license: %s
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
      enable: %d
      port: %d
      ip:
%s

    https:
      enable: %d
      port: %d
      ip:
%s
      certificate: ""
      private-key: ""
`, options.defaultVoicePort, formatYamlList(options.voiceIP, 4), options.filetransferPort, formatYamlList(options.filetransferIP, 4), acceptValue, options.workingDirectory, options.workingDirectory, options.queryAdminPass, httpEnabled, options.queryHttpPort, formatYamlList(queryIPs, 6), httpsEnabled, options.queryHttpsPort, formatYamlList(queryIPs, 6))
}

func formatYamlList(values []string, indent int) string {
	prefix := strings.Repeat(" ", indent)
	lines := make([]string, 0, len(values))
	for _, value := range values {
		lines = append(lines, fmt.Sprintf("%s- %s", prefix, value))
	}
	return strings.Join(lines, "\n")
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

func parseBool(value string, fallback bool) bool {
	if value == "" {
		return fallback
	}
	parsed, err := strconv.ParseBool(value)
	if err != nil {
		return fallback
	}
	return parsed
}

func parseInt(value string, fallback int) int {
	if value == "" {
		return fallback
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return fallback
	}
	return parsed
}

func parseStringList(value string, fallback []string) []string {
	if value == "" {
		return fallback
	}
	if strings.HasPrefix(strings.TrimSpace(value), "[") {
		var parsed []string
		if err := json.Unmarshal([]byte(value), &parsed); err == nil && len(parsed) > 0 {
			return parsed
		}
	}
	parts := strings.Split(value, ",")
	values := make([]string, 0, len(parts))
	for _, part := range parts {
		trimmed := strings.TrimSpace(part)
		if trimmed != "" {
			values = append(values, trimmed)
		}
	}
	if len(values) == 0 {
		return fallback
	}
	return values
}

func boolToInt(value bool) int {
	if value {
		return 1
	}
	return 0
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
