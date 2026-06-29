package main

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"io"
	"log"
	"math/rand"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"time"
)

// xvfbScreenSpec is the Xvfb screen geometry required for Qt/XCB compatibility.
// 1x1x8 is insufficient; Qt requires a 24-bit colour depth display.
const xvfbScreenSpec = "1024x768x24"

// clientQueryPluginName is the filename of the ClientQuery plugin shared library.
const clientQueryPluginName = "libclientquery_plugin_linux_amd64.so"

// validateClientQueryPlugin checks that the ClientQuery plugin .so file exists in
// officialClientDir/plugins/. Returns a descriptive error if missing; returns nil if
// officialClientDir is empty (check not applicable) or if the plugin is present.
func validateClientQueryPlugin(officialClientDir string) error {
	if officialClientDir == "" {
		return nil
	}
	pluginPath := filepath.Join(officialClientDir, "plugins", clientQueryPluginName)
	info, err := os.Stat(pluginPath)
	if err != nil || !info.Mode().IsRegular() {
		return fmt.Errorf(
			"clientquery_plugin_missing: plugin %s not found in %s/plugins/; "+
				"the official TeamSpeak client installation is incomplete",
			clientQueryPluginName, officialClientDir)
	}
	return nil
}

// officialClientDirFromPaths returns the directory that contains the official TS3
// client installation. It prefers the runscript directory over the binary directory.
func officialClientDirFromPaths(clientBinaryPath, clientRunscriptPath string) string {
	if strings.TrimSpace(clientRunscriptPath) != "" {
		return filepath.Dir(clientRunscriptPath)
	}
	if strings.TrimSpace(clientBinaryPath) != "" {
		return filepath.Dir(clientBinaryPath)
	}
	return ""
}

// pulseAudioState captures the outcome of attempting to start PulseAudio.
// It is threaded through to ts3DiagContext so crash diagnostics always show
// whether audio was ready and which socket path was expected.
type pulseAudioState struct {
	socketPath   string // intended path; set even when audio is not ready
	socketExists bool   // pulse.sock was found on disk
	socketReady  bool   // pulse.sock is a Unix socket
	started      bool   // pulseaudio/pipewire-pulse process was launched
	audioReady   bool   // socket verified as a reachable Unix socket
	// Extended diagnostics (populated even on failure)
	pid            int    // PID of the pulseaudio process (0 if not started)
	exitCode       int    // exit code if process exited; -1 if still running
	stderrTail     string // last lines of pulseaudio stderr output
	configPath     string // path to pulse_default.pa written on disk
	command        string // full command line passed to exec.Command
	xdgRuntimeDir  string // XDG_RUNTIME_DIR supplied to pulseaudio
	notReadyReason string // one of: pulseaudio_binary_not_found, pulseaudio_start_failed,
	//                        pulseaudio_exited, pulseaudio_socket_missing, pactl_failed,
	//                        sink_source_missing
	pactlError string // pactl error message if notReadyReason == "pactl_failed"
}

// envSocket returns the socket path to use in PULSE_SERVER, or "" if not ready.
// When "" is returned, buildTS3Env omits PULSE_SERVER entirely.
func (p pulseAudioState) envSocket() string {
	if p.audioReady {
		return p.socketPath
	}
	return ""
}

// ts3DiagContext captures the pre-launch configuration used to start the TS3
// client subprocess. Stored in the adapter so crash diagnostics can include
// full launch context. No secrets are included.
type ts3DiagContext struct {
	mode          string // "runscript" or "binary"
	execPath      string
	args          []string
	cmdDir        string
	display       string
	runtimeDir    string
	ts3Home       string // persistent ts3home (settings.db, license state)
	xdgRuntimeDir string
	tmpDir        string
	cqHost        string
	cqPort        int
	officialDir   string
	pluginPath    string
	pluginExists  bool
	runscriptExst bool
	binaryPath    string
	binaryExists  bool
	env           []string
	// persistent ts3home diagnostics
	settingsDbPath        string
	settingsDbExists      bool
	licenseAcceptRequired bool
	// pulse audio diagnostics
	pulseSocketPath   string
	pulseSocketExists bool
	pulseSocketReady  bool
	pulseStarted      bool
	audioReady        bool
	// extended pulse audio diagnostics
	pulseaudioPid        int
	pulseaudioExitCode   int
	pulseaudioStderrTail string
	pulseaudioConfigPath string
	pulseaudioCommand    string
	pulseaudioXDGRuntime string
	audioNotReadyReason  string
	pactlError           string
}

// envValue returns the value for key from an env slice (KEY=VALUE format), or "".
func envValue(env []string, key string) string {
	prefix := key + "="
	for _, kv := range env {
		if strings.HasPrefix(kv, prefix) {
			return strings.TrimPrefix(kv, prefix)
		}
	}
	return ""
}

// buildLDLibraryPath constructs a colon-separated LD_LIBRARY_PATH that includes
// clientDir and, if they exist, its lib/ and lib/platform/ subdirectories so the
// TS3 client can find its bundled Qt platform plugins.
func buildLDLibraryPath(clientDir string) string {
	parts := []string{clientDir}
	for _, sub := range []string{"lib", "lib/platform"} {
		p := filepath.Join(clientDir, sub)
		if info, err := os.Stat(p); err == nil && info.IsDir() {
			parts = append(parts, p)
		}
	}
	return strings.Join(parts, ":")
}

// ExternalClientBridgeAdapter starts a real TeamSpeak 3 client headless
// (via Xvfb + PulseAudio virtual sink) and monitors its connection state.
//
// Secrets (server_password, channel_password) are never passed as CLI
// arguments — they are written to a temp config file with mode 0600 and
// that file path is passed to the TS3 client via its settings directory.
//
// No SinusBot. No TS3AudioBot. No reverse engineering. No protocol reimplementation.
type ExternalClientBridgeAdapter struct {
	mu sync.Mutex

	xvfbCmd               *exec.Cmd
	pulseCmd              *exec.Cmd
	ts3Cmd                *exec.Cmd
	ffmpegCmd             *exec.Cmd
	ts3Stderr             *bytes.Buffer
	display               string
	pulseSocket           string
	sinkName              string
	sourceName            string
	runtimeDir            string // root of runtime dirs (volatile; may be removed on cleanup)
	persistentTs3Home     string // persistent ts3home; never removed on cleanup
	tmpHome               string // non-empty only when using os.MkdirTemp (non-persistent)
	ts3LogPath            string
	crashdumpPath         string
	clientQueryHost       string
	clientQueryPort       int
	state                 string
	clientID              string
	lastError             string
	licenseAcceptRequired bool // preserved across cleanup so Status() can report it
	serverPW              string
	channelPW             string
	lastParams            connectParams
	connectCancel         context.CancelFunc
	ts3DiagCtx            *ts3DiagContext // pre-launch snapshot; nil until first start attempt
}

func NewExternalClientBridgeAdapter() *ExternalClientBridgeAdapter {
	return &ExternalClientBridgeAdapter{state: stateDisconnected}
}

