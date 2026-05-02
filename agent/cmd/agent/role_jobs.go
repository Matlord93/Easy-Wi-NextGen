package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"

	"easywi/agent/internal/jobs"
)

var roleEnsureMutex sync.Mutex

func handleRoleEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	role := strings.ToLower(payloadValue(job.Payload, "role"))
	if role == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing role"},
			Completed: time.Now().UTC(),
		}, nil
	}

	return ensureBaseForRoleResultSerialized(role, job)
}

func handleGameEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	return ensureBaseForRoleResultSerialized("game", job)
}

func handleMailEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	return ensureBaseForRoleResultSerialized("mail", job)
}

func handleDnsEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	return ensureBaseForRoleResultSerialized("dns", job)
}

func handleDbEnsureBase(job jobs.Job) (jobs.Result, func() error) {
	return ensureBaseForRoleResultSerialized("db", job)
}

func ensureBaseForRoleResultSerialized(role string, job jobs.Job) (jobs.Result, func() error) {
	roleEnsureMutex.Lock()
	defer roleEnsureMutex.Unlock()
	return ensureBaseForRoleResult(role, job)
}

func ensureBaseForRoleResult(role string, job jobs.Job) (jobs.Result, func() error) {
	message, err := ensureBaseForRole(role)
	if err != nil {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": err.Error(), "details": message, "role": role},
			Completed: time.Now().UTC(),
		}, nil
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    map[string]string{"message": "role base ensured", "details": message, "role": role},
		Completed: time.Now().UTC(),
	}, nil
}

func ensureBaseForRole(role string) (string, error) {
	if runtime.GOOS == "windows" {
		return ensureBaseForRoleWindows(role)
	}
	if runtime.GOOS != "linux" {
		return "", fmt.Errorf("role ensure is only supported on linux agents")
	}

	family, err := detectOSFamily()
	if err != nil {
		return "", err
	}

	var output strings.Builder
	appendOutput(&output, fmt.Sprintf("detected_os_family=%s", family))

	packages := rolePackages(role, family)
	if len(packages) == 0 {
		return output.String(), fmt.Errorf("unsupported role: %s", role)
	}

	if role == "game" && containsString(packages, "temurin-25-jdk") {
		if err := ensureTemurinRepo(&output); err != nil {
			return output.String(), err
		}
	}

	if err := installPackages(family, packages, &output); err != nil {
		return output.String(), err
	}

	if err := ensureRoleFiles(role, &output); err != nil {
		return output.String(), err
	}
	if role == "mail" {
		if err := ensureMailSecurityDefaults(&output); err != nil {
			return output.String(), err
		}
	}
	if role == "web" || role == "core" {
		if err := ensureWebServerDefaults(&output); err != nil {
			return output.String(), err
		}
	}

	if role == "game" {
		if err := installSteamCmd(&output); err != nil {
			return output.String(), err
		}
	}

	if err := enableRoleServices(role, &output); err != nil {
		return output.String(), err
	}

	return output.String(), nil
}

type windowsRolePlan struct {
	features       []string
	wingetPackages []string
	services       []string
}

func ensureBaseForRoleWindows(role string) (string, error) {
	var output strings.Builder
	appendOutput(&output, "detected_os_family=windows")

	plan := windowsRoleInstallPlan(role)
	if plan == nil {
		return output.String(), fmt.Errorf("unsupported role: %s", role)
	}

	if err := installWindowsFeatures(plan.features, &output); err != nil {
		return output.String(), err
	}
	if err := installWindowsPackages(plan.wingetPackages, &output); err != nil {
		return output.String(), err
	}
	if err := ensureRoleFilesWindows(role, &output); err != nil {
		return output.String(), err
	}
	if role == "game" || role == "web" || role == "core" {
		if err := ensureWindowsSFTPAlternative(&output); err != nil {
			return output.String(), err
		}
	}
	if role == "game" {
		if err := installSteamCmdWindows(&output); err != nil {
			return output.String(), err
		}
	}
	if err := enableWindowsRoleServices(plan.services, &output); err != nil {
		return output.String(), err
	}

	return output.String(), nil
}

