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

var (
	accessLookPath                  = exec.LookPath
	accessRunCommandLogged          = runCommandLogged
	accessRunCommandOutput          = runCommandOutput
	ensureLinuxProFTPDSFTPReadyFunc = ensureLinuxProFTPDSFTPReady
	ensureProFTPDUserFunc           = ensureProFTPDUser
	checkLinuxProFTPDHealthFunc     = checkLinuxProFTPDHealth
	detectProFTPDServiceNameFunc    = detectProFTPDServiceName
	ensureServiceRunningFunc        = ensureServiceRunning
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
	if err := ensureProFTPDSFTPModuleInstalled(); err != nil {
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
	confDir, err := detectProFTPDConfDir()
	if err != nil {
		return err
	}
	if err := ensureProFTPDConfig(confDir); err != nil {
		return err
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
	if _, err := accessLookPath("apt-get"); err == nil {
		if out, err := accessRunCommandLogged("apt-get", "update"); err != nil {
			return fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=apt-get packages=%s output=%s", strings.Join(packages, ","), strings.TrimSpace(out))
		}
		args := append([]string{"install", "-y"}, packages...)
		if out, err := accessRunCommandLogged("apt-get", args...); err != nil {
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
	if out, err := accessRunCommandLogged(installer, args...); err != nil {
		return fmt.Errorf("PACKAGE_INSTALL_FAILED: package_manager=%s packages=%s output=%s", installer, strings.Join(packages, ","), strings.TrimSpace(out))
	}
	return nil
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
	repaired, repairErr := enableProFTPDSFTPModule()
	if repairErr != nil {
		return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD mod_sftp is not installed or enabled; module_output=%q modules_conf_repaired=%t repair_error=%v; install proftpd-mod-crypto on Debian/Ubuntu or proftpd-mod_sftp on RHEL-compatible systems", strings.TrimSpace(out), repaired, repairErr)
	}
	service, serviceErr := detectProFTPDServiceNameFunc()
	if serviceErr == nil {
		_ = ensureServiceRunningFunc(service)
	}
	after, afterErr := accessRunCommandLogged("proftpd", "-l")
	if afterErr != nil || !proFTPDModuleListHasSFTP(after) {
		return fmt.Errorf("PROFTPD_SFTP_MODULE_MISSING: ProFTPD mod_sftp is not installed or enabled after repair; before=%q after=%q modules_conf_repaired=%t service_error=%v; install proftpd-mod-crypto on Debian/Ubuntu or proftpd-mod_sftp on RHEL-compatible systems", strings.TrimSpace(out), strings.TrimSpace(after), repaired, serviceErr)
	}
	return nil
}

func enableProFTPDSFTPModule() (bool, error) {
	modulesPath := "/etc/proftpd/modules.conf"
	if _, err := os.Stat(modulesPath); err != nil {
		return false, fmt.Errorf("modules.conf unavailable at %s: %w", modulesPath, err)
	}
	return enableProFTPDSFTPModuleInFile(modulesPath)
}

func enableProFTPDSFTPModuleInFile(path string) (bool, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return false, err
	}
	lines := strings.Split(string(raw), "\n")
	repaired := false
	found := false
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		if strings.EqualFold(trimmed, "LoadModule mod_sftp.c") {
			found = true
			continue
		}
		if strings.EqualFold(strings.TrimSpace(strings.TrimPrefix(trimmed, "#")), "LoadModule mod_sftp.c") {
			if !found {
				lines[i] = "LoadModule mod_sftp.c"
				found = true
				repaired = true
			} else {
				lines[i] = "#" + strings.TrimLeft(strings.TrimPrefix(trimmed, "#"), " \t")
			}
		}
	}
	if !found {
		if len(lines) > 0 && strings.TrimSpace(lines[len(lines)-1]) == "" {
			lines[len(lines)-1] = "LoadModule mod_sftp.c"
		} else {
			lines = append(lines, "LoadModule mod_sftp.c")
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
		"  SFTPEngine on",
		fmt.Sprintf("  Port %d", port),
		"  SFTPLog /var/log/proftpd/easywi-sftp.log",
		fmt.Sprintf("  SFTPHostKey %s", linuxHostKey),
		fmt.Sprintf("  AuthUserFile %s", linuxAuthUserFile),
		"  RequireValidShell off",
		"  DefaultRoot ~",
		"  MaxInstances 30",
		"  TimeoutIdle 600",
		"</IfModule>",
		"# END EASYWI MANAGED",
	}, "\n") + "\n"
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
	if err := os.MkdirAll(filepath.Dir(linuxAuthUserFile), 0o700); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: create auth file dir: %w", err)
	}
	if _, err := os.Stat(linuxAuthUserFile); err == nil {
		_ = os.Chmod(linuxAuthUserFile, 0o600)
		return nil
	} else if !os.IsNotExist(err) {
		return fmt.Errorf("PERMISSION_DENIED: stat auth file: %w", err)
	}
	if err := os.WriteFile(linuxAuthUserFile, []byte{}, 0o600); err != nil {
		return fmt.Errorf("CONFIG_INVALID: create auth file: %w", err)
	}
	return nil
}

func ensureProFTPDKeys() error {
	if err := os.MkdirAll(linuxKeyDir, 0o700); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: create key dir: %w", err)
	}
	if _, err := os.Stat(linuxHostKey); err == nil {
		_ = os.Chmod(linuxHostKey, 0o600)
		return nil
	}
	if out, err := accessRunCommandLogged("ssh-keygen", "-q", "-t", "rsa", "-b", "3072", "-N", "", "-f", linuxHostKey); err != nil {
		return fmt.Errorf("CONFIG_INVALID: %s", strings.TrimSpace(out))
	}
	if err := os.Chmod(linuxHostKey, 0o600); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chmod key: %w", err)
	}
	return nil
}

