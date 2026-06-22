package main

import (
	"bufio"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"sync"
)

const (
	adapterBackendPlaceholder   = "placeholder"
	adapterBackendNativeSDK     = "native_sdk"
	adapterBackendClientLibrary = "client_library"
	adapterBackendDisabled      = "disabled"
)

// ErrClientBackendNotAvailable is returned by stub adapters for all operations
// that require a real, allowed TeamSpeak client layer.
var ErrClientBackendNotAvailable = errors.New("TeamSpeak client backend not available: client_backend_required")

var ErrClientBackendDisabled = errors.New("TeamSpeak client backend disabled")

// connectParams holds the configuration supplied by the runtime in a connect request.
type connectParams struct {
	BackendType     string
	BackendPath     string
	Host            string
	Port            int
	Profile         string
	Nickname        string
	IdentityPath    string
	ChannelID       string
	ServerPassword  string // secret – must not appear in logs or error strings
	ChannelPassword string // secret – must not appear in logs or error strings
}

// adapterStatus is a point-in-time snapshot returned by TeamspeakClientAdapter.Status.
type adapterStatus struct {
	BackendType string
	Ready       bool
	State       string // "connected", "disconnected", "connecting", "error"
	ClientID    string
	ChannelID   string
	LastError   string
}

// TeamspeakClientAdapter is the interface a real TeamSpeak client layer must implement
// to be plugged into the bridge. Stub adapters satisfy the interface but never
// claim to be connected without a real, allowed client implementation.
//
// All methods receive a context.Context; implementations must respect cancellation.
// Secret values (ServerPassword, channel passwords) must never appear in error strings.
type TeamspeakClientAdapter interface {
	Connect(ctx context.Context, config connectParams) (clientID string, err error)
	Disconnect(ctx context.Context) error
	Reconnect(ctx context.Context) (clientID string, err error)
	Authenticate(ctx context.Context) error
	SetNickname(ctx context.Context, nickname string) error
	JoinChannel(ctx context.Context, channelID, password string) (actualChannelID string, err error)
	LeaveChannel(ctx context.Context) error
	SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error
	Status(ctx context.Context) (adapterStatus, error)
	Shutdown(ctx context.Context) error
}

// NewTeamspeakClientAdapter selects the bridge adapter for backendType. The
// native_sdk and client_library adapters delegate all operations to an
// admin-provided subprocess binary at backendPath via the bridge NDJSON protocol.
func NewTeamspeakClientAdapter(backendType, backendPath string) TeamspeakClientAdapter {
	switch normalizeBackendType(backendType) {
	case adapterBackendDisabled:
		return NewDisabledAdapter()
	case adapterBackendNativeSDK:
		return NewNativeSDKAdapter(backendPath)
	case adapterBackendClientLibrary:
		return NewClientLibraryAdapter(backendPath)
	default:
		return NewPlaceholderAdapter()
	}
}

func normalizeBackendType(backendType string) string {
	switch strings.ToLower(strings.TrimSpace(backendType)) {
	case "", adapterBackendPlaceholder:
		return adapterBackendPlaceholder
	case adapterBackendNativeSDK:
		return adapterBackendNativeSDK
	case adapterBackendClientLibrary:
		return adapterBackendClientLibrary
	case adapterBackendDisabled:
		return adapterBackendDisabled
	default:
		return adapterBackendPlaceholder
	}
}

// SelectingAdapter chooses the concrete adapter from each connect request. This
// lets the runtime pass backend_type/backend_path over the existing NDJSON bridge
// protocol without shelling out or starting unvalidated helpers.
type SelectingAdapter struct {
	current TeamspeakClientAdapter
}

func NewSelectingAdapter() *SelectingAdapter {
	return &SelectingAdapter{current: NewPlaceholderAdapter()}
}

