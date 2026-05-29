package main

import (
	"bufio"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"golang.org/x/crypto/bcrypt"
)

const (
	linuxAuthUserFile       = "/var/lib/easywi/proftpd/passwd"
	linuxKeyDir             = "/etc/proftpd/keys"
	linuxHostKey            = "/etc/proftpd/keys/easywi_rsa_key"
	windowsEmbeddedService  = "EasyWI-SFTP"
	windowsEmbeddedConfig   = `C:\ProgramData\EasyWI\sftp\config.json`
	windowsEmbeddedUsers    = `C:\ProgramData\EasyWI\sftp\users.json`
	windowsEmbeddedBinary   = "easywi-sftp.exe"
	windowsOpenSSHConfig    = `C:\ProgramData\ssh\sshd_config`
	defaultAccessListenPort = 2222
)

type accessEnvelope struct {
	OK        bool              `json:"ok"`
	Data      map[string]any    `json:"data,omitempty"`
	ErrorCode string            `json:"error_code,omitempty"`
	Message   string            `json:"message,omitempty"`
	RequestID string            `json:"request_id,omitempty"`
	Debug     map[string]string `json:"debug,omitempty"`
}

type accessCapabilities struct {
	SupportedBackends []string          `json:"supported_backends"`
	DefaultBackend    string            `json:"default_backend"`
	Exclusions        map[string]string `json:"exclusions,omitempty"`
}

type proFTPDModuleRepairDiagnostics struct {
	ModuleConfigPath     string
	ModuleConfigRepaired bool
	ModuleSOFound        bool
	ModuleSOPath         string
	ModuleConfigError    string
	ProFTPDTestOutput    string
	ServiceRestartOutput string
	PackageInstallOutput string
	DistroID             string
	DistroLike           string
	PackageManager       string
	AttemptedPackages    []string
	SearchedModulePaths  []string
}

func (d proFTPDModuleRepairDiagnostics) String() string {
	parts := []string{
		fmt.Sprintf("module_config_path=%s", d.ModuleConfigPath),
		fmt.Sprintf("module_config_repaired=%t", d.ModuleConfigRepaired),
		fmt.Sprintf("module_so_found=%t", d.ModuleSOFound),
		fmt.Sprintf("module_so_path=%s", d.ModuleSOPath),
		fmt.Sprintf("module_config_error=%s", sanitizeDiagnosticValue(d.ModuleConfigError)),
		fmt.Sprintf("proftpd_test_output=%s", sanitizeDiagnosticValue(d.ProFTPDTestOutput)),
		fmt.Sprintf("service_restart_output=%s", sanitizeDiagnosticValue(d.ServiceRestartOutput)),
		fmt.Sprintf("package_install_output=%s", sanitizeDiagnosticValue(d.PackageInstallOutput)),
		fmt.Sprintf("distro_id=%s", d.DistroID),
		fmt.Sprintf("distro_like=%s", d.DistroLike),
		fmt.Sprintf("package_manager=%s", d.PackageManager),
		fmt.Sprintf("attempted_packages=%s", strings.Join(d.AttemptedPackages, ",")),
		fmt.Sprintf("searched_module_paths=%s", strings.Join(d.SearchedModulePaths, ",")),
	}
	return strings.Join(parts, " ")
}

func sanitizeDiagnosticValue(value string) string {
	value = strings.ReplaceAll(value, "\n", " ")
	value = strings.ReplaceAll(value, "\r", " ")
	return strings.TrimSpace(value)
}

var (
	accessLookPath                  = exec.LookPath
	accessRunCommandLogged          = runCommandLogged
	accessRunCommandOutput          = runCommandOutput
	proFTPDAuthUserFilePath         = linuxAuthUserFile
	proFTPDHostKeyPath              = linuxHostKey
	detectProFTPDConfDirFunc        = detectProFTPDConfDir
	proFTPDModuleConfigCandidates   = defaultProFTPDModuleConfigCandidates
	proFTPDModuleSearchPatterns     = defaultProFTPDModuleSearchPatterns
	proFTPDManagedModulesPath       = defaultProFTPDManagedModulesPath
	proFTPDAppArmorProfilePath      = "/etc/apparmor.d/proftpd"
	proFTPDAppArmorLocalPath        = "/etc/apparmor.d/local/proftpd"
	proFTPDAppArmorActiveFunc       = apparmorLikelyActive
	ensureLinuxProFTPDSFTPReadyFunc = ensureLinuxProFTPDSFTPReady
	ensureProFTPDUserFunc           = ensureProFTPDUser
	checkLinuxProFTPDHealthFunc     = checkLinuxProFTPDHealth
	detectProFTPDServiceNameFunc    = detectProFTPDServiceName
	ensureServiceRunningFunc        = ensureServiceRunning
	checkTCPListeningFunc           = checkTCPListening
	linuxPackageInstallMu           sync.Mutex
	accessPackageManagerRetrySleep  = 5 * time.Second
)

func handleAccessCapabilitiesHTTP(w http.ResponseWriter, r *http.Request) bool {
	if r.URL.Path != "/v1/access/capabilities" {
		return false
	}
	requestID := strings.TrimSpace(r.Header.Get("X-Request-ID"))
	if r.Method != http.MethodGet {
		writeAccessEnvelope(w, http.StatusMethodNotAllowed, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "method not allowed", RequestID: requestID})
		return true
	}
	caps := discoverAccessCapabilities()
	writeAccessEnvelope(w, http.StatusOK, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{
		"supported_backends": caps.SupportedBackends,
		"default_backend":    caps.DefaultBackend,
		"exclusions":         caps.Exclusions,
	}})
	return true
}

func handleInstanceAccessHTTP(w http.ResponseWriter, r *http.Request, instanceID string) bool {
	base := "/v1/instances/" + strings.TrimSpace(instanceID) + "/access/"
	if !strings.HasPrefix(r.URL.Path, base) {
		return false
	}
	requestID := strings.TrimSpace(r.Header.Get("X-Request-ID"))
	action := strings.Trim(strings.TrimPrefix(r.URL.Path, base), "/ ")

	switch action {
	case "health":
		if r.Method != http.MethodGet {
			writeAccessEnvelope(w, http.StatusMethodNotAllowed, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "method not allowed", RequestID: requestID})
			return true
		}
		response := accessHealth()
		response.RequestID = requestID
		writeAccessEnvelope(w, http.StatusOK, response)
		return true
	case "provision", "reset":
		if r.Method != http.MethodPost {
			writeAccessEnvelope(w, http.StatusMethodNotAllowed, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "method not allowed", RequestID: requestID})
			return true
		}
		payload := parseQueryHTTPPayload(r)
		username := strings.TrimSpace(payload["username"])
		password := strings.TrimSpace(payload["password"])
		rootPath := strings.TrimSpace(payload["root_path"])
		preferred := strings.ToUpper(strings.TrimSpace(payload["preferred_backend"]))
		host := strings.TrimSpace(payload["host"])
		if host == "" {
			host = "127.0.0.1"
		}
		if username == "" || password == "" || rootPath == "" {
			writeAccessEnvelope(w, http.StatusUnprocessableEntity, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "username, password and root_path are required", RequestID: requestID})
			return true
		}
		if !filepath.IsAbs(rootPath) || strings.Contains(rootPath, "..") {
			writeAccessEnvelope(w, http.StatusUnprocessableEntity, accessEnvelope{OK: false, ErrorCode: "ROOT_INVALID", Message: "root_path must be absolute and safe", RequestID: requestID})
			return true
		}

		backend, err := provisionAccessBackend(username, password, rootPath, preferred)
		if err != nil {
			writeAccessEnvelope(w, http.StatusOK, accessEnvelope{OK: false, ErrorCode: mapAccessErr(err), Message: sanitizeAccessError(err), RequestID: requestID})
			return true
		}

		h := accessHealth()
		if !h.OK {
			h.RequestID = requestID
			writeAccessEnvelope(w, http.StatusOK, h)
			return true
		}

		writeAccessEnvelope(w, http.StatusOK, accessEnvelope{OK: true, RequestID: requestID, Data: map[string]any{
			"backend":        backend,
			"host":           host,
			"port":           defaultAccessListenPort,
			"username":       username,
			"root_path":      rootPath,
			"service_status": "running",
		}})
		return true
	default:
		writeAccessEnvelope(w, http.StatusNotFound, accessEnvelope{OK: false, ErrorCode: "INVALID_INPUT", Message: "unknown access action", RequestID: requestID})
		return true
	}
}

func writeAccessEnvelope(w http.ResponseWriter, status int, response accessEnvelope) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(response)
}