func (a *ExternalClientBridgeAdapter) Connect(ctx context.Context, params connectParams) (string, error) {
	a.mu.Lock()
	a.serverPW = params.ServerPassword
	a.channelPW = params.ChannelPassword
	a.lastParams = params
	a.licenseAcceptRequired = false // reset on each new connect attempt
	a.mu.Unlock()

	connectStep := "start"
	log.Printf("connect_step=%s", connectStep)

	_ = a.cleanup()

	clientBinary := strings.TrimSpace(params.ClientBinaryPath)
	runscriptPath := strings.TrimSpace(params.ClientRunscriptPath)

	// The runscript is preferred over the binary; clientBinary is only required when
	// no runscript is provided.
	runscriptAccessible := false
	if runscriptPath != "" {
		if _, statErr := os.Stat(runscriptPath); statErr == nil {
			runscriptAccessible = true
		}
	}

	if !runscriptAccessible {
		if clientBinary == "" {
			return "", errors.New("client_binary_path is required for external_client_bridge")
		}
		if err := validateExternalClientBinary(clientBinary); err != nil {
			return "", err
		}
		if err := validateClientBinaryName(clientBinary); err != nil {
			return "", err
		}
	}

	if strings.TrimSpace(params.Host) == "" {
		return "", errors.New("host is required for external_client_bridge connect")
	}

	runtimeDir, tmpHome, err := buildRuntimeDir(params.InstancePath, params.RuntimeDir)
	if err != nil {
		return "", err
	}

	// Persistent ts3home: stores settings.db, license state, ClientQuery config.
	// This directory must survive runtime cleanup and must never be deleted by the bridge.
	persistentHome := buildPersistentTs3Home(params.InstancePath, runtimeDir)
	if err := ensurePersistentTs3HomeDirs(persistentHome); err != nil {
		return "", fmt.Errorf("external_client_bridge create persistent ts3home: %w", err)
	}

	a.mu.Lock()
	a.runtimeDir = runtimeDir
	a.persistentTs3Home = persistentHome
	a.tmpHome = tmpHome
	a.mu.Unlock()

	log.Printf("external_client_bridge runtime_dir=%s", runtimeDir)
	log.Printf("external_client_bridge persistent_ts3home=%s", persistentHome)

	settingsDbPath := filepath.Join(persistentHome, ".ts3client", "settings.db")
	_, settingsDbStatErr := os.Stat(settingsDbPath)
	log.Printf("external_client_bridge settings_db_path=%s settings_db_exists=%v",
		settingsDbPath, settingsDbStatErr == nil)

	cqHost := strings.TrimSpace(params.ClientQueryHost)
	if cqHost == "" {
		cqHost = "127.0.0.1"
	}

	connectStep = "allocate_port"
	log.Printf("connect_step=%s", connectStep)
	cqPort, err := allocateClientQueryPort(cqHost, params.InstancePath, params.ClientQueryPort)
	if err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("last_connect_step=%s %w", connectStep, err)
	}
	log.Printf("connect_step=%s_done port=%d", connectStep, cqPort)

	// Write ClientQuery plugin config to the persistent ts3home so the plugin
	// reads the correct port on every launch.
	connectStep = "write_cq_config"
	log.Printf("connect_step=%s port=%d", connectStep, cqPort)
	if err := writeClientQueryPluginConfig(persistentHome, cqHost, cqPort); err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("last_connect_step=%s external_client_bridge clientquery config: %w", connectStep, err)
	}
	log.Printf("connect_step=%s_done", connectStep)

	officialClientDir := officialClientDirFromPaths(clientBinary, strings.TrimSpace(params.ClientRunscriptPath))
	if err := validateClientQueryPlugin(officialClientDir); err != nil {
		_ = a.cleanup()
		return "", err
	}

	// Read api_key before starting TS3, so we can probe readiness immediately.
	cqIniPath := clientQueryApiKeyIniPath(persistentHome)
	_, cqIniStatErr := os.Stat(cqIniPath)
	cqIniExists := cqIniStatErr == nil
	preStartApiKey := readClientQueryApiKey(persistentHome)
	log.Printf("external_client_bridge clientquery_ini_path=%s clientquery_ini_exists=%v clientquery_api_key_present=%v",
		cqIniPath, cqIniExists, preStartApiKey != "")
	if preStartApiKey != "" {
		log.Printf("external_client_bridge clientquery_api_key_masked=%s (pre-start)", maskApiKey(preStartApiKey))
	}

	connectStep = "start_xvfb"
	log.Printf("connect_step=%s", connectStep)
	display, xvfbCmd, err := startXvfb(runtimeDir)
	if err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("last_connect_step=%s external_client_bridge xvfb start: %w", connectStep, err)
	}
	a.mu.Lock()
	a.xvfbCmd = xvfbCmd
	a.display = display
	a.mu.Unlock()
	if xvfbCmd != nil && xvfbCmd.Process != nil {
		log.Printf("connect_step=%s_done xvfb_pid=%d display=%s", connectStep, xvfbCmd.Process.Pid, display)
	} else {
		log.Printf("connect_step=%s_done display=%s", connectStep, display)
	}

	connectStep = "start_pulseaudio"
	log.Printf("connect_step=%s", connectStep)
	sinkName, sourceName, pulseCmd, pulseState, err := startPulseAudio(runtimeDir, display)
	if err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("last_connect_step=%s external_client_bridge pulseaudio init: %w", connectStep, err)
	}
	a.mu.Lock()
	a.pulseCmd = pulseCmd
	a.pulseSocket = pulseState.envSocket()
	a.sinkName = sinkName
	a.sourceName = sourceName
	a.mu.Unlock()
	log.Printf("connect_step=%s_done audio_ready=%v", connectStep, pulseState.audioReady)

	ts3StartTime := time.Now()
	connectStep = "start_ts3_client"
	log.Printf("connect_step=%s", connectStep)
	ts3Cmd, ts3Stderr, diagCtx, err := startTS3Client(ctx, params, clientBinary, runtimeDir, persistentHome, display, pulseState, cqHost, cqPort)
	if err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("last_connect_step=%s external_client_bridge ts3client start: %w", connectStep, err)
	}
	a.mu.Lock()
	a.ts3Cmd = ts3Cmd
	a.ts3Stderr = ts3Stderr
	a.ts3DiagCtx = diagCtx
	a.mu.Unlock()
	log.Printf("connect_step=%s_done ts3_pid=%d", connectStep, ts3Cmd.Process.Pid)

	monCtx, monCancel := context.WithTimeout(ctx, 45*time.Second)
	a.mu.Lock()
	a.connectCancel = monCancel
	a.mu.Unlock()
	defer monCancel()

	// Cancel the monitor context the moment the TS3 process exits so we fail
	// fast on crash/defunct instead of waiting for the full 45-second timeout.
	go func() { _ = ts3Cmd.Wait(); monCancel() }()

	connectStep = "wait_clientquery"
	log.Printf("connect_step=%s host=%s port=%d api_key_present=%v", connectStep, cqHost, cqPort, preStartApiKey != "")
	if err := waitForClientQueryReady(monCtx, cqHost, cqPort, preStartApiKey); err != nil {
		// Distinguish between TS3 crash (ProcessState != nil after Wait()) and
		// ClientQuery not responding on the expected port.
		if strings.Contains(err.Error(), "clientquery_port_mismatch") {
			log.Printf("connect_step=%s_failed clientquery_port_mismatch=true", connectStep)
			_ = a.cleanupKeepFiles()
			return "", fmt.Errorf("%s last_connect_step=%s clientquery_listening=false api_key_present=%v",
				err.Error(), connectStep, preStartApiKey != "")
		}
		if ts3Cmd.ProcessState != nil {
			// TS3 process exited — collect crash diagnostics.
			log.Printf("connect_step=%s_failed ts3_crashed=true", connectStep)
			diagErr := a.collectTS3StartFailedError(ts3Cmd, ts3Stderr, runtimeDir)
			_ = a.cleanupKeepFiles()
			return "", diagErr
		}
		// TS3 still running but ClientQuery not ready.
		log.Printf("connect_step=%s_failed clientquery_not_ready=true", connectStep)
		_ = a.cleanupKeepFiles()
		return "", fmt.Errorf("%s last_connect_step=%s clientquery_listening=false ts3_pid=%d api_key_present=%v",
			err.Error(), connectStep, ts3Cmd.Process.Pid, preStartApiKey != "")
	}
	log.Printf("connect_step=%s_done clientquery_listening=true", connectStep)

	connectStep = "read_api_key"
	log.Printf("connect_step=%s", connectStep)
	// ClientQuery is ready. Re-read the api_key now that the plugin has fully
	// initialised. On the first run the plugin generates a new random key during
	// startup and writes it to clientquery.ini; we must pick up that key here so
	// all subsequent ClientQuery commands are properly authenticated.
	// On restarts the key is the same one already in the INI (preserved by
	// writeClientQueryPluginConfig), so the retry typically returns on attempt 1.
	apiKey, _ := readClientQueryApiKeyWithRetry(monCtx, persistentHome, 5*time.Second)
	log.Printf("connect_step=%s_done api_key_present=%v", connectStep, apiKey != "")
	if apiKey != "" {
		log.Printf("external_client_bridge clientquery_api_key_masked=%s (post-ready)", maskApiKey(apiKey))
	}

	connectStep = "license_check"
	log.Printf("connect_step=%s", connectStep)
	// Check whether the current TS3 start (since ts3StartTime) shows a LicenseViewer
	// dialog. Only the log file produced by THIS start counts; old log files from
	// previous starts must not block the connect (false-positive prevention).
	ts3LogDirs := []string{
		filepath.Join(persistentHome, ".ts3client", "logs"),
		filepath.Join(runtimeDir, "logs"),
	}
	licResult := checkCurrentTs3LogForLicense(ts3LogDirs, ts3StartTime)
	log.Printf("connect_step=%s license_check_source=%s license_check_log_path=%s license_check_current_log_has_licenseviewer=%v license_check_current_log_requires_accept=%v license_accept_required=%v",
		connectStep, licResult.Source, licResult.LogPath,
		licResult.CurrentLogHasLicenseViewer, licResult.CurrentLogRequiresAccept, licResult.LicenseAcceptRequired)

	if !licResult.LicenseAcceptRequired {
		// Fallback: if ClientQuery returns error 1796, re-check the current log
		// (it may have been written after our first scan).
		if probeClientQueryLicenseBlock(cqHost, cqPort, apiKey, ts3LogDirs, ts3StartTime) {
			licResult = checkCurrentTs3LogForLicense(ts3LogDirs, ts3StartTime)
			licResult.LicenseAcceptRequired = true
			log.Printf("connect_step=%s license_check_source=%s license_check_log_path=%s license_check_current_log_has_licenseviewer=%v license_check_current_log_requires_accept=%v license_accept_required=%v (probe)",
				connectStep, licResult.Source, licResult.LogPath,
				licResult.CurrentLogHasLicenseViewer, licResult.CurrentLogRequiresAccept, licResult.LicenseAcceptRequired)
		}
	}

	if licResult.LicenseAcceptRequired {
		log.Printf("connect_step=%s_failed license_accept_required=true", connectStep)
		log.Printf("external_client_bridge license_accept_required=true persistent_ts3home=%s capability_status=license_acceptance_required connected=false voice_client_available=false",
			persistentHome)
		log.Printf("external_client_bridge status_message=\"TeamSpeak client license must be accepted once by an administrator\"")
		a.mu.Lock()
		a.licenseAcceptRequired = true
		a.mu.Unlock()
		_ = a.cleanupKeepFiles()
		return "", errors.New("license_accept_required: TeamSpeak Client license must be accepted before connecting; " +
			"run 'easywi-teamspeak-bridge --accept-ts3-client-license --instance-path <path>' once as an administrator, then retry")
	}
	log.Printf("connect_step=%s_done license_required=false", connectStep)

	// Issue the connect command to the TS3 client via ClientQuery.
	tsPort := params.Port
	if tsPort <= 0 {
		tsPort = 9987
	}
	connectStep = "clientquery_connect"
	log.Printf("connect_step=%s host=%s port=%d nickname=%s api_key_present=%v",
		connectStep, params.Host, tsPort, params.Nickname, apiKey != "")
	connectErr := connectViaClientQuery(cqHost, cqPort, apiKey, params.Host, tsPort, params.Nickname)
	if connectErr != nil {
		log.Printf("connect_step=%s_failed: %v", connectStep, connectErr)
		_ = a.cleanupKeepFiles()
		return "", fmt.Errorf("last_connect_step=%s external_client_bridge clientquery_connect: %w", connectStep, connectErr)
	}
	log.Printf("connect_step=%s_done connect_sent=true", connectStep)
	log.Printf("external_client_bridge connect_sent=true")

	connectStep = "wait_server_connected"
	log.Printf("connect_step=%s host=%s port=%d", connectStep, params.Host, tsPort)
	// Wait until the TS3 client is actually connected to the TeamSpeak server.
	// Success requires whoami to return clid=<id> cid=<id> + error id=0.
	connectedCLID, connectedCID, waitErr := waitForTSServerConnected(monCtx, cqHost, cqPort, apiKey)
	if waitErr != nil {
		log.Printf("connect_step=%s_failed: %v", connectStep, waitErr)
		_ = a.cleanupKeepFiles()
		return "", fmt.Errorf("external_client_bridge ts_server_connect: %w; last_connect_step=%s clientquery_listening=true api_key_present=%v auth_attempted=true connect_sent=true ts3_pid=%d",
			waitErr, connectStep, apiKey != "", ts3Cmd.Process.Pid)
	}
	log.Printf("connect_step=%s_done ts_server_connected=true clid=%s cid=%s", connectStep, connectedCLID, connectedCID)
	log.Printf("external_client_bridge ts_server_connected=true connected_clid=%s connected_cid=%s", connectedCLID, connectedCID)
	log.Printf("external_client_bridge state_connected=true capability_status=ready voice_client_available=true")

	// Determine whether audio injection is available (PulseAudio sink reachable).
	a.mu.Lock()
	audioReady := a.pulseSocket != "" && a.sinkName != ""
	a.mu.Unlock()
	a.mu.Lock()
	diagCtxSnap := a.ts3DiagCtx
	a.mu.Unlock()
	notReadyReason := ""
	if diagCtxSnap != nil {
		notReadyReason = diagCtxSnap.audioNotReadyReason
	}
	log.Printf("external_client_bridge audio_injection_ready=%v", audioReady)
	if !audioReady {
		log.Printf("external_client_bridge audio_injection_ready=false pulse_socket_empty=%v sink_name_empty=%v audio_injection_not_ready_reason=%s; clientquery_control_ready=true teamspeak_server_connected=true but audio frames will fail until PulseAudio is ready",
			a.pulseSocket == "", a.sinkName == "", notReadyReason)
	}

	a.mu.Lock()
	a.clientQueryHost = cqHost
	a.clientQueryPort = cqPort
	a.clientID = connectedCLID
	a.state = stateConnected
	a.mu.Unlock()
	return connectedCLID, nil
}