func (a *SelectingAdapter) Connect(ctx context.Context, config connectParams) (string, error) {
	if a.current != nil {
		_ = a.current.Shutdown(ctx)
	}
	a.current = NewTeamspeakClientAdapter(config.BackendType, config.BackendPath)
	return a.current.Connect(ctx, config)
}
func (a *SelectingAdapter) Disconnect(ctx context.Context) error { return a.current.Disconnect(ctx) }
func (a *SelectingAdapter) Reconnect(ctx context.Context) (string, error) {
	return a.current.Reconnect(ctx)
}
func (a *SelectingAdapter) Authenticate(ctx context.Context) error {
	return a.current.Authenticate(ctx)
}
func (a *SelectingAdapter) SetNickname(ctx context.Context, nickname string) error {
	return a.current.SetNickname(ctx, nickname)
}
func (a *SelectingAdapter) JoinChannel(ctx context.Context, channelID, password string) (string, error) {
	return a.current.JoinChannel(ctx, channelID, password)
}
func (a *SelectingAdapter) LeaveChannel(ctx context.Context) error {
	return a.current.LeaveChannel(ctx)
}
func (a *SelectingAdapter) SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error {
	return a.current.SendOpusFrame(ctx, frame, durationMs)
}
func (a *SelectingAdapter) Status(ctx context.Context) (adapterStatus, error) {
	return a.current.Status(ctx)
}
func (a *SelectingAdapter) Shutdown(ctx context.Context) error { return a.current.Shutdown(ctx) }

// PlaceholderAdapter satisfies TeamspeakClientAdapter without connecting to any
// TeamSpeak server. Connect and all voice operations return ErrClientBackendNotAvailable.
// Replace this with a real implementation once a supported TeamSpeak client library
// is available.
//
// No reverse engineering, no SinusBot, no TS3AudioBot, no ServerQuery audio.
type PlaceholderAdapter struct{}

func NewPlaceholderAdapter() *PlaceholderAdapter { return &PlaceholderAdapter{} }
func (*PlaceholderAdapter) Connect(_ context.Context, _ connectParams) (string, error) {
	return "", ErrClientBackendNotAvailable
}
func (*PlaceholderAdapter) Disconnect(_ context.Context) error { return nil }
func (*PlaceholderAdapter) Reconnect(_ context.Context) (string, error) {
	return "", ErrClientBackendNotAvailable
}
func (*PlaceholderAdapter) Authenticate(_ context.Context) error { return ErrClientBackendNotAvailable }
func (*PlaceholderAdapter) JoinChannel(_ context.Context, _ string, _ string) (string, error) {
	return "", ErrClientBackendNotAvailable
}
func (*PlaceholderAdapter) LeaveChannel(_ context.Context) error          { return nil }
func (*PlaceholderAdapter) SetNickname(_ context.Context, _ string) error { return nil }
func (*PlaceholderAdapter) SendOpusFrame(_ context.Context, _ []byte, _ int) error {
	return ErrClientBackendNotAvailable
}
func (*PlaceholderAdapter) Status(_ context.Context) (adapterStatus, error) {
	return adapterStatus{BackendType: adapterBackendPlaceholder, State: stateDisconnected, Ready: false}, nil
}
func (*PlaceholderAdapter) Shutdown(_ context.Context) error { return nil }

type DisabledAdapter struct{}

func NewDisabledAdapter() *DisabledAdapter { return &DisabledAdapter{} }
func (*DisabledAdapter) Connect(_ context.Context, _ connectParams) (string, error) {
	return "", ErrClientBackendDisabled
}
func (*DisabledAdapter) Disconnect(_ context.Context) error { return nil }
func (*DisabledAdapter) Reconnect(_ context.Context) (string, error) {
	return "", ErrClientBackendDisabled
}
func (*DisabledAdapter) Authenticate(_ context.Context) error { return ErrClientBackendDisabled }
func (*DisabledAdapter) JoinChannel(_ context.Context, _ string, _ string) (string, error) {
	return "", ErrClientBackendDisabled
}
func (*DisabledAdapter) LeaveChannel(_ context.Context) error { return nil }
func (*DisabledAdapter) SetNickname(_ context.Context, _ string) error {
	return ErrClientBackendDisabled
}
func (*DisabledAdapter) SendOpusFrame(_ context.Context, _ []byte, _ int) error {
	return ErrClientBackendDisabled
}
func (*DisabledAdapter) Status(_ context.Context) (adapterStatus, error) {
	return adapterStatus{BackendType: adapterBackendDisabled, State: stateDisconnected, Ready: false}, nil
}
func (*DisabledAdapter) Shutdown(_ context.Context) error { return nil }