func discoverAccessCapabilities() accessCapabilities {
	exclusions := map[string]string{}
	supported := make([]string, 0, 3)

	if runtime.GOOS == "windows" {
		embeddedPath := findEmbeddedSFTPBinary()
		if embeddedPath != "" {
			supported = append(supported, "WINDOWS_EMBEDDED_SFTP")
		} else {
			exclusions["WINDOWS_EMBEDDED_SFTP"] = "embedded binary easywi-sftp.exe not found"
		}
		supported = append(supported, "WINDOWS_OPENSSH_SFTP")
		defaultBackend := "WINDOWS_OPENSSH_SFTP"
		if len(supported) > 0 && supported[0] == "WINDOWS_EMBEDDED_SFTP" {
			defaultBackend = "WINDOWS_EMBEDDED_SFTP"
		}
		return accessCapabilities{SupportedBackends: supported, DefaultBackend: defaultBackend, Exclusions: exclusions}
	}

	if hasLinuxPackageManager() {
		supported = append(supported, "PROFTPD_SFTP")
	} else {
		exclusions["PROFTPD_SFTP"] = "no supported package manager found"
	}
	if len(supported) == 0 {
		supported = append(supported, "NONE")
	}
	return accessCapabilities{SupportedBackends: supported, DefaultBackend: supported[0], Exclusions: exclusions}
}

func accessHealth() accessEnvelope {
	caps := discoverAccessCapabilities()
	preferred := caps.DefaultBackend
	if preferred == "WINDOWS_EMBEDDED_SFTP" {
		if err := checkWindowsEmbeddedSFTPHealth(); err == nil {
			return accessEnvelope{OK: true, Data: map[string]any{"backend": "WINDOWS_EMBEDDED_SFTP", "service_status": "running", "port": defaultAccessListenPort}}
		}
	}

	if runtime.GOOS == "windows" {
		if err := checkWindowsOpenSSHHealth(); err != nil {
			return accessEnvelope{OK: false, ErrorCode: mapAccessErr(err), Message: sanitizeAccessError(err), Data: map[string]any{"backend": "NONE", "service_status": "down"}}
		}
		return accessEnvelope{OK: true, Data: map[string]any{"backend": "WINDOWS_OPENSSH_SFTP", "service_status": "running", "port": defaultAccessListenPort}}
	}

	if err := checkLinuxProFTPDHealth(); err != nil {
		return accessEnvelope{OK: false, ErrorCode: mapAccessErr(err), Message: sanitizeAccessError(err), Data: map[string]any{"backend": "NONE", "service_status": "down"}}
	}
	return accessEnvelope{OK: true, Data: map[string]any{"backend": "PROFTPD_SFTP", "service_status": "running", "port": defaultAccessListenPort}}
}

func provisionAccessBackend(username, password, rootPath, preferred string) (string, error) {
	caps := discoverAccessCapabilities()
	backend := preferred
	if backend == "" || backend == "NONE" {
		backend = caps.DefaultBackend
	}

	if runtime.GOOS == "windows" {
		if backend == "WINDOWS_EMBEDDED_SFTP" {
			if err := provisionWindowsEmbeddedSFTP(username, password, rootPath); err != nil {
				if !strings.Contains(strings.ToUpper(err.Error()), "BACKEND_UNSUPPORTED") {
					return "", err
				}
			}
			if err := checkWindowsEmbeddedSFTPHealth(); err == nil {
				return "WINDOWS_EMBEDDED_SFTP", nil
			}
		}
		if backend != "WINDOWS_OPENSSH_SFTP" && backend != "WINDOWS_EMBEDDED_SFTP" {
			return "", fmt.Errorf("BACKEND_UNSUPPORTED: %s", backend)
		}
		if err := provisionWindowsOpenSSH(username, password, rootPath); err != nil {
			return "", err
		}
		if err := checkWindowsOpenSSHHealth(); err != nil {
			return "", err
		}
		return "WINDOWS_OPENSSH_SFTP", nil
	}

	if backend != "PROFTPD_SFTP" && backend != "FTP_ONLY" {
		return "", fmt.Errorf("BACKEND_UNSUPPORTED: %s", backend)
	}
	if err := provisionLinuxProFTPD(username, password, rootPath); err != nil {
		return "", err
	}
	if err := checkLinuxProFTPDHealth(); err != nil {
		return "", err
	}
	return "PROFTPD_SFTP", nil
}

func provisionLinuxProFTPD(username, password, rootPath string) error {
	if err := ensureLinuxProFTPDSFTPReadyFunc(); err != nil {
		return err
	}
	if err := ensureProFTPDUserFunc(username, password, rootPath); err != nil {
		return err
	}
	if err := checkLinuxProFTPDHealthFunc(); err != nil {
		return err
	}
	return nil
}

func ensureLinuxProFTPDSFTPReady() error {
	osID, osLike := detectLinuxDistribution()
	pkgs := linuxPackagesForDistro(osID, osLike)
	if len(pkgs) == 0 {
		return fmt.Errorf("BACKEND_UNSUPPORTED: unsupported linux distro id=%s like=%s", osID, osLike)
	}
	log.Printf("proftpd sftp provisioning start distro_id=%s distro_like=%s packages=%s", osID, osLike, strings.Join(pkgs, ","))
	if err := installLinuxPackages(pkgs); err != nil {
		return fmt.Errorf("%w (distro_id=%s distro_like=%s packages=%s)", err, osID, osLike, strings.Join(pkgs, ","))
	}
	if err := ensureEasyWIUser(); err != nil {
		return err
	}
	if err := ensureProFTPDAuthFile(); err != nil {
		return err
	}
	if err := ensureProFTPDKeys(); err != nil {
		return err
	}
	confDir, err := detectProFTPDConfDirFunc()
	if err != nil {
		return err
	}
	if err := ensureProFTPDConfig(confDir); err != nil {
		return err
	}
	if err := ensureProFTPDAppArmorAccess(); err != nil {
		return err
	}
	if err := ensureProFTPDSFTPModuleInstalled(); err != nil {
		return fmt.Errorf("%w (distro_id=%s distro_like=%s packages=%s)", err, osID, osLike, strings.Join(pkgs, ","))
	}
	if out, err := runProFTPDConfigTestWithAuthRepair(); err != nil {
		if proFTPDTestOutputHasSFTPModuleError(out) {
			return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD configuration cannot load mod_sftp: %s", strings.TrimSpace(out))
		}
		return fmt.Errorf("CONFIG_INVALID: ProFTPD configuration test failed: %s", strings.TrimSpace(out))
	}
	if err := ensureLinuxSFTPFirewall(defaultAccessListenPort); err != nil {
		return err
	}
	serviceName, err := detectProFTPDServiceNameFunc()
	if err != nil {
		return err
	}
	if err := ensureServiceRunningFunc(serviceName); err != nil {
		return err
	}
	if err := checkLinuxProFTPDHealthFunc(); err != nil {
		return err
	}
	return nil
}

func detectLinuxDistribution() (string, string) {
	f, err := os.Open("/etc/os-release")
	if err != nil {
		return "", ""
	}
	defer func() { _ = f.Close() }()
	id, like := "", ""
	s := bufio.NewScanner(f)
	for s.Scan() {
		line := strings.TrimSpace(s.Text())
		if strings.HasPrefix(line, "ID=") {
			id = strings.Trim(strings.TrimPrefix(line, "ID="), "\"'")
		}
		if strings.HasPrefix(line, "ID_LIKE=") {
			like = strings.Trim(strings.TrimPrefix(line, "ID_LIKE="), "\"'")
		}
	}
	return strings.ToLower(id), strings.ToLower(like)
}

func linuxPackagesForDistro(osID, like string) []string {
	joined := osID + " " + like
	if strings.Contains(joined, "debian") || strings.Contains(joined, "ubuntu") {
		return []string{"proftpd-basic", "proftpd-mod-crypto"}
	}
	if strings.Contains(joined, "rhel") || strings.Contains(joined, "centos") || strings.Contains(joined, "fedora") || strings.Contains(joined, "rocky") || strings.Contains(joined, "almalinux") {
		return []string{"proftpd", "proftpd-utils", "proftpd-mod_sftp"}
	}
	if hasLinuxPackageManager() {
		return []string{"proftpd"}
	}
	return nil
}

func hasLinuxPackageManager() bool {
	_, aptErr := accessLookPath("apt-get")
	_, dnfErr := accessLookPath("dnf")
	_, yumErr := accessLookPath("yum")
	return aptErr == nil || dnfErr == nil || yumErr == nil
}

func installLinuxPackages(packages []string) error {
	if len(packages) == 0 {
		return nil
	}

	linuxPackageInstallMu.Lock()
	defer linuxPackageInstallMu.Unlock()

	if _, err := accessLookPath("apt-get"); err == nil {
		if out, err := runPackageManagerCommandWithRetry("apt-get", []string{"update"}, 12); err != nil {
			return fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=apt-get packages=%s output=%s", strings.Join(packages, ","), strings.TrimSpace(out))
		}
		args := append([]string{"install", "-y"}, packages...)
		if out, err := runPackageManagerCommandWithRetry("apt-get", args, 12); err != nil {
			return fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=apt-get packages=%s output=%s", strings.Join(packages, ","), strings.TrimSpace(out))
		}
		return nil
	}
	installer := ""
	if _, err := accessLookPath("dnf"); err == nil {
		installer = "dnf"
	} else if _, err := accessLookPath("yum"); err == nil {
		installer = "yum"
	}
	if installer == "" {
		return fmt.Errorf("BACKEND_UNSUPPORTED: no package manager for packages=%s", strings.Join(packages, ","))
	}
	args := append([]string{"install", "-y"}, packages...)
	if out, err := runPackageManagerCommandWithRetry(installer, args, 6); err != nil {
		return fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=%s packages=%s output=%s", installer, strings.Join(packages, ","), strings.TrimSpace(out))
	}
	return nil
}