// collectTS3StartFailedError gathers diagnostics when the TS3 client fails to
// establish a connection (crash, XCB failure, etc.) and returns a structured
// error with status ts3client_start_failed. No secrets are included.
func (a *ExternalClientBridgeAdapter) collectTS3StartFailedError(ts3Cmd *exec.Cmd, stderrBuf *bytes.Buffer, runtimeDir string) error {
	stderrText := ""
	if stderrBuf != nil {
		raw := stderrBuf.String()
		if len(raw) > 2000 {
			raw = raw[len(raw)-2000:]
		}
		stderrText = strings.TrimSpace(raw)
	}

	logsDir := filepath.Join(runtimeDir, "logs")
	crashdumpsDir := filepath.Join(runtimeDir, "crashdumps")

	// Determine persistent ts3home from the stored diag context (may be empty if
	// startTS3Client was never called successfully).
	a.mu.Lock()
	diagCtxForLogs := a.ts3DiagCtx
	a.mu.Unlock()
	persistentHome := ""
	if diagCtxForLogs != nil {
		persistentHome = diagCtxForLogs.ts3Home
	}

	var ts3ClientLogs string
	if persistentHome != "" {
		ts3ClientLogs = filepath.Join(persistentHome, ".ts3client", "logs")
	} else {
		ts3ClientLogs = filepath.Join(runtimeDir, "ts3home", ".ts3client", "logs")
	}

	logPath, logTail := findTs3LogTail([]string{ts3ClientLogs, logsDir}, 20)
	crashdumpSearchDirs := []string{crashdumpsDir}
	if persistentHome != "" {
		crashdumpSearchDirs = append(crashdumpSearchDirs, filepath.Join(persistentHome, ".ts3client"))
	}
	crashdump := findTs3Crashdump(crashdumpSearchDirs)

	a.mu.Lock()
	a.ts3LogPath = logPath
	a.crashdumpPath = crashdump
	diagCtx := a.ts3DiagCtx
	a.mu.Unlock()

	parts := []string{"ts3client_start_failed"}

	// Exit status — only available when the process actually exited.
	if ts3Cmd != nil && ts3Cmd.ProcessState != nil {
		parts = append(parts, fmt.Sprintf("exit_code=%d", ts3Cmd.ProcessState.ExitCode()))
		if !ts3Cmd.ProcessState.Exited() {
			parts = append(parts, "signal=true")
		}
	}

	// Startup context so operators can diff against a known-good invocation.
	if diagCtx != nil {
		parts = append(parts, fmt.Sprintf("ts3_start_mode=%s", diagCtx.mode))
		parts = append(parts, "cmd_dir="+diagCtx.cmdDir)
		parts = append(parts, "display="+diagCtx.display)
		parts = append(parts, "tmpdir="+diagCtx.tmpDir)
		parts = append(parts, "xdg_runtime_dir="+diagCtx.xdgRuntimeDir)
		parts = append(parts, "ts3_executable="+diagCtx.execPath)
		parts = append(parts, fmt.Sprintf("ts3_args=%s", strings.Join(diagCtx.args, " ")))
		parts = append(parts, fmt.Sprintf("pulse_server=%s", envValue(diagCtx.env, "PULSE_SERVER")))
		parts = append(parts, fmt.Sprintf("audio_ready=%v", diagCtx.audioReady))
	}

	if stderrText != "" {
		parts = append(parts, "stderr: "+stderrText)
	}
	if logTail != "" {
		parts = append(parts, "log_tail: "+logTail)
	}
	if logPath != "" {
		parts = append(parts, "ts3_log_path: "+logPath)
	}
	if crashdump != "" {
		parts = append(parts, "crashdump_path: "+crashdump)
	}

	// Re-emit the full startup snapshot alongside the crash so both appear in
	// the same journal window.
	if diagCtx != nil {
		logTS3StartDiagnostics(diagCtx)
	}

	return errors.New(strings.Join(parts, "; "))
}

func (a *ExternalClientBridgeAdapter) Disconnect(ctx context.Context) error {
	return a.cleanup()
}

func (a *ExternalClientBridgeAdapter) Reconnect(ctx context.Context) (string, error) {
	_ = a.cleanup()
	a.mu.Lock()
	params := a.lastParams
	a.mu.Unlock()
	return a.Connect(ctx, params)
}

func (a *ExternalClientBridgeAdapter) Authenticate(ctx context.Context) error {
	a.mu.Lock()
	s := a.state
	a.mu.Unlock()
	if s != stateConnected {
		return errors.New("external_client_bridge not connected")
	}
	return nil
}

func (a *ExternalClientBridgeAdapter) SetNickname(ctx context.Context, nickname string) error {
	return nil
}

func (a *ExternalClientBridgeAdapter) JoinChannel(ctx context.Context, channelID, password string) (string, error) {
	return channelID, nil
}

func (a *ExternalClientBridgeAdapter) LeaveChannel(ctx context.Context) error { return nil }

func (a *ExternalClientBridgeAdapter) SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error {
	return a.SendAudioFrame(ctx, "opus", frame, durationMs)
}

func (a *ExternalClientBridgeAdapter) SendAudioFrame(ctx context.Context, format string, frame []byte, durationMs int) error {
	a.mu.Lock()
	pulseSocket := a.pulseSocket
	sinkName := a.sinkName
	a.mu.Unlock()
	if pulseSocket == "" || sinkName == "" {
		return audioInjectionError{Component: "pulse_pcm_writer", FrameFormat: format, PulseSocket: pulseSocket, SinkName: sinkName, PayloadLen: len(frame), Message: "external_client_bridge audio not ready"}
	}
	switch strings.ToLower(strings.TrimSpace(format)) {
	case "pcm", "pcm_s16le":
		return injectPCMViaPulse(ctx, frame, pulseSocket, sinkName, 48000, 2)
	case "opus":
		if payloadLooksLikePCM(frame, 48000, 2, durationMs) {
			log.Printf("external_client_bridge opus frame looks like PCM; routing as pcm_s16le payload_len=%d sample_rate=48000 channels=2 duration_ms=%d", len(frame), durationMs)
			return injectPCMViaPulse(ctx, frame, pulseSocket, sinkName, 48000, 2)
		}
		return injectOpusViaPulse(ctx, frame, durationMs, pulseSocket, sinkName)
	default:
		return audioInjectionError{Component: "bridge_send_audio_frame", FrameFormat: format, PayloadLen: len(frame), Message: "unsupported frame format"}
	}
}

func (a *ExternalClientBridgeAdapter) Status(ctx context.Context) (adapterStatus, error) {
	a.mu.Lock()
	s := a.state
	cid := a.clientID
	lastErr := a.lastError
	ts3LogPath := a.ts3LogPath
	crashdumpPath := a.crashdumpPath
	cqPort := a.clientQueryPort
	licenseAcceptRequired := a.licenseAcceptRequired
	a.mu.Unlock()
	return adapterStatus{
		BackendType:           adapterBackendExternalClientBridge,
		Ready:                 s == stateConnected,
		State:                 s,
		ClientID:              cid,
		LastError:             lastErr,
		Ts3LogPath:            ts3LogPath,
		CrashdumpPath:         crashdumpPath,
		ClientQueryPort:       cqPort,
		LicenseAcceptRequired: licenseAcceptRequired,
	}, nil
}

func (a *ExternalClientBridgeAdapter) Shutdown(ctx context.Context) error {
	return a.cleanup()
}

func (a *ExternalClientBridgeAdapter) cleanup() error {
	a.mu.Lock()
	xvfbCmd := a.xvfbCmd
	pulseCmd := a.pulseCmd
	ts3Cmd := a.ts3Cmd
	ffmpegCmd := a.ffmpegCmd
	tmpHome := a.tmpHome
	cancel := a.connectCancel
	a.xvfbCmd = nil
	a.pulseCmd = nil
	a.ts3Cmd = nil
	a.ffmpegCmd = nil
	a.ts3Stderr = nil
	a.runtimeDir = ""
	a.persistentTs3Home = ""
	a.tmpHome = ""
	a.display = ""
	a.pulseSocket = ""
	a.sinkName = ""
	a.sourceName = ""
	a.state = stateDisconnected
	a.clientID = ""
	a.clientQueryHost = ""
	a.clientQueryPort = 0
	a.connectCancel = nil
	a.ts3DiagCtx = nil
	// ts3LogPath, crashdumpPath, and licenseAcceptRequired are preserved so Status() can still report them.
	a.mu.Unlock()

	if cancel != nil {
		cancel()
	}
	for _, cmd := range []*exec.Cmd{ffmpegCmd, ts3Cmd, pulseCmd, xvfbCmd} {
		if cmd != nil && cmd.Process != nil {
			_ = cmd.Process.Kill()
			_ = cmd.Wait()
		}
	}
	// Remove only the temp directory; persistent runtime dirs (under instance_path) are kept.
	if tmpHome != "" {
		_ = os.RemoveAll(tmpHome)
	}
	return nil
}

// cleanupKeepFiles stops all subprocesses and resets state but does NOT remove the
// runtime directory. Call this on TS3 crash so logs and crashdumps remain on disk
// for post-mortem inspection.
func (a *ExternalClientBridgeAdapter) cleanupKeepFiles() error {
	a.mu.Lock()
	xvfbCmd := a.xvfbCmd
	pulseCmd := a.pulseCmd
	ts3Cmd := a.ts3Cmd
	ffmpegCmd := a.ffmpegCmd
	cancel := a.connectCancel
	a.xvfbCmd = nil
	a.pulseCmd = nil
	a.ts3Cmd = nil
	a.ffmpegCmd = nil
	a.ts3Stderr = nil
	a.runtimeDir = ""
	a.persistentTs3Home = ""
	a.tmpHome = ""
	a.display = ""
	a.pulseSocket = ""
	a.sinkName = ""
	a.sourceName = ""
	a.state = stateDisconnected
	a.clientID = ""
	a.clientQueryHost = ""
	a.clientQueryPort = 0
	a.connectCancel = nil
	// ts3LogPath, crashdumpPath, and licenseAcceptRequired are preserved so Status() and errors can report them.
	a.mu.Unlock()

	if cancel != nil {
		cancel()
	}
	for _, cmd := range []*exec.Cmd{ffmpegCmd, ts3Cmd, pulseCmd, xvfbCmd} {
		if cmd != nil && cmd.Process != nil {
			_ = cmd.Process.Kill()
			_ = cmd.Wait()
		}
	}
	// Runtime dir (including tmpHome) is intentionally NOT removed here to preserve
	// crash logs and crashdumps for diagnosis.
	return nil
}