// processBackedAdapter manages an admin-provided client binary subprocess that speaks
// the bridge NDJSON protocol. The binary at backendPath is exec'd directly (no shell);
// it must be a regular, executable file that is not a known disallowed binary.
//
// Constraint: no automatic downloads. The binary must be installed by the administrator.
// Rejected binaries: sinusbot, ts3audiobot. No reverse engineering or ServerQuery audio.
type processBackedAdapter struct {
	backendPath string
	kind        string // "client_library" or "native_sdk"
	envKey      string // env var injected when spawning, e.g. "EASYWI_TS_CLIENT_LIB=1"

	mu         sync.Mutex
	cmd        *exec.Cmd
	stdin      io.WriteCloser
	scanner    *bufio.Scanner
	state      string
	clientID   string
	channelID  string
	lastParams connectParams
}

func newProcessBackedAdapter(backendPath, kind, envKey string) *processBackedAdapter {
	return &processBackedAdapter{
		backendPath: backendPath,
		kind:        kind,
		envKey:      envKey,
		state:       stateDisconnected,
	}
}

func (a *processBackedAdapter) Connect(ctx context.Context, params connectParams) (string, error) {
	if err := validateBackendPath(a.backendPath); err != nil {
		return "", fmt.Errorf("TeamSpeak %s backend unavailable: %w", a.kind, err)
	}
	if err := validateClientBinaryName(a.backendPath); err != nil {
		return "", fmt.Errorf("TeamSpeak %s backend unavailable: %w", a.kind, err)
	}

	a.stopProcess()

	cmd := exec.Command(a.backendPath)
	cmd.Env = append(os.Environ(), a.envKey)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return "", fmt.Errorf("TeamSpeak %s backend stdin pipe: %w", a.kind, err)
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		_ = stdin.Close()
		return "", fmt.Errorf("TeamSpeak %s backend stdout pipe: %w", a.kind, err)
	}
	if err := cmd.Start(); err != nil {
		_ = stdin.Close()
		return "", fmt.Errorf("TeamSpeak %s backend start: %w", a.kind, err)
	}

	a.mu.Lock()
	a.cmd = cmd
	a.stdin = stdin
	a.scanner = bufio.NewScanner(stdout)
	a.lastParams = params
	a.mu.Unlock()

	resp, err := a.roundTrip(ctx, bridgeRequest{
		Action:          "connect",
		BackendType:     params.BackendType,
		BackendPath:     params.BackendPath,
		Host:            params.Host,
		Port:            params.Port,
		Profile:         params.Profile,
		Nickname:        params.Nickname,
		IdentityPath:    params.IdentityPath,
		ChannelID:       params.ChannelID,
		ServerPassword:  params.ServerPassword,
		ChannelPassword: params.ChannelPassword,
	})
	if err != nil {
		a.stopProcess()
		a.mu.Lock()
		a.state = stateDisconnected
		a.clientID = ""
		a.mu.Unlock()
		return "", err
	}

	a.mu.Lock()
	a.state = stateConnected
	a.clientID = resp.ClientID
	a.mu.Unlock()
	return resp.ClientID, nil
}

func (a *processBackedAdapter) Disconnect(ctx context.Context) error {
	if a.isRunning() {
		_, _ = a.roundTrip(context.Background(), bridgeRequest{Action: "disconnect"})
	}
	a.stopProcess()
	a.mu.Lock()
	a.state = stateDisconnected
	a.clientID = ""
	a.channelID = ""
	a.mu.Unlock()
	return nil
}