func runPackageManagerCommandWithRetry(name string, args []string, maxAttempts int) (string, error) {
	if maxAttempts < 1 {
		maxAttempts = 1
	}
	attemptOutputs := make([]string, 0, maxAttempts)
	var lastErr error
	for attempt := 1; attempt <= maxAttempts; attempt++ {
		out, err := accessRunCommandLogged(name, args...)
		trimmed := strings.TrimSpace(out)
		if err == nil {
			if len(attemptOutputs) == 0 {
				return out, nil
			}
			attemptOutputs = append(attemptOutputs, fmt.Sprintf("attempt %d/%d succeeded: %s", attempt, maxAttempts, trimmed))
			return strings.Join(attemptOutputs, " | "), nil
		}
		lastErr = err
		attemptOutputs = append(attemptOutputs, fmt.Sprintf("attempt %d/%d failed: %s", attempt, maxAttempts, trimmed))
		if !packageManagerOutputIndicatesLock(trimmed) || attempt == maxAttempts {
			return strings.Join(attemptOutputs, " | "), err
		}
		time.Sleep(accessPackageManagerRetrySleep)
	}
	return strings.Join(attemptOutputs, " | "), lastErr
}

func packageManagerOutputIndicatesLock(output string) bool {
	lower := strings.ToLower(output)
	lockMarkers := []string{
		"could not get lock",
		"unable to acquire the dpkg frontend lock",
		"unable to lock the administration directory",
		"unable to lock directory",
		"is another process using it",
		"existing lock",
		"failed to synchronize cache for repo",
	}
	for _, marker := range lockMarkers {
		if strings.Contains(lower, marker) {
			return true
		}
	}
	return false
}

func ensureProFTPDSFTPModuleInstalled() error {
	if _, err := accessLookPath("proftpd"); err != nil {
		return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: proftpd binary is missing after package installation")
	}
	out, err := accessRunCommandLogged("proftpd", "-l")
	if err != nil {
		return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: unable to verify ProFTPD modules output=%s", strings.TrimSpace(out))
	}
	if proFTPDModuleListHasSFTP(out) {
		return nil
	}
	repaired, diag, repairErr := enableProFTPDSFTPModuleWithDiagnostics()
	if repairErr != nil {
		if strings.Contains(strings.ToUpper(repairErr.Error()), "CONFIG_INVALID") {
			return repairErr
		}
		return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD mod_sftp is not installed or enabled; before=%q %s repair_error=%v; install proftpd-mod-crypto on Debian/Ubuntu or proftpd-mod_sftp on RHEL-compatible systems", strings.TrimSpace(out), diag.String(), repairErr)
	}
	testOut, testErr := runProFTPDConfigTestWithAuthRepair()
	diag.ProFTPDTestOutput = testOut
	if testErr != nil {
		if proFTPDTestOutputHasSFTPModuleError(testOut) {
			return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD configuration cannot load mod_sftp after repair; before=%q modules_conf_repaired=%t %s", strings.TrimSpace(out), repaired, diag.String())
		}
		return fmt.Errorf("CONFIG_INVALID: ProFTPD configuration test failed after SFTP module repair; %s", diag.String())
	}
	service, serviceErr := detectProFTPDServiceNameFunc()
	if serviceErr == nil {
		if err := ensureServiceRunningFunc(service); err != nil {
			diag.ServiceRestartOutput = err.Error()
			return fmt.Errorf("SERVICE_START_FAILED: ProFTPD restart failed after SFTP module repair; %s", diag.String())
		}
	} else {
		diag.ServiceRestartOutput = serviceErr.Error()
	}
	after, afterErr := accessRunCommandLogged("proftpd", "-l")
	debugOut, _ := accessRunCommandLogged("proftpd", "-td10")
	activePath, active := findActiveProFTPDSFTPModuleConfig()
	if active && diag.ModuleSOFound && (testErr == nil || proFTPDDebugOutputHasSFTP(testOut) || proFTPDDebugOutputHasSFTP(debugOut)) {
		return nil
	}
	if afterErr != nil || (!proFTPDModuleListHasSFTP(after) && !proFTPDDebugOutputHasSFTP(debugOut)) {
		diag.ModuleConfigPath = firstNonEmptyProFTPD(diag.ModuleConfigPath, activePath)
		return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD mod_sftp is not installed or enabled after repair; before=%q after=%q modules_conf_repaired=%t %s; install proftpd-mod-crypto on Debian/Ubuntu or proftpd-mod_sftp on RHEL-compatible systems", strings.TrimSpace(out), strings.TrimSpace(after), repaired, diag.String())
	}
	return nil
}

func enableProFTPDSFTPModuleWithDiagnostics() (bool, proFTPDModuleRepairDiagnostics, error) {
	diag := proFTPDModuleRepairDiagnostics{}
	modulePath, searched := findProFTPDSFTPModuleSO()
	diag.SearchedModulePaths = searched
	diag.ModuleSOPath = modulePath
	diag.ModuleSOFound = modulePath != ""
	if modulePath == "" {
		out, manager, packages, err := installProFTPDSFTPPackage()
		diag.PackageInstallOutput = out
		diag.PackageManager = manager
		diag.AttemptedPackages = packages
		modulePath, searched = findProFTPDSFTPModuleSO()
		diag.SearchedModulePaths = append(diag.SearchedModulePaths, searched...)
		diag.ModuleSOPath = modulePath
		diag.ModuleSOFound = modulePath != ""
		if err != nil {
			diag.ModuleConfigError = err.Error()
			return false, diag, err
		}
		if modulePath == "" {
			id, like := detectLinuxDistribution()
			diag.DistroID, diag.DistroLike = id, like
			return false, diag, fmt.Errorf("mod_sftp.so not found after package install")
		}
	}
	moduleConfigPath, confDir, err := chooseProFTPDModuleConfigPath()
	if err != nil {
		diag.ModuleConfigError = err.Error()
		return false, diag, err
	}
	diag.ModuleConfigPath = moduleConfigPath
	if confDir != "" {
		if _, err := ensureProFTPDManagedConfigIncluded(confDir); err != nil {
			diag.ModuleConfigError = err.Error()
			return false, diag, err
		}
	}
	repaired, err := enableProFTPDSFTPModuleInFile(moduleConfigPath)
	diag.ModuleConfigRepaired = repaired
	if err != nil {
		diag.ModuleConfigError = err.Error()
		return repaired, diag, err
	}
	return repaired, diag, nil
}

func enableProFTPDSFTPModuleInFile(path string) (bool, error) {
	return enableProFTPDSFTPModuleInFileWithLine(path, "LoadModule mod_sftp.c mod_sftp.so")
}

func enableProFTPDSFTPModuleInFileWithLine(path, moduleLine string) (bool, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
				return false, err
			}
			raw = nil
		} else {
			return false, err
		}
	}
	lines := strings.Split(string(raw), "\n")
	repaired := false
	found := false
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		uncommented := strings.TrimSpace(strings.TrimPrefix(trimmed, "#"))
		if isProFTPDSFTPModuleLine(trimmed) && !strings.HasPrefix(trimmed, "#") {
			if !found {
				found = true
				if !strings.EqualFold(trimmed, moduleLine) && len(strings.Fields(trimmed)) < 3 {
					lines[i] = moduleLine
					repaired = true
				}
			} else {
				lines[i] = "#" + strings.TrimLeft(trimmed, " \t")
				repaired = true
			}
			continue
		}
		if isProFTPDSFTPModuleLine(uncommented) {
			if !found {
				lines[i] = moduleLine
				found = true
				repaired = true
			} else if !strings.HasPrefix(trimmed, "#") {
				lines[i] = "#" + strings.TrimLeft(trimmed, " \t")
				repaired = true
			}
		}
	}
	if !found {
		if len(lines) > 0 && strings.TrimSpace(lines[len(lines)-1]) == "" {
			lines[len(lines)-1] = moduleLine
		} else {
			lines = append(lines, moduleLine)
		}
		repaired = true
	}
	if !repaired {
		return false, nil
	}
	content := strings.Join(lines, "\n")
	if !strings.HasSuffix(content, "\n") {
		content += "\n"
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		return false, err
	}
	return true, nil
}

func defaultProFTPDModuleConfigCandidates() []string {
	candidates := []string{"/etc/proftpd/modules.conf"}
	for _, pattern := range []string{"/etc/proftpd/conf.modules.d/*.conf", "/etc/proftpd.d/*.conf", "/etc/proftpd/conf.d/*.conf"} {
		matches, _ := filepath.Glob(pattern)
		sort.Strings(matches)
		candidates = append(candidates, matches...)
	}
	candidates = append(candidates, "/etc/proftpd/proftpd.conf", "/etc/proftpd.conf")
	return uniqueProFTPDStrings(candidates)
}

func defaultProFTPDModuleSearchPatterns() []string {
	return []string{
		"/usr/lib/proftpd/mod_sftp.so",
		"/usr/lib64/proftpd/mod_sftp.so",
		"/usr/lib/*/proftpd/mod_sftp.so",
		"/usr/lib/proftpd/*.so",
		"/usr/lib64/proftpd/*.so",
	}
}

