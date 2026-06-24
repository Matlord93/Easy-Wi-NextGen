package main

import (
	"bufio"
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"sync"
	"time"

	"easywi/agent/internal/jobs"
	"github.com/creack/pty"
)

const (
	teamspeakBackendStatusNotConfigured          = "not_configured"
	teamspeakBackendStatusBinaryMissing          = "binary_missing"
	teamspeakBackendStatusBinaryNotExecutable    = "binary_not_executable"
	teamspeakBackendStatusLibraryMissing         = "library_missing"
	teamspeakBackendStatusOpusMissing            = "opus_missing"
	teamspeakBackendStatusIdentityMissing        = "identity_missing"
	teamspeakBackendStatusInvalidPermissions     = "invalid_permissions"
	teamspeakBackendStatusClientBackendRequired  = "client_backend_required"
	teamspeakBackendStatusReady                  = "ready"
	teamspeakBackendStatusConnected              = "connected"
	teamspeakBackendStatusFailed                 = "failed"
	teamspeakBackendStatusOfficialNotInstalled   = "official_client_not_installed"
	teamspeakBackendStatusOfficialDownloadFailed = "official_client_download_failed"
	teamspeakBackendStatusOfficialChecksumFailed = "official_client_checksum_failed"
	teamspeakBackendStatusOfficialInstalled      = "official_client_installed"
	teamspeakBackendStatusOfficialLibraryMissing = "official_client_installed_library_missing"
	teamspeakBackendStatusOfficialInvalid        = "official_client_invalid"
	teamspeakBackendStatusOfficialReady          = "official_client_ready"

	teamspeakBackendStatusExternalBridgeReady      = "external_bridge_ready"
	teamspeakBackendStatusXvfbMissing              = "xvfb_missing"
	teamspeakBackendStatusAudioBackendMissing      = "audio_backend_missing"
	teamspeakBackendStatusClientBinaryMissing      = "client_binary_missing"
	teamspeakBackendStatusClientQueryPluginMissing = "clientquery_plugin_missing"
)

const clientQueryPluginFilename = "libclientquery_plugin_linux_amd64.so"

// teamspeakExternalBridgePackages is the set of system packages that must be
// installed for the external_client_bridge backend (Xvfb + Qt/XCB + PulseAudio).
// Packages with Ubuntu 24.04 renames are handled by teamspeakExternalBridgePackageAlternatives.
var teamspeakExternalBridgePackages = []string{
	"xvfb",
	"x11-utils",
	"dbus-x11",
	"libxcb-xinerama0",
	"libxkbcommon-x11-0",
	"libxcb-cursor0",
	"libxcb-render0",
	"libxcb-shape0",
	"libxcb-shm0",
	"libxcb1",
	"libxcb-glx0",
	"libx11-6",
	"libxext6",
	"libxrender1",
	"libnss3",
	"libxcomposite1",
	"libxcursor1",
	"libxi6",
	"libxtst6",
	"libxrandr2",
	"libatk1.0-0",
	"libatk-bridge2.0-0",
	"libgtk-3-0",
	"libpulse0",
	"libasound2-plugins",
	"pulseaudio",
}

// teamspeakExternalBridgePackageAlternatives lists packages that were renamed
// between Ubuntu releases. Each pair is [preferred (Ubuntu 24.04+), fallback
// (Ubuntu 22.04 and earlier)]. The installer tries the preferred name first and
// falls back to the second when apt-cache cannot locate the preferred package.
var teamspeakExternalBridgePackageAlternatives = [][2]string{
	{"libasound2t64", "libasound2"}, // Ubuntu 24.04 transition package rename
	{"libc++1-18", "libc++1"},       // LLVM versioned vs. unversioned
	{"libc++abi1-18", "libc++abi1"}, // LLVM versioned vs. unversioned
}

const teamspeakBackendDefaultTimeout = 8 * time.Second
const teamspeakOfficialClientDownloadTimeout = 90 * time.Second
const teamspeakOfficialClientMaxDownloadBytes int64 = 250 * 1024 * 1024

// teamspeakOfficialClientInstallerTimeout is the maximum time the PTY-based installer loop may run.
// Overridable in tests.
var teamspeakOfficialClientInstallerTimeout = 300 * time.Second

// teamspeakPTYControlRe matches ANSI/VT100 escape sequences emitted by terminal pagers.
var teamspeakPTYControlRe = regexp.MustCompile(`\x1b(?:\[[0-9;?]*[a-zA-Z]|[^\[])`)

func stripTeamspeakPTYControl(s string) string {
	s = teamspeakPTYControlRe.ReplaceAllString(s, "")
	s = strings.ReplaceAll(s, "\r", "")
	return s
}

var teamspeakOfficialClientAllowedHosts = map[string]bool{
	"files.teamspeak-services.com": true,
}

var teamspeakOfficialHTTPClient = &http.Client{
	Timeout: teamspeakOfficialClientDownloadTimeout,
	CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if len(via) >= 5 {
			return errors.New("too many redirects")
		}
		return validateTeamspeakOfficialClientURL(req.URL.String())
	},
}

type teamspeakBackendConfig struct {
	BackendType         string
	BackendPath         string
	LibraryPath         string
	OpusLibraryPath     string
	IdentityPath        string
	InstallPath         string
	BinaryPath          string
	Version             string
	ExpectedChecksum    string
	AutoInstall         bool
	Host                string
	Port                int
	Profile             string
	Nickname            string
	ChannelID           string
	ServerPassword      string
	ChannelPassword     string
	BridgePath          string
	ClientBinaryPath    string
	ClientRunscriptPath string
	AudioBackend        string
	InstallDependencies bool
	InstancePath        string // base path for persistent runtime dirs
	ClientQueryHost     string
	ClientQueryPort     int
}

type teamspeakBackendValidation struct {
	Status                     string
	BackendType                string
	BackendPath                string
	LibraryPath                string
	OpusLibraryPath            string
	IdentityPath               string
	InstallPath                string
	BinaryPath                 string
	Version                    string
	Checksum                   string
	AutoInstall                bool
	BinaryFound                bool
	BinaryExec                 bool
	LibraryFound               bool
	OpusFound                  bool
	IdentityFound              bool
	BuildMode                  string
	Ready                      bool
	Connected                  bool
	LastError                  string
	LastCheckedAt              string
	BridgePath                 string
	ClientBinaryPath           string
	ClientRunscriptPath        string
	XvfbAvailable              bool
	AudioBackendAvailable      bool
	ClientQueryPluginAvailable bool
	OfficialClientDir          string
}

type teamspeakBackendProcessResponse struct {
	OK        bool   `json:"ok"`
	Error     string `json:"error,omitempty"`
	Ready     bool   `json:"ready,omitempty"`
	State     string `json:"state,omitempty"`
	ClientID  string `json:"client_id,omitempty"`
	ChannelID string `json:"channel_id,omitempty"`
	BuildMode string `json:"build_mode,omitempty"`
}

type teamspeakOfficialClientConfig struct {
	NodeID               string
	Version              string
	DownloadURL          string
	ExpectedSHA256       string
	InstallPath          string
	RequestedBy          string
	AcceptedConfirmation bool
}