func (a *processBackedAdapter) Reconnect(ctx context.Context) (string, error) {
	a.mu.Lock()
	hasBridge := a.stdin != nil && a.scanner != nil
	lastParams := a.lastParams
	a.mu.Unlock()

	if hasBridge {
		resp, err := a.roundTrip(ctx, bridgeRequest{Action: "reconnect"})
		if err == nil {
			a.mu.Lock()
			a.state = stateConnected
			if resp.ClientID != "" {
				a.clientID = resp.ClientID
			}
			cid := a.clientID
			a.mu.Unlock()
			return cid, nil
		}
	}
	_ = a.Disconnect(ctx)
	return a.Connect(ctx, lastParams)
}

func (a *processBackedAdapter) Authenticate(ctx context.Context) error {
	_, err := a.roundTrip(ctx, bridgeRequest{Action: "status"})
	return err
}

func (a *processBackedAdapter) SetNickname(ctx context.Context, nickname string) error {
	_, err := a.roundTrip(ctx, bridgeRequest{Action: "set_nickname", Nickname: nickname})
	return err
}

func (a *processBackedAdapter) JoinChannel(ctx context.Context, channelID, password string) (string, error) {
	resp, err := a.roundTrip(ctx, bridgeRequest{Action: "join_channel", ChannelID: channelID, ChannelPassword: password})
	if err != nil {
		return "", err
	}
	actual := resp.ChannelID
	if actual == "" {
		actual = channelID
	}
	a.mu.Lock()
	a.channelID = actual
	a.mu.Unlock()
	return actual, nil
}

func (a *processBackedAdapter) LeaveChannel(ctx context.Context) error {
	_, err := a.roundTrip(ctx, bridgeRequest{Action: "leave_channel"})
	if err != nil {
		return err
	}
	a.mu.Lock()
	a.channelID = ""
	a.mu.Unlock()
	return nil
}

func (a *processBackedAdapter) SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error {
	_, err := a.roundTrip(ctx, bridgeRequest{
		Action:     "send_opus_frame",
		Format:     "opus",
		Payload:    base64.StdEncoding.EncodeToString(frame),
		DurationMs: durationMs,
	})
	return err
}

func (a *processBackedAdapter) Status(ctx context.Context) (adapterStatus, error) {
	a.mu.Lock()
	running := a.stdin != nil
	state := a.state
	clientID := a.clientID
	channelID := a.channelID
	a.mu.Unlock()

	if !running {
		return adapterStatus{
			BackendType: a.kind,
			State:       stateDisconnected,
			Ready:       false,
		}, nil
	}

	resp, err := a.roundTrip(ctx, bridgeRequest{Action: "status"})
	if err != nil {
		return adapterStatus{
			BackendType: a.kind,
			State:       state,
			Ready:       false,
			LastError:   err.Error(),
		}, nil
	}

	s := resp.State
	if s == "" {
		s = state
	}
	cid := resp.ClientID
	if cid == "" {
		cid = clientID
	}
	chid := resp.ChannelID
	if chid == "" {
		chid = channelID
	}
	return adapterStatus{
		BackendType: a.kind,
		Ready:       resp.Ready && s == stateConnected,
		State:       s,
		ClientID:    cid,
		ChannelID:   chid,
	}, nil
}

func (a *processBackedAdapter) Shutdown(ctx context.Context) error {
	if a.isRunning() {
		_, _ = a.roundTrip(context.Background(), bridgeRequest{Action: "shutdown"})
	}
	a.stopProcess()
	a.mu.Lock()
	a.state = stateDisconnected
	a.clientID = ""
	a.channelID = ""
	a.mu.Unlock()
	return nil
}

func (a *processBackedAdapter) isRunning() bool {
	a.mu.Lock()
	defer a.mu.Unlock()
	return a.stdin != nil
}

func (a *processBackedAdapter) stopProcess() {
	a.mu.Lock()
	cmd := a.cmd
	stdin := a.stdin
	a.cmd = nil
	a.stdin = nil
	a.scanner = nil
	a.mu.Unlock()
	if stdin != nil {
		_ = stdin.Close()
	}
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
}