func uniqueProFTPDStrings(values []string) []string {
	seen := map[string]bool{}
	unique := make([]string, 0, len(values))
	for _, value := range values {
		if value == "" || seen[value] {
			continue
		}
		seen[value] = true
		unique = append(unique, value)
	}
	return unique
}

func findProFTPDSFTPModuleSO() (string, []string) {
	searched := []string{}
	patterns := proFTPDModuleSearchPatterns()
	if sharedDir := proFTPDSharedModuleDirFromVersion(); sharedDir != "" {
		patterns = append([]string{filepath.Join(sharedDir, "mod_sftp.so")}, patterns...)
	}
	for _, pattern := range patterns {
		matches, err := filepath.Glob(pattern)
		if err != nil || len(matches) == 0 {
			searched = append(searched, pattern)
			continue
		}
		sort.Strings(matches)
		for _, match := range matches {
			searched = append(searched, match)
			if filepath.Base(match) == "mod_sftp.so" {
				if st, err := os.Stat(match); err == nil && !st.IsDir() {
					return match, uniqueProFTPDStrings(searched)
				}
			}
		}
	}
	return "", uniqueProFTPDStrings(searched)
}

func proFTPDSharedModuleDirFromVersion() string {
	if _, err := accessLookPath("proftpd"); err != nil {
		return ""
	}
	out, err := accessRunCommandLogged("proftpd", "-V")
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(out, "\n") {
		lower := strings.ToLower(line)
		if !strings.Contains(lower, "module") || (!strings.Contains(lower, "directory") && !strings.Contains(lower, "path")) {
			continue
		}
		fields := strings.Fields(strings.TrimSpace(line))
		for i := len(fields) - 1; i >= 0; i-- {
			candidate := strings.Trim(fields[i], "'\"")
			if filepath.IsAbs(candidate) {
				return candidate
			}
		}
	}
	return ""
}

func chooseProFTPDModuleConfigPath() (string, string, error) {
	candidates := proFTPDModuleConfigCandidates()
	fallback := ""
	for _, path := range candidates {
		st, err := os.Stat(path)
		if err != nil || st.IsDir() {
			continue
		}
		if fallback == "" && isProFTPDModuleConfigFile(path) {
			fallback = path
		}
		raw, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		if hasActiveProFTPDLoadModule(string(raw)) {
			return path, "", nil
		}
	}
	if fallback != "" {
		return fallback, "", nil
	}
	managedPath, confDir, err := proFTPDManagedModulesPath()
	if err != nil {
		return "", "", err
	}
	return managedPath, confDir, nil
}

func isProFTPDModuleConfigFile(path string) bool {
	clean := filepath.ToSlash(filepath.Clean(path))
	base := filepath.Base(clean)
	return base == "modules.conf" || strings.Contains(clean, "/conf.modules.d/") || strings.Contains(clean, "/proftpd.d/") || strings.Contains(clean, "/conf.d/")
}

func defaultProFTPDManagedModulesPath() (string, string, error) {
	if st, err := os.Stat("/etc/proftpd.d"); err == nil && st.IsDir() {
		return "/etc/proftpd.d/easywi-modules.conf", "/etc/proftpd.d", nil
	}
	confDir := "/etc/proftpd/conf.d"
	if err := os.MkdirAll(confDir, 0o755); err != nil {
		return "", "", fmt.Errorf("CONFIG_INVALID: could not create modules include dir: %w", err)
	}
	return filepath.Join(confDir, "easywi-modules.conf"), confDir, nil
}

func hasActiveProFTPDLoadModule(content string) bool {
	for _, line := range strings.Split(content, "\n") {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "#") {
			continue
		}
		fields := strings.Fields(trimmed)
		if len(fields) > 0 && strings.EqualFold(fields[0], "LoadModule") {
			return true
		}
	}
	return false
}

func isProFTPDSFTPModuleLine(line string) bool {
	fields := strings.Fields(line)
	return len(fields) >= 2 && strings.EqualFold(fields[0], "LoadModule") && strings.EqualFold(fields[1], "mod_sftp.c")
}

func proFTPDDebugOutputHasSFTP(output string) bool {
	lower := strings.ToLower(output)
	return strings.Contains(lower, "mod_sftp.c") || strings.Contains(lower, "mod_sftp.so")
}

func runProFTPDConfigTestWithAuthRepair() (string, error) {
	if err := ensureProFTPDAuthFile(); err != nil {
		return "", err
	}
	out, err := accessRunCommandLogged("proftpd", "-t")
	if err == nil || !proFTPDTestOutputHasAuthUserFileError(out) {
		return out, err
	}
	if repairErr := ensureProFTPDAuthFile(); repairErr != nil {
		return out, repairErr
	}
	retryOut, retryErr := accessRunCommandLogged("proftpd", "-t")
	if strings.TrimSpace(retryOut) == "" {
		return out, retryErr
	}
	if strings.TrimSpace(out) == "" {
		return retryOut, retryErr
	}
	return strings.TrimSpace(out) + "\n" + retryOut, retryErr
}

func proFTPDTestOutputHasAuthUserFileError(output string) bool {
	lower := strings.ToLower(output)
	return strings.Contains(lower, "authuserfile") && (strings.Contains(lower, "no such file") || strings.Contains(lower, "unable to use") || strings.Contains(lower, "permission denied"))
}

func proFTPDTestOutputHasSFTPModuleError(output string) bool {
	lower := strings.ToLower(output)
	return strings.Contains(lower, "mod_sftp") && (strings.Contains(lower, "unknown module") || strings.Contains(lower, "unable to load") || strings.Contains(lower, "cannot load") || strings.Contains(lower, "no such file"))
}

func findActiveProFTPDSFTPModuleConfig() (string, bool) {
	for _, path := range proFTPDModuleConfigCandidates() {
		raw, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		for _, line := range strings.Split(string(raw), "\n") {
			trimmed := strings.TrimSpace(line)
			if strings.HasPrefix(trimmed, "#") {
				continue
			}
			if isProFTPDSFTPModuleLine(trimmed) {
				return path, true
			}
		}
	}
	return "", false
}

func firstNonEmptyProFTPD(values ...string) string {
	for _, value := range values {
		if strings.TrimSpace(value) != "" {
			return value
		}
	}
	return ""
}

func installProFTPDSFTPPackage() (string, string, []string, error) {
	id, like := detectLinuxDistribution()
	joined := id + " " + like
	if _, err := accessLookPath("apt-get"); err == nil && (strings.Contains(joined, "debian") || strings.Contains(joined, "ubuntu") || joined == " ") {
		packages := []string{"proftpd-mod-crypto"}
		updateOut, updateErr := accessRunCommandLogged("apt-get", "update")
		installOut := ""
		var installErr error
		if updateErr == nil {
			installOut, installErr = accessRunCommandLogged("apt-get", "install", "-y", packages[0])
		}
		out := strings.TrimSpace(updateOut + "\n" + installOut)
		if updateErr != nil {
			return out, "apt-get", packages, fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=apt-get packages=%s output=%s", strings.Join(packages, ","), strings.TrimSpace(updateOut))
		}
		if installErr != nil {
			return out, "apt-get", packages, fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=apt-get packages=%s output=%s", strings.Join(packages, ","), strings.TrimSpace(installOut))
		}
		return out, "apt-get", packages, nil
	}
	packages := []string{"proftpd-mod_sftp"}
	if _, err := accessLookPath("dnf"); err == nil {
		out, installErr := accessRunCommandLogged("dnf", "install", "-y", packages[0])
		if installErr == nil {
			return out, "dnf", packages, nil
		}
		if _, yumErr := accessLookPath("yum"); yumErr != nil {
			return out, "dnf", packages, fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=dnf packages=%s output=%s", strings.Join(packages, ","), strings.TrimSpace(out))
		}
		yumOut, yumErr := accessRunCommandLogged("yum", "install", "-y", packages[0])
		combined := strings.TrimSpace(out + "\n" + yumOut)
		if yumErr != nil {
			return combined, "dnf,yum", packages, fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=dnf,yum packages=%s output=%s", strings.Join(packages, ","), combined)
		}
		return combined, "dnf,yum", packages, nil
	}
	if _, err := accessLookPath("yum"); err == nil {
		out, installErr := accessRunCommandLogged("yum", "install", "-y", packages[0])
		if installErr != nil {
			return out, "yum", packages, fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=yum packages=%s output=%s", strings.Join(packages, ","), strings.TrimSpace(out))
		}
		return out, "yum", packages, nil
	}
	return "", "", packages, fmt.Errorf("BACKEND_UNSUPPORTED: no package manager for packages=%s", strings.Join(packages, ","))
}

func proFTPDModuleListHasSFTP(output string) bool {
	for _, line := range strings.Split(strings.ToLower(output), "\n") {
		line = strings.TrimSpace(line)
		if line == "mod_sftp.c" || strings.Contains(line, " mod_sftp.c") || strings.Contains(line, "mod_sftp.c ") {
			return true
		}
	}

	return false
}

func detectProFTPDConfDir() (string, error) {
	candidates := []string{"/etc/proftpd/conf.d", "/etc/proftpd.d"}
	for _, dir := range candidates {
		if st, err := os.Stat(dir); err == nil && st.IsDir() {
			return dir, nil
		}
	}
	if err := os.MkdirAll("/etc/proftpd/conf.d", 0o755); err != nil {
		return "", fmt.Errorf("CONFIG_INVALID: could not create include dir: %w", err)
	}
	return "/etc/proftpd/conf.d", nil
}

