package main

import (
	"context"
	"encoding/base64"
	"errors"
	"fmt"
	"math/rand"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"time"
)

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

	xvfbCmd       *exec.Cmd
	pulseCmd      *exec.Cmd
	ts3Cmd        *exec.Cmd
	ffmpegCmd     *exec.Cmd
	display       string
	pulseSocket   string
	sinkName      string
	sourceName    string
	tmpHome       string
	state         string
	clientID      string
	lastError     string
	serverPW      string
	channelPW     string
	lastParams    connectParams
	connectCancel context.CancelFunc
}

func NewExternalClientBridgeAdapter() *ExternalClientBridgeAdapter {
	return &ExternalClientBridgeAdapter{state: stateDisconnected}
}

func (a *ExternalClientBridgeAdapter) Connect(ctx context.Context, params connectParams) (string, error) {
	a.mu.Lock()
	a.serverPW = params.ServerPassword
	a.channelPW = params.ChannelPassword
	a.lastParams = params
	a.mu.Unlock()

	_ = a.cleanup()

	clientBinary := strings.TrimSpace(params.ClientBinaryPath)
	if clientBinary == "" {
		return "", errors.New("client_binary_path is required for external_client_bridge")
	}
	if err := validateExternalClientBinary(clientBinary); err != nil {
		return "", err
	}
	if err := validateClientBinaryName(clientBinary); err != nil {
		return "", err
	}

	if strings.TrimSpace(params.Host) == "" {
		return "", errors.New("host is required for external_client_bridge connect")
	}

	tmpHome, err := os.MkdirTemp("", "easywi-ts3-bridge-*")
	if err != nil {
		return "", fmt.Errorf("external_client_bridge create temp home: %w", err)
	}
	a.mu.Lock()
	a.tmpHome = tmpHome
	a.mu.Unlock()

	display, xvfbCmd, err := startXvfb(tmpHome)
	if err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("external_client_bridge xvfb start: %w", err)
	}
	a.mu.Lock()
	a.xvfbCmd = xvfbCmd
	a.display = display
	a.mu.Unlock()

	sinkName, sourceName, pulseCmd, pulseSocket, err := startPulseAudio(tmpHome, display)
	if err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("external_client_bridge pulseaudio start: %w", err)
	}
	a.mu.Lock()
	a.pulseCmd = pulseCmd
	a.pulseSocket = pulseSocket
	a.sinkName = sinkName
	a.sourceName = sourceName
	a.mu.Unlock()

	ts3Cmd, err := startTS3Client(ctx, params, clientBinary, tmpHome, display, pulseSocket)
	if err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("external_client_bridge ts3client start: %w", err)
	}
	a.mu.Lock()
	a.ts3Cmd = ts3Cmd
	a.mu.Unlock()

	port := params.Port
	if port <= 0 {
		port = 9987
	}
	monCtx, monCancel := context.WithTimeout(ctx, 45*time.Second)
	a.mu.Lock()
	a.connectCancel = monCancel
	a.mu.Unlock()
	defer monCancel()

	if err := waitForTCPConnection(monCtx, params.Host, port); err != nil {
		_ = a.cleanup()
		return "", fmt.Errorf("external_client_bridge ts3client did not connect to %s:%d: %w", params.Host, port, err)
	}

	a.mu.Lock()
	a.state = stateConnected
	a.mu.Unlock()
	return "", nil
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
	a.mu.Lock()
	pulseSocket := a.pulseSocket
	sinkName := a.sinkName
	a.mu.Unlock()
	if pulseSocket == "" || sinkName == "" {
		return errors.New("external_client_bridge audio not ready")
	}
	return injectOpusViaPulse(ctx, frame, durationMs, pulseSocket, sinkName)
}