func windowsRoleInstallPlan(role string) *windowsRolePlan {
	switch role {
	case "game":
		return &windowsRolePlan{
			wingetPackages: []string{"EclipseAdoptium.Temurin.25.JDK"},
			services:       []string{"sshd"},
		}
	case "web", "core":
		return &windowsRolePlan{
			features: []string{
				"Web-Server", "Web-WebServer", "Web-Common-Http", "Web-Default-Doc", "Web-Static-Content",
				"Web-Http-Errors", "Web-App-Dev", "Web-CGI", "Web-FastCGI", "Web-ISAPI-Ext",
				"Web-ISAPI-Filter", "Web-Asp-Net45", "Web-Mgmt-Tools", "Web-Mgmt-Console",
			},
			wingetPackages: []string{"PHP.PHP"},
			services:       []string{"W3SVC"},
		}
	case "dns":
		return &windowsRolePlan{features: []string{"DNS"}, services: []string{"DNS"}}
	case "mail":
		return &windowsRolePlan{features: []string{"SMTP-Server"}, services: []string{"SMTPSVC"}}
	case "db":
		return &windowsRolePlan{wingetPackages: []string{"MariaDB.Server", "PostgreSQL.PostgreSQL"}}
	default:
		return nil
	}
}

func installWindowsFeatures(features []string, output *strings.Builder) error {
	if len(features) == 0 {
		return nil
	}
	shell := windowsPowerShellBinary()
	if shell == "" {
		return fmt.Errorf("powershell is required to install Windows features")
	}

	featureArray := powershellArrayLiteral(features)
	script := fmt.Sprintf(`
$features=%s

function Split-FeatureAvailability {
	param(
		[string[]]$Requested,
		[string[]]$Available
	)
	$availableSet = @{}
	foreach ($entry in $Available) {
		if ($entry) { $availableSet[$entry] = $true }
	}
	$installable = @()
	$missing = @()
	foreach ($name in $Requested) {
		if ($availableSet.ContainsKey($name)) {
			$installable += $name
		} else {
			$missing += $name
		}
	}
	return @{ Installable = $installable; Missing = $missing }
}

if (Get-Command Install-WindowsFeature -ErrorAction SilentlyContinue) {
	$available = @(Get-WindowsFeature | Where-Object { $_.Name } | ForEach-Object { $_.Name })
	$split = Split-FeatureAvailability -Requested $features -Available $available
	if ($split.Missing.Count -gt 0) {
		Write-Output ("windows_features_skipped=" + ($split.Missing -join ","))
	}
	if ($split.Installable.Count -gt 0) {
		Install-WindowsFeature -Name $split.Installable -IncludeManagementTools
	} else {
		Write-Output "windows_features_nothing_to_install"
	}
} elseif (Get-Command Add-WindowsFeature -ErrorAction SilentlyContinue) {
	$available = @(Get-WindowsFeature | Where-Object { $_.Name } | ForEach-Object { $_.Name })
	$split = Split-FeatureAvailability -Requested $features -Available $available
	if ($split.Missing.Count -gt 0) {
		Write-Output ("windows_features_skipped=" + ($split.Missing -join ","))
	}
	if ($split.Installable.Count -gt 0) {
		Add-WindowsFeature -Name $split.Installable
	} else {
		Write-Output "windows_features_nothing_to_install"
	}
} elseif (Get-Command Enable-WindowsOptionalFeature -ErrorAction SilentlyContinue) {
	$available = @(Get-WindowsOptionalFeature -Online | Where-Object { $_.FeatureName } | ForEach-Object { $_.FeatureName })
	$split = Split-FeatureAvailability -Requested $features -Available $available
	if ($split.Missing.Count -gt 0) {
		Write-Output ("windows_features_skipped=" + ($split.Missing -join ","))
	}
	foreach ($f in $split.Installable) {
		Enable-WindowsOptionalFeature -Online -FeatureName $f -All -NoRestart
	}
	if ($split.Installable.Count -eq 0) {
		Write-Output "windows_features_nothing_to_install"
	}
} else {
	throw "no supported feature installer found"
}`, featureArray)
	return runCommandWithOutput(shell, []string{"-NoProfile", "-NonInteractive", "-Command", script}, output)
}

func installWindowsPackages(packages []string, output *strings.Builder) error {
	if len(packages) == 0 {
		return nil
	}
	if !commandExists("winget") {
		return fmt.Errorf("winget is required to install packages: %s", strings.Join(packages, ", "))
	}
	for _, pkg := range packages {
		if err := runCommandWithOutput("winget", []string{"install", "--id", pkg, "--silent", "--accept-source-agreements", "--accept-package-agreements"}, output); err != nil {
			return err
		}
	}
	return nil
}