func ensureProFTPDConfig(confDir string) error {
	if err := os.MkdirAll(confDir, 0o755); err != nil {
		return fmt.Errorf("CONFIG_INVALID: create config dir: %w", err)
	}
	content := buildProFTPDManagedConfig(defaultAccessListenPort)
	path := filepath.Join(confDir, "easywi-sftp.conf")
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		return fmt.Errorf("CONFIG_INVALID: write config: %w", err)
	}
	repaired, err := ensureProFTPDManagedConfigIncluded(confDir)
	if err != nil {
		return err
	}
	if repaired {
		log.Printf("proftpd easywi config include repaired conf_dir=%s", confDir)
	}
	return nil
}

func ensureProFTPDManagedConfigIncluded(confDir string) (bool, error) {
	for _, mainConfig := range []string{"/etc/proftpd/proftpd.conf", "/etc/proftpd.conf"} {
		if st, err := os.Stat(mainConfig); err == nil && !st.IsDir() {
			return ensureProFTPDIncludeInFile(mainConfig, confDir)
		}
	}
	return false, nil
}

func ensureProFTPDIncludeInFile(mainConfigPath, confDir string) (bool, error) {
	raw, err := os.ReadFile(mainConfigPath)
	if err != nil {
		return false, fmt.Errorf("CONFIG_INVALID: read main config %s: %w", mainConfigPath, err)
	}
	includeLine := fmt.Sprintf("Include %s/*.conf", confDir)
	includeLineAlt := fmt.Sprintf("Include %s/*", confDir)
	lines := strings.Split(string(raw), "\n")
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.HasPrefix(trimmed, "#") {
			continue
		}
		if strings.EqualFold(trimmed, includeLine) || strings.EqualFold(trimmed, includeLineAlt) || strings.Contains(trimmed, confDir) {
			return false, nil
		}
	}
	content := string(raw)
	if strings.TrimSpace(content) != "" && !strings.HasSuffix(content, "\n") {
		content += "\n"
	}
	content += includeLine + "\n"
	if err := os.WriteFile(mainConfigPath, []byte(content), 0o644); err != nil {
		return false, fmt.Errorf("CONFIG_INVALID: write main config include %s: %w", mainConfigPath, err)
	}
	return true, nil
}

func buildProFTPDManagedConfig(port int) string {
	return strings.Join([]string{
		"# BEGIN EASYWI MANAGED",
		"<IfModule mod_sftp.c>",
		"  <VirtualHost 0.0.0.0>",
		fmt.Sprintf("    Port %d", port),
		"    SFTPEngine on",
		"    SFTPLog /var/log/proftpd/easywi-sftp.log",
		fmt.Sprintf("    SFTPHostKey %s", proFTPDHostKeyPath),
		fmt.Sprintf("    AuthUserFile %s", proFTPDAuthUserFilePath),
		"    RequireValidShell off",
		"    DefaultRoot ~",
		"    AllowOverwrite on",
		"    TimeoutIdle 600",
		"  </VirtualHost>",
		"</IfModule>",
		"# END EASYWI MANAGED",
	}, "\n") + "\n"
}

func ensureProFTPDAppArmorAccess() error {
	if !apparmorProfileExists(proFTPDAppArmorProfilePath) {
		return nil
	}
	if !proFTPDAppArmorActiveFunc() {
		log.Printf("proftpd apparmor profile present but AppArmor appears inactive; skipping profile reload")
		return nil
	}
	localPath := proFTPDAppArmorLocalPath
	if err := os.MkdirAll(filepath.Dir(localPath), 0o755); err != nil {
		return fmt.Errorf("CONFIG_INVALID: create AppArmor local dir: %w", err)
	}
	rules := []string{
		"# EasyWI ProFTPD/SFTP auth files",
		"/var/lib/easywi/proftpd/ r,",
		"/var/lib/easywi/proftpd/passwd r,",
	}
	changed, err := appendUniqueLines(localPath, rules, 0o644)
	if err != nil {
		return fmt.Errorf("CONFIG_INVALID: update AppArmor local profile: %w", err)
	}
	if _, err := accessLookPath("apparmor_parser"); err != nil {
		log.Printf("proftpd apparmor local profile updated=%t but apparmor_parser is unavailable; reload skipped", changed)
		return nil
	}
	if out, err := accessRunCommandLogged("apparmor_parser", "-r", proFTPDAppArmorProfilePath); err != nil {
		return fmt.Errorf("CONFIG_INVALID: reload AppArmor proftpd profile failed: %s", strings.TrimSpace(out))
	}
	return nil
}

func apparmorProfileExists(path string) bool {
	st, err := os.Stat(path)
	return err == nil && !st.IsDir()
}

func apparmorLikelyActive() bool {
	for _, path := range []string{"/sys/module/apparmor/parameters/enabled", "/sys/kernel/security/apparmor/profiles"} {
		raw, err := os.ReadFile(path)
		if err != nil {
			continue
		}
		text := strings.TrimSpace(strings.ToLower(string(raw)))
		return text == "y" || text == "yes" || strings.Contains(text, "proftpd") || strings.Contains(text, "apparmor")
	}
	if _, err := accessLookPath("aa-status"); err == nil {
		if _, err := accessRunCommandLogged("aa-status", "--enabled"); err == nil {
			return true
		}
	}
	return false
}

func appendUniqueLines(path string, wanted []string, perm os.FileMode) (bool, error) {
	raw, err := os.ReadFile(path)
	if err != nil && !os.IsNotExist(err) {
		return false, err
	}
	content := string(raw)
	lines := strings.Split(content, "\n")
	existing := map[string]bool{}
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed != "" {
			existing[trimmed] = true
		}
	}
	changed := false
	for _, line := range wanted {
		if existing[strings.TrimSpace(line)] {
			continue
		}
		if strings.TrimSpace(content) != "" && !strings.HasSuffix(content, "\n") {
			content += "\n"
		}
		content += line + "\n"
		existing[strings.TrimSpace(line)] = true
		changed = true
	}
	if !changed {
		return false, nil
	}
	return true, os.WriteFile(path, []byte(content), perm)
}

func detectProFTPDServiceName() (string, error) {
	if _, err := accessLookPath("systemctl"); err == nil {
		out, err := accessRunCommandLogged("systemctl", "list-unit-files", "--type=service", "--no-legend")
		if err == nil {
			rows := strings.Split(out, "\n")
			serviceCandidates := []string{"proftpd.service", "proftpd-basic.service"}
			for _, row := range rows {
				for _, candidate := range serviceCandidates {
					if strings.Contains(row, candidate) {
						return candidate, nil
					}
				}
			}
		}
	}
	for _, candidate := range []string{"proftpd", "proftpd.service", "proftpd-basic", "proftpd-basic.service"} {
		if _, err := accessRunCommandLogged("service", candidate, "status"); err == nil {
			return candidate, nil
		}
	}
	return "proftpd", nil
}

func ensureEasyWIUser() error {
	if _, err := accessRunCommandLogged("id", "-u", "easywi"); err == nil {
		return nil
	}
	_, _ = accessRunCommandLogged("groupadd", "--system", "easywi")
	if out, err := accessRunCommandLogged("useradd", "--system", "--gid", "easywi", "--home-dir", "/var/lib/easywi", "--shell", "/usr/sbin/nologin", "easywi"); err != nil && !strings.Contains(strings.ToLower(out), "exists") {
		return fmt.Errorf("PERMISSION_DENIED: %s", strings.TrimSpace(out))
	}
	return nil
}

func ensureProFTPDAuthFile() error {
	group, err := proFTPDPrimaryGroupName()
	if err != nil {
		return err
	}
	dir := filepath.Dir(proFTPDAuthUserFilePath)
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: create auth file dir: %w", err)
	}
	if err := ensureSearchableDirectory(filepath.Dir(dir)); err != nil {
		return err
	}
	if err := accessChownRootGroup(dir, group); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chown auth file dir: %w", err)
	}
	if err := os.Chmod(dir, 0o750); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chmod auth file dir: %w", err)
	}
	if _, err := os.Stat(proFTPDAuthUserFilePath); err == nil {
		if err := accessChownRootGroup(proFTPDAuthUserFilePath, group); err != nil {
			return fmt.Errorf("PERMISSION_DENIED: chown auth file: %w", err)
		}
		if err := os.Chmod(proFTPDAuthUserFilePath, 0o640); err != nil {
			return fmt.Errorf("PERMISSION_DENIED: chmod auth file: %w", err)
		}
		return nil
	} else if !os.IsNotExist(err) {
		return fmt.Errorf("PERMISSION_DENIED: stat auth file: %w", err)
	}
	if err := os.WriteFile(proFTPDAuthUserFilePath, []byte{}, 0o640); err != nil {
		return fmt.Errorf("CONFIG_INVALID: create auth file: %w", err)
	}
	if err := accessChownRootGroup(proFTPDAuthUserFilePath, group); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chown auth file: %w", err)
	}
	if err := os.Chmod(proFTPDAuthUserFilePath, 0o640); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chmod auth file: %w", err)
	}
	return nil
}