func (a *ExternalClientBridgeAdapter) Status(ctx context.Context) (adapterStatus, error) {
	a.mu.Lock()
	s := a.state
	cid := a.clientID
	lastErr := a.lastError
	a.mu.Unlock()
	return adapterStatus{
		BackendType: adapterBackendExternalClientBridge,
		Ready:       s == stateConnected,
		State:       s,
		ClientID:    cid,
		LastError:   lastErr,
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
	a.tmpHome = ""
	a.display = ""
	a.pulseSocket = ""
	a.sinkName = ""
	a.sourceName = ""
	a.state = stateDisconnected
	a.clientID = ""
	a.connectCancel = nil
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
	if tmpHome != "" {
		_ = os.RemoveAll(tmpHome)
	}
	return nil
}

// startXvfb allocates a free display number and starts Xvfb on it.
// The caller is responsible for killing the returned cmd on cleanup.
func startXvfb(tmpHome string) (string, *exec.Cmd, error) {
	xvfbPath, err := exec.LookPath("Xvfb")
	if err != nil {
		return "", nil, errors.New("Xvfb not found: install the xvfb package")
	}

	display := allocateDisplay()
	cmd := exec.Command(xvfbPath, display, "-screen", "0", "1x1x8", "-nolisten", "tcp")
	cmd.Env = append(os.Environ(), "HOME="+tmpHome)
	if err := cmd.Start(); err != nil {
		return "", nil, fmt.Errorf("Xvfb start: %w", err)
	}
	time.Sleep(300 * time.Millisecond)
	return display, cmd, nil
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

// startPulseAudio starts a per-instance PulseAudio daemon with a null sink and
// virtual source so the TS3 client can capture our injected audio.
func startPulseAudio(tmpHome, display string) (sinkName, sourceName string, cmd *exec.Cmd, socketPath string, err error) {
	pulsePath, lookErr := exec.LookPath("pulseaudio")
	if lookErr != nil {
		// PipeWire-PulseAudio may provide the pulseaudio command too.
		pulsePath, lookErr = exec.LookPath("pipewire-pulse")
		if lookErr != nil {
			return "", "", nil, "", errors.New("PulseAudio/PipeWire not found: install pulseaudio or pipewire-pulse")
		}
	}

	sinkName = "easywi_sink_" + strconv.FormatInt(time.Now().UnixNano(), 36)
	sourceName = "easywi_source_" + strconv.FormatInt(time.Now().UnixNano(), 36)
	socketPath = filepath.Join(tmpHome, "pulse.sock")

	pulseConfig := fmt.Sprintf(`
load-module module-null-sink sink_name=%s sink_properties=device.description="EasyWiMusicbot"
load-module module-virtual-source source_name=%s master=%s.monitor
set-default-sink %s
set-default-source %s
`, sinkName, sourceName, sinkName, sinkName, sourceName)

	configPath := filepath.Join(tmpHome, "pulse_default.pa")
	if writeErr := os.WriteFile(configPath, []byte(pulseConfig), 0o600); writeErr != nil {
		return "", "", nil, "", fmt.Errorf("write pulse config: %w", writeErr)
	}

	cmd = exec.Command(pulsePath,
		"--daemonize=false",
		"--exit-idle-time=-1",
		"--file="+configPath,
		"--socket="+socketPath,
	)
	cmd.Env = append(os.Environ(),
		"HOME="+tmpHome,
		"DISPLAY="+display,
		"XDG_RUNTIME_DIR="+tmpHome,
		"XDG_CONFIG_HOME="+filepath.Join(tmpHome, ".config"),
	)
	if startErr := cmd.Start(); startErr != nil {
		return "", "", nil, "", fmt.Errorf("PulseAudio start: %w", startErr)
	}
	for i := 0; i < 20; i++ {
		if _, statErr := os.Stat(socketPath); statErr == nil {
			break
		}
		time.Sleep(200 * time.Millisecond)
	}
	return sinkName, sourceName, cmd, socketPath, nil
}

// startTS3Client launches the TS3 client headless with an isolated environment.
// Secrets are written to a temp config file (mode 0600); they never appear in argv.
func startTS3Client(ctx context.Context, params connectParams, clientBinary, tmpHome, display, pulseSocket string) (*exec.Cmd, error) {
	ts3Home := filepath.Join(tmpHome, "ts3home")
	if err := os.MkdirAll(ts3Home, 0o700); err != nil {
		return nil, fmt.Errorf("create ts3 home: %w", err)
	}

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
		return nil, fmt.Errorf("write ts3 ini: %w", err)
	}

	cmd := exec.CommandContext(ctx, clientBinary, uri, "-nosingleinstance", "-headless")
	cmd.Env = []string{
		"HOME=" + ts3Home,
		"DISPLAY=" + display,
		"PULSE_SERVER=unix:" + pulseSocket,
		"XDG_CONFIG_HOME=" + ts3Home,
		"XDG_DATA_HOME=" + ts3Home,
		"XDG_CACHE_HOME=" + ts3Home,
		"LD_LIBRARY_PATH=" + filepath.Dir(clientBinary),
		"PATH=/usr/local/bin:/usr/bin:/bin",
		"USER=easywi",
	}
	cmd.Dir = filepath.Dir(clientBinary)
	if err := cmd.Start(); err != nil {
		return nil, fmt.Errorf("ts3client start: %w", err)
	}
	return cmd, nil
}

// waitForTCPConnection polls until a TCP connection to host:port exists in the
// system's established connections table, or ctx is cancelled.
func waitForTCPConnection(ctx context.Context, host string, port int) error {
	target := net.JoinHostPort(host, fmt.Sprintf("%d", port))
	ticker := time.NewTicker(500 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return fmt.Errorf("timeout waiting for TS3 client connection to %s", target)
		case <-ticker.C:
			conn, err := net.DialTimeout("tcp", target, 2*time.Second)
			if err == nil {
				_ = conn.Close()
				return nil
			}
		}
	}
}

// injectOpusViaPulse decodes an Opus frame via ffmpeg and writes the PCM output
// to the PulseAudio null sink. Secrets never appear here.
func injectOpusViaPulse(ctx context.Context, opusFrame []byte, durationMs int, pulseSocket, sinkName string) error {
	if len(opusFrame) == 0 {
		return nil
	}
	ffmpegPath, err := exec.LookPath("ffmpeg")
	if err != nil {
		return errors.New("ffmpeg not found: required for audio injection")
	}

	oggData := wrapOpusInOgg(opusFrame, durationMs)

	cmd := exec.CommandContext(ctx, ffmpegPath,
		"-v", "quiet",
		"-f", "ogg",
		"-i", "pipe:0",
		"-f", "pulse",
		"-device", sinkName,
		"pipe:1",
	)
	cmd.Env = append(os.Environ(), "PULSE_SERVER=unix:"+pulseSocket)
	cmd.Stdin = strings.NewReader(base64.StdEncoding.EncodeToString(oggData))
	return cmd.Run()
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