func handleMusicbotTeamspeakBackendInstall(job jobs.Job) orchestratorResult {
	cfg := teamspeakBackendConfigFromJob(job)
	validation := validateTeamspeakBackendConfig(cfg, true)
	if validation.Status != teamspeakBackendStatusReady {
		return teamspeakBackendResult("failed", validation)
	}
	return teamspeakBackendResult("success", validation)
}

func handleMusicbotTeamspeakBackendRepair(job jobs.Job) orchestratorResult {
	// Repair is intentionally local-only: it validates paths and permissions,
	// and for external_client_bridge tries to migrate a missing ClientQuery
	// plugin from known ts3home locations without downloading proprietary files.
	cfg := teamspeakBackendConfigFromJob(job)
	if cfg.BackendType == "external_client_bridge" {
		_ = repairTeamspeakClientQueryPlugin(cfg) // best-effort; validation reports final status
	}
	validation := validateTeamspeakBackendConfig(cfg, true)
	readyStatuses := map[string]bool{
		teamspeakBackendStatusReady:               true,
		teamspeakBackendStatusExternalBridgeReady: true,
		teamspeakBackendStatusConnected:           true,
	}
	if !readyStatuses[validation.Status] {
		return teamspeakBackendResult("failed", validation)
	}
	return teamspeakBackendResult("success", validation)
}

// officialClientDirFrom returns the directory of the official TS3 client
// installation, preferring the runscript path directory over the binary path.
func officialClientDirFrom(clientBinaryPath, clientRunscriptPath string) string {
	if d := filepath.Dir(strings.TrimSpace(clientRunscriptPath)); d != "" && d != "." {
		return d
	}
	if d := filepath.Dir(strings.TrimSpace(clientBinaryPath)); d != "" && d != "." {
		return d
	}
	return ""
}

// repairTeamspeakClientQueryPlugin attempts to fix a missing ClientQuery plugin
// by migrating it from known ts3home paths. No proprietary files are downloaded.
func repairTeamspeakClientQueryPlugin(cfg teamspeakBackendConfig) error {
	officialClientDir := officialClientDirFrom(cfg.ClientBinaryPath, cfg.ClientRunscriptPath)
	if officialClientDir == "" {
		return fmt.Errorf("cannot determine official client dir from client_binary_path / client_runscript_path")
	}

	pluginDir := filepath.Join(officialClientDir, "plugins")
	pluginDst := filepath.Join(pluginDir, clientQueryPluginFilename)

	if info, err := os.Stat(pluginDst); err == nil && info.Mode().IsRegular() {
		return nil // already present
	}

	// Collect migration-source candidates (ts3home directories left by previous runs).
	var sources []string
	if cfg.InstancePath != "" {
		sources = append(sources,
			filepath.Join(cfg.InstancePath, "runtime", "teamspeak-bridge", "ts3home", ".ts3client", "plugins", clientQueryPluginFilename),
			filepath.Join(cfg.InstancePath, "ts3home", ".ts3client", "plugins", clientQueryPluginFilename),
		)
	}
	// Also check sibling directories of the official-client dir (e.g. /opt/easywi/musicbot/teamspeak-client/<instance>/ts3home/…).
	if parentDir := filepath.Dir(officialClientDir); parentDir != "" && parentDir != "." {
		if entries, err := os.ReadDir(parentDir); err == nil {
			for _, e := range entries {
				if !e.IsDir() {
					continue
				}
				sources = append(sources,
					filepath.Join(parentDir, e.Name(), "ts3home", ".ts3client", "plugins", clientQueryPluginFilename),
				)
			}
		}
	}

	for _, src := range sources {
		info, err := os.Stat(src)
		if err != nil || !info.Mode().IsRegular() {
			continue
		}
		if err := os.MkdirAll(pluginDir, 0o755); err != nil {
			return fmt.Errorf("create plugins dir %s: %w", pluginDir, err)
		}
		if err := copyFile(src, pluginDst, 0o755); err != nil {
			return fmt.Errorf("copy %s → %s: %w", filepath.Base(src), pluginDst, err)
		}
		return nil
	}

	return fmt.Errorf("clientquery_plugin_missing: %s not found in official-client dir or any ts3home migration path", clientQueryPluginFilename)
}

func handleMusicbotTeamspeakBackendStatus(job jobs.Job) orchestratorResult {
	cfg := teamspeakBackendConfigFromJob(job)
	validation := validateTeamspeakBackendConfig(cfg, true)
	if validation.Status == teamspeakBackendStatusReady {
		if resp, err := teamspeakBackendCommand(cfg, map[string]any{"action": "status"}); err == nil {
			validation.BuildMode = resp.BuildMode
			validation.Connected = resp.State == "connected"
			if validation.Connected {
				validation.Status = teamspeakBackendStatusConnected
			}
		} else if validation.BuildMode == "" {
			validation.LastError = sanitizeTeamspeakBackendError(err.Error(), cfg)
		}
	}
	return teamspeakBackendResult("success", validation)
}

func handleMusicbotTeamspeakBackendValidate(job jobs.Job) orchestratorResult {
	cfg := teamspeakBackendConfigFromJob(job)
	validation := validateTeamspeakBackendConfig(cfg, true)
	if validation.Status != teamspeakBackendStatusReady {
		return teamspeakBackendResult("failed", validation)
	}
	return teamspeakBackendResult("success", validation)
}

func handleMusicbotTeamspeakBackendTestConnection(job jobs.Job) orchestratorResult {
	cfg := teamspeakBackendConfigFromJob(job)
	validation := validateTeamspeakBackendConfig(cfg, false)
	if validation.Status != teamspeakBackendStatusReady {
		return teamspeakBackendResult("failed", validation)
	}
	if strings.TrimSpace(cfg.Host) == "" {
		validation.Status = teamspeakBackendStatusFailed
		validation.LastError = "host is required for TeamSpeak backend connection test"
		return teamspeakBackendResult("failed", validation)
	}
	connect := map[string]any{
		"action":                "connect",
		"backend_type":          cfg.BackendType,
		"backend_path":          cfg.LibraryPath,
		"host":                  cfg.Host,
		"port":                  cfg.Port,
		"profile":               cfg.Profile,
		"nickname":              cfg.Nickname,
		"identity_path":         cfg.IdentityPath,
		"server_password":       cfg.ServerPassword,
		"channel_id":            cfg.ChannelID,
		"channel_password":      cfg.ChannelPassword,
		"client_binary_path":    cfg.ClientBinaryPath,
		"client_runscript_path": cfg.ClientRunscriptPath,
		"audio_backend":         cfg.AudioBackend,
		"instance_path":         cfg.InstancePath,
		"client_query_host":     cfg.ClientQueryHost,
		"client_query_port":     cfg.ClientQueryPort,
	}
	resp, err := teamspeakBackendCommandSequence(cfg, []map[string]any{connect})
	if err != nil {
		validation.Status = teamspeakBackendStatusFailed
		validation.LastError = sanitizeTeamspeakBackendError(err.Error(), cfg)
		return teamspeakBackendResult("failed", validation)
	}
	validation.BuildMode = resp.BuildMode
	validation.Connected = resp.State == "connected" || resp.OK
	validation.Status = teamspeakBackendStatusConnected
	if strings.TrimSpace(cfg.Nickname) != "" {
		if _, err := teamspeakBackendCommandSequence(cfg, []map[string]any{connect, map[string]any{"action": "set_nickname", "nickname": cfg.Nickname}}); err != nil {
			validation.Status = teamspeakBackendStatusFailed
			validation.LastError = sanitizeTeamspeakBackendError(err.Error(), cfg)
			return teamspeakBackendResult("failed", validation)
		}
	}
	if strings.TrimSpace(cfg.ChannelID) != "" {
		sequence := []map[string]any{connect, map[string]any{"action": "join_channel", "channel_id": cfg.ChannelID, "channel_password": cfg.ChannelPassword}}
		frame := base64.StdEncoding.EncodeToString([]byte{0xf8, 0xff, 0xfe})
		sequence = append(sequence, map[string]any{"action": "send_opus_frame", "format": "opus", "payload": frame, "duration_ms": 20})
		if _, err := teamspeakBackendCommandSequence(cfg, sequence); err != nil {
			validation.Status = teamspeakBackendStatusFailed
			validation.LastError = sanitizeTeamspeakBackendError(err.Error(), cfg)
			return teamspeakBackendResult("failed", validation)
		}
	}
	_, _ = teamspeakBackendCommand(cfg, map[string]any{"action": "shutdown"})
	return teamspeakBackendResult("success", validation)
}