func ensureProFTPDUser(username, password, rootPath string) error {
	if err := os.MkdirAll(filepath.Dir(linuxAuthUserFile), 0o700); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: %w", err)
	}
	if err := os.MkdirAll(rootPath, 0o750); err != nil {
		return fmt.Errorf("ROOT_INVALID: %w", err)
	}
	uidOut, err := accessRunCommandOutput("id", "-u", "easywi")
	if err != nil {
		return fmt.Errorf("PERMISSION_DENIED: easywi uid missing")
	}
	gidOut, err := accessRunCommandOutput("id", "-g", "easywi")
	if err != nil {
		return fmt.Errorf("PERMISSION_DENIED: easywi gid missing")
	}
	uid := strings.TrimSpace(uidOut)
	gid := strings.TrimSpace(gidOut)
	if out, err := accessRunCommandLogged("chown", "-R", uid+":"+gid, rootPath); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chown root path failed: %s", strings.TrimSpace(out))
	}
	if err := os.Chmod(rootPath, 0o750); err != nil {
		return fmt.Errorf("PERMISSION_DENIED: chmod root path failed: %w", err)
	}
	hash, err := accessRunCommandOutput("openssl", "passwd", "-6", password)
	if err != nil {
		h := sha256.Sum256([]byte(password))
		hash = "$easywi$" + hex.EncodeToString(h[:])
	}
	entry := fmt.Sprintf("%s:%s:%s:%s::%s:/usr/sbin/nologin", username, strings.TrimSpace(hash), uid, gid, rootPath)
	existing, readErr := os.ReadFile(linuxAuthUserFile)
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
	if err := os.WriteFile(linuxAuthUserFile, []byte(strings.Join(updated, "\n")+"\n"), 0o600); err != nil {
		return fmt.Errorf("CONFIG_INVALID: write auth file: %w", err)
	}
	return nil
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

func ensureServiceRunning(service string) error {
	if _, err := accessLookPath("systemctl"); err == nil {
		if out, err := accessRunCommandLogged("systemctl", "daemon-reload"); err != nil {
			return fmt.Errorf("SERVICE_START_FAILED: %s", strings.TrimSpace(out))
		}
		if out, err := accessRunCommandLogged("systemctl", "restart", service); err != nil {
			if out2, err2 := accessRunCommandLogged("systemctl", "start", service); err2 != nil {
				return fmt.Errorf("SERVICE_START_FAILED: %s | %s", strings.TrimSpace(out), strings.TrimSpace(out2))
			}
		}
		// Enabling boot-start should not fail provisioning on hosts with custom init wiring.
		_, _ = accessRunCommandLogged("systemctl", "enable", service)
		return nil
	}
	if out, err := accessRunCommandLogged("service", service, "restart"); err != nil {
		return fmt.Errorf("SERVICE_START_FAILED: %s", strings.TrimSpace(out))
	}
	return nil
}

func checkLinuxProFTPDHealth() error {
	confDir, err := detectProFTPDConfDir()
	if err != nil {
		return err
	}
	managedConfig := filepath.Join(confDir, "easywi-sftp.conf")
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
	if !strings.Contains(cfgStr, "# BEGIN EASYWI MANAGED") || !strings.Contains(cfgStr, "# END EASYWI MANAGED") {
		if err := ensureProFTPDConfig(confDir); err != nil {
			return fmt.Errorf("CONFIG_INVALID: managed markers missing")
		}
	}
	if _, err := ensureProFTPDManagedConfigIncluded(confDir); err != nil {
		return err
	}
	if _, err := os.Stat(linuxHostKey); err != nil {
		if err := ensureProFTPDKeys(); err != nil {
			return fmt.Errorf("CONFIG_INVALID: host key missing")
		}
	}
	if err := ensureProFTPDSFTPModuleInstalled(); err != nil {
		return err
	}
	if _, err := os.Stat(linuxAuthUserFile); err != nil {
		return fmt.Errorf("CONFIG_INVALID: auth file missing")
	}
	service, err := detectProFTPDServiceName()
	if err != nil {
		return err
	}
	if _, err := accessLookPath("systemctl"); err == nil {
		out, err := accessRunCommandOutput("systemctl", "is-active", service)
		if err != nil || strings.TrimSpace(out) != "active" {
			if err := ensureServiceRunning(service); err != nil {
				return err
			}
		}
	}
	if err := checkTCPListening(defaultAccessListenPort); err != nil {
		return fmt.Errorf("PORT_IN_USE: port %d not reachable: %w", defaultAccessListenPort, err)
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
	if err := checkTCPListening(defaultAccessListenPort); err != nil {
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
	if err := checkTCPListening(defaultAccessListenPort); err != nil {
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