// buildRuntimeDir returns the root runtime directory for a bridge instance.
//
// Priority order:
//  1. If runtimeDirOverride is non-empty, it is used as-is (persistent; tmpHome is empty).
//  2. If instancePath is non-empty, <instancePath>/runtime/teamspeak-bridge is used (persistent; tmpHome is empty).
//  3. Otherwise a temporary directory is created; tmpHome equals runtimeDir and is removed by cleanup().
func buildRuntimeDir(instancePath, runtimeDirOverride string) (runtimeDir, tmpHome string, err error) {
	if strings.TrimSpace(runtimeDirOverride) != "" {
		runtimeDir = runtimeDirOverride
		if err = os.MkdirAll(runtimeDir, 0o750); err != nil {
			return "", "", fmt.Errorf("external_client_bridge create runtime dir: %w", err)
		}
		if err = ensureRuntimeSubdirs(runtimeDir); err != nil {
			return "", "", err
		}
		return runtimeDir, "", nil
	}
	if strings.TrimSpace(instancePath) != "" {
		runtimeDir = filepath.Join(instancePath, "runtime", "teamspeak-bridge")
		if err = os.MkdirAll(runtimeDir, 0o750); err != nil {
			return "", "", fmt.Errorf("external_client_bridge create runtime dir: %w", err)
		}
		if err = ensureRuntimeSubdirs(runtimeDir); err != nil {
			return "", "", err
		}
		return runtimeDir, "", nil
	}
	tmpHome, err = os.MkdirTemp("", "easywi-ts3-bridge-*")
	if err != nil {
		return "", "", fmt.Errorf("external_client_bridge create temp home: %w", err)
	}
	if err = os.Chmod(tmpHome, 0o750); err != nil {
		_ = os.RemoveAll(tmpHome)
		return "", "", fmt.Errorf("external_client_bridge chmod temp home: %w", err)
	}
	if err = ensureRuntimeSubdirs(tmpHome); err != nil {
		_ = os.RemoveAll(tmpHome)
		return "", "", err
	}
	return tmpHome, tmpHome, nil
}

// ensureRuntimeSubdirs creates the required sub-directory tree under runtimeDir.
// xdg-runtime is created 0700 (required by the XDG spec and systemd); all other
// directories are created 0750.
//
// Note: ts3home is NOT created here. The persistent ts3home lives at
// <instancePath>/data/teamspeak-client/ts3home and is managed by
// ensurePersistentTs3HomeDirs to survive runtime cleanup.
func ensureRuntimeSubdirs(runtimeDir string) error {
	type entry struct {
		path string
		perm os.FileMode
	}
	dirs := []entry{
		{"logs", 0o750},
		{"crashdumps", 0o750},
		{"pulse", 0o750},
		{"tmp", 0o750},
		{"cache", 0o750},
		{"xdg-runtime", 0o700},
	}
	for _, d := range dirs {
		if err := os.MkdirAll(filepath.Join(runtimeDir, d.path), d.perm); err != nil {
			return fmt.Errorf("external_client_bridge create runtime subdir %s: %w", d.path, err)
		}
	}
	return nil
}

// buildPersistentTs3Home returns the path to the persistent TS3 home directory
// where settings.db and license state are stored. Unlike the runtime dir, this
// directory must survive cleanup and must never be deleted by the bridge.
//
// When instancePath is provided: <instancePath>/data/teamspeak-client/ts3home
// When instancePath is empty (temp dir fallback): <runtimeDir>/ts3home
func buildPersistentTs3Home(instancePath, runtimeDir string) string {
	if strings.TrimSpace(instancePath) != "" {
		return filepath.Join(instancePath, "data", "teamspeak-client", "ts3home")
	}
	return filepath.Join(runtimeDir, "ts3home")
}

// ensurePersistentTs3HomeDirs creates the persistent ts3home directory tree with
// mode 0700. The tree must not be removed on runtime cleanup.
func ensurePersistentTs3HomeDirs(persistentTs3Home string) error {
	for _, subPath := range []string{
		persistentTs3Home,
		filepath.Join(persistentTs3Home, ".config"),
		filepath.Join(persistentTs3Home, ".local", "share"),
		filepath.Join(persistentTs3Home, ".ts3client", "logs"),
	} {
		if err := os.MkdirAll(subPath, 0o700); err != nil {
			return fmt.Errorf("persistent ts3home subdir %s: %w", subPath, err)
		}
	}
	return nil
}

// buildXvfbArgs constructs the Xvfb command arguments for the given display.
// Exposed as a separate function for unit testing without requiring Xvfb.
func buildXvfbArgs(display string) []string {
	return []string{display, "-screen", "0", xvfbScreenSpec, "-ac", "-nolisten", "tcp"}
}

// startXvfb allocates a free display number, starts Xvfb, and waits until the
// display is ready to accept connections. The caller must kill the returned cmd
// on cleanup.
func startXvfb(runtimeDir string) (string, *exec.Cmd, error) {
	xvfbPath, err := exec.LookPath("Xvfb")
	if err != nil {
		return "", nil, errors.New("xvfb not found: install the xvfb package")
	}

	display := allocateDisplay()
	cmd := exec.Command(xvfbPath, buildXvfbArgs(display)...)
	cmd.Env = append(os.Environ(), "HOME="+runtimeDir)
	if err := cmd.Start(); err != nil {
		return "", nil, fmt.Errorf("xvfb start: %w", err)
	}

	if err := waitForXvfbReady(display, 5*time.Second); err != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
		return "", nil, err
	}
	return display, cmd, nil
}

// waitForXvfbReady polls until the Xvfb display socket exists and xdpyinfo can
// connect to it, or until timeout expires. Both checks run inside the same
// process namespace so PrivateTmp is not an issue.
func waitForXvfbReady(display string, timeout time.Duration) error {
	displayNum := strings.TrimPrefix(display, ":")
	socketPath := "/tmp/.X11-unix/X" + displayNum

	xdpyinfoPath, _ := exec.LookPath("xdpyinfo")

	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if _, statErr := os.Stat(socketPath); statErr == nil {
			if xdpyinfoPath != "" {
				probe := exec.Command(xdpyinfoPath, "-display", display)
				probe.Env = append(os.Environ(), "DISPLAY="+display)
				if probe.Run() == nil {
					return nil
				}
			} else {
				return nil
			}
		}
		time.Sleep(500 * time.Millisecond)
	}

	// Final attempt with captured stderr for the error message.
	if xdpyinfoPath != "" {
		var stderrBuf bytes.Buffer
		probe := exec.Command(xdpyinfoPath, "-display", display)
		probe.Stderr = &stderrBuf
		probe.Env = append(os.Environ(), "DISPLAY="+display)
		if probe.Run() == nil {
			return nil
		}
		msg := strings.TrimSpace(stderrBuf.String())
		if len(msg) > 500 {
			msg = msg[:500]
		}
		return fmt.Errorf("xvfb_failed: Xvfb display %s not ready after %s: %s", display, timeout, msg)
	}
	if _, err := os.Stat(socketPath); err == nil {
		return nil
	}
	return fmt.Errorf("xvfb_failed: Xvfb display %s socket not ready after %s", display, timeout)
}

func allocateDisplay() string {
	for n := 100 + rand.Intn(100); n < 300; n++ {
		lockFile := fmt.Sprintf("/tmp/.X%d-lock", n)
		if _, err := os.Stat(lockFile); os.IsNotExist(err) {
			return fmt.Sprintf(":%d", n)
		}
	}
	return fmt.Sprintf(":%d", 100+rand.Intn(200))
}

// buildPulseAudioConfig generates the PulseAudio configuration for an isolated
// per-instance daemon. module-native-protocol-unix is loaded first with an
// explicit socket path so the bridge, pactl, and TS3 all use the same socket.
func buildPulseAudioConfig(socketPath, sinkName, sourceName string) string {
	blackholeSinkName := "easywi_ts3_playback_blackhole_" + strings.TrimPrefix(strings.TrimPrefix(sinkName, "easywi_sink_"), "sink_")
	return fmt.Sprintf(
		"load-module module-native-protocol-unix socket=%s auth-anonymous=1\n"+
			"load-module module-null-sink sink_name=%s sink_properties=device.description=\"EasyWiMusicbot\"\n"+
			"load-module module-null-sink sink_name=%s sink_properties=device.description=\"EasyWiTS3PlaybackBlackhole\"\n"+
			"load-module module-virtual-source source_name=%s master=%s.monitor\n"+
			"set-default-sink %s\n"+
			"set-default-source %s\n",
		socketPath, sinkName, blackholeSinkName, sourceName, sinkName, blackholeSinkName, sourceName,
	)
}

// filterEnvKeys returns env without any entry whose key equals one of the given keys.
// Used to override PULSE_SERVER and other vars before setting our own values.
func filterEnvKeys(env []string, keys ...string) []string {
	out := make([]string, 0, len(env))
	for _, kv := range env {
		skip := false
		for _, k := range keys {
			if strings.HasPrefix(kv, k+"=") || kv == k {
				skip = true
				break
			}
		}
		if !skip {
			out = append(out, kv)
		}
	}
	return out
}

// tailLines returns the last n non-empty lines of s, joined with "\n".
func tailLines(s string, n int) string {
	lines := strings.Split(strings.TrimRight(s, "\n"), "\n")
	var out []string
	for _, l := range lines {
		if strings.TrimSpace(l) != "" {
			out = append(out, l)
		}
	}
	if len(out) > n {
		out = out[len(out)-n:]
	}
	return strings.Join(out, "\n")
}

// isPulseProcessAlive returns true when the given process is still running.
// It uses signal 0 to probe the process without sending a real signal.
func isPulseProcessAlive(p *os.Process) bool {
	if p == nil {
		return false
	}
	return p.Signal(syscall.Signal(0)) == nil
}