func proFTPDPrimaryGroupName() (string, error) {
	if _, err := accessRunCommandLogged("id", "-u", "proftpd"); err != nil {
		return "", fmt.Errorf("CONFIG_INVALID: required system user proftpd does not exist after package installation")
	}
	if _, err := accessRunCommandLogged("getent", "group", "nogroup"); err == nil {
		return "nogroup", nil
	}
	out, err := accessRunCommandLogged("id", "-gn", "proftpd")
	if err != nil || strings.TrimSpace(out) == "" {
		return "", fmt.Errorf("CONFIG_INVALID: could not determine primary group for proftpd user: %s", strings.TrimSpace(out))
	}
	return strings.TrimSpace(out), nil
}

func accessChownRootGroup(path, group string) error {
	out, err := accessRunCommandLogged("chown", "root:"+group, path)
	if err != nil {
		return fmt.Errorf("%s", strings.TrimSpace(out))
	}
	return nil
}

func ensureSearchableDirectory(path string) error {
	info, err := os.Stat(path)
	if err != nil {
		return fmt.Errorf("PERMISSION_DENIED: stat searchable directory %s: %w", path, err)
	}
	if !info.IsDir() {
		return fmt.Errorf("PERMISSION_DENIED: searchable path %s is not a directory", path)
	}
	mode := info.Mode().Perm() | 0o111
	if err := os.Chmod(path, mode); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chmod searchable directory %s: %w", path, err)
	}
	return nil
}

func ensureProFTPDKeys() error {
	if err := os.MkdirAll(filepath.Dir(proFTPDHostKeyPath), 0o700); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: create key dir: %w", err)
	}
	if _, err := os.Stat(proFTPDHostKeyPath); err == nil {
		_ = os.Chmod(proFTPDHostKeyPath, 0o600)
		return nil
	}
	if out, err := accessRunCommandLogged("ssh-keygen", "-q", "-t", "rsa", "-b", "3072", "-N", "", "-f", proFTPDHostKeyPath); err != nil {
		return fmt.Errorf("CONFIG_INVALID: %s", strings.TrimSpace(out))
	}
	if err := os.Chmod(proFTPDHostKeyPath, 0o600); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chmod key: %w", err)
	}
	return nil
}

func ensureProFTPDUser(username, password, rootPath string) error {
	if err := os.MkdirAll(filepath.Dir(proFTPDAuthUserFilePath), 0o700); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: %w", err)
	}

	rootInfo, err := os.Stat(rootPath)
	rootExisted := err == nil
	if err != nil {
		if !os.IsNotExist(err) {
			return fmt.Errorf("ROOT_INVALID: %w", err)
		}
		if err := os.MkdirAll(rootPath, 0o750); err != nil {
			return fmt.Errorf("ROOT_INVALID: %w", err)
		}
		rootInfo, err = os.Stat(rootPath)
		if err != nil {
			return fmt.Errorf("ROOT_INVALID: stat root path: %w", err)
		}
	}
	if !rootInfo.IsDir() {
		return fmt.Errorf("ROOT_INVALID: not a directory")
	}

	uid, gid, err := proFTPDAccountIDsForRoot(rootInfo, rootPath, rootExisted)
	if err != nil {
		return err
	}
	if err := ensureProFTPDRootWritable(rootPath, uid, gid); err != nil {
		return err
	}
	hash, err := accessRunCommandOutput("openssl", "passwd", "-6", password)
	if err != nil {
		h := sha256.Sum256([]byte(password))
		hash = "$easywi$" + hex.EncodeToString(h[:])
	}
	entry := fmt.Sprintf("%s:%s:%s:%s::%s:/usr/sbin/nologin", username, strings.TrimSpace(hash), uid, gid, rootPath)
	existing, readErr := os.ReadFile(proFTPDAuthUserFilePath)
	if readErr != nil && !os.IsNotExist(readErr) {
		return fmt.Errorf("PERMISSION_DENIED: read auth file: %w", readErr)
	}
	lines := strings.Split(strings.TrimSpace(string(existing)), "\n")
	updated := make([]string, 0, len(lines)+1)
	replaced := false
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		if strings.HasPrefix(line, username+":") {
			updated = append(updated, entry)
			replaced = true
			continue
		}
		updated = append(updated, line)
	}
	if !replaced {
		updated = append(updated, entry)
	}
	if err := os.WriteFile(proFTPDAuthUserFilePath, []byte(strings.Join(updated, "\n")+"\n"), 0o640); err != nil {
		return fmt.Errorf("CONFIG_INVALID: write auth file: %w", err)
	}
	return ensureProFTPDAuthFile()
}

func ensureProFTPDRootWritable(rootPath, uidString, gidString string) error {
	uid, err := strconv.Atoi(strings.TrimSpace(uidString))
	if err != nil || uid < 0 {
		return fmt.Errorf("PERMISSION_DENIED: invalid sftp uid %q", uidString)
	}
	gid, err := strconv.Atoi(strings.TrimSpace(gidString))
	if err != nil || gid < 0 {
		return fmt.Errorf("PERMISSION_DENIED: invalid sftp gid %q", gidString)
	}

	return filepath.WalkDir(rootPath, func(path string, entry os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return fmt.Errorf("PERMISSION_DENIED: walk root path %s: %w", path, walkErr)
		}
		info, err := entry.Info()
		if err != nil {
			return fmt.Errorf("PERMISSION_DENIED: stat root path entry %s: %w", path, err)
		}
		currentUID, currentGID := fileOwnerIDs(info)
		if currentUID >= 0 && currentGID >= 0 && (currentUID != uid || currentGID != gid) {
			if err := os.Lchown(path, uid, gid); err != nil {
				return fmt.Errorf("PERMISSION_DENIED: chown root path entry %s: %w", path, err)
			}
		}
		if info.Mode()&os.ModeSymlink != 0 {
			return nil
		}
		mode := info.Mode().Perm()
		wanted := mode | 0o600
		if entry.IsDir() {
			wanted = mode | 0o700
		}
		if mode != wanted {
			if err := os.Chmod(path, wanted); err != nil {
				return fmt.Errorf("PERMISSION_DENIED: chmod root path entry %s: %w", path, err)
			}
		}
		return nil
	})
}

func proFTPDAccountIDsForRoot(rootInfo os.FileInfo, rootPath string, rootExisted bool) (string, string, error) {
	if rootExisted {
		uid, gid := fileOwnerIDs(rootInfo)
		if uid < 0 || gid < 0 {
			return "", "", fmt.Errorf("PERMISSION_DENIED: determine owner for %s", rootPath)
		}
		if uid == 0 {
			return "", "", fmt.Errorf("PERMISSION_DENIED: refusing to create SFTP account with root-owned root path %s", rootPath)
		}
		return strconv.Itoa(uid), strconv.Itoa(gid), nil
	}

	uidOut, err := accessRunCommandOutput("id", "-u", "easywi")
	if err != nil {
		return "", "", fmt.Errorf("PERMISSION_DENIED: easywi uid missing")
	}
	gidOut, err := accessRunCommandOutput("id", "-g", "easywi")
	if err != nil {
		return "", "", fmt.Errorf("PERMISSION_DENIED: easywi gid missing")
	}
	uid := strings.TrimSpace(uidOut)
	gid := strings.TrimSpace(gidOut)
	if uid == "" || gid == "" {
		return "", "", fmt.Errorf("PERMISSION_DENIED: easywi uid/gid missing")
	}
	return uid, gid, nil
}

func ensureLinuxSFTPFirewall(port int) error {
	portSpec := fmt.Sprintf("%d/tcp", port)
	if _, err := accessLookPath("ufw"); err == nil {
		if out, err := accessRunCommandLogged("ufw", "allow", portSpec); err != nil {
			return fmt.Errorf("FIREWALL_CONFIG_FAILED: ufw allow %s failed: %s", portSpec, strings.TrimSpace(out))
		}
	}
	if _, err := accessLookPath("firewall-cmd"); err == nil {
		if out, err := accessRunCommandLogged("firewall-cmd", "--permanent", "--add-port="+portSpec); err != nil {
			return fmt.Errorf("FIREWALL_CONFIG_FAILED: firewall-cmd add-port %s failed: %s", portSpec, strings.TrimSpace(out))
		}
		if out, err := accessRunCommandLogged("firewall-cmd", "--reload"); err != nil {
			return fmt.Errorf("FIREWALL_CONFIG_FAILED: firewall-cmd reload failed: %s", strings.TrimSpace(out))
		}
	}

	return nil
}

type proFTPDPortHealthDiagnostics struct {
	ConfigPath        string
	ConfigContent     string
	IncludeRepaired   bool
	ProFTPDTestOutput string
	SSOutput          string
	ListenError       string
	ServiceName       string
	ServiceActive     string
	PortConflictHint  string
}