func ensureRoleFilesWindows(role string, output *strings.Builder) error {
	baseDir := windowsEasyWiBaseDir()
	rolesDir := filepath.Join(baseDir, "roles.d")
	if err := os.MkdirAll(rolesDir, 0o755); err != nil {
		return fmt.Errorf("create roles dir: %w", err)
	}

	roleConfig := filepath.Join(rolesDir, role+".conf")
	if err := os.WriteFile(roleConfig, []byte("role="+role+"\n"), 0o600); err != nil {
		return fmt.Errorf("write role config: %w", err)
	}
	appendOutput(output, "role_config_written="+roleConfig)

	if role == "game" {
		dirs := []string{
			filepath.Join(baseDir, "game"),
			filepath.Join(baseDir, "game", "steamcmd"),
			filepath.Join(baseDir, "game", "runner"),
			filepath.Join(baseDir, "game", "sniper"),
			filepath.Join(baseDir, "game", "servers"),
			filepath.Join(baseDir, "game", "logs"),
		}
		for _, dir := range dirs {
			if err := os.MkdirAll(dir, 0o750); err != nil {
				return fmt.Errorf("create game dir %s: %w", dir, err)
			}
		}
	}

	return nil
}

func ensureWindowsSFTPAlternative(output *strings.Builder) error {
	shell := windowsPowerShellBinary()
	if shell == "" {
		return fmt.Errorf("powershell is required to configure windows sftp")
	}

	script := strings.Join([]string{
		`$ErrorActionPreference='Stop'`,
		`$cap = Get-WindowsCapability -Online -Name OpenSSH.Server* -ErrorAction SilentlyContinue`,
		`if ($cap -and $cap.State -ne 'Installed') { Add-WindowsCapability -Online -Name $cap.Name | Out-Null }`,
		`if (Get-Service -Name sshd -ErrorAction SilentlyContinue) { Set-Service -Name sshd -StartupType Automatic; Start-Service -Name sshd -ErrorAction SilentlyContinue }`,
		`$cfgPath='C:\ProgramData\ssh\sshd_config'`,
		"if (Test-Path $cfgPath) { $cfg = Get-Content -Path $cfgPath -Raw; if ($cfg -notmatch '(?m)^Port\\s+2222$') { Add-Content -Path $cfgPath -Value '# BEGIN EASYWI MANAGED`nPort 2222`n# END EASYWI MANAGED'; Restart-Service -Name sshd -Force } }",
		`if (-not (Get-NetFirewallRule -DisplayName 'EasyWI-SFTP-2222' -ErrorAction SilentlyContinue)) { New-NetFirewallRule -DisplayName 'EasyWI-SFTP-2222' -Direction Inbound -Action Allow -Protocol TCP -LocalPort 2222 | Out-Null }`,
	}, "; ")

	if err := runCommandWithOutput(shell, []string{"-NoProfile", "-NonInteractive", "-Command", script}, output); err != nil {
		return err
	}
	appendOutput(output, "windows_sftp=openssh_port_2222")
	return nil
}

func enableWindowsRoleServices(services []string, output *strings.Builder) error {
	if len(services) == 0 {
		return nil
	}
	for _, service := range services {
		if err := runCommandWithOutput("sc", []string{"start", service}, output); err != nil {
			appendOutput(output, "service_failed="+service)
		} else {
			appendOutput(output, "service_started="+service)
		}
	}
	return nil
}

func installSteamCmdWindows(output *strings.Builder) error {
	if commandExists("steamcmd") {
		appendOutput(output, "steamcmd=already_installed")
		return nil
	}

	shell := windowsPowerShellBinary()
	if shell == "" {
		return fmt.Errorf("powershell is required to install steamcmd")
	}

	steamCmdDir := filepath.Join(windowsEasyWiBaseDir(), "game", "steamcmd")
	archivePath := filepath.Join(steamCmdDir, "steamcmd.zip")
	script := fmt.Sprintf(`$dir="%s"; New-Item -ItemType Directory -Path $dir -Force | Out-Null; Invoke-WebRequest -Uri "https://steamcdn-a.akamaihd.net/client/installer/steamcmd.zip" -OutFile "%s"; Expand-Archive -Path "%s" -DestinationPath $dir -Force`, steamCmdDir, archivePath, archivePath)
	if err := runCommandWithOutput(shell, []string{"-NoProfile", "-NonInteractive", "-Command", script}, output); err != nil {
		return err
	}
	appendOutput(output, "steamcmd_path="+filepath.Join(steamCmdDir, "steamcmd.exe"))
	return nil
}