func handleMusicbotTeamspeakBackendInstallOfficialClient(job jobs.Job) orchestratorResult {
	cfg := teamspeakOfficialClientConfigFromJob(job)
	result := map[string]any{
		"status":          teamspeakBackendStatusOfficialNotInstalled,
		"version":         cfg.Version,
		"download_url":    cfg.DownloadURL,
		"install_path":    cfg.InstallPath,
		"requested_by":    cfg.RequestedBy,
		"last_checked_at": time.Now().UTC().Format(time.RFC3339),
	}
	if !cfg.AcceptedConfirmation {
		result["status"] = teamspeakBackendStatusOfficialInvalid
		result["last_error"] = "accepted_license_confirmation=true is required"
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	if err := validateTeamspeakOfficialClientURL(cfg.DownloadURL); err != nil {
		result["status"] = teamspeakBackendStatusOfficialInvalid
		result["last_error"] = err.Error()
		return orchestratorResult{status: "failed", errorText: err.Error(), resultPayload: result}
	}
	installPath, err := validateTeamspeakOfficialInstallPath(cfg.InstallPath)
	if err != nil {
		result["status"] = teamspeakBackendStatusOfficialInvalid
		result["last_error"] = err.Error()
		return orchestratorResult{status: "failed", errorText: err.Error(), resultPayload: result}
	}
	if err := os.MkdirAll(installPath, 0o755); err != nil {
		result["status"] = teamspeakBackendStatusOfficialInvalid
		result["last_error"] = fmt.Sprintf("create official client install_path: %v", err)
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	tmpDir, err := os.MkdirTemp("", "easywi-ts3-client-*")
	if err != nil {
		result["status"] = teamspeakBackendStatusOfficialInvalid
		result["last_error"] = fmt.Sprintf("create temp dir: %v", err)
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	defer func() { _ = os.RemoveAll(tmpDir) }()
	installerPath := filepath.Join(tmpDir, "TeamSpeak3-Client.run")
	checksum, err := downloadTeamspeakOfficialClient(cfg.DownloadURL, installerPath)
	if err != nil {
		result["status"] = teamspeakBackendStatusOfficialDownloadFailed
		result["last_error"] = sanitizeTeamspeakOfficialClientText(err.Error())
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	result["checksum"] = checksum
	if cfg.ExpectedSHA256 != "" && !strings.EqualFold(cfg.ExpectedSHA256, checksum) {
		result["status"] = teamspeakBackendStatusOfficialChecksumFailed
		result["last_error"] = "official TeamSpeak client installer checksum mismatch"
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	if err := os.Chmod(installerPath, 0o500); err != nil {
		result["status"] = teamspeakBackendStatusOfficialInvalid
		result["last_error"] = fmt.Sprintf("chmod official client installer: %v", err)
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	if err := extractTeamspeakOfficialClient(installerPath, installPath); err != nil {
		result["status"] = teamspeakBackendStatusOfficialInvalid
		result["last_error"] = sanitizeTeamspeakOfficialClientText(err.Error())
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	installedFiles := collectTeamspeakOfficialInstalledFiles(installPath, 200)
	libraryPath := findFirstExistingFile(installPath, []string{"libts3client.so"})
	opusPath := findFirstExistingFile(installPath, []string{"libopus.so", "libopus.so.0"})
	clientQueryPluginPath := findFirstExistingFile(installPath, []string{clientQueryPluginFilename})
	result["installed_files"] = installedFiles
	result["official_client_last_installed_at"] = time.Now().UTC().Format(time.RFC3339)
	result["official_client_install_path"] = installPath
	result["status"] = teamspeakBackendStatusOfficialInstalled
	if libraryPath != "" {
		result["library_path"] = libraryPath
	}
	if opusPath != "" {
		result["opus_library_path"] = opusPath
	}
	if clientQueryPluginPath != "" {
		result["client_query_plugin_path"] = clientQueryPluginPath
	}
	if err := chmodTeamspeakOfficialTree(installPath); err != nil {
		result["last_error"] = sanitizeTeamspeakOfficialClientText(err.Error())
	}
	if clientQueryPluginPath == "" {
		// Plugin missing after extraction; this is required for external_client_bridge mode.
		// Warn in last_error but do not fail the install – the plugin may arrive via repair.
		warn := fmt.Sprintf("%s not found in %s/plugins/ after installation; run 'repair' if using external_client_bridge", clientQueryPluginFilename, installPath)
		if existing, _ := result["last_error"].(string); existing != "" {
			result["last_error"] = existing + "; " + warn
		} else {
			result["last_error"] = warn
		}
	}
	if libraryPath == "" {
		msg := "Offizieller TeamSpeak Client wurde installiert, aber keine ladbare libts3client.so gefunden. Bitte TS3 Client SDK/Library oder kompatiblen Client-Layer bereitstellen."
		result["status"] = teamspeakBackendStatusOfficialLibraryMissing
		result["last_error"] = msg
		return orchestratorResult{status: "failed", errorText: msg, resultPayload: result}
	}
	result["status"] = teamspeakBackendStatusOfficialInstalled

	clientBinaryPath := findFirstExistingFile(installPath, []string{"ts3client_linux_amd64", "ts3client_linux_x86_64"})
	clientRunscriptPath := findFirstExistingFile(installPath, []string{"ts3client_runscript.sh"})
	if clientBinaryPath != "" {
		result["client_binary_path"] = clientBinaryPath
		result["client_runscript_path"] = clientRunscriptPath
		result["backend_type_suggestion"] = "external_client_bridge"
		if info, err := os.Stat("/usr/local/bin/easywi-teamspeak-bridge"); err == nil && !info.IsDir() && info.Mode().Perm()&0o111 != 0 {
			result["bridge_path_suggestion"] = "/usr/local/bin/easywi-teamspeak-bridge"
		}
	} else {
		result["backend_type_suggestion"] = "client_library"
		if info, err := os.Stat("/usr/local/bin/easywi-teamspeak-client"); err == nil && !info.IsDir() && info.Mode().Perm()&0o111 != 0 {
			result["backend_path_suggestion"] = "/usr/local/bin/easywi-teamspeak-client"
		}
	}
	return orchestratorResult{status: "success", resultPayload: result}
}

func teamspeakBackendConfigFromJob(job jobs.Job) teamspeakBackendConfig {
	backendPath := strings.TrimSpace(payloadValue(job.Payload, "backend_path"))
	binaryPath := strings.TrimSpace(payloadValue(job.Payload, "binary_path"))
	if backendPath == "" {
		backendPath = binaryPath
	}
	if binaryPath == "" {
		binaryPath = backendPath
	}
	port := 9987
	if raw := strings.TrimSpace(payloadValue(job.Payload, "port")); raw != "" {
		_, _ = fmt.Sscanf(raw, "%d", &port)
	}
	return teamspeakBackendConfig{
		BackendType:         normalizeAgentTeamspeakBackendType(payloadValue(job.Payload, "backend_type")),
		BackendPath:         backendPath,
		LibraryPath:         strings.TrimSpace(payloadValue(job.Payload, "library_path")),
		OpusLibraryPath:     strings.TrimSpace(payloadValue(job.Payload, "opus_library_path")),
		IdentityPath:        strings.TrimSpace(payloadValue(job.Payload, "identity_path")),
		InstallPath:         strings.TrimSpace(payloadValue(job.Payload, "install_path")),
		BinaryPath:          binaryPath,
		Version:             strings.TrimSpace(payloadValue(job.Payload, "version")),
		ExpectedChecksum:    strings.TrimSpace(payloadValue(job.Payload, "expected_checksum", "checksum")),
		AutoInstall:         payloadBool(job.Payload, "auto_install_enabled"),
		Host:                strings.TrimSpace(payloadValue(job.Payload, "host")),
		Port:                port,
		Profile:             normalizeTeamspeakBackendProfile(payloadValue(job.Payload, "profile")),
		Nickname:            strings.TrimSpace(payloadValue(job.Payload, "nickname")),
		ChannelID:           strings.TrimSpace(payloadValue(job.Payload, "channel_id")),
		ServerPassword:      payloadValue(job.Payload, "server_password"),
		ChannelPassword:     payloadValue(job.Payload, "channel_password"),
		BridgePath:          strings.TrimSpace(payloadValue(job.Payload, "bridge_path")),
		ClientBinaryPath:    strings.TrimSpace(payloadValue(job.Payload, "client_binary_path")),
		ClientRunscriptPath: strings.TrimSpace(payloadValue(job.Payload, "client_runscript_path")),
		AudioBackend:        strings.TrimSpace(payloadValue(job.Payload, "audio_backend")),
		InstallDependencies: payloadBool(job.Payload, "install_dependencies"),
		InstancePath:        strings.TrimSpace(payloadValue(job.Payload, "instance_path")),
		ClientQueryHost:     strings.TrimSpace(payloadValue(job.Payload, "client_query_host")),
		ClientQueryPort: func() int {
			raw := strings.TrimSpace(payloadValue(job.Payload, "client_query_port"))
			if raw == "" {
				return 0
			}
			var p int
			_, _ = fmt.Sscanf(raw, "%d", &p)
			return p
		}(),
	}
}

func teamspeakOfficialClientConfigFromJob(job jobs.Job) teamspeakOfficialClientConfig {
	version := strings.TrimSpace(payloadValue(job.Payload, "version"))
	if version == "" {
		version = "3.6.2"
	}
	return teamspeakOfficialClientConfig{
		NodeID:               strings.TrimSpace(payloadValue(job.Payload, "node_id")),
		Version:              version,
		DownloadURL:          strings.TrimSpace(payloadValue(job.Payload, "download_url")),
		ExpectedSHA256:       strings.TrimSpace(payloadValue(job.Payload, "expected_sha256")),
		InstallPath:          strings.TrimSpace(payloadValue(job.Payload, "install_path")),
		RequestedBy:          strings.TrimSpace(payloadValue(job.Payload, "requested_by")),
		AcceptedConfirmation: payloadBool(job.Payload, "accepted_license_confirmation"),
	}
}

func validateTeamspeakOfficialClientURL(rawURL string) error {
	if strings.TrimSpace(rawURL) == "" {
		return errors.New("official TeamSpeak client download_url is required")
	}
	parsed, err := url.Parse(rawURL)
	if err != nil {
		return fmt.Errorf("invalid official TeamSpeak client download_url: %w", err)
	}
	if parsed.Scheme != "https" {
		return errors.New("official TeamSpeak client download_url must use https")
	}
	host := strings.ToLower(parsed.Hostname())
	if host == "localhost" || strings.HasSuffix(host, ".localhost") {
		return errors.New("official TeamSpeak client download_url must not use localhost")
	}
	if !teamspeakOfficialClientAllowedHosts[host] {
		return fmt.Errorf("official TeamSpeak client download host %q is not allowed", host)
	}
	if ip := net.ParseIP(host); ip != nil {
		return errors.New("official TeamSpeak client download_url must not use an IP address")
	}
	if strings.ContainsAny(rawURL, "\x00\n\r") {
		return errors.New("official TeamSpeak client download_url contains disallowed characters")
	}
	return nil
}

func validateTeamspeakOfficialInstallPath(rawPath string) (string, error) {
	if strings.TrimSpace(rawPath) == "" {
		return "", errors.New("official_client_install_path is required")
	}
	// Reject null bytes, newlines, and shell metacharacters as defense-in-depth.
	if strings.ContainsAny(rawPath, "\x00\n\r;$`&|<>") || !filepath.IsAbs(rawPath) {
		return "", errors.New("official_client_install_path must be an absolute path without shell metacharacters")
	}
	cleaned := filepath.Clean(rawPath)
	if cleaned == string(filepath.Separator) || cleaned == "." || !strings.Contains(strings.ToLower(filepath.ToSlash(cleaned)), "/teamspeak-client") {
		return "", errors.New("official_client_install_path must target a teamspeak-client directory")
	}
	return cleaned, nil
}

func downloadTeamspeakOfficialClient(downloadURL string, destination string) (string, error) {
	if err := validateTeamspeakOfficialClientURL(downloadURL); err != nil {
		return "", err
	}
	ctx, cancel := context.WithTimeout(context.Background(), teamspeakOfficialClientDownloadTimeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, downloadURL, nil)
	if err != nil {
		return "", err
	}
	resp, err := teamspeakOfficialHTTPClient.Do(req)
	if err != nil {
		return "", err
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode < 200 || resp.StatusCode > 299 {
		return "", fmt.Errorf("official TeamSpeak client download failed with HTTP status %d", resp.StatusCode)
	}
	if resp.ContentLength > teamspeakOfficialClientMaxDownloadBytes {
		return "", fmt.Errorf("official TeamSpeak client download exceeds maximum size")
	}
	output, err := os.OpenFile(destination, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o600)
	if err != nil {
		return "", err
	}
	defer func() { _ = output.Close() }()
	limited := io.LimitReader(resp.Body, teamspeakOfficialClientMaxDownloadBytes+1)
	hasher := sha256.New()
	written, err := io.Copy(io.MultiWriter(output, hasher), limited)
	if err != nil {
		return "", err
	}
	if written > teamspeakOfficialClientMaxDownloadBytes {
		return "", fmt.Errorf("official TeamSpeak client download exceeds maximum size")
	}
	return hex.EncodeToString(hasher.Sum(nil)), nil
}

func extractTeamspeakOfficialClient(installerPath string, installPath string) error {
	const maxOutputBytes = 8 * 1024

	ctx, cancel := context.WithTimeout(context.Background(), teamspeakOfficialClientInstallerTimeout)
	defer cancel()

	cmd := exec.CommandContext(ctx, installerPath, "--target", installPath, "--noexec")

	ptmx, err := pty.Start(cmd)
	if err != nil {
		return fmt.Errorf("extract official TeamSpeak client installer (pty): %w", err)
	}
	defer func() { _ = ptmx.Close() }()

	exitCh := make(chan error, 1)
	var mu sync.Mutex
	var outputBuf bytes.Buffer

	go func() {
		tmp := make([]byte, 512)
		for {
			n, readErr := ptmx.Read(tmp)
			if n > 0 {
				mu.Lock()
				if outputBuf.Len() < maxOutputBytes {
					toWrite := n
					if remaining := maxOutputBytes - outputBuf.Len(); toWrite > remaining {
						toWrite = remaining
					}
					outputBuf.Write(tmp[:toWrite])
				}
				mu.Unlock()
			}
			if readErr != nil {
				break
			}
		}
		exitCh <- cmd.Wait()
	}()

	sentEnter := false
	sentQ := false
	sentY := false
	startedAt := time.Now()
	ticker := time.NewTicker(300 * time.Millisecond)
	defer ticker.Stop()

	for {
		select {
		case exitErr := <-exitCh:
			if ctx.Err() != nil {
				return errors.New("official TeamSpeak installer timed out while running in PTY mode")
			}
			if exitErr != nil {
				mu.Lock()
				out := sanitizeTeamspeakOfficialClientText(outputBuf.String())
				mu.Unlock()
				return fmt.Errorf("extract official TeamSpeak client installer: %w: %s", exitErr, out)
			}
			return nil
		case <-ctx.Done():
			if cmd.Process != nil {
				_ = cmd.Process.Kill()
			}
			return errors.New("official TeamSpeak installer timed out while running in PTY mode")
		case <-ticker.C:
			elapsed := time.Since(startedAt)
			mu.Lock()
			current := stripTeamspeakPTYControl(strings.ToLower(outputBuf.String()))
			mu.Unlock()

			// Send initial Enter to advance past any startup prompt.
			if !sentEnter {
				_, _ = ptmx.Write([]byte("\r"))
				sentEnter = true
			}

			// Quit pager (less/more) when prompt is recognized or after a short wait.
			if !sentQ {
				if strings.Contains(current, "--more--") ||
					strings.Contains(current, "(end)") ||
					strings.Contains(current, "press enter") ||
					strings.Contains(current, "hit enter") ||
					elapsed > 3*time.Second {
					_, _ = ptmx.Write([]byte("q"))
					sentQ = true
				}
			}

			// Accept license when recognized or after fallback delay.
			if !sentY {
				if strings.Contains(current, "accept") ||
					strings.Contains(current, "agree") ||
					strings.Contains(current, "license") ||
					strings.Contains(current, "terms") ||
					strings.Contains(current, "[y/n]") ||
					strings.Contains(current, "(y/n)") ||
					elapsed > 5*time.Second {
					_, _ = ptmx.Write([]byte("y\r"))
					sentY = true
				}
			}
		}
	}
}

func collectTeamspeakOfficialInstalledFiles(root string, limit int) []string {
	files := []string{}
	_ = filepath.WalkDir(root, func(path string, d os.DirEntry, err error) error {
		if err != nil || path == root {
			return nil
		}
		if len(files) >= limit {
			return filepath.SkipDir
		}
		rel, relErr := filepath.Rel(root, path)
		if relErr == nil {
			files = append(files, filepath.ToSlash(rel))
		}
		return nil
	})
	return files
}

func findFirstExistingFile(root string, names []string) string {
	found := ""
	_ = filepath.WalkDir(root, func(path string, d os.DirEntry, err error) error {
		if err != nil || d.IsDir() || found != "" {
			return nil
		}
		base := filepath.Base(path)
		for _, name := range names {
			if base == name {
				found = path
				return io.EOF
			}
		}
		return nil
	})
	return found
}

func chmodTeamspeakOfficialTree(root string) error {
	return filepath.WalkDir(root, func(path string, d os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		if d.IsDir() {
			return os.Chmod(path, 0o755)
		}
		base := strings.ToLower(filepath.Base(path))
		mode := os.FileMode(0o644)
		// Shell scripts, TS3 client binaries, and shared libraries need to be
		// executable (or at least readable) for the TS3 client to load them.
		// ts3client_runscript.sh and ts3client_linux_amd64 do not contain
		// "teamspeak" in their name, so we match by prefix/extension as well.
		if strings.HasSuffix(base, ".sh") ||
			strings.HasPrefix(base, "ts3client") ||
			strings.Contains(base, "teamspeak") ||
			strings.HasSuffix(base, ".so") ||
			strings.Contains(base, ".so.") {
			mode = 0o755
		}
		return os.Chmod(path, mode)
	})
}

func sanitizeTeamspeakOfficialClientText(value string) string {
	value = stripTeamspeakPTYControl(value)
	value = strings.ReplaceAll(value, "\x00", "")
	lines := strings.Split(value, "\n")
	filtered := make([]string, 0, len(lines))
	inLicenseBlock := false
	for _, line := range lines {
		lower := strings.ToLower(strings.TrimSpace(line))
		// Collapse license/terms/privacy blocks into a single placeholder line.
		if strings.Contains(lower, "end user license") ||
			strings.Contains(lower, "license agreement") ||
			strings.Contains(lower, "terms and conditions") ||
			strings.Contains(lower, "terms of service") ||
			strings.Contains(lower, "privacy policy") {
			if !inLicenseBlock {
				filtered = append(filtered, "[license/terms text omitted]")
				inLicenseBlock = true
			}
			continue
		}
		if inLicenseBlock {
			// Resume capture on blank lines or accept prompts.
			if lower == "" || strings.HasPrefix(lower, "do you accept") ||
				lower == "[y/n]" || lower == "(y/n)" || lower == "y/n" {
				inLicenseBlock = false
				filtered = append(filtered, line)
			}
			continue
		}
		filtered = append(filtered, line)
	}
	if len(filtered) > 20 {
		filtered = filtered[:20]
	}
	result := strings.TrimSpace(strings.Join(filtered, "\n"))
	if len(result) > 2000 {
		result = result[:2000]
	}
	return result
}

func validateTeamspeakBackendConfig(cfg teamspeakBackendConfig, probeBuildMode bool) teamspeakBackendValidation {
	if cfg.BackendType == "external_client_bridge" {
		return validateTeamspeakExternalClientBridgeConfig(cfg)
	}
	now := time.Now().UTC().Format(time.RFC3339)
	result := teamspeakBackendValidation{Status: teamspeakBackendStatusReady, BackendType: cfg.BackendType, BackendPath: cfg.BackendPath, LibraryPath: cfg.LibraryPath, OpusLibraryPath: cfg.OpusLibraryPath, IdentityPath: cfg.IdentityPath, InstallPath: cfg.InstallPath, BinaryPath: cfg.BinaryPath, Version: cfg.Version, AutoInstall: cfg.AutoInstall, LastCheckedAt: now}
	if cfg.BackendType == "" || cfg.BackendType == "placeholder" || cfg.BackendType == "disabled" {
		result.Status = teamspeakBackendStatusNotConfigured
		result.LastError = "TeamSpeak Client Backend is not configured"
		return result
	}
	if err := validateTeamspeakBackendExecutable(cfg.BackendPath); err != nil {
		result.Status = classifyTeamspeakBackendValidationError(err)
		result.LastError = err.Error()
		return result
	}
	result.BinaryFound = true
	result.BinaryExec = true
	checksum, err := fileSHA256(cfg.BackendPath)
	if err == nil {
		result.Checksum = checksum
	}
	if cfg.ExpectedChecksum != "" && !strings.EqualFold(cfg.ExpectedChecksum, result.Checksum) {
		result.Status = teamspeakBackendStatusInvalidPermissions
		result.LastError = "backend binary checksum does not match configured checksum"
		return result
	}
	libraryPath, err := resolveTeamspeakLibraryPath(cfg.LibraryPath)
	if err != nil {
		result.Status = teamspeakBackendStatusLibraryMissing
		result.LastError = err.Error()
		return result
	}
	result.LibraryPath = libraryPath
	result.LibraryFound = true
	opusPath, err := resolveOpusLibraryPath(cfg.OpusLibraryPath, filepath.Dir(libraryPath))
	if err != nil {
		result.Status = teamspeakBackendStatusOpusMissing
		result.LastError = err.Error()
		return result
	}
	result.OpusLibraryPath = opusPath
	result.OpusFound = true
	if cfg.IdentityPath != "" {
		if err := validateTeamspeakIdentityPath(cfg.IdentityPath); err != nil {
			result.Status = classifyTeamspeakBackendValidationError(err)
			result.LastError = err.Error()
			return result
		}
		result.IdentityFound = true
	}
	if probeBuildMode {
		probeCfg := cfg
		probeCfg.LibraryPath = libraryPath
		if resp, err := teamspeakBackendCommand(probeCfg, map[string]any{"action": "status"}); err == nil {
			result.BuildMode = resp.BuildMode
			if resp.BuildMode == "stub" {
				result.Status = teamspeakBackendStatusClientBackendRequired
				result.Ready = false
				result.LastError = "easywi-teamspeak-client was built without a real TeamSpeak client layer"
				return result
			}
		} else {
			result.Status = teamspeakBackendStatusFailed
			result.LastError = sanitizeTeamspeakBackendError(err.Error(), cfg)
			return result
		}
	}
	result.Ready = true
	return result
}

func validateTeamspeakExternalClientBridgeConfig(cfg teamspeakBackendConfig) teamspeakBackendValidation {
	now := time.Now().UTC().Format(time.RFC3339)
	result := teamspeakBackendValidation{
		Status:        teamspeakBackendStatusReady,
		BackendType:   cfg.BackendType,
		InstallPath:   cfg.InstallPath,
		AutoInstall:   cfg.AutoInstall,
		LastCheckedAt: now,
	}

	bridgePath := cfg.BridgePath
	if bridgePath == "" {
		bridgePath = cfg.BackendPath
	}
	if bridgePath == "" {
		bridgePath = "/usr/local/bin/easywi-teamspeak-bridge"
	}
	if err := validateTeamspeakBackendExecutable(bridgePath); err != nil {
		result.Status = teamspeakBackendStatusBinaryMissing
		result.LastError = "easywi-teamspeak-bridge binary missing or not executable: " + err.Error()
		return result
	}
	result.BridgePath = bridgePath
	result.BackendPath = bridgePath
	result.BinaryFound = true
	result.BinaryExec = true

	clientBinaryPath := cfg.ClientBinaryPath
	if clientBinaryPath == "" {
		result.Status = teamspeakBackendStatusClientBinaryMissing
		result.LastError = "client_binary_path (ts3client_linux_amd64) is required for external_client_bridge"
		return result
	}
	if err := validateTeamspeakBackendExecutable(clientBinaryPath); err != nil {
		result.Status = teamspeakBackendStatusClientBinaryMissing
		result.LastError = "TeamSpeak client binary missing or not executable: " + err.Error()
		return result
	}
	result.ClientBinaryPath = clientBinaryPath

	if cfg.ClientRunscriptPath != "" {
		if info, err := os.Stat(cfg.ClientRunscriptPath); err == nil && !info.IsDir() {
			result.ClientRunscriptPath = cfg.ClientRunscriptPath
		}
	}

	// Validate that the ClientQuery plugin exists in official-client/plugins/.
	// Without it the TS3 client cannot expose its local control interface and
	// the bridge will fail at Connect() time with clientquery_plugin_missing.
	officialClientDir := officialClientDirFrom(clientBinaryPath, cfg.ClientRunscriptPath)
	if officialClientDir != "" {
		result.OfficialClientDir = officialClientDir
		pluginPath := filepath.Join(officialClientDir, "plugins", clientQueryPluginFilename)
		if info, err := os.Stat(pluginPath); err != nil || !info.Mode().IsRegular() {
			result.Status = teamspeakBackendStatusClientQueryPluginMissing
			result.LastError = fmt.Sprintf(
				"clientquery_plugin_missing: %s not found in %s/plugins/; run 'repair' or reinstall the official TeamSpeak client",
				clientQueryPluginFilename, officialClientDir,
			)
			return result
		}
		result.ClientQueryPluginAvailable = true
	}

	if _, err := exec.LookPath("Xvfb"); err != nil {
		result.Status = teamspeakBackendStatusXvfbMissing
		result.LastError = "Xvfb not found in PATH; install the xvfb package (e.g. apt-get install xvfb)"
		return result
	}
	result.XvfbAvailable = true

	audioOK := false
	for _, bin := range []string{"pulseaudio", "pipewire-pulse", "pipewire", "pactl"} {
		if _, err := exec.LookPath(bin); err == nil {
			audioOK = true
			break
		}
	}
	if !audioOK {
		result.Status = teamspeakBackendStatusAudioBackendMissing
		result.LastError = "PulseAudio or PipeWire not found; install pulseaudio or pipewire-pulse"
		return result
	}
	result.AudioBackendAvailable = true

	result.Ready = true
	result.Status = teamspeakBackendStatusExternalBridgeReady
	return result
}

// resolvePackageAlternative returns preferred when apt-cache can find it,
// otherwise returns fallback. This handles packages renamed between Ubuntu releases
// (e.g. libasound2 → libasound2t64 in Ubuntu 24.04).
func resolvePackageAlternative(preferred, fallback string) string {
	cmd := exec.Command("apt-cache", "show", preferred)
	if cmd.Run() == nil {
		return preferred
	}
	return fallback
}

func handleMusicbotTeamspeakBackendInstallDependencies(job jobs.Job) orchestratorResult {
	if !payloadBool(job.Payload, "install_dependencies") {
		return orchestratorResult{
			status:    "failed",
			errorText: "install_dependencies=true is required to install system dependencies",
			resultPayload: map[string]any{
				"status":     teamspeakBackendStatusFailed,
				"last_error": "install_dependencies=true is required to install system dependencies",
			},
		}
	}

	packages := append([]string(nil), teamspeakExternalBridgePackages...)

	aptPath, aptErr := exec.LookPath("apt-get")
	if aptErr == nil {
		for _, pair := range teamspeakExternalBridgePackageAlternatives {
			packages = append(packages, resolvePackageAlternative(pair[0], pair[1]))
		}
	}
	if aptErr == nil {
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		defer cancel()
		cmd := exec.CommandContext(ctx, aptPath, "install", "-y", "--no-install-recommends")
		cmd.Args = append(cmd.Args, packages...)
		cmd.Env = append(os.Environ(), "DEBIAN_FRONTEND=noninteractive")
		out, err := cmd.CombinedOutput()
		outStr := strings.TrimSpace(string(out))
		if len(outStr) > 4000 {
			outStr = outStr[:4000]
		}
		if err != nil {
			return orchestratorResult{
				status:    "failed",
				errorText: fmt.Sprintf("apt-get install failed: %v", err),
				resultPayload: map[string]any{
					"status":     teamspeakBackendStatusFailed,
					"last_error": fmt.Sprintf("apt-get install failed: %v\n%s", err, outStr),
				},
			}
		}
		return orchestratorResult{
			status: "success",
			resultPayload: map[string]any{
				"status":   "dependencies_installed",
				"packages": packages,
				"output":   outStr,
			},
		}
	}

	for _, mgr := range []string{"dnf", "yum"} {
		mgrPath, err := exec.LookPath(mgr)
		if err != nil {
			continue
		}
		ctx, cancel := context.WithTimeout(context.Background(), 5*time.Minute)
		defer cancel()
		cmd := exec.CommandContext(ctx, mgrPath, "install", "-y")
		cmd.Args = append(cmd.Args, packages...)
		out, err := cmd.CombinedOutput()
		outStr := strings.TrimSpace(string(out))
		if len(outStr) > 4000 {
			outStr = outStr[:4000]
		}
		if err != nil {
			return orchestratorResult{
				status:    "failed",
				errorText: fmt.Sprintf("%s install failed: %v", mgr, err),
				resultPayload: map[string]any{
					"status":     teamspeakBackendStatusFailed,
					"last_error": fmt.Sprintf("%s install failed: %v\n%s", mgr, err, outStr),
				},
			}
		}
		return orchestratorResult{
			status: "success",
			resultPayload: map[string]any{
				"status":   "dependencies_installed",
				"packages": packages,
				"output":   outStr,
			},
		}
	}

	return orchestratorResult{
		status:    "failed",
		errorText: "no supported package manager found (apt-get, dnf, yum)",
		resultPayload: map[string]any{
			"status":     teamspeakBackendStatusFailed,
			"last_error": "no supported package manager found (apt-get, dnf, yum)",
		},
	}
}

func validateTeamspeakBackendExecutable(path string) error {
	if strings.TrimSpace(path) == "" {
		return errors.New("backend_path is required")
	}
	if strings.ContainsAny(path, "\x00\n\r") || !filepath.IsAbs(path) {
		return errors.New("backend_path must be an absolute path")
	}
	if err := validateTeamspeakClientBinaryName(path); err != nil {
		return err
	}
	info, err := os.Lstat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return fmt.Errorf("backend binary missing: %s", path)
		}
		return fmt.Errorf("backend binary stat failed: %w", err)
	}
	if info.Mode()&os.ModeSymlink != 0 {
		resolved, err := filepath.EvalSymlinks(path)
		if err != nil {
			return fmt.Errorf("backend symlink target is invalid: %w", err)
		}
		if err := validateTeamspeakClientBinaryName(resolved); err != nil {
			return err
		}
		clean := filepath.Clean(resolved)
		if !strings.HasPrefix(clean, "/usr/local/bin/") && !strings.HasPrefix(clean, "/opt/easywi/") {
			return fmt.Errorf("backend_path symlink resolves outside allowed administrator-managed paths")
		}
		info, err = os.Stat(clean)
		if err != nil {
			return fmt.Errorf("backend symlink target stat failed: %w", err)
		}
	}
	if !info.Mode().IsRegular() {
		return fmt.Errorf("backend_path must point to a regular executable file")
	}
	if runtime.GOOS != "windows" && info.Mode().Perm()&0o111 == 0 {
		return fmt.Errorf("backend binary is not executable: %s", path)
	}
	return nil
}

func validateTeamspeakClientBinaryName(path string) error {
	name := strings.ToLower(filepath.Base(path))
	for _, banned := range []string{"sinusbot", "ts3audiobot", "lavalink"} {
		if strings.Contains(name, banned) {
			return fmt.Errorf("unsupported TeamSpeak backend binary %q is not allowed", filepath.Base(path))
		}
	}
	return nil
}

func resolveTeamspeakLibraryPath(path string) (string, error) {
	if strings.TrimSpace(path) == "" {
		return "", errors.New("library_path is required")
	}
	if strings.ContainsAny(path, "\x00\n\r") || !filepath.IsAbs(path) {
		return "", errors.New("library_path must be an absolute path")
	}
	candidate := filepath.Clean(path)
	allowedNames := []string{"libts3client.so", "libteamspeak_sdk_client.so"}
	if stat, err := os.Stat(candidate); err == nil && stat.IsDir() {
		for _, name := range allowedNames {
			if p := filepath.Join(candidate, name); func() bool { s, e := os.Stat(p); return e == nil && !s.IsDir() }() {
				candidate = p
				break
			}
		}
	}
	stat, err := os.Stat(candidate)
	if err != nil {
		return "", fmt.Errorf("client library missing: %s", candidate)
	}
	if stat.IsDir() || stat.Mode().Perm()&0o444 == 0 {
		return "", fmt.Errorf("client library is not readable: %s", candidate)
	}
	base := filepath.Base(candidate)
	validName := false
	for _, name := range allowedNames {
		if base == name {
			validName = true
			break
		}
	}
	if !validName {
		return "", fmt.Errorf("library_path must point to libts3client.so, libteamspeak_sdk_client.so, or a directory containing one of them")
	}
	return candidate, nil
}

func resolveOpusLibraryPath(path string, libraryDir string) (string, error) {
	candidates := []string{}
	if strings.TrimSpace(path) != "" {
		if strings.ContainsAny(path, "\x00\n\r") || !filepath.IsAbs(path) {
			return "", errors.New("opus_library_path must be an absolute path")
		}
		candidate := filepath.Clean(path)
		if stat, err := os.Stat(candidate); err == nil && stat.IsDir() {
			candidates = append(candidates, filepath.Join(candidate, "libopus.so"), filepath.Join(candidate, "libopus.so.0"))
		} else {
			candidates = append(candidates, candidate)
		}
	}
	if libraryDir != "" {
		candidates = append(candidates, filepath.Join(libraryDir, "libopus.so"), filepath.Join(libraryDir, "libopus.so.0"))
	}
	candidates = append(candidates, "/usr/lib/x86_64-linux-gnu/libopus.so.0", "/usr/lib/x86_64-linux-gnu/libopus.so", "/usr/lib64/libopus.so.0", "/usr/lib64/libopus.so", "/usr/lib/libopus.so.0", "/usr/lib/libopus.so")
	for _, candidate := range candidates {
		stat, err := os.Stat(candidate)
		if err == nil && !stat.IsDir() && stat.Mode().Perm()&0o444 != 0 && (filepath.Base(candidate) == "libopus.so" || filepath.Base(candidate) == "libopus.so.0") {
			return filepath.Clean(candidate), nil
		}
	}
	return "", errors.New("libopus.so or libopus.so.0 missing")
}

func validateTeamspeakIdentityPath(path string) error {
	if strings.ContainsAny(path, "\x00\n\r") || !filepath.IsAbs(path) {
		return errors.New("identity_path must be an absolute path")
	}
	stat, err := os.Stat(path)
	if err != nil {
		return fmt.Errorf("identity file missing: %s", path)
	}
	if stat.IsDir() {
		return fmt.Errorf("identity_path must point to a file")
	}
	if runtime.GOOS != "windows" && stat.Mode().Perm()&0o077 != 0 {
		return fmt.Errorf("identity file permissions are too broad; use 0600 or stricter")
	}
	return nil
}

func classifyTeamspeakBackendValidationError(err error) string {
	msg := strings.ToLower(err.Error())
	switch {
	case strings.Contains(msg, "missing") || strings.Contains(msg, "required"):
		if strings.Contains(msg, "identity") {
			return teamspeakBackendStatusIdentityMissing
		}
		return teamspeakBackendStatusBinaryMissing
	case strings.Contains(msg, "not executable"):
		return teamspeakBackendStatusBinaryNotExecutable
	default:
		return teamspeakBackendStatusInvalidPermissions
	}
}

func normalizeAgentTeamspeakBackendType(raw string) string {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "client_library", "native_sdk", "disabled", "placeholder", "external_client_bridge":
		return strings.ToLower(strings.TrimSpace(raw))
	default:
		return "placeholder"
	}
}

func normalizeTeamspeakBackendProfile(raw string) string {
	profile := strings.ToLower(strings.TrimSpace(raw))
	if profile != "ts6" {
		return "ts3"
	}
	return profile
}

func teamspeakBackendCommand(cfg teamspeakBackendConfig, req map[string]any) (teamspeakBackendProcessResponse, error) {
	responses, err := teamspeakBackendCommandResponses(cfg, []map[string]any{req})
	if err != nil || len(responses) == 0 {
		return teamspeakBackendProcessResponse{}, err
	}
	return responses[len(responses)-1], nil
}

func teamspeakBackendCommandSequence(cfg teamspeakBackendConfig, reqs []map[string]any) (teamspeakBackendProcessResponse, error) {
	responses, err := teamspeakBackendCommandResponses(cfg, append(reqs, map[string]any{"action": "shutdown"}))
	if err != nil || len(responses) == 0 {
		return teamspeakBackendProcessResponse{}, err
	}
	return responses[len(responses)-1], nil
}

func teamspeakBackendCommandResponses(cfg teamspeakBackendConfig, reqs []map[string]any) ([]teamspeakBackendProcessResponse, error) {
	ctx, cancel := context.WithTimeout(context.Background(), teamspeakBackendDefaultTimeout)
	defer cancel()
	binaryPath := cfg.BackendPath
	var envVar string
	switch cfg.BackendType {
	case "native_sdk":
		envVar = "EASYWI_TS_NATIVE_SDK=1"
	case "external_client_bridge":
		binaryPath = cfg.BridgePath
		if binaryPath == "" {
			binaryPath = "/usr/local/bin/easywi-teamspeak-bridge"
		}
		envVar = "EASYWI_TS_BRIDGE=1"
	default:
		envVar = "EASYWI_TS_CLIENT_LIB=1"
	}
	cmd := exec.CommandContext(ctx, binaryPath)
	cmd.Env = append(os.Environ(), envVar)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		return nil, err
	}
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	stderrDone := make(chan string, 1)
	go func() {
		data, _ := io.ReadAll(io.LimitReader(stderr, 4096))
		stderrDone <- string(data)
	}()
	scanner := bufio.NewScanner(stdout)
	responses := make([]teamspeakBackendProcessResponse, 0, len(reqs))
	for _, req := range reqs {
		encoded, err := json.Marshal(req)
		if err != nil {
			_ = cmd.Process.Kill()
			return nil, err
		}
		if _, err := stdin.Write(append(encoded, '\n')); err != nil {
			_ = cmd.Process.Kill()
			return nil, err
		}
		if !scanner.Scan() {
			_ = cmd.Process.Kill()
			stderrText := <-stderrDone
			if scanErr := scanner.Err(); scanErr != nil {
				return nil, fmt.Errorf("TeamSpeak backend read failed: %w", scanErr)
			}
			return nil, fmt.Errorf("TeamSpeak backend exited without response: %s", strings.TrimSpace(stderrText))
		}
		var resp teamspeakBackendProcessResponse
		if err := json.Unmarshal(scanner.Bytes(), &resp); err != nil {
			_ = cmd.Process.Kill()
			return nil, fmt.Errorf("TeamSpeak backend returned invalid NDJSON: %w", err)
		}
		if !resp.OK {
			_ = stdin.Close()
			_ = cmd.Process.Kill()
			if resp.Error == "" {
				resp.Error = "TeamSpeak backend command failed"
			}
			return nil, errors.New(resp.Error)
		}
		responses = append(responses, resp)
	}
	_ = stdin.Close()
	_ = cmd.Wait()
	return responses, nil
}

func fileSHA256(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer func() { _ = f.Close() }()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func sanitizeTeamspeakBackendError(value string, cfg teamspeakBackendConfig) string {
	out := value
	for _, secret := range []string{cfg.ServerPassword, cfg.ChannelPassword} {
		if secret != "" {
			out = strings.ReplaceAll(out, secret, "[redacted]")
		}
	}
	return out
}

func teamspeakBackendResult(status string, validation teamspeakBackendValidation) orchestratorResult {
	payload := map[string]any{
		"backend_type":                  validation.BackendType,
		"backend_path":                  validation.BackendPath,
		"library_path":                  validation.LibraryPath,
		"opus_library_path":             validation.OpusLibraryPath,
		"identity_path":                 validation.IdentityPath,
		"install_path":                  validation.InstallPath,
		"binary_path":                   validation.BinaryPath,
		"version":                       validation.Version,
		"checksum":                      validation.Checksum,
		"auto_install_enabled":          validation.AutoInstall,
		"status":                        validation.Status,
		"binary_found":                  validation.BinaryFound,
		"binary_executable":             validation.BinaryExec,
		"library_found":                 validation.LibraryFound,
		"opus_found":                    validation.OpusFound,
		"identity_found":                validation.IdentityFound,
		"build_mode":                    validation.BuildMode,
		"ready":                         validation.Ready,
		"connected":                     validation.Connected,
		"last_error":                    validation.LastError,
		"last_checked_at":               validation.LastCheckedAt,
		"bridge_path":                   validation.BridgePath,
		"client_binary_path":            validation.ClientBinaryPath,
		"client_runscript_path":         validation.ClientRunscriptPath,
		"xvfb_available":                validation.XvfbAvailable,
		"audio_backend_available":       validation.AudioBackendAvailable,
		"client_query_plugin_available": validation.ClientQueryPluginAvailable,
		"official_client_dir":           validation.OfficialClientDir,
	}
	return orchestratorResult{status: status, errorText: validation.LastError, resultPayload: payload}
}