func (d proFTPDPortHealthDiagnostics) String() string {
	return strings.Join([]string{
		fmt.Sprintf("config_path=%s", d.ConfigPath),
		fmt.Sprintf("config_content=%q", sanitizeDiagnosticValue(d.ConfigContent)),
		fmt.Sprintf("include_repaired=%t", d.IncludeRepaired),
		fmt.Sprintf("proftpd_test_output=%s", sanitizeDiagnosticValue(d.ProFTPDTestOutput)),
		fmt.Sprintf("ss_output=%s", sanitizeDiagnosticValue(d.SSOutput)),
		fmt.Sprintf("listen_error=%s", sanitizeDiagnosticValue(d.ListenError)),
		fmt.Sprintf("service_name=%s", d.ServiceName),
		fmt.Sprintf("systemctl_is_active=%s", sanitizeDiagnosticValue(d.ServiceActive)),
		fmt.Sprintf("port_conflict_hint=%s", sanitizeDiagnosticValue(d.PortConflictHint)),
	}, " ")
}

func proFTPDPortDiagnostics(configPath string, includeRepaired bool, testOut, serviceName, serviceActive string, listenErr error) proFTPDPortHealthDiagnostics {
	cfg, _ := os.ReadFile(configPath)
	ssOut := ""
	if _, err := accessLookPath("ss"); err == nil {
		ssOut, _ = accessRunCommandLogged("ss", "-tulpn")
	} else if _, err := accessLookPath("netstat"); err == nil {
		ssOut, _ = accessRunCommandLogged("netstat", "-tulpn")
	}
	hint := ""
	if strings.Contains(ssOut, ":21") && !strings.Contains(ssOut, fmt.Sprintf(":%d", defaultAccessListenPort)) {
		hint = fmt.Sprintf("proftpd appears to listen on port 21 but not %d; verify EasyWI VirtualHost include and Port directive", defaultAccessListenPort)
	}
	listenMsg := ""
	if listenErr != nil {
		listenMsg = listenErr.Error()
	}
	return proFTPDPortHealthDiagnostics{
		ConfigPath:        configPath,
		ConfigContent:     string(cfg),
		IncludeRepaired:   includeRepaired,
		ProFTPDTestOutput: testOut,
		SSOutput:          ssOut,
		ListenError:       listenMsg,
		ServiceName:       serviceName,
		ServiceActive:     serviceActive,
		PortConflictHint:  hint,
	}
}

func proFTPDSystemctlIsActive(service string) string {
	if _, err := accessLookPath("systemctl"); err != nil {
		return ""
	}
	out, err := accessRunCommandOutput("systemctl", "is-active", service)
	if err != nil {
		return strings.TrimSpace(out + " " + err.Error())
	}
	return strings.TrimSpace(out)
}

func ensureServiceRunning(service string) error {
	if _, err := accessLookPath("systemctl"); err == nil {
		if out, err := accessRunCommandLogged("systemctl", "daemon-reload"); err != nil {
			return fmt.Errorf("SERVICE_START_FAILED: %s", strings.TrimSpace(out))
		}
		if out, err := accessRunCommandLogged("systemctl", "restart", service); err != nil {
			if out2, err2 := accessRunCommandLogged("systemctl", "start", service); err2 != nil {
				return fmt.Errorf("SERVICE_START_FAILED: %s | %s %s", strings.TrimSpace(out), strings.TrimSpace(out2), proFTPDServiceFailureDetails(service))
			}
		}
		// Enabling boot-start should not fail provisioning on hosts with custom init wiring.
		_, _ = accessRunCommandLogged("systemctl", "enable", service)
		return nil
	}
	if out, err := accessRunCommandLogged("service", service, "restart"); err != nil {
		return fmt.Errorf("SERVICE_START_FAILED: %s %s", strings.TrimSpace(out), proFTPDServiceFailureDetails(service))
	}
	return nil
}

func proFTPDServiceFailureDetails(service string) string {
	parts := []string{}
	if _, err := accessLookPath("systemctl"); err == nil {
		if out, err := accessRunCommandLogged("systemctl", "status", service, "--no-pager"); out != "" || err != nil {
			parts = append(parts, "systemctl_status="+sanitizeDiagnosticValue(out))
		}
	}
	if _, err := accessLookPath("journalctl"); err == nil {
		if out, err := accessRunCommandLogged("journalctl", "-u", service, "-n", "100", "--no-pager"); out != "" || err != nil {
			parts = append(parts, "journal="+sanitizeDiagnosticValue(out))
		}
		if out, err := accessRunCommandLogged("journalctl", "-k", "-n", "100", "--no-pager"); out != "" || err != nil {
			denials := []string{}
			for _, line := range strings.Split(out, "\n") {
				if strings.Contains(strings.ToLower(line), "apparmor") {
					denials = append(denials, line)
				}
			}
			if len(denials) > 0 {
				parts = append(parts, "apparmor="+sanitizeDiagnosticValue(strings.Join(denials, "\n")))
			}
		}
	}
	return strings.Join(parts, " ")
}

func checkLinuxProFTPDHealth() error {
	confDir, err := detectProFTPDConfDirFunc()
	if err != nil {
		return err
	}
	managedConfig := filepath.Join(confDir, "easywi-sftp.conf")
	includeRepaired := false
	if err := ensureProFTPDAuthFile(); err != nil {
		return fmt.Errorf("CONFIG_INVALID: auth file missing: %w", err)
	}
	cfg, err := os.ReadFile(managedConfig)
	if err != nil {
		if err := ensureProFTPDConfig(confDir); err != nil {
			return fmt.Errorf("CONFIG_INVALID: managed config missing")
		}
		cfg, err = os.ReadFile(managedConfig)
		if err != nil {
			return fmt.Errorf("CONFIG_INVALID: managed config missing")
		}
	}
	cfgStr := string(cfg)
	if !strings.Contains(cfgStr, "# BEGIN EASYWI MANAGED") || !strings.Contains(cfgStr, "# END EASYWI MANAGED") || !strings.Contains(cfgStr, "<VirtualHost 0.0.0.0>") || !strings.Contains(cfgStr, fmt.Sprintf("Port %d", defaultAccessListenPort)) || strings.Contains(cfgStr, "MaxInstances") {
		if err := ensureProFTPDConfig(confDir); err != nil {
			return fmt.Errorf("CONFIG_INVALID: managed markers missing")
		}
	}
	repaired, err := ensureProFTPDManagedConfigIncluded(confDir)
	if err != nil {
		return err
	}
	includeRepaired = includeRepaired || repaired
	if _, err := os.Stat(proFTPDHostKeyPath); err != nil {
		if err := ensureProFTPDKeys(); err != nil {
			return fmt.Errorf("CONFIG_INVALID: host key missing")
		}
	}
	if err := ensureProFTPDAppArmorAccess(); err != nil {
		return err
	}
	if err := ensureProFTPDSFTPModuleInstalled(); err != nil {
		return err
	}
	testOut, err := runProFTPDConfigTestWithAuthRepair()
	if err != nil {
		if proFTPDTestOutputHasSFTPModuleError(testOut) {
			return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD configuration cannot load mod_sftp: %s", strings.TrimSpace(testOut))
		}
		return fmt.Errorf("CONFIG_INVALID: ProFTPD configuration test failed: %s", strings.TrimSpace(testOut))
	}
	service, err := detectProFTPDServiceNameFunc()
	if err != nil {
		return err
	}
	activeOut := proFTPDSystemctlIsActive(service)
	if activeOut != "" && activeOut != "active" {
		if err := ensureServiceRunningFunc(service); err != nil {
			return err
		}
		activeOut = proFTPDSystemctlIsActive(service)
	}
	if listenErr := checkTCPListeningFunc(defaultAccessListenPort); listenErr == nil {
		return nil
	} else {
		if err := ensureProFTPDAuthFile(); err != nil {
			return fmt.Errorf("CONFIG_INVALID: auth file missing before port repair: %w", err)
		}
		if err := ensureProFTPDConfig(confDir); err != nil {
			return err
		}
		repaired, err := ensureProFTPDManagedConfigIncluded(confDir)
		if err != nil {
			return err
		}
		includeRepaired = includeRepaired || repaired
		testOut, err = runProFTPDConfigTestWithAuthRepair()
		if err != nil {
			if proFTPDTestOutputHasSFTPModuleError(testOut) {
				return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD configuration cannot load mod_sftp during port repair: %s", strings.TrimSpace(testOut))
			}
			return fmt.Errorf("CONFIG_INVALID: ProFTPD configuration test failed during port repair: %s", strings.TrimSpace(testOut))
		}
		if err := ensureServiceRunningFunc(service); err != nil {
			diag := proFTPDPortDiagnostics(managedConfig, includeRepaired, testOut, service, activeOut, listenErr)
			return fmt.Errorf("SERVICE_START_FAILED: ProFTPD restart after SFTP port repair failed: %v %s", err, diag.String())
		}
		activeOut = proFTPDSystemctlIsActive(service)
		if retryErr := checkTCPListeningFunc(defaultAccessListenPort); retryErr != nil {
			diag := proFTPDPortDiagnostics(managedConfig, includeRepaired, testOut, service, activeOut, retryErr)
			return fmt.Errorf("PORT_NOT_LISTENING: ProFTPD is active but SFTP port %d is not reachable after config repair and restart; %s", defaultAccessListenPort, diag.String())
		}
	}
	return nil
}