// runPactl runs pactl with the given PULSE_SERVER and args. Returns combined
// stdout output on success. Times out after 5 s. Returns ("", err) if pactl is
// not installed — callers treat missing pactl as a soft skip, not a hard failure.
func runPactl(pulseServer string, args ...string) (string, error) {
	pactlPath, lookErr := exec.LookPath("pactl")
	if lookErr != nil {
		return "", fmt.Errorf("pactl_not_found")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, pactlPath, args...)
	cmd.Env = append(
		filterEnvKeys(os.Environ(), "PULSE_SERVER", "PULSE_RUNTIME_PATH"),
		"PULSE_SERVER="+pulseServer,
	)
	out, err := cmd.Output()
	if err != nil {
		return "", err
	}
	return string(out), nil
}

// startPulseAudio starts a per-instance PulseAudio daemon with a null sink and
// virtual source so the TS3 client can capture our injected audio.
//
// The daemon is started fully isolated: its own HOME, XDG_RUNTIME_DIR, and
// XDG_CONFIG_HOME prevent interference with any global PulseAudio session.
// The socket path is written explicitly into pulse_default.pa via
// module-native-protocol-unix so that the bridge, pactl, and TS3 all use the
// same socket.
//
// Audio failures (binary not found, process start error, socket not ready) are
// never fatal: they return state.audioReady=false with a nil error so the
// caller can start TS3 without PULSE_SERVER. Only genuine infrastructure errors
// (config/directory write failures) are returned as non-nil errors.
func startPulseAudio(runtimeDir, display string) (sinkName, sourceName string, cmd *exec.Cmd, state pulseAudioState, err error) {
	pulseDir := filepath.Join(runtimeDir, "pulse")
	state.socketPath = filepath.Join(pulseDir, "pulse.sock")
	state.exitCode = -1 // -1 means still running

	pulsePath, lookErr := exec.LookPath("pulseaudio")
	if lookErr != nil {
		pulsePath, lookErr = exec.LookPath("pipewire-pulse")
		if lookErr != nil {
			state.notReadyReason = "pulseaudio_binary_not_found"
			log.Printf("external_client_bridge audio not ready; pulseaudio/pipewire-pulse not found; starting TS3 without PULSE_SERVER")
			return "", "", nil, state, nil
		}
	}

	sinkName = "easywi_sink_" + strconv.FormatInt(time.Now().UnixNano(), 36)
	sourceName = "easywi_source_" + strconv.FormatInt(time.Now().UnixNano(), 36)

	if mkErr := os.MkdirAll(pulseDir, 0o700); mkErr != nil {
		return "", "", nil, state, fmt.Errorf("create pulse dir: %w", mkErr)
	}

	// Isolated HOME and config dirs prevent PulseAudio from reading the
	// operator's ~/.config/pulse or interfering with a global daemon.
	pulseHome := filepath.Join(runtimeDir, "pulse-home")
	if mkErr := os.MkdirAll(pulseHome, 0o700); mkErr != nil {
		return "", "", nil, state, fmt.Errorf("create pulse home: %w", mkErr)
	}

	// The XDG_RUNTIME_DIR must be 0700 (XDG spec).
	xdgRuntimeDir := filepath.Join(runtimeDir, "xdg-runtime")
	if mkErr := os.MkdirAll(xdgRuntimeDir, 0o700); mkErr != nil {
		return "", "", nil, state, fmt.Errorf("create xdg_runtime_dir for pulseaudio: %w", mkErr)
	}
	state.xdgRuntimeDir = xdgRuntimeDir

	configPath := filepath.Join(pulseDir, "pulse_default.pa")
	if writeErr := os.WriteFile(configPath, []byte(buildPulseAudioConfig(state.socketPath, sinkName, sourceName)), 0o600); writeErr != nil {
		return "", "", nil, state, fmt.Errorf("write pulse config: %w", writeErr)
	}
	state.configPath = configPath

	args := []string{
		"--daemonize=no",
		"--exit-idle-time=-1",
		"--log-target=stderr",
		"--file=" + configPath,
	}
	state.command = pulsePath + " " + strings.Join(args, " ")

	var stderrBuf bytes.Buffer
	cmd = exec.Command(pulsePath, args...)
	cmd.Stderr = &stderrBuf
	// Strip inherited pulse-related vars then set our isolated values.
	cmd.Env = append(
		filterEnvKeys(os.Environ(),
			"PULSE_SERVER", "PULSE_RUNTIME_PATH", "PULSE_STATE_PATH",
			"HOME", "XDG_RUNTIME_DIR", "XDG_CONFIG_HOME"),
		"HOME="+pulseHome,
		"DISPLAY="+display,
		"XDG_RUNTIME_DIR="+xdgRuntimeDir,
		"XDG_CONFIG_HOME="+filepath.Join(pulseHome, ".config"),
	)

	if startErr := cmd.Start(); startErr != nil {
		state.notReadyReason = "pulseaudio_start_failed"
		log.Printf("external_client_bridge audio not ready; pulseaudio_start_failed=%v pulse_socket_expected=%s; starting TS3 without PULSE_SERVER",
			startErr, state.socketPath)
		logPulseAudioNotReady(state, &stderrBuf)
		return "", "", nil, state, nil
	}
	state.started = true
	state.pid = cmd.Process.Pid
	log.Printf("external_client_bridge pulseaudio_started=true pulseaudio_pid=%d pulse_socket_expected=%s", state.pid, state.socketPath)

	// Poll up to 8 s for the socket to appear, checking whether the process
	// has already exited on each iteration so we fail fast on startup crashes.
	const pollInterval = 200 * time.Millisecond
	const maxWait = 8 * time.Second
	deadline := time.Now().Add(maxWait)
	for time.Now().Before(deadline) {
		if checkPulseSocketReady(state.socketPath) {
			break
		}
		if !isPulseProcessAlive(cmd.Process) {
			break
		}
		time.Sleep(pollInterval)
	}

	// Collect final socket state.
	if info, statErr := os.Stat(state.socketPath); statErr == nil {
		state.socketExists = true
		state.socketReady = info.Mode()&os.ModeSocket != 0
	}

	// Determine reason if not ready yet.
	if !state.socketReady {
		if !isPulseProcessAlive(cmd.Process) {
			state.notReadyReason = "pulseaudio_exited"
			// Reap the zombie so it doesn't stay defunct.
			_ = cmd.Wait()
			if cmd.ProcessState != nil {
				state.exitCode = cmd.ProcessState.ExitCode()
			}
			log.Printf("external_client_bridge audio not ready; pulseaudio_exited=true pulseaudio_pid=%d pulseaudio_exit_code=%d pulse_socket_expected=%s",
				state.pid, state.exitCode, state.socketPath)
			logPulseAudioNotReady(state, &stderrBuf)
			return sinkName, sourceName, nil, state, nil
		}
		state.notReadyReason = "pulseaudio_socket_missing"
		log.Printf("external_client_bridge audio not ready; pulseaudio_socket_missing=true pulseaudio_pid=%d pulse_socket_expected=%s socket_exists=%v",
			state.pid, state.socketPath, state.socketExists)
		logPulseAudioNotReady(state, &stderrBuf)
		return sinkName, sourceName, cmd, state, nil
	}

	// Socket exists; verify PulseAudio responds to pactl and the sink/source were loaded.
	pulseServer := "unix:" + state.socketPath
	pactlOut, pactlErr := runPactl(pulseServer, "info")
	if pactlErr != nil && pactlErr.Error() != "pactl_not_found" {
		state.notReadyReason = "pactl_failed"
		state.pactlError = pactlErr.Error()
		log.Printf("external_client_bridge audio not ready; pactl_failed=true pactl_error=%v pulseaudio_pid=%d pulse_socket=%s",
			pactlErr, state.pid, state.socketPath)
		logPulseAudioNotReady(state, &stderrBuf)
		return sinkName, sourceName, cmd, state, nil
	}
	_ = pactlOut // used for log only; not parsed

	// When pactl is available, also confirm the expected sink and source loaded.
	if pactlErr == nil {
		sinksOut, _ := runPactl(pulseServer, "list", "short", "sinks")
		sourcesOut, _ := runPactl(pulseServer, "list", "short", "sources")
		sinkFound := strings.Contains(sinksOut, sinkName)
		sourceFound := strings.Contains(sourcesOut, sourceName)
		if !sinkFound || !sourceFound {
			state.notReadyReason = "sink_source_missing"
			log.Printf("external_client_bridge audio not ready; sink_source_missing=true sink_found=%v source_found=%v pulseaudio_pid=%d",
				sinkFound, sourceFound, state.pid)
			logPulseAudioNotReady(state, &stderrBuf)
			return sinkName, sourceName, cmd, state, nil
		}
		log.Printf("external_client_bridge pactl_verified=true sink=%s source=%s", sinkName, sourceName)
	} else {
		// pactl not installed — skip pactl verification, socket dial is sufficient.
		log.Printf("external_client_bridge pactl_not_installed=true pulse_socket=%s; skipping pactl verification", state.socketPath)
	}

	state.audioReady = true
	log.Printf("external_client_bridge audio_injection_ready=true pulseaudio_pid=%d pulse_socket=%s sink=%s source=%s",
		state.pid, state.socketPath, sinkName, sourceName)
	return sinkName, sourceName, cmd, state, nil
}

// logPulseAudioNotReady emits all diagnostic fields required for post-mortem
// analysis when PulseAudio fails to become ready during bridge startup.
func logPulseAudioNotReady(state pulseAudioState, stderrBuf *bytes.Buffer) {
	stderr := ""
	if stderrBuf != nil {
		stderr = tailLines(stderrBuf.String(), 20)
	}
	if state.stderrTail != "" {
		stderr = state.stderrTail
	}
	log.Printf("external_client_bridge audio_injection_not_ready_reason=%s", state.notReadyReason)
	log.Printf("external_client_bridge pulseaudio_pid=%d", state.pid)
	log.Printf("external_client_bridge pulseaudio_exit_code=%d", state.exitCode)
	log.Printf("external_client_bridge pulseaudio_config_path=%s", state.configPath)
	log.Printf("external_client_bridge pulseaudio_command=%s", state.command)
	log.Printf("external_client_bridge pulseaudio_env_XDG_RUNTIME_DIR=%s", state.xdgRuntimeDir)
	log.Printf("external_client_bridge pulse_socket_expected=%s", state.socketPath)
	log.Printf("external_client_bridge pulse_socket_candidates_checked=%s", state.socketPath)
	if state.pactlError != "" {
		log.Printf("external_client_bridge pactl_error=%s", state.pactlError)
	}
	if stderr != "" {
		log.Printf("external_client_bridge pulseaudio_stderr_tail=%s", stderr)
	}
}