func (a *processBackedAdapter) roundTrip(ctx context.Context, req bridgeRequest) (bridgeResponse, error) {
	if err := ctx.Err(); err != nil {
		return bridgeResponse{}, err
	}
	a.mu.Lock()
	defer a.mu.Unlock()
	if a.stdin == nil || a.scanner == nil {
		return bridgeResponse{}, fmt.Errorf("TeamSpeak %s backend not connected", a.kind)
	}
	encoded, err := json.Marshal(req)
	if err != nil {
		return bridgeResponse{}, fmt.Errorf("TeamSpeak %s backend encode: %w", a.kind, err)
	}
	if _, err := a.stdin.Write(append(encoded, '\n')); err != nil {
		return bridgeResponse{}, fmt.Errorf("TeamSpeak %s backend write: %w", a.kind, err)
	}
	if !a.scanner.Scan() {
		if scanErr := a.scanner.Err(); scanErr != nil {
			return bridgeResponse{}, fmt.Errorf("TeamSpeak %s backend read: %w", a.kind, scanErr)
		}
		return bridgeResponse{}, fmt.Errorf("TeamSpeak %s backend closed", a.kind)
	}
	var resp bridgeResponse
	if err := json.Unmarshal(a.scanner.Bytes(), &resp); err != nil {
		return bridgeResponse{}, fmt.Errorf("TeamSpeak %s backend response: %w", a.kind, err)
	}
	if !resp.OK {
		if resp.Error == "" {
			resp.Error = fmt.Sprintf("TeamSpeak %s backend command failed", a.kind)
		}
		return resp, errors.New(resp.Error)
	}
	if resp.State == stateConnected {
		a.state = stateConnected
	}
	if resp.ClientID != "" {
		a.clientID = resp.ClientID
	}
	return resp, nil
}

// NativeSDKAdapter delegates all TeamSpeak operations to an admin-provided native
// SDK helper binary at backendPath. The binary must speak the bridge NDJSON protocol.
// The environment variable EASYWI_TS_NATIVE_SDK=1 is injected when spawning.
//
// No automatic download. No SinusBot. No TS3AudioBot. No reverse engineering.
type NativeSDKAdapter struct {
	proc *processBackedAdapter
}

func NewNativeSDKAdapter(backendPath string) *NativeSDKAdapter {
	return &NativeSDKAdapter{
		proc: newProcessBackedAdapter(backendPath, adapterBackendNativeSDK, "EASYWI_TS_NATIVE_SDK=1"),
	}
}

func (a *NativeSDKAdapter) Connect(ctx context.Context, params connectParams) (string, error) {
	return a.proc.Connect(ctx, params)
}
func (a *NativeSDKAdapter) Disconnect(ctx context.Context) error { return a.proc.Disconnect(ctx) }
func (a *NativeSDKAdapter) Reconnect(ctx context.Context) (string, error) {
	return a.proc.Reconnect(ctx)
}
func (a *NativeSDKAdapter) Authenticate(ctx context.Context) error { return a.proc.Authenticate(ctx) }
func (a *NativeSDKAdapter) SetNickname(ctx context.Context, nickname string) error {
	return a.proc.SetNickname(ctx, nickname)
}
func (a *NativeSDKAdapter) JoinChannel(ctx context.Context, channelID, password string) (string, error) {
	return a.proc.JoinChannel(ctx, channelID, password)
}
func (a *NativeSDKAdapter) LeaveChannel(ctx context.Context) error { return a.proc.LeaveChannel(ctx) }
func (a *NativeSDKAdapter) SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error {
	return a.proc.SendOpusFrame(ctx, frame, durationMs)
}
func (a *NativeSDKAdapter) Status(ctx context.Context) (adapterStatus, error) {
	return a.proc.Status(ctx)
}
func (a *NativeSDKAdapter) Shutdown(ctx context.Context) error { return a.proc.Shutdown(ctx) }