func windowsEasyWiBaseDir() string {
	if base := os.Getenv("ProgramData"); base != "" {
		return filepath.Join(base, "EasyWi")
	}
	return filepath.Join("C:\\ProgramData", "EasyWi")
}

func windowsPowerShellBinary() string {
	if commandExists("powershell") {
		return "powershell"
	}
	if commandExists("pwsh") {
		return "pwsh"
	}
	return ""
}

func powershellArrayLiteral(items []string) string {
	if len(items) == 0 {
		return "@()"
	}
	escaped := make([]string, 0, len(items))
	for _, item := range items {
		escaped = append(escaped, "'"+strings.ReplaceAll(item, "'", "''")+"'")
	}
	return "@(" + strings.Join(escaped, ",") + ")"
}

func detectOSFamily() (string, error) {
	content, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return "", fmt.Errorf("read os-release: %w", err)
	}

	var id, idLike string
	for _, line := range strings.Split(string(content), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		value = strings.Trim(value, `"'`)
		switch key {
		case "ID":
			id = value
		case "ID_LIKE":
			idLike = value
		}
	}

	switch id {
	case "debian", "ubuntu":
		return "debian", nil
	case "rhel", "centos", "fedora", "rocky", "almalinux":
		return "rhel", nil
	}

	if strings.Contains(idLike, "debian") {
		return "debian", nil
	}
	if strings.Contains(idLike, "rhel") || strings.Contains(idLike, "fedora") {
		return "rhel", nil
	}

	return "", fmt.Errorf("unsupported os family: %s", id)
}

func rolePackages(role, family string) []string {
	switch role {
	case "game":
		if family == "debian" {
			return []string{"ca-certificates", "curl", "tar", "xz-utils", "unzip", "tmux", "screen", "lib32gcc-s1", "lib32stdc++6", "libc6-i386", "gnupg", "temurin-25-jdk", "proftpd-basic", "proftpd-mod-crypto"}
		}
		if family == "rhel" {
			return []string{"ca-certificates", "curl", "tar", "xz", "unzip", "tmux", "screen", "glibc.i686", "libstdc++.i686", "temurin-25-jdk", "proftpd", "proftpd-utils", "proftpd-mod_sftp"}
		}
	case "web":
		if family == "debian" {
			return []string{"nginx", "php-fpm", "proftpd-basic", "proftpd-mod-crypto"}
		}
		if family == "rhel" {
			return []string{"nginx", "php-fpm", "proftpd", "proftpd-utils", "proftpd-mod_sftp"}
		}
	case "core":
		return []string{"nginx", "php-fpm"}
	case "dns":
		if family == "debian" {
			return []string{"pdns-server", "pdns-backend-bind"}
		}
		if family == "rhel" {
			return []string{"pdns", "pdns-backend-bind"}
		}
	case "mail":
		return append([]string{"postfix"}, mailDovecotPackages(family)...)
	case "db":
		if family == "debian" {
			return []string{"mariadb-server", "postgresql"}
		}
		if family == "rhel" {
			return []string{"mariadb-server", "postgresql-server"}
		}
	case "ts3", "ts6", "sinusbot":
		return roleBasePackages(family)
	}
	return nil
}

func roleBasePackages(family string) []string {
	if family == "debian" {
		return []string{"ca-certificates", "curl", "tar", "xz-utils", "bzip2"}
	}
	if family == "rhel" {
		return []string{"ca-certificates", "curl", "tar", "xz", "bzip2"}
	}
	return nil
}

func mailDovecotPackages(family string) []string {
	if family != "debian" {
		return []string{"dovecot"}
	}

	if commandExists("apt-cache") {
		if err := exec.Command("apt-cache", "show", "dovecot-core").Run(); err == nil {
			return []string{"dovecot-core", "dovecot-imapd"}
		}
		if err := exec.Command("apt-cache", "show", "dovecot").Run(); err == nil {
			return []string{"dovecot"}
		}
	}

	return []string{"dovecot-core", "dovecot-imapd"}
}