// buildTS3Env constructs the minimal, fully-specified environment for the TS3
// client subprocess. WAYLAND_DISPLAY is explicitly cleared to force the XCB
// platform plugin. QT_QPA_PLATFORM=xcb is set unconditionally.
//
// ts3Home is the persistent TS3 home (settings.db, license state, ClientQuery config).
// cacheDir is a volatile runtime cache path (may be wiped on cleanup).
// xdgRuntimeDir is the per-session XDG runtime dir (volatile).
// tmpDir is the TMPDIR for temporary files.
// clientDir is the directory containing the TS3 client binary or runscript.
// EASYWI_TS_BRIDGE=1 is always set so the runscript/client can detect the bridge.
// PULSE_SERVER is included only when pulseSocketPath is non-empty.
func buildTS3Env(ts3Home, xdgRuntimeDir, tmpDir, display, pulseSocketPath, clientDir, cacheDir string) []string {
	env := []string{
		"EASYWI_TS_BRIDGE=1",
		"HOME=" + ts3Home,
		"DISPLAY=" + display,
		"QT_QPA_PLATFORM=xcb",
		"QT_LOGGING_RULES=*.debug=false",
		"XDG_CONFIG_HOME=" + filepath.Join(ts3Home, ".config"),
		"XDG_DATA_HOME=" + filepath.Join(ts3Home, ".local", "share"),
		"XDG_CACHE_HOME=" + cacheDir,
		"XDG_RUNTIME_DIR=" + xdgRuntimeDir,
		"TMPDIR=" + tmpDir,
		"LD_LIBRARY_PATH=" + buildLDLibraryPath(clientDir),
		"PATH=/usr/local/bin:/usr/bin:/bin",
		"USER=easywi",
		"WAYLAND_DISPLAY=",
	}
	if pulseSocketPath != "" {
		env = append(env, "PULSE_SERVER=unix:"+pulseSocketPath)
	}
	if dbusAddr := tryDBusLaunch(); dbusAddr != "" {
		env = append(env, "DBUS_SESSION_BUS_ADDRESS="+dbusAddr)
	}
	return env
}

// checkPulseSocketReady returns true when socketPath exists on disk as a Unix
// socket and accepts a connection. Both the stat check (ModeSocket) and the
// net.Dial are required: a stale or regular file at socketPath must not be
// mistaken for a ready PulseAudio daemon.
func checkPulseSocketReady(socketPath string) bool {
	info, err := os.Stat(socketPath)
	if err != nil || info.Mode()&os.ModeSocket == 0 {
		return false
	}
	conn, err := net.Dial("unix", socketPath)
	if err != nil {
		return false
	}
	_ = conn.Close()
	return true
}

// tryDBusLaunch attempts to start a DBus session bus and returns its address.
// Returns "" if dbus-launch is unavailable or fails; the TS3 client runs without
// DBus in that case.
func tryDBusLaunch() string {
	dbusLaunchPath, err := exec.LookPath("dbus-launch")
	if err != nil {
		return ""
	}
	cmd := exec.Command(dbusLaunchPath, "--sh-syntax")
	out, err := cmd.Output()
	if err != nil {
		return ""
	}
	for _, line := range strings.Split(string(out), "\n") {
		if !strings.HasPrefix(line, "DBUS_SESSION_BUS_ADDRESS=") {
			continue
		}
		val := strings.TrimPrefix(line, "DBUS_SESSION_BUS_ADDRESS=")
		val = strings.TrimSuffix(strings.TrimSpace(val), ";")
		val = strings.Trim(val, "'\"")
		if val != "" {
			return val
		}
	}
	return ""
}

// logTS3StartDiagnostics writes a comprehensive pre-launch snapshot to the log
// so operators can diff against a known-good invocation when diagnosing crashes.
// The string literal "ts3_start_mode" is present so it can be found with
// `strings <binary> | grep ts3_start_mode` to confirm the diagnostic build.
func logTS3StartDiagnostics(d *ts3DiagContext) {
	log.Printf("ts3start ts3_start_mode=%s", d.mode)
	log.Printf("ts3start ts3_executable=%s", d.execPath)
	log.Printf("ts3start ts3_args=%s", strings.Join(d.args, " "))
	log.Printf("ts3start cmd_dir=%s", d.cmdDir)
	log.Printf("ts3start display=%s", d.display)
	log.Printf("ts3start runtime_dir=%s", d.runtimeDir)
	log.Printf("ts3start persistent_ts3home=%s", d.ts3Home)
	log.Printf("ts3start settings_db_path=%s", d.settingsDbPath)
	log.Printf("ts3start settings_db_exists=%v", d.settingsDbExists)
	log.Printf("ts3start license_accept_required=%v", d.licenseAcceptRequired)
	log.Printf("ts3start xdg_config_home=%s", filepath.Join(d.ts3Home, ".config"))
	log.Printf("ts3start xdg_data_home=%s", filepath.Join(d.ts3Home, ".local", "share"))
	log.Printf("ts3start xdg_cache_home=%s", envValue(d.env, "XDG_CACHE_HOME"))
	log.Printf("ts3start xdg_runtime_dir=%s", d.xdgRuntimeDir)
	log.Printf("ts3start tmpdir=%s", d.tmpDir)
	log.Printf("ts3start home=%s", d.ts3Home)
	log.Printf("ts3start client_query_host=%s", d.cqHost)
	log.Printf("ts3start expected_client_query_port=%d", d.cqPort)
	log.Printf("ts3start official_client_dir=%s", d.officialDir)
	log.Printf("ts3start plugin_path=%s", d.pluginPath)
	log.Printf("ts3start plugin_exists=%v", d.pluginExists)
	log.Printf("ts3start runscript_exists=%v", d.runscriptExst)
	log.Printf("ts3start binary_exists=%v", d.binaryExists)
	log.Printf("ts3start EASYWI_TS_BRIDGE=%s", envValue(d.env, "EASYWI_TS_BRIDGE"))
	log.Printf("ts3start QT_QPA_PLATFORM=%s", envValue(d.env, "QT_QPA_PLATFORM"))
	log.Printf("ts3start QT_LOGGING_RULES=%s", envValue(d.env, "QT_LOGGING_RULES"))
	log.Printf("ts3start LD_LIBRARY_PATH=%s", envValue(d.env, "LD_LIBRARY_PATH"))
	log.Printf("ts3start PATH=%s", envValue(d.env, "PATH"))
	log.Printf("ts3start pulse_server=%s", envValue(d.env, "PULSE_SERVER"))
	log.Printf("ts3start pulse_socket_expected=%s", d.pulseSocketPath)
	log.Printf("ts3start pulse_socket_exists=%v", d.pulseSocketExists)
	log.Printf("ts3start pulse_socket_ready=%v", d.pulseSocketReady)
	log.Printf("ts3start pulseaudio_started=%v", d.pulseStarted)
	log.Printf("ts3start pulseaudio_pid=%d", d.pulseaudioPid)
	log.Printf("ts3start pulseaudio_exit_code=%d", d.pulseaudioExitCode)
	log.Printf("ts3start pulseaudio_config_path=%s", d.pulseaudioConfigPath)
	log.Printf("ts3start pulseaudio_command=%s", d.pulseaudioCommand)
	log.Printf("ts3start pulseaudio_env_XDG_RUNTIME_DIR=%s", d.pulseaudioXDGRuntime)
	log.Printf("ts3start audio_ready=%v", d.audioReady)
	log.Printf("ts3start audio_injection_not_ready_reason=%s", d.audioNotReadyReason)
	if d.pactlError != "" {
		log.Printf("ts3start pactl_error=%s", d.pactlError)
	}
	if d.pulseaudioStderrTail != "" {
		log.Printf("ts3start pulseaudio_stderr_tail=%s", d.pulseaudioStderrTail)
	}
}