// ClientLibraryAdapter delegates all TeamSpeak operations to an admin-provided client
// library helper binary at backendPath. The binary must speak the bridge NDJSON protocol.
// The environment variable EASYWI_TS_CLIENT_LIB=1 is injected when spawning.
//
// No automatic download. No SinusBot. No TS3AudioBot. No reverse engineering.
type ClientLibraryAdapter struct {
	proc *processBackedAdapter
}

func NewClientLibraryAdapter(backendPath string) *ClientLibraryAdapter {
	return &ClientLibraryAdapter{
		proc: newProcessBackedAdapter(backendPath, adapterBackendClientLibrary, "EASYWI_TS_CLIENT_LIB=1"),
	}
}

func (a *ClientLibraryAdapter) Connect(ctx context.Context, params connectParams) (string, error) {
	return a.proc.Connect(ctx, params)
}
func (a *ClientLibraryAdapter) Disconnect(ctx context.Context) error {
	return a.proc.Disconnect(ctx)
}
func (a *ClientLibraryAdapter) Reconnect(ctx context.Context) (string, error) {
	return a.proc.Reconnect(ctx)
}
func (a *ClientLibraryAdapter) Authenticate(ctx context.Context) error {
	return a.proc.Authenticate(ctx)
}
func (a *ClientLibraryAdapter) SetNickname(ctx context.Context, nickname string) error {
	return a.proc.SetNickname(ctx, nickname)
}
func (a *ClientLibraryAdapter) JoinChannel(ctx context.Context, channelID, password string) (string, error) {
	return a.proc.JoinChannel(ctx, channelID, password)
}
func (a *ClientLibraryAdapter) LeaveChannel(ctx context.Context) error {
	return a.proc.LeaveChannel(ctx)
}
func (a *ClientLibraryAdapter) SendOpusFrame(ctx context.Context, frame []byte, durationMs int) error {
	return a.proc.SendOpusFrame(ctx, frame, durationMs)
}
func (a *ClientLibraryAdapter) Status(ctx context.Context) (adapterStatus, error) {
	return a.proc.Status(ctx)
}
func (a *ClientLibraryAdapter) Shutdown(ctx context.Context) error { return a.proc.Shutdown(ctx) }

// validateBackendPath checks that path is absolute, not a symlink, exists, is a
// regular file or directory, and (for regular files) is executable.
func validateBackendPath(path string) error {
	path = strings.TrimSpace(path)
	if path == "" {
		return errors.New("backend_path is required")
	}
	clean := filepath.Clean(path)
	if !filepath.IsAbs(clean) {
		return errors.New("backend_path must be absolute")
	}
	linkInfo, err := os.Lstat(clean)
	if err != nil {
		if errors.Is(err, os.ErrNotExist) {
			return errors.New("backend_path does not exist")
		}
		return fmt.Errorf("backend_path cannot be inspected: %w", err)
	}
	if linkInfo.Mode()&os.ModeSymlink != 0 {
		return errors.New("backend_path must not be a symlink")
	}
	info, err := os.Stat(clean)
	if err != nil {
		return fmt.Errorf("backend_path cannot be inspected: %w", err)
	}
	if !info.IsDir() && !info.Mode().IsRegular() {
		return errors.New("backend_path must be a regular file or directory")
	}
	if info.Mode().IsRegular() && info.Mode()&0o111 == 0 {
		return errors.New("backend_path is not executable")
	}
	return nil
}

// validateClientBinaryName rejects known disallowed binaries by base name.
// SinusBot and TS3AudioBot are third-party musicbot platforms that must not be
// used as a TeamSpeak client layer in this bridge.
func validateClientBinaryName(path string) error {
	base := strings.ToLower(filepath.Base(path))
	if strings.Contains(base, "sinusbot") || strings.Contains(base, "ts3audiobot") {
		return errors.New("unsupported client binary: sinusbot and ts3audiobot are not allowed")
	}
	return nil
}