func installPackages(family string, packages []string, output *strings.Builder) error {
	if len(packages) == 0 {
		return nil
	}

	var updateCmd []string
	var installCmd []string

	switch family {
	case "debian":
		updateCmd = []string{"apt-get", "update", "-y"}
		installCmd = append([]string{"apt-get", "install", "-y"}, packages...)
	case "rhel":
		if commandExists("dnf") {
			updateCmd = []string{"dnf", "makecache"}
			installCmd = append([]string{"dnf", "install", "-y"}, packages...)
		} else {
			updateCmd = []string{"yum", "makecache"}
			installCmd = append([]string{"yum", "install", "-y"}, packages...)
		}
	default:
		return fmt.Errorf("unsupported package manager for family: %s", family)
	}

	if err := runCommandWithOutput(updateCmd[0], updateCmd[1:], output); err != nil {
		return err
	}
	if err := runCommandWithOutput(installCmd[0], installCmd[1:], output); err != nil {
		return err
	}

	return nil
}

func installSteamCmd(output *strings.Builder) error {
	if commandExists("steamcmd") {
		appendOutput(output, "steamcmd=already_installed")
		return nil
	}

	const steamCmdURL = "https://steamcdn-a.akamaihd.net/client/installer/steamcmd_linux.tar.gz"
	steamCmdDir := "/var/lib/easywi/game/steamcmd"
	archivePath := filepath.Join(steamCmdDir, "steamcmd_linux.tar.gz")

	if err := os.MkdirAll(steamCmdDir, 0o750); err != nil {
		return fmt.Errorf("create steamcmd dir: %w", err)
	}
	if err := os.Chmod(steamCmdDir, 0o755); err != nil {
		return fmt.Errorf("chmod steamcmd dir: %w", err)
	}

	switch {
	case commandExists("curl"):
		if err := runCommandWithOutput("curl", []string{"-fsSL", steamCmdURL, "-o", archivePath}, output); err != nil {
			return err
		}
	case commandExists("wget"):
		if err := runCommandWithOutput("wget", []string{"-qO", archivePath, steamCmdURL}, output); err != nil {
			return err
		}
	default:
		return fmt.Errorf("steamcmd download failed: missing curl or wget")
	}

	if err := runCommandWithOutput("tar", []string{"-xzf", archivePath, "-C", steamCmdDir}, output); err != nil {
		return err
	}
	if err := os.Remove(archivePath); err != nil {
		appendOutput(output, "steamcmd_archive_cleanup_failed="+err.Error())
	}

	steamCmdPath := filepath.Join(steamCmdDir, "steamcmd.sh")
	if _, err := os.Stat(steamCmdPath); err == nil {
		if err := os.Chmod(steamCmdPath, 0o755); err != nil {
			return fmt.Errorf("chmod steamcmd: %w", err)
		}
		if err := runCommandWithOutput("ln", []string{"-sf", steamCmdPath, "/usr/local/bin/steamcmd"}, output); err != nil {
			return err
		}
	}

	return nil
}

func ensureTemurinRepo(output *strings.Builder) error {
	family, err := detectOSFamily()
	if err != nil {
		return err
	}

	switch family {
	case "debian":
		return ensureTemurinRepoDebian(output)
	case "rhel":
		return ensureTemurinRepoRhel(output)
	default:
		return nil
	}
}

// adoptiumDebianCodename maps an OS codename to the nearest Adoptium-supported
// Debian/Ubuntu codename. Adoptium publishes releases for specific LTS
// codenames; newer or unsupported codenames fall back to the latest known one.
func adoptiumDebianCodename(codename string) string {
	supported := map[string]bool{
		"bionic": true, // Ubuntu 18.04
		"focal":  true, // Ubuntu 20.04
		"jammy":  true, // Ubuntu 22.04
		"noble":  true, // Ubuntu 24.04
		"buster":   true, // Debian 10
		"bullseye": true, // Debian 11
		"bookworm": true, // Debian 12
	}
	if supported[codename] {
		return codename
	}
	// Ordered newest-to-oldest so we pick the highest supported version.
	fallbackOrder := []string{"noble", "jammy", "focal", "bionic"}
	for _, fb := range fallbackOrder {
		if supported[fb] {
			return fb
		}
	}
	return codename
}