func findEmbeddedSFTPBinary() string {
	exePath, _ := os.Executable()
	dirs := []string{}
	if exePath != "" {
		dirs = append(dirs, filepath.Dir(exePath))
	}
	dirs = append(dirs, `C:\easywi\agent`, `C:\Program Files\EasyWI\agent`)
	for _, dir := range dirs {
		candidate := filepath.Join(dir, windowsEmbeddedBinary)
		if st, err := os.Stat(candidate); err == nil && !st.IsDir() {
			return candidate
		}
	}
	return ""
}

func provisionWindowsEmbeddedSFTP(username, password, rootPath string) error {
	binary := findEmbeddedSFTPBinary()
	if binary == "" {
		return fmt.Errorf("BACKEND_UNSUPPORTED: embedded binary not found")
	}
	if err := os.MkdirAll(filepath.Dir(windowsEmbeddedConfig), 0o755); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: %w", err)
	}
	config := map[string]any{"version": 1, "listen": fmt.Sprintf("0.0.0.0:%d", defaultAccessListenPort), "users_file": windowsEmbeddedUsers, "marker": "BEGIN EASYWI MANAGED"}
	blob, err := json.MarshalIndent(config, "", "  ")
	if err != nil {
		return fmt.Errorf("INTERNAL_ERROR: marshal embedded config: %w", err)
	}
	if err := os.WriteFile(windowsEmbeddedConfig, blob, 0o644); err != nil {
		return fmt.Errorf("CONFIG_INVALID: %w", err)
	}
	if err := upsertWindowsEmbeddedUser(username, password, rootPath); err != nil {
		return err
	}
	installCmd := fmt.Sprintf(`$ErrorActionPreference='Stop'; if (-not (Get-Service -Name '%s' -ErrorAction SilentlyContinue)) { New-Service -Name '%s' -BinaryPathName '"%s" --config "%s"' -DisplayName '%s' -StartupType Automatic }; Start-Service -Name '%s'`, windowsEmbeddedService, windowsEmbeddedService, binary, windowsEmbeddedConfig, windowsEmbeddedService, windowsEmbeddedService)
	if out, err := accessRunCommandLogged("powershell", "-NoProfile", "-NonInteractive", "-Command", installCmd); err != nil {
		return fmt.Errorf("SERVICE_START_FAILED: %s", strings.TrimSpace(out))
	}
	return nil
}

func upsertWindowsEmbeddedUser(username, password, rootPath string) error {
	return upsertWindowsEmbeddedUserFile(windowsEmbeddedUsers, username, password, rootPath)
}

func upsertWindowsEmbeddedUserFile(usersPath, username, password, rootPath string) error {
	hash, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return fmt.Errorf("INTERNAL_ERROR: generate bcrypt hash")
	}
	type embeddedUser struct {
		Username     string `json:"username"`
		PasswordHash string `json:"password_hash"`
		RootPath     string `json:"root_path"`
		Enabled      bool   `json:"enabled"`
		UpdatedAt    string `json:"updated_at"`
	}
	newEntry := embeddedUser{Username: username, PasswordHash: string(hash), RootPath: rootPath, Enabled: true, UpdatedAt: time.Now().UTC().Format(time.RFC3339)}
	users := []embeddedUser{}
	raw, readErr := os.ReadFile(usersPath)
	if readErr != nil && !os.IsNotExist(readErr) {
		return fmt.Errorf("PERMISSION_DENIED: read embedded users file: %w", readErr)
	}
	if readErr == nil {
		if err := json.Unmarshal(raw, &users); err != nil {
			return fmt.Errorf("CONFIG_INVALID: parse embedded users file: %w", err)
		}
	}
	found := false
	for i := range users {
		if users[i].Username == username {
			users[i] = newEntry
			found = true
			break
		}
	}
	if !found {
		users = append(users, newEntry)
	}
	sort.Slice(users, func(i, j int) bool { return users[i].Username < users[j].Username })
	blob, err := json.MarshalIndent(users, "", "  ")
	if err != nil {
		return fmt.Errorf("INTERNAL_ERROR: marshal embedded users file: %w", err)
	}
	if err := os.WriteFile(usersPath, blob, 0o600); err != nil {
		return fmt.Errorf("CONFIG_INVALID: %w", err)
	}
	return nil
}

func checkWindowsEmbeddedSFTPHealth() error {
	if _, err := os.Stat(windowsEmbeddedConfig); err != nil {
		return fmt.Errorf("CONFIG_INVALID: embedded config missing")
	}
	statusCmd := fmt.Sprintf(`(Get-Service -Name '%s' -ErrorAction SilentlyContinue).Status`, windowsEmbeddedService)
	out, err := accessRunCommandOutput("powershell", "-NoProfile", "-NonInteractive", "-Command", statusCmd)
	if err != nil || !strings.Contains(strings.ToLower(strings.TrimSpace(out)), "running") {
		return fmt.Errorf("SERVICE_START_FAILED: embedded service not running")
	}
	if err := checkTCPListeningFunc(defaultAccessListenPort); err != nil {
		return fmt.Errorf("PORT_IN_USE: %w", err)
	}
	return nil
}

func provisionWindowsOpenSSH(username, password, rootPath string) error {
	_ = username
	_ = password
	_ = rootPath
	_, _ = accessRunCommandLogged("powershell", "-NoProfile", "-NonInteractive", "-Command", "Add-WindowsCapability -Online -Name OpenSSH.Server~~~~0.0.1.0")
	orig, _ := os.ReadFile(windowsOpenSSHConfig)
	block := strings.Join([]string{
		"# BEGIN EASYWI MANAGED",
		fmt.Sprintf("Port %d", defaultAccessListenPort),
		"PasswordAuthentication yes",
		"DenyUsers Administrator",
		"Match Group easywi-sftp",
		"  ForceCommand internal-sftp",
		"# END EASYWI MANAGED",
	}, "\n") + "\n"
	merged := string(orig)
	if strings.Contains(merged, "# BEGIN EASYWI MANAGED") {
		start := strings.Index(merged, "# BEGIN EASYWI MANAGED")
		end := strings.Index(merged, "# END EASYWI MANAGED")
		if start >= 0 && end >= start {
			end += len("# END EASYWI MANAGED")
			merged = merged[:start] + block + merged[end:]
		}
	} else {
		merged += "\n" + block
	}
	if err := os.WriteFile(windowsOpenSSHConfig, []byte(merged), 0o644); err != nil {
		return fmt.Errorf("CONFIG_INVALID: %w", err)
	}
	if _, err := accessRunCommandLogged("powershell", "-NoProfile", "-NonInteractive", "-Command", "Restart-Service sshd -Force"); err != nil {
		_ = os.WriteFile(windowsOpenSSHConfig, orig, 0o644)
		_, _ = accessRunCommandLogged("powershell", "-NoProfile", "-NonInteractive", "-Command", "Restart-Service sshd -Force")
		return fmt.Errorf("SERVICE_START_FAILED: sshd restart failed and rollback applied")
	}
	return nil
}

func checkWindowsOpenSSHHealth() error {
	out, err := accessRunCommandOutput("powershell", "-NoProfile", "-NonInteractive", "-Command", "(Get-Service sshd -ErrorAction SilentlyContinue).Status")
	if err != nil || strings.TrimSpace(out) == "" {
		return fmt.Errorf("SERVICE_START_FAILED: sshd service unavailable")
	}
	if err := checkTCPListeningFunc(defaultAccessListenPort); err != nil {
		return fmt.Errorf("PORT_IN_USE: %w", err)
	}
	return nil
}

func sanitizeAccessError(err error) string {
	msg := err.Error()
	msg = strings.ReplaceAll(msg, "\n", " ")
	msg = strings.ReplaceAll(msg, "\r", " ")
	return strings.TrimSpace(msg)
}

func mapAccessErr(err error) string {
	msg := strings.ToUpper(err.Error())
	switch {
	case strings.Contains(msg, "INVALID_INPUT"):
		return "INVALID_INPUT"
	case strings.Contains(msg, "PERMISSION"):
		return "PERMISSION_DENIED"
	case strings.Contains(msg, "PACKAGE_INSTALL_FAILED"):
		return "PACKAGE_INSTALL_FAILED"
	case strings.Contains(msg, "SERVICE_START_FAILED"):
		return "SERVICE_START_FAILED"
	case strings.Contains(msg, "CONFIG_INVALID"):
		return "CONFIG_INVALID"
	case strings.Contains(msg, "PROFTPD_SFTP_MODULE_MISSING"):
		return "PROFTPD_SFTP_MODULE_MISSING"
	case strings.Contains(msg, "FIREWALL_CONFIG_FAILED"):
		return "FIREWALL_CONFIG_FAILED"
	case strings.Contains(msg, "PORT_NOT_LISTENING"):
		return "PORT_NOT_LISTENING"
	case strings.Contains(msg, "PORT_IN_USE"):
		return "PORT_IN_USE"
	case strings.Contains(msg, "ROOT_INVALID"):
		return "ROOT_INVALID"
	case strings.Contains(msg, "BACKEND_UNSUPPORTED"):
		return "BACKEND_UNSUPPORTED"
	default:
		return "INTERNAL_ERROR"
	}
}

func checkTCPListening(port int) error {
	conn, err := net.DialTimeout("tcp", "127.0.0.1:"+strconv.Itoa(port), 1200*time.Millisecond)
	if err != nil {
		return err
	}
	_ = conn.Close()
	return nil
}