// startTS3Client launches the TS3 client headless with a fully-specified,
// isolated environment. Secrets are written to a temp config file (mode 0600);
// they never appear in argv or the environment.
//
// persistentTs3Home is the directory that must survive cleanup; it holds
// settings.db, the license state, and the ClientQuery plugin config.
// The runtime volatile paths (xdg-runtime, cache, tmp) live under runtimeDir.
//
// If params.ClientRunscriptPath is set and the file is accessible, the runscript
// is used instead of the binary. The runscript is launched with only
// -nosingleinstance (no URI argument) because some runscripts do not forward
// extra arguments to the TS3 client. The ts3.ini file contains the server URI so
// the client connects on startup regardless.
//
// cqHost and cqPort are recorded in the returned ts3DiagContext for crash
// diagnostics; they are not passed to the TS3 process.
func startTS3Client(ctx context.Context, params connectParams, clientBinary, runtimeDir, persistentTs3Home, display string, pulse pulseAudioState, cqHost string, cqPort int) (*exec.Cmd, *bytes.Buffer, *ts3DiagContext, error) {
	ts3Home := persistentTs3Home
	if err := ensurePersistentTs3HomeDirs(ts3Home); err != nil {
		return nil, nil, nil, fmt.Errorf("ensure ts3 home: %w", err)
	}

	xdgRuntimeDir := filepath.Join(runtimeDir, "xdg-runtime")
	if err := os.MkdirAll(xdgRuntimeDir, 0o700); err != nil {
		return nil, nil, nil, fmt.Errorf("create xdg_runtime_dir: %w", err)
	}
	if err := os.Chmod(xdgRuntimeDir, 0o700); err != nil {
		return nil, nil, nil, fmt.Errorf("chmod xdg_runtime_dir: %w", err)
	}

	cacheDir := filepath.Join(runtimeDir, "cache")
	if err := os.MkdirAll(cacheDir, 0o750); err != nil {
		return nil, nil, nil, fmt.Errorf("create cache dir: %w", err)
	}

	// Compute TMPDIR: use <instancePath>/runtime/tmp when instancePath is set,
	// matching the known-good manual invocation. The bridge runtimeDir is a
	// sub-directory of <instancePath>/runtime/; using the parent-level tmp
	// avoids a path depth discrepancy that can trigger early Qt crashes.
	var tmpDir string
	if ip := strings.TrimSpace(params.InstancePath); ip != "" {
		tmpDir = filepath.Join(ip, "runtime", "tmp")
	} else {
		tmpDir = filepath.Join(runtimeDir, "tmp")
	}
	if err := os.MkdirAll(tmpDir, 0o750); err != nil {
		return nil, nil, nil, fmt.Errorf("create tmpdir: %w", err)
	}
	log.Printf("ts3start tmpdir=%s (instance_path=%q)", tmpDir, params.InstancePath)

	port := params.Port
	if port <= 0 {
		port = 9987
	}

	// Build ts3server:// URI. Secrets go to the ini file, not the URI.
	uri := fmt.Sprintf("ts3server://%s?port=%d", params.Host, port)
	if params.Nickname != "" {
		uri += "&nickname=" + params.Nickname
	}
	if params.ChannelID != "" {
		uri += "&channel=" + params.ChannelID
	}

	// Write secrets to isolated 0600 config file — never in argv.
	iniLines := []string{
		"[General]",
		"LastServer=" + uri,
	}
	if params.ServerPassword != "" {
		iniLines = append(iniLines, "ServerPassword="+params.ServerPassword)
	}
	if params.ChannelPassword != "" {
		iniLines = append(iniLines, "ChannelPassword="+params.ChannelPassword)
	}
	iniContent := strings.Join(iniLines, "\n") + "\n"
	iniPath := filepath.Join(ts3Home, "ts3.ini")
	if err := os.WriteFile(iniPath, []byte(iniContent), 0o600); err != nil {
		return nil, nil, nil, fmt.Errorf("write ts3 ini: %w", err)
	}

	// Determine executable: prefer runscript if accessible.
	runscript := strings.TrimSpace(params.ClientRunscriptPath)
	var execPath string
	var cmdArgs []string
	var workDir string
	var clientDir string
	var mode string
	var runscriptExst bool

	if runscript != "" {
		_, statErr := os.Stat(runscript)
		runscriptExst = statErr == nil
		if runscriptExst {
			execPath = runscript
			cmdArgs = []string{"-nosingleinstance"}
			workDir = filepath.Dir(runscript)
			clientDir = filepath.Dir(runscript)
			mode = "runscript"
		}
	}
	if execPath == "" {
		mode = "binary"
		execPath = clientBinary
		cmdArgs = []string{uri, "-nosingleinstance", "-headless"}
		workDir = filepath.Dir(clientBinary)
		clientDir = filepath.Dir(clientBinary)
	}

	// Gather plugin / binary existence for diagnostics.
	officialClientDir := officialClientDirFromPaths(clientBinary, strings.TrimSpace(params.ClientRunscriptPath))
	pluginPath := ""
	pluginExists := false
	if officialClientDir != "" {
		pluginPath = filepath.Join(officialClientDir, "plugins", clientQueryPluginName)
		if _, err := os.Stat(pluginPath); err == nil {
			pluginExists = true
		}
	}
	binaryPath := clientBinary
	binaryExists := false
	if binaryPath != "" {
		if _, err := os.Stat(binaryPath); err == nil {
			binaryExists = true
		}
	}

	env := buildTS3Env(ts3Home, xdgRuntimeDir, tmpDir, display, pulse.envSocket(), clientDir, cacheDir)

	settingsDbPath := filepath.Join(ts3Home, ".ts3client", "settings.db")
	_, settingsDbStatErr := os.Stat(settingsDbPath)
	settingsDbExists := settingsDbStatErr == nil

	diagCtx := &ts3DiagContext{
		mode:                 mode,
		execPath:             execPath,
		args:                 cmdArgs,
		cmdDir:               workDir,
		display:              display,
		runtimeDir:           runtimeDir,
		ts3Home:              ts3Home,
		xdgRuntimeDir:        xdgRuntimeDir,
		tmpDir:               tmpDir,
		cqHost:               cqHost,
		cqPort:               cqPort,
		officialDir:          officialClientDir,
		pluginPath:           pluginPath,
		pluginExists:         pluginExists,
		runscriptExst:        runscriptExst,
		binaryPath:           binaryPath,
		binaryExists:         binaryExists,
		env:                  env,
		settingsDbPath:       settingsDbPath,
		settingsDbExists:     settingsDbExists,
		pulseSocketPath:      pulse.socketPath,
		pulseSocketExists:    pulse.socketExists,
		pulseSocketReady:     pulse.socketReady,
		pulseStarted:         pulse.started,
		audioReady:           pulse.audioReady,
		pulseaudioPid:        pulse.pid,
		pulseaudioExitCode:   pulse.exitCode,
		pulseaudioStderrTail: pulse.stderrTail,
		pulseaudioConfigPath: pulse.configPath,
		pulseaudioCommand:    pulse.command,
		pulseaudioXDGRuntime: pulse.xdgRuntimeDir,
		audioNotReadyReason:  pulse.notReadyReason,
		pactlError:           pulse.pactlError,
	}
	logTS3StartDiagnostics(diagCtx)

	cmd := exec.CommandContext(ctx, execPath, cmdArgs...)
	cmd.Env = env
	cmd.Dir = workDir
	var stderrBuf bytes.Buffer
	cmd.Stderr = &stderrBuf
	// Remove stale TS3Client* IPC sockets from tmpDir before launching the new
	// process. These are left behind when a previous TS3 instance was killed without
	// cleanup and prevent -nosingleinstance from working correctly.
	cleanupStaleTS3Sockets(tmpDir)

	if err := cmd.Start(); err != nil {
		return nil, nil, nil, fmt.Errorf("ts3client start: %w", err)
	}
	log.Printf("external_client_bridge ts3_process_started=true ts3_process_pid=%d", cmd.Process.Pid)
	return cmd, &stderrBuf, diagCtx, nil
}

// cleanupStaleTS3Sockets removes stale TS3Client* IPC socket files from dir.
// These are left behind when a previous TS3 process was killed without cleanup;
// their presence can prevent -nosingleinstance from working correctly.
// Only socket-type files matching the TS3Client* name prefix are removed.
func cleanupStaleTS3Sockets(dir string) {
	entries, err := os.ReadDir(dir)
	if err != nil {
		return
	}
	for _, e := range entries {
		if e.IsDir() || !strings.HasPrefix(e.Name(), "TS3Client") {
			continue
		}
		info, infoErr := e.Info()
		if infoErr != nil {
			continue
		}
		if info.Mode()&os.ModeSocket == 0 {
			continue
		}
		socketPath := filepath.Join(dir, e.Name())
		if removeErr := os.Remove(socketPath); removeErr == nil {
			log.Printf("external_client_bridge stale_ts3_socket_removed=%s", socketPath)
		}
	}
}

type audioInjectionError struct {
	Component       string
	Command         string
	ExitCode        int
	StderrTail      string
	StdoutTail      string
	FrameFormat     string
	PCMLen          int
	PayloadLen      int
	SampleRate      int
	Channels        int
	PulseSocket     string
	SinkName        string
	PulseServer     string
	BytesWritten    int
	FramesProcessed int
	FramesSent      int
	Message         string
}

func (e audioInjectionError) Error() string {
	parts := []string{}
	add := func(k string, v any) { parts = append(parts, fmt.Sprintf("%s=%v", k, v)) }
	if e.Message != "" {
		add("message", e.Message)
	}
	component := strings.TrimSpace(e.Component)
	if component == "" {
		component = "unknown"
	}
	add("component", component)
	if e.Command != "" {
		add("command", e.Command)
	}
	if e.ExitCode != 0 {
		add("exit_code", e.ExitCode)
	}
	if e.StderrTail != "" {
		add("stderr_tail", strings.ReplaceAll(e.StderrTail, "\n", " | "))
	}
	if e.StdoutTail != "" {
		add("stdout_tail", strings.ReplaceAll(e.StdoutTail, "\n", " | "))
	}
	if e.FrameFormat != "" {
		add("frame_format", e.FrameFormat)
	}
	add("pcm_len", e.PCMLen)
	add("payload_len", e.PayloadLen)
	add("sample_rate", e.SampleRate)
	add("channels", e.Channels)
	if e.PulseSocket != "" {
		add("pulse_socket", e.PulseSocket)
	}
	if e.SinkName != "" {
		add("sink_name", e.SinkName)
	}
	if e.PulseServer != "" {
		add("PULSE_SERVER", e.PulseServer)
	}
	add("bytes_written", e.BytesWritten)
	add("frames_processed", e.FramesProcessed)
	add("frames_sent", e.FramesSent)
	return strings.Join(parts, "; ")
}

func commandString(path string, args []string) string { return path + " " + strings.Join(args, " ") }

func exitCode(err error) int {
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return ee.ExitCode()
	}
	return 0
}

// injectOpusViaPulse decodes an Opus frame via ffmpeg and writes the PCM output
// to the PulseAudio null sink. Secrets never appear here.
func injectOpusViaPulse(ctx context.Context, opusFrame []byte, durationMs int, pulseSocket, sinkName string) error {
	if len(opusFrame) == 0 {
		return nil
	}
	ffmpegPath, err := exec.LookPath("ffmpeg")
	if err != nil {
		return audioInjectionError{Component: "ffmpeg_decode_opus", FrameFormat: "opus", PayloadLen: len(opusFrame), PulseSocket: pulseSocket, SinkName: sinkName, PulseServer: "unix:" + pulseSocket, Message: "ffmpeg not found: required for audio injection"}
	}
	oggData := wrapOpusInOgg(opusFrame, durationMs)
	args := []string{"-v", "error", "-f", "ogg", "-i", "pipe:0", "-f", "pulse", "-device", sinkName, "EasyWI TeamSpeak Opus"}
	cmd := exec.CommandContext(ctx, ffmpegPath, args...)
	cmd.Env = append(filterEnvKeys(os.Environ(), "PULSE_SERVER"), "PULSE_SERVER=unix:"+pulseSocket)
	cmd.Stdin = bytes.NewReader(oggData)
	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	if err := cmd.Run(); err != nil {
		return audioInjectionError{Component: "ffmpeg_decode_opus", Command: commandString(ffmpegPath, args), ExitCode: exitCode(err), StderrTail: tailLines(stderr.String(), 20), StdoutTail: tailLines(stdout.String(), 20), FrameFormat: "opus", PayloadLen: len(opusFrame), SampleRate: 48000, Channels: 2, PulseSocket: pulseSocket, SinkName: sinkName, PulseServer: "unix:" + pulseSocket, BytesWritten: len(oggData), Message: err.Error()}
	}
	return nil
}

type pulsePCMWriter struct {
	mu                sync.Mutex
	cmd               *exec.Cmd
	stdin             io.WriteCloser
	pacatPath         string
	args              []string
	env               []string
	restarts          int
	bytesWrittenTotal int64
}

var persistentPulseWriters sync.Map

func pulseWriterKey(pulseSocket, sinkName string, sampleRate, channels int) string {
	return pulseSocket + "\x00" + sinkName + "\x00" + strconv.Itoa(sampleRate) + "\x00" + strconv.Itoa(channels)
}