func ensureTemurinRepoDebian(output *strings.Builder) error {
	const keyringPath = "/etc/apt/keyrings/adoptium.gpg"
	const listPath = "/etc/apt/sources.list.d/adoptium.list"

	codename, err := debianCodename()
	if err != nil {
		return err
	}
	if codename == "" {
		return fmt.Errorf("unable to resolve debian codename for adoptium repo")
	}
	targetCodename := adoptiumDebianCodename(codename)

	if existing, err := os.ReadFile(listPath); err == nil {
		if strings.Contains(string(existing), " "+targetCodename+" ") {
			appendOutput(output, "adoptium_repo=already_configured")
			return nil
		}
		// File exists but uses a codename that Adoptium doesn't support
		// (e.g. "resolute"). Overwrite it with the correct codename.
		newLine := fmt.Sprintf("deb [signed-by=%s] https://packages.adoptium.net/artifactory/deb %s main\n", keyringPath, targetCodename)
		if err := os.WriteFile(listPath, []byte(newLine), 0o644); err != nil {
			return fmt.Errorf("update adoptium list: %w", err)
		}
		appendOutput(output, "adoptium_repo=migrated_codename")
		return nil
	}

	codename = targetCodename

	if err := runCommandWithOutput("mkdir", []string{"-p", "/etc/apt/keyrings"}, output); err != nil {
		return err
	}

	keyPath := "/tmp/adoptium.asc"
	switch {
	case commandExists("curl"):
		if err := runCommandWithOutput("curl", []string{"-fsSL", "https://packages.adoptium.net/artifactory/api/gpg/key/public", "-o", keyPath}, output); err != nil {
			return err
		}
	case commandExists("wget"):
		if err := runCommandWithOutput("wget", []string{"-qO", keyPath, "https://packages.adoptium.net/artifactory/api/gpg/key/public"}, output); err != nil {
			return err
		}
	default:
		return fmt.Errorf("adoptium repo setup failed: missing curl or wget")
	}

	if !commandExists("gpg") {
		if commandExists("apt-get") {
			if err := runCommandWithOutput("apt-get", []string{"update"}, output); err != nil {
				return err
			}
			if err := runCommandWithOutput("apt-get", []string{"install", "-y", "gnupg"}, output); err != nil {
				return err
			}
		}
	}
	if !commandExists("gpg") {
		return fmt.Errorf("adoptium repo setup failed: gpg not available")
	}

	if err := runCommandWithOutput("gpg", []string{"--dearmor", "-o", keyringPath, keyPath}, output); err != nil {
		return err
	}

	repoLine := fmt.Sprintf("deb [signed-by=%s] https://packages.adoptium.net/artifactory/deb %s main\n", keyringPath, codename)
	if err := os.WriteFile(listPath, []byte(repoLine), 0o644); err != nil {
		return fmt.Errorf("write adoptium list: %w", err)
	}
	appendOutput(output, "adoptium_repo=configured")

	return nil
}

func ensureTemurinRepoRhel(output *strings.Builder) error {
	const repoPath = "/etc/yum.repos.d/adoptium.repo"

	if _, err := os.Stat(repoPath); err == nil {
		appendOutput(output, "adoptium_repo=already_configured")
		return nil
	}

	majorVersion, err := rhelMajorVersion()
	if err != nil {
		return err
	}
	if majorVersion == "" {
		return fmt.Errorf("unable to resolve rhel major version for adoptium repo")
	}

	baseURL := fmt.Sprintf("https://packages.adoptium.net/artifactory/rpm/centos/%s/x86_64", majorVersion)
	repo := fmt.Sprintf(`[Adoptium]
name=Adoptium
baseurl=%s
enabled=1
gpgcheck=1
gpgkey=https://packages.adoptium.net/artifactory/api/gpg/key/public
`, baseURL)

	if err := os.WriteFile(repoPath, []byte(repo), 0o644); err != nil {
		return fmt.Errorf("write adoptium repo: %w", err)
	}
	appendOutput(output, "adoptium_repo=configured")

	return nil
}

func debianCodename() (string, error) {
	content, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return "", fmt.Errorf("read os-release: %w", err)
	}

	var codename string
	for _, line := range strings.Split(string(content), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		value = strings.Trim(value, `"'`)
		switch key {
		case "VERSION_CODENAME":
			codename = value
		case "UBUNTU_CODENAME":
			if codename == "" {
				codename = value
			}
		}
	}

	return codename, nil
}

func rhelMajorVersion() (string, error) {
	content, err := os.ReadFile("/etc/os-release")
	if err != nil {
		return "", fmt.Errorf("read os-release: %w", err)
	}

	for _, line := range strings.Split(string(content), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok || key != "VERSION_ID" {
			continue
		}
		value = strings.Trim(value, `"'`)
		parts := strings.Split(value, ".")
		if len(parts) > 0 {
			return parts[0], nil
		}
	}

	return "", nil
}

func containsString(values []string, target string) bool {
	for _, value := range values {
		if value == target {
			return true
		}
	}
	return false
}

func ensureRoleFiles(role string, output *strings.Builder) error {
	if err := os.MkdirAll("/etc/easywi/roles.d", 0o755); err != nil {
		return fmt.Errorf("create roles dir: %w", err)
	}

	roleConfig := filepath.Join("/etc/easywi/roles.d", role+".conf")
	if err := os.WriteFile(roleConfig, []byte("role="+role+"\n"), 0o600); err != nil {
		return fmt.Errorf("write role config: %w", err)
	}
	appendOutput(output, "role_config_written="+roleConfig)

	if role == "game" {
		dirs := []string{
			"/etc/easywi/game",
			"/var/lib/easywi/game",
			"/var/lib/easywi/game/steamcmd",
			"/var/lib/easywi/game/runner",
			"/var/lib/easywi/game/sniper",
			"/var/lib/easywi/game/servers",
			"/var/log/easywi/game",
		}
		for _, dir := range dirs {
			if err := os.MkdirAll(dir, 0o750); err != nil {
				return fmt.Errorf("create game dir %s: %w", dir, err)
			}
			switch dir {
			case "/var/lib/easywi/game":
				if err := os.Chmod(dir, 0o711); err != nil {
					return fmt.Errorf("chmod game dir %s: %w", dir, err)
				}
			case "/var/lib/easywi/game/steamcmd":
				if err := os.Chmod(dir, 0o755); err != nil {
					return fmt.Errorf("chmod steamcmd dir %s: %w", dir, err)
				}
			}
		}
	}

	return nil
}

func ensureMailSecurityDefaults(output *strings.Builder) error {
	if !commandExists("postconf") {
		appendOutput(output, "postconf_missing=true")
		return nil
	}

	settings := []string{
		"smtpd_sasl_auth_enable=yes",
		"smtpd_sasl_type=dovecot",
		"smtpd_sasl_path=private/auth",
		"smtpd_tls_security_level=may",
		"smtpd_tls_auth_only=yes",
		"smtpd_helo_required=yes",
		"smtpd_helo_restrictions=reject_invalid_helo_hostname,reject_non_fqdn_helo_hostname",
		"smtpd_sender_restrictions=reject_non_fqdn_sender,reject_unknown_sender_domain",
		"smtpd_recipient_restrictions=reject_non_fqdn_recipient,reject_unknown_recipient_domain,reject_unlisted_recipient,permit_mynetworks,permit_sasl_authenticated,reject_unauth_destination",
		"smtpd_relay_restrictions=permit_mynetworks,permit_sasl_authenticated,reject_unauth_destination",
	}

	for _, setting := range settings {
		if err := runCommandWithOutput("postconf", []string{"-e", setting}, output); err != nil {
			return err
		}
	}

	dovecotConfDir := "/etc/dovecot/conf.d"
	if _, err := os.Stat(dovecotConfDir); err == nil {
		authConfPath := filepath.Join(dovecotConfDir, "99-easywi-auth.conf")
		authConf := "## Managed by Easy-Wi agent\n" +
			"auth_mechanisms = plain login\n" +
			"service auth {\n" +
			"  unix_listener /var/spool/postfix/private/auth {\n" +
			"    mode = 0660\n" +
			"    user = postfix\n" +
			"    group = postfix\n" +
			"  }\n" +
			"}\n"
		if err := os.WriteFile(authConfPath, []byte(authConf), 0o640); err != nil {
			return fmt.Errorf("write dovecot auth config: %w", err)
		}
		appendOutput(output, "dovecot_auth_written="+authConfPath)

		usersConfPath := filepath.Join(dovecotConfDir, "99-easywi-users.conf")
		usersConf := "## Managed by Easy-Wi agent\n" +
			"passdb {\n" +
			"  driver = passwd-file\n" +
			"  args = scheme=SHA512-CRYPT username_format=%u /etc/dovecot/users\n" +
			"}\n" +
			"userdb {\n" +
			"  driver = static\n" +
			"  args = uid=vmail gid=vmail home=/var/mail/vhosts/%d/%n\n" +
			"}\n"
		if err := os.WriteFile(usersConfPath, []byte(usersConf), 0o640); err != nil {
			return fmt.Errorf("write dovecot users config: %w", err)
		}
		appendOutput(output, "dovecot_users_written="+usersConfPath)
	} else {
		appendOutput(output, "dovecot_conf_missing=true")
	}

	if commandExists("systemctl") {
		_ = runCommandWithOutput("systemctl", []string{"reload", "postfix"}, output)
		_ = runCommandWithOutput("systemctl", []string{"reload", "dovecot"}, output)
	}

	return nil
}