func getPersistentPulseWriter(pulseSocket, sinkName string, sampleRate, channels int) (*pulsePCMWriter, error) {
	key := pulseWriterKey(pulseSocket, sinkName, sampleRate, channels)
	if existing, ok := persistentPulseWriters.Load(key); ok {
		return existing.(*pulsePCMWriter), nil
	}
	pacatPath, err := exec.LookPath("pacat")
	if err != nil {
		return nil, err
	}
	w := &pulsePCMWriter{
		pacatPath: pacatPath,
		args:      []string{"--playback", "--raw", "--format=s16le", "--rate=" + strconv.Itoa(sampleRate), "--channels=" + strconv.Itoa(channels), "--device=" + sinkName, "--latency-msec=60"},
		env:       append(filterEnvKeys(os.Environ(), "PULSE_SERVER"), "PULSE_SERVER=unix:"+pulseSocket),
	}
	actual, _ := persistentPulseWriters.LoadOrStore(key, w)
	return actual.(*pulsePCMWriter), nil
}

func (w *pulsePCMWriter) startLocked(ctx context.Context) error {
	cmd := exec.CommandContext(ctx, w.pacatPath, w.args...)
	cmd.Env = w.env
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return err
	}
	cmd.Stdout = io.Discard
	cmd.Stderr = io.Discard
	if err := cmd.Start(); err != nil {
		_ = stdin.Close()
		return err
	}
	w.cmd = cmd
	w.stdin = stdin
	w.restarts++
	log.Printf("pulse_pcm_writer mode=persistent pid=%d restarts=%d command=%q", cmd.Process.Pid, w.restarts, commandString(w.pacatPath, w.args))
	return nil
}

func (w *pulsePCMWriter) resetLocked() {
	if w.stdin != nil {
		_ = w.stdin.Close()
	}
	if w.cmd != nil && w.cmd.Process != nil {
		_ = w.cmd.Process.Kill()
		_ = w.cmd.Wait()
	}
	w.stdin = nil
	w.cmd = nil
}

func (w *pulsePCMWriter) Write(ctx context.Context, pcm []byte) error {
	w.mu.Lock()
	defer w.mu.Unlock()
	if w.stdin == nil || w.cmd == nil || w.cmd.ProcessState != nil {
		w.resetLocked()
		if err := w.startLocked(ctx); err != nil {
			return err
		}
	}
	start := time.Now()
	if _, err := w.stdin.Write(pcm); err != nil {
		w.resetLocked()
		if startErr := w.startLocked(ctx); startErr != nil {
			return fmt.Errorf("restart pacat writer after write error %v: %w", err, startErr)
		}
		if _, retryErr := w.stdin.Write(pcm); retryErr != nil {
			return retryErr
		}
	}
	w.bytesWrittenTotal += int64(len(pcm))
	log.Printf("pulse_pcm_writer mode=persistent pid=%d bytes=%d bytes_total=%d write_ms=%d", w.cmd.Process.Pid, len(pcm), w.bytesWrittenTotal, time.Since(start).Milliseconds())
	return nil
}

func injectPCMViaPulse(ctx context.Context, pcm []byte, pulseSocket, sinkName string, sampleRate, channels int) error {
	if len(pcm) == 0 {
		return nil
	}
	if sampleRate <= 0 {
		sampleRate = 48000
	}
	if channels <= 0 {
		channels = 2
	}
	writer, err := getPersistentPulseWriter(pulseSocket, sinkName, sampleRate, channels)
	if err != nil {
		return audioInjectionError{Component: "pacat", FrameFormat: "pcm_s16le", PCMLen: len(pcm), PayloadLen: len(pcm), SampleRate: sampleRate, Channels: channels, PulseSocket: pulseSocket, SinkName: sinkName, PulseServer: "unix:" + pulseSocket, Message: "pacat not found: required for PCM audio injection"}
	}
	if err := writer.Write(ctx, pcm); err != nil {
		return audioInjectionError{Component: "pulse_pcm_writer", Command: commandString(writer.pacatPath, writer.args), FrameFormat: "pcm_s16le", PCMLen: len(pcm), PayloadLen: len(pcm), SampleRate: sampleRate, Channels: channels, PulseSocket: pulseSocket, SinkName: sinkName, PulseServer: "unix:" + pulseSocket, BytesWritten: len(pcm), Message: err.Error()}
	}
	return nil
}

// licenseCheckResult holds the outcome of checking the current TS3 log file for
// a LicenseViewer dialog. All fields are populated even when no block is detected
// so callers can log them for diagnostics.
type licenseCheckResult struct {
	Source                     string // "current_log" or "none"
	LogPath                    string
	CurrentLogHasLicenseViewer bool
	CurrentLogRequiresAccept   bool
	LicenseAcceptRequired      bool
}

// findCurrentTs3Log returns the path to the most recently modified ts3client_*.log
// file in dirs that was last modified at or after notBefore. When notBefore is the
// zero value the time filter is skipped. Returns "" when no matching file exists.
func findCurrentTs3Log(dirs []string, notBefore time.Time) string {
	var latestPath string
	var latestMod time.Time
	for _, dir := range dirs {
		entries, err := os.ReadDir(dir)
		if err != nil {
			continue
		}
		for _, e := range entries {
			if e.IsDir() || !strings.HasPrefix(e.Name(), "ts3client_") || !strings.HasSuffix(e.Name(), ".log") {
				continue
			}
			info, err := e.Info()
			if err != nil {
				continue
			}
			if !notBefore.IsZero() && info.ModTime().Before(notBefore) {
				continue // skip log files from before this TS3 start
			}
			if latestPath == "" || info.ModTime().After(latestMod) {
				latestPath = filepath.Join(dir, e.Name())
				latestMod = info.ModTime()
			}
		}
	}
	return latestPath
}

// checkCurrentTs3LogForLicense scans only the single most recently modified
// ts3client_*.log file in dirs (written at or after notBefore) for the
// LicenseViewer + "require accept=1" pattern that indicates the TS3 client is
// blocked by the license dialog.
//
// Using notBefore = time just before startTS3Client prevents old log files from
// previous TS3 starts from triggering a false-positive block.
// When notBefore is the zero value the time filter is skipped (used in tests and
// the legacy scanTs3LogForLicenseViewer wrapper).
func checkCurrentTs3LogForLicense(dirs []string, notBefore time.Time) licenseCheckResult {
	logPath := findCurrentTs3Log(dirs, notBefore)
	if logPath == "" {
		return licenseCheckResult{Source: "none"}
	}
	data, err := os.ReadFile(logPath)
	if err != nil {
		return licenseCheckResult{Source: "current_log", LogPath: logPath}
	}
	text := string(data)
	hasViewer := strings.Contains(text, "LicenseViewer")
	requiresAccept := hasViewer && strings.Contains(text, "require accept=1")
	return licenseCheckResult{
		Source:                     "current_log",
		LogPath:                    logPath,
		CurrentLogHasLicenseViewer: hasViewer,
		CurrentLogRequiresAccept:   requiresAccept,
		LicenseAcceptRequired:      requiresAccept,
	}
}

// scanTs3LogForLicenseViewer is a legacy wrapper around checkCurrentTs3LogForLicense
// that scans all log files without a time filter. Kept for compatibility with tests
// that predate the per-start current-log detection.
func scanTs3LogForLicenseViewer(dirs []string) bool {
	return checkCurrentTs3LogForLicense(dirs, time.Time{}).LicenseAcceptRequired
}

// probeClientQueryLicenseBlock connects to the ClientQuery plugin and sends
// "use schandlerid=1". When the TS3 Client is blocked by the LicenseViewer,
// all commands return error id=1796 "currently not possible". The function
// returns true only when error 1796 is received AND the CURRENT log (written
// at or after notBefore) also contains "LicenseViewer", to avoid false positives
// from unrelated 1796 errors.
func probeClientQueryLicenseBlock(host string, port int, apiKey string, ts3LogDirs []string, notBefore time.Time) bool {
	if host == "" {
		host = "127.0.0.1"
	}
	conn, scanner, err := clientQueryConnect(host, port, apiKey, 5*time.Second)
	if err != nil {
		return false
	}
	defer func() { _ = conn.Close() }()

	resp, err := clientQueryExecCommand(conn, scanner, "use schandlerid=1", 5*time.Second)
	if err != nil {
		return false
	}
	for _, line := range strings.Split(resp, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "error id=1796") {
			return checkCurrentTs3LogForLicense(ts3LogDirs, notBefore).LicenseAcceptRequired
		}
		if strings.HasPrefix(line, "error id=") {
			return false
		}
	}
	return false
}

// findTs3LogTail searches dirs for the most recently modified ts3client_*.log
// file and returns its path plus the last tailLines lines.
func findTs3LogTail(dirs []string, tailLines int) (path, tail string) {
	var latestPath string
	var latestTime time.Time
	for _, dir := range dirs {
		entries, readErr := os.ReadDir(dir)
		if readErr != nil {
			continue
		}
		for _, e := range entries {
			if e.IsDir() {
				continue
			}
			name := e.Name()
			if strings.HasPrefix(name, "ts3client_") && strings.HasSuffix(name, ".log") {
				info, infoErr := e.Info()
				if infoErr != nil {
					continue
				}
				if latestPath == "" || info.ModTime().After(latestTime) {
					latestPath = filepath.Join(dir, name)
					latestTime = info.ModTime()
				}
			}
		}
	}
	if latestPath == "" {
		return "", ""
	}
	data, readErr := os.ReadFile(latestPath)
	if readErr != nil {
		return latestPath, ""
	}
	lines := strings.Split(strings.TrimRight(string(data), "\n"), "\n")
	if len(lines) > tailLines {
		lines = lines[len(lines)-tailLines:]
	}
	return latestPath, strings.Join(lines, "\n")
}

// findTs3Crashdump searches dirs for a crashdump file (.dmp, .core, or a file
// with "crash" in its name) and returns the first match.
func findTs3Crashdump(dirs []string) string {
	for _, dir := range dirs {
		entries, readErr := os.ReadDir(dir)
		if readErr != nil {
			continue
		}
		for _, e := range entries {
			if e.IsDir() {
				continue
			}
			name := strings.ToLower(e.Name())
			if strings.Contains(name, "crash") || strings.HasSuffix(name, ".dmp") || strings.HasSuffix(name, ".core") {
				return filepath.Join(dir, e.Name())
			}
		}
	}
	return ""
}

func validateExternalClientBinary(path string) error {
	if strings.ContainsAny(path, "\x00\n\r") || !filepath.IsAbs(path) {
		return errors.New("client_binary_path must be an absolute path without special characters")
	}
	info, err := os.Stat(path)
	if err != nil {
		if os.IsNotExist(err) {
			return fmt.Errorf("ts3client binary not found: %s", path)
		}
		return fmt.Errorf("ts3client binary stat: %w", err)
	}
	if info.IsDir() {
		return fmt.Errorf("client_binary_path must be a file, not a directory: %s", path)
	}
	if info.Mode()&0o111 == 0 {
		return fmt.Errorf("ts3client binary is not executable: %s", path)
	}
	return nil
}