func ensureWebServerDefaults(output *strings.Builder) error {
	dirs := []string{
		"/etc/easywi/web/nginx/vhosts",
		"/etc/easywi/web/apache/vhosts",
	}
	for _, dir := range dirs {
		if err := os.MkdirAll(dir, 0o750); err != nil {
			return fmt.Errorf("create webserver dir %s: %w", dir, err)
		}
		appendOutput(output, "webserver_dir_ready="+dir)
	}

	if _, err := os.Stat("/etc/nginx"); err == nil {
		nginxIncludePath := "/etc/nginx/conf.d/99-easywi-vhosts.conf"
		nginxInclude := "## Managed by Easy-Wi agent\ninclude /etc/easywi/web/nginx/vhosts/*.conf;\n"
		if err := os.WriteFile(nginxIncludePath, []byte(nginxInclude), 0o640); err != nil {
			return fmt.Errorf("write nginx include config: %w", err)
		}
		appendOutput(output, "nginx_include_written="+nginxIncludePath)
	}

	if _, err := os.Stat("/etc/apache2"); err == nil {
		apacheConfPath := "/etc/apache2/conf-available/easywi-vhosts.conf"
		apacheConf := "## Managed by Easy-Wi agent\nIncludeOptional /etc/easywi/web/apache/vhosts/*.conf\n"
		if err := os.WriteFile(apacheConfPath, []byte(apacheConf), 0o640); err != nil {
			return fmt.Errorf("write apache include config: %w", err)
		}
		appendOutput(output, "apache_include_written="+apacheConfPath)

		if commandExists("a2enconf") {
			_ = runCommandWithOutput("a2enconf", []string{"easywi-vhosts"}, output)
		}
	}

	if commandExists("systemctl") {
		_ = runCommandWithOutput("systemctl", []string{"reload", "nginx"}, output)
		_ = runCommandWithOutput("systemctl", []string{"reload", "apache2"}, output)
	}

	return nil
}

func enableRoleServices(role string, output *strings.Builder) error {
	var services []string

	switch role {
	case "web":
		services = []string{"nginx", "php-fpm", "php8.4-fpm", "php8.3-fpm", "php8.2-fpm", "php8.1-fpm", "proftpd", "proftpd.service", "proftpd-basic", "proftpd-basic.service"}
	case "core":
		services = []string{"nginx", "php-fpm", "php8.4-fpm", "php8.3-fpm", "php8.2-fpm", "php8.1-fpm"}
	case "dns":
		services = []string{"pdns", "powerdns"}
	case "mail":
		services = []string{"postfix", "dovecot"}
	case "db":
		services = []string{"mariadb", "mysql", "postgresql"}
	case "game":
		services = []string{"proftpd", "proftpd.service", "proftpd-basic", "proftpd-basic.service"}
	default:
		return nil
	}

	for _, service := range services {
		if !commandExists("systemctl") {
			continue
		}
		if err := runCommandWithOutput("systemctl", []string{"enable", "--now", service}, output); err != nil {
			appendOutput(output, "service_failed="+service)
		} else {
			appendOutput(output, "service_enabled="+service)
		}
	}

	return nil
}

func commandExists(name string) bool {
	_, err := exec.LookPath(name)
	return err == nil
}

func runCommandWithOutput(name string, args []string, output *strings.Builder) error {
	cmd := exec.Command(name, args...)
	if name == "apt-get" {
		cmd.Env = append(os.Environ(), "DEBIAN_FRONTEND=noninteractive")
	}
	commandOutput, err := StreamCommand(cmd, "", nil)
	appendOutput(output, fmt.Sprintf("cmd=%s %s", name, strings.Join(args, " ")))
	if len(commandOutput) > 0 {
		appendOutput(output, commandOutput)
	}
	if err != nil {
		return fmt.Errorf("command %s failed: %w", name, err)
	}
	return nil
}

func appendOutput(output *strings.Builder, message string) {
	const maxOutput = 4000
	if output.Len() >= maxOutput {
		return
	}
	if output.Len()+len(message)+1 > maxOutput {
		message = message[:maxOutput-output.Len()-1]
	}
	output.WriteString(message)
	output.WriteString("\n")
}
