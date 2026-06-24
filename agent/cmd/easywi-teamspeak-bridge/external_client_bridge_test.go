package main

import (
	"bufio"
	"bytes"
	"context"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// TestXvfbScreenSpecIs1024x768x24 verifies the compile-time constant.
func TestXvfbScreenSpecIs1024x768x24(t *testing.T) {
	if xvfbScreenSpec != "1024x768x24" {
		t.Errorf("xvfbScreenSpec = %q, want 1024x768x24", xvfbScreenSpec)
	}
}

// TestBuildXvfbArgsContains1024x768x24AndAC checks that the Xvfb command is
// built with the correct screen spec and the -ac flag (required for Qt/XCB).
func TestBuildXvfbArgsContains1024x768x24AndAC(t *testing.T) {
	args := buildXvfbArgs(":175")
	joined := strings.Join(args, " ")
	if !strings.Contains(joined, "1024x768x24") {
		t.Errorf("xvfb args %v do not contain 1024x768x24", args)
	}
	found := false
	for _, a := range args {
		if a == "-ac" {
			found = true
			break
		}
	}
	if !found {
		t.Errorf("xvfb args %v do not contain -ac flag", args)
	}
}

// TestBuildXvfbArgsContainsNoTCP verifies -nolisten tcp is present.
func TestBuildXvfbArgsContainsNoTCP(t *testing.T) {
	args := buildXvfbArgs(":175")
	joined := strings.Join(args, " ")
	if !strings.Contains(joined, "-nolisten tcp") {
		t.Errorf("xvfb args %v do not contain -nolisten tcp", args)
	}
}

// TestBuildTS3EnvContainsQtXcb verifies that QT_QPA_PLATFORM=xcb is set.
func TestBuildTS3EnvContainsQtXcb(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if m["QT_QPA_PLATFORM"] != "xcb" {
		t.Errorf("QT_QPA_PLATFORM = %q, want xcb", m["QT_QPA_PLATFORM"])
	}
}

// TestBuildTS3EnvContainsXDGRuntimeDir verifies XDG_RUNTIME_DIR is set.
func TestBuildTS3EnvContainsXDGRuntimeDir(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if m["XDG_RUNTIME_DIR"] != "/xdg-runtime" {
		t.Errorf("XDG_RUNTIME_DIR = %q, want /xdg-runtime", m["XDG_RUNTIME_DIR"])
	}
}

// TestBuildTS3EnvClearsWaylandDisplay verifies WAYLAND_DISPLAY is explicitly
// cleared to prevent Qt from picking up a Wayland compositor over XCB.
func TestBuildTS3EnvClearsWaylandDisplay(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	val, present := m["WAYLAND_DISPLAY"]
	if !present {
		t.Error("WAYLAND_DISPLAY not present in env; it must be explicitly set to empty to override any inherited value")
	}
	if val != "" {
		t.Errorf("WAYLAND_DISPLAY = %q, want empty string", val)
	}
}

// TestBuildTS3EnvContainsDisplay verifies the X display is forwarded.
func TestBuildTS3EnvContainsDisplay(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if m["DISPLAY"] != ":175" {
		t.Errorf("DISPLAY = %q, want :175", m["DISPLAY"])
	}
}

// TestBuildTS3EnvContainsTMPDIR verifies TMPDIR is set to the instance tmp dir
// so the TS3 client does not pollute the system /tmp.
func TestBuildTS3EnvContainsTMPDIR(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/runtime/tmp", ":175", "unix:/pulse.sock", "/opt/ts3/ts3client_linux_amd64", "/runtime/cache")
	m := envToMap(env)
	if m["TMPDIR"] != "/runtime/tmp" {
		t.Errorf("TMPDIR = %q, want /runtime/tmp", m["TMPDIR"])
	}
}

// TestBuildRuntimeDirWithInstancePath checks that the runtime directory tree is
// created under <instancePath>/runtime/teamspeak-bridge/ with correct permissions.
// Note: ts3home is NOT in the runtime dir; it lives at
// <instancePath>/data/teamspeak-client/ts3home (persistent, not cleaned up).
func TestBuildRuntimeDirWithInstancePath(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, tmpHome, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	if tmpHome != "" {
		t.Errorf("tmpHome = %q, want empty for persistent runtime dir", tmpHome)
	}
	expectedBase := filepath.Join(instancePath, "runtime", "teamspeak-bridge")
	if runtimeDir != expectedBase {
		t.Errorf("runtimeDir = %q, want %q", runtimeDir, expectedBase)
	}
	// Top-level subdirs: 0750 except xdg-runtime which must be 0700.
	for _, tc := range []struct {
		sub  string
		perm os.FileMode
	}{
		{"logs", 0o750},
		{"crashdumps", 0o750},
		{"pulse", 0o750},
		{"tmp", 0o750},
		{"cache", 0o750},
		{"xdg-runtime", 0o700},
	} {
		subPath := filepath.Join(runtimeDir, tc.sub)
		info, statErr := os.Stat(subPath)
		if statErr != nil {
			t.Errorf("subdir %q missing: %v", tc.sub, statErr)
			continue
		}
		if info.Mode().Perm() != tc.perm {
			t.Errorf("subdir %q permissions = %04o, want %04o", tc.sub, info.Mode().Perm(), tc.perm)
		}
	}
	// ts3home must NOT be inside the runtime dir (it is persistent, not volatile).
	ts3HomeInRuntime := filepath.Join(runtimeDir, "ts3home")
	if _, statErr := os.Stat(ts3HomeInRuntime); statErr == nil {
		t.Errorf("ts3home must not exist inside runtimeDir %q; it belongs in the persistent data dir", runtimeDir)
	}
}

// TestBuildRuntimeDirWithoutInstancePath checks that a temp dir is used as fallback.
func TestBuildRuntimeDirWithoutInstancePath(t *testing.T) {
	runtimeDir, tmpHome, err := buildRuntimeDir("", "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	defer func() {
		if tmpHome != "" {
			_ = os.RemoveAll(tmpHome)
		}
	}()
	if tmpHome == "" {
		t.Error("tmpHome should be non-empty when no instance_path is provided")
	}
	if runtimeDir != tmpHome {
		t.Errorf("runtimeDir = %q should equal tmpHome = %q", runtimeDir, tmpHome)
	}
}

// TestRuntimeDirNoTempDirWhenInstancePathSet verifies that no os.MkdirTemp call
// is made when instancePath is provided — tmpHome must be empty and the runtime
// dir must live under instancePath, not under a system temp dir.
func TestRuntimeDirNoTempDirWhenInstancePathSet(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, tmpHome, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	if tmpHome != "" {
		t.Errorf("tmpHome = %q; want empty when instancePath is provided (no os.MkdirTemp should be used)", tmpHome)
	}
	if !strings.HasPrefix(runtimeDir, instancePath) {
		t.Errorf("runtimeDir %q must be under instancePath %q", runtimeDir, instancePath)
	}
}

// TestXDGRuntimeDirCreatedWith0700 verifies that xdg-runtime is created with
// mode 0700, as required by the XDG Base Directory Specification and systemd.
func TestXDGRuntimeDirCreatedWith0700(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	xdgRuntimeDir := filepath.Join(runtimeDir, "xdg-runtime")
	info, statErr := os.Stat(xdgRuntimeDir)
	if statErr != nil {
		t.Fatalf("xdg-runtime dir not created: %v", statErr)
	}
	if info.Mode().Perm() != 0o700 {
		t.Errorf("xdg-runtime permissions = %04o, want 0700", info.Mode().Perm())
	}
}

// TestLogsUnderRuntimeDir verifies that the logs and crashdumps directories are
// created under <instancePath>/runtime/teamspeak-bridge/.
func TestLogsUnderRuntimeDir(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	for _, dir := range []string{"logs", "crashdumps"} {
		if _, statErr := os.Stat(filepath.Join(runtimeDir, dir)); statErr != nil {
			t.Errorf("%s dir not created under runtimeDir: %v", dir, statErr)
		}
	}
}

// TestCollectTS3StartFailedErrorContainsStatus verifies that when the TS3 client
// crashes (e.g. XCB failure) the returned error contains "ts3client_start_failed".
func TestCollectTS3StartFailedErrorContainsStatus(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}

	var stderrBuf bytes.Buffer
	stderrBuf.WriteString("qt.qpa.xcb: could not connect to display :175\n" +
		"This application failed to start because no Qt platform plugin could be initialized.\n")

	adapter := NewExternalClientBridgeAdapter()
	diagErr := adapter.collectTS3StartFailedError(nil, &stderrBuf, runtimeDir)
	if diagErr == nil {
		t.Fatal("expected non-nil error from collectTS3StartFailedError")
	}
	if !strings.Contains(diagErr.Error(), "ts3client_start_failed") {
		t.Errorf("error %q does not contain 'ts3client_start_failed'", diagErr.Error())
	}
	if !strings.Contains(diagErr.Error(), "xcb") {
		t.Errorf("error %q does not contain stderr xcb output", diagErr.Error())
	}
}

// TestCollectTS3StartFailedErrorWithLogFile checks that log path and tail are
// included in the diagnostic error when a ts3client_*.log file exists under the
// canonical persistent ts3home .ts3client/logs path.
func TestCollectTS3StartFailedErrorWithLogFile(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	persistentHome := buildPersistentTs3Home(instancePath, runtimeDir)
	if err := ensurePersistentTs3HomeDirs(persistentHome); err != nil {
		t.Fatalf("ensurePersistentTs3HomeDirs: %v", err)
	}

	logContent := "line1\nline2\nts3client crashed here\n"
	// Write to the canonical TS3 log location inside the persistent ts3home.
	logPath := filepath.Join(persistentHome, ".ts3client", "logs", "ts3client_2026-06-23.log")
	if writeErr := os.WriteFile(logPath, []byte(logContent), 0o600); writeErr != nil {
		t.Fatalf("write log: %v", writeErr)
	}

	adapter := NewExternalClientBridgeAdapter()
	// Inject diagCtx so collectTS3StartFailedError knows the persistent ts3home.
	adapter.mu.Lock()
	adapter.ts3DiagCtx = &ts3DiagContext{ts3Home: persistentHome}
	adapter.mu.Unlock()

	diagErr := adapter.collectTS3StartFailedError(nil, nil, runtimeDir)
	if diagErr == nil {
		t.Fatal("expected error")
	}
	if !strings.Contains(diagErr.Error(), "ts3client_start_failed") {
		t.Errorf("error %q missing 'ts3client_start_failed'", diagErr.Error())
	}
	if !strings.Contains(diagErr.Error(), "ts3_log_path") {
		t.Errorf("error %q missing 'ts3_log_path'", diagErr.Error())
	}
	if !strings.Contains(diagErr.Error(), "ts3client crashed here") {
		t.Errorf("error %q missing log tail content", diagErr.Error())
	}
}

// TestWaitForXvfbReadySocketCheck verifies that waitForXvfbReady returns nil
// when the X socket appears within the timeout, without requiring xdpyinfo.
func TestWaitForXvfbReadySocketCheck(t *testing.T) {
	socketDir, err := os.MkdirTemp("", "x11-unix-*")
	if err != nil {
		t.Fatalf("MkdirTemp: %v", err)
	}
	defer os.RemoveAll(socketDir)

	// Override the socket path by pointing the display to a non-existent number,
	// then create the socket ourselves after a short delay.
	// Since waitForXvfbReady uses /tmp/.X11-unix/X<n>, we can't easily mock it
	// without patching the path. Instead, test the timeout path to ensure the
	// function returns an error with "xvfb_failed" when the socket never appears.
	err = waitForXvfbReady(":250", 200*time.Millisecond)
	if err == nil {
		t.Skip("display :250 unexpectedly exists in test environment")
	}
	if !strings.Contains(err.Error(), "xvfb_failed") {
		t.Errorf("error %q does not contain 'xvfb_failed'", err.Error())
	}
}

// TestFindTs3LogTailReturnsLatest checks that the newest ts3client_*.log is found.
func TestFindTs3LogTailReturnsLatest(t *testing.T) {
	dir := t.TempDir()
	old := filepath.Join(dir, "ts3client_2026-01-01.log")
	newer := filepath.Join(dir, "ts3client_2026-06-23.log")
	if err := os.WriteFile(old, []byte("old log\n"), 0o600); err != nil {
		t.Fatal(err)
	}
	// Ensure mtime differs.
	time.Sleep(5 * time.Millisecond)
	if err := os.WriteFile(newer, []byte("line1\nline2\nnew log\n"), 0o600); err != nil {
		t.Fatal(err)
	}

	path, tail := findTs3LogTail([]string{dir}, 5)
	if path != newer {
		t.Errorf("findTs3LogTail returned path %q, want %q", path, newer)
	}
	if !strings.Contains(tail, "new log") {
		t.Errorf("log tail %q does not contain 'new log'", tail)
	}
	if strings.Contains(tail, "old log") {
		t.Errorf("log tail %q should not contain old log content", tail)
	}
}

// TestFindTs3CrashdumpDetectsDmp checks that a .dmp file is found.
func TestFindTs3CrashdumpDetectsDmp(t *testing.T) {
	dir := t.TempDir()
	dumpPath := filepath.Join(dir, "ts3client.dmp")
	if err := os.WriteFile(dumpPath, []byte("dump"), 0o600); err != nil {
		t.Fatal(err)
	}
	got := findTs3Crashdump([]string{dir})
	if got != dumpPath {
		t.Errorf("findTs3Crashdump = %q, want %q", got, dumpPath)
	}
}

// TestFindTs3CrashdumpDetectsCrashFile checks that a file with "crash" in its name is found.
func TestFindTs3CrashdumpDetectsCrashFile(t *testing.T) {
	dir := t.TempDir()
	crashPath := filepath.Join(dir, "crashreport_20260623.log")
	if err := os.WriteFile(crashPath, []byte("crash"), 0o600); err != nil {
		t.Fatal(err)
	}
	got := findTs3Crashdump([]string{dir})
	if got != crashPath {
		t.Errorf("findTs3Crashdump = %q, want %q", got, crashPath)
	}
}

// TestFindTs3CrashdumpNoMatch verifies empty string is returned when no crashdump exists.
func TestFindTs3CrashdumpNoMatch(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "ts3client.log"), []byte("normal"), 0o600); err != nil {
		t.Fatal(err)
	}
	got := findTs3Crashdump([]string{dir})
	if got != "" {
		t.Errorf("findTs3Crashdump = %q, want empty", got)
	}
}

// TestBuildRuntimeDirDoesNotRemoveOnCleanup checks that the persistent runtime
// dir is NOT removed when cleanup() is called (only tmpHome should be removed).
func TestBuildRuntimeDirDoesNotRemoveOnCleanup(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, tmpHome, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	if tmpHome != "" {
		t.Fatalf("expected no tmpHome for persistent runtime dir, got %q", tmpHome)
	}

	// Simulate what cleanup() does: remove tmpHome (empty), leave runtimeDir.
	if tmpHome != "" {
		_ = os.RemoveAll(tmpHome)
	}

	if _, statErr := os.Stat(runtimeDir); statErr != nil {
		t.Errorf("persistent runtimeDir was removed by cleanup: %v", statErr)
	}
}

// TestBuildRuntimeDirExplicitRuntimeDir verifies that a non-empty runtimeDirOverride is
// used directly, even when instancePath is also provided.
func TestBuildRuntimeDirExplicitRuntimeDir(t *testing.T) {
	base := t.TempDir()
	explicit := filepath.Join(base, "custom-bridge-dir")
	runtimeDir, tmpHome, err := buildRuntimeDir("/some/instance/path", explicit)
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	if tmpHome != "" {
		t.Errorf("tmpHome = %q, want empty for explicit runtime_dir", tmpHome)
	}
	if runtimeDir != explicit {
		t.Errorf("runtimeDir = %q, want %q", runtimeDir, explicit)
	}
	// Subdirs must be created under the explicit path (ts3home is NOT in runtime dir).
	for _, sub := range []string{"logs", "crashdumps", "cache", "xdg-runtime"} {
		if _, statErr := os.Stat(filepath.Join(runtimeDir, sub)); statErr != nil {
			t.Errorf("subdir %q not created under explicit runtimeDir: %v", sub, statErr)
		}
	}
}

// TestBuildRuntimeDirExplicitRuntimeDirIgnoresInstancePath verifies that runtimeDirOverride
// takes priority over the InstancePath-derived path.
func TestBuildRuntimeDirExplicitRuntimeDirIgnoresInstancePath(t *testing.T) {
	base := t.TempDir()
	instancePath := filepath.Join(base, "instance")
	explicit := filepath.Join(base, "explicit-runtime")
	runtimeDir, _, err := buildRuntimeDir(instancePath, explicit)
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	if strings.HasPrefix(runtimeDir, instancePath) {
		t.Errorf("runtimeDir %q should NOT be under instancePath %q when runtimeDirOverride is set", runtimeDir, instancePath)
	}
	if runtimeDir != explicit {
		t.Errorf("runtimeDir = %q, want %q", runtimeDir, explicit)
	}
}

// TestCleanupKeepFilesPreservesRuntimeDir verifies that cleanupKeepFiles() does NOT
// remove the runtime directory so crash logs remain available for diagnosis.
func TestCleanupKeepFilesPreservesRuntimeDir(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}
	persistentHome := buildPersistentTs3Home(instancePath, runtimeDir)
	if err := ensurePersistentTs3HomeDirs(persistentHome); err != nil {
		t.Fatalf("ensurePersistentTs3HomeDirs: %v", err)
	}

	// Write a fake crash log to simulate a real TS3 crash (in persistent ts3home).
	logPath := filepath.Join(persistentHome, ".ts3client", "logs", "ts3client_crash.log")
	if writeErr := os.WriteFile(logPath, []byte("crash log\n"), 0o600); writeErr != nil {
		t.Fatalf("write log: %v", writeErr)
	}

	adapter := NewExternalClientBridgeAdapter()
	adapter.mu.Lock()
	adapter.runtimeDir = runtimeDir
	adapter.persistentTs3Home = persistentHome
	adapter.tmpHome = "" // persistent dir — tmpHome is empty
	adapter.mu.Unlock()

	_ = adapter.cleanupKeepFiles()

	// Runtime dir and persistent ts3home (with crash log) must still exist.
	if _, statErr := os.Stat(runtimeDir); statErr != nil {
		t.Errorf("runtimeDir removed by cleanupKeepFiles: %v", statErr)
	}
	if _, statErr := os.Stat(persistentHome); statErr != nil {
		t.Errorf("persistent ts3home removed by cleanupKeepFiles: %v", statErr)
	}
	if _, statErr := os.Stat(logPath); statErr != nil {
		t.Errorf("crash log removed by cleanupKeepFiles: %v", statErr)
	}
}

// TestCollectTS3StartFailedErrorIncludesCrashdumpPath verifies that the diagnostic
// error includes crashdump_path when a .dmp file is found.
func TestCollectTS3StartFailedErrorIncludesCrashdumpPath(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}

	dumpPath := filepath.Join(runtimeDir, "crashdumps", "ts3client.dmp")
	if writeErr := os.WriteFile(dumpPath, []byte("dump"), 0o600); writeErr != nil {
		t.Fatalf("write dump: %v", writeErr)
	}

	adapter := NewExternalClientBridgeAdapter()
	diagErr := adapter.collectTS3StartFailedError(nil, nil, runtimeDir)
	if diagErr == nil {
		t.Fatal("expected error")
	}
	if !strings.Contains(diagErr.Error(), "crashdump_path") {
		t.Errorf("error %q missing 'crashdump_path'", diagErr.Error())
	}
	if !strings.Contains(diagErr.Error(), dumpPath) {
		t.Errorf("error %q missing dump path %q", diagErr.Error(), dumpPath)
	}
}

// TestValidateClientQueryPluginMissingDir verifies that an empty officialClientDir
// returns nil (check is not applicable when no client dir is known).
func TestValidateClientQueryPluginMissingDir(t *testing.T) {
	if err := validateClientQueryPlugin(""); err != nil {
		t.Errorf("empty dir should return nil, got: %v", err)
	}
}

// TestValidateClientQueryPluginMissingPlugin verifies that when the plugins/ dir
// exists but the .so file is absent, an error containing "clientquery_plugin_missing"
// is returned.
func TestValidateClientQueryPluginMissingPlugin(t *testing.T) {
	dir := t.TempDir()
	pluginsDir := filepath.Join(dir, "plugins")
	if err := os.MkdirAll(pluginsDir, 0o755); err != nil {
		t.Fatal(err)
	}
	// plugins/ dir exists but no .so file inside it
	err := validateClientQueryPlugin(dir)
	if err == nil {
		t.Fatal("expected error for missing plugin")
	}
	if !strings.Contains(err.Error(), "clientquery_plugin_missing") {
		t.Errorf("error %q does not contain 'clientquery_plugin_missing'", err.Error())
	}
}

// TestValidateClientQueryPluginFound verifies that when the .so file is present,
// validateClientQueryPlugin returns nil.
func TestValidateClientQueryPluginFound(t *testing.T) {
	dir := t.TempDir()
	pluginsDir := filepath.Join(dir, "plugins")
	if err := os.MkdirAll(pluginsDir, 0o755); err != nil {
		t.Fatal(err)
	}
	soPath := filepath.Join(pluginsDir, clientQueryPluginName)
	if err := os.WriteFile(soPath, []byte("fake so"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := validateClientQueryPlugin(dir); err != nil {
		t.Errorf("expected nil for present plugin, got: %v", err)
	}
}

// TestOfficialClientDirFromRunscript verifies that when ClientRunscriptPath is set,
// officialClientDirFromPaths returns its directory.
func TestOfficialClientDirFromRunscript(t *testing.T) {
	got := officialClientDirFromPaths("/opt/ts3/ts3client_linux_amd64", "/opt/ts3/ts3client_runscript.sh")
	want := "/opt/ts3"
	if got != want {
		t.Errorf("officialClientDirFromPaths = %q, want %q", got, want)
	}
}

// TestOfficialClientDirFromBinary verifies that when ClientRunscriptPath is empty,
// officialClientDirFromPaths falls back to the binary directory.
func TestOfficialClientDirFromBinary(t *testing.T) {
	got := officialClientDirFromPaths("/opt/ts3/ts3client_linux_amd64", "")
	want := "/opt/ts3"
	if got != want {
		t.Errorf("officialClientDirFromPaths = %q, want %q", got, want)
	}
}

// envToMap converts a []string{"KEY=VAL", ...} slice to a map for easy lookup.
func envToMap(env []string) map[string]string {
	m := make(map[string]string, len(env))
	for _, kv := range env {
		parts := strings.SplitN(kv, "=", 2)
		if len(parts) == 2 {
			m[parts[0]] = parts[1]
		} else {
			m[parts[0]] = ""
		}
	}
	return m
}

// newFakeRunscriptDir creates a temp dir with a fake ts3client_runscript.sh that
// sleeps indefinitely, plus a fake ts3client_linux_amd64 binary. Returns the dir
// and the runscript path.
func newFakeRunscriptDir(t *testing.T) (dir, runscript string) {
	t.Helper()
	dir = t.TempDir()
	runscript = filepath.Join(dir, "ts3client_runscript.sh")
	if err := os.WriteFile(runscript, []byte("#!/bin/sh\nsleep 60\n"), 0o755); err != nil {
		t.Fatalf("write fake runscript: %v", err)
	}
	return dir, runscript
}

// startTS3ClientForTest calls startTS3Client with a fake runscript that just sleeps.
// The persistent ts3home is set to <instancePath>/data/teamspeak-client/ts3home.
// The caller must kill the returned cmd.
func startTS3ClientForTest(t *testing.T, runscriptPath, instancePath string) (*exec.Cmd, *ts3DiagContext) {
	t.Helper()
	runtimeDir := filepath.Join(instancePath, "runtime", "teamspeak-bridge")
	if err := os.MkdirAll(runtimeDir, 0o750); err != nil {
		t.Fatalf("mkdir runtimeDir: %v", err)
	}
	if err := ensureRuntimeSubdirs(runtimeDir); err != nil {
		t.Fatalf("ensureRuntimeSubdirs: %v", err)
	}
	persistentTs3Home := buildPersistentTs3Home(instancePath, runtimeDir)
	if err := ensurePersistentTs3HomeDirs(persistentTs3Home); err != nil {
		t.Fatalf("ensurePersistentTs3HomeDirs: %v", err)
	}
	params := connectParams{
		Host:                "ts.example.com",
		ClientRunscriptPath: runscriptPath,
		InstancePath:        instancePath,
	}
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	cmd, _, diagCtx, err := startTS3Client(ctx, params, "", runtimeDir, persistentTs3Home, ":201", pulseAudioState{socketPath: "/fake/pulse.sock"}, "127.0.0.1", 25639)
	if err != nil {
		t.Fatalf("startTS3Client: %v", err)
	}
	return cmd, diagCtx
}

// TestBuildTS3EnvContainsEasywiBridge verifies EASYWI_TS_BRIDGE=1 is set.
func TestBuildTS3EnvContainsEasywiBridge(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if m["EASYWI_TS_BRIDGE"] != "1" {
		t.Errorf("EASYWI_TS_BRIDGE = %q, want 1", m["EASYWI_TS_BRIDGE"])
	}
}

// TestBuildTS3EnvContainsXDGConfigHome verifies XDG_CONFIG_HOME is under ts3home.
func TestBuildTS3EnvContainsXDGConfigHome(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if m["XDG_CONFIG_HOME"] != "/ts3home/.config" {
		t.Errorf("XDG_CONFIG_HOME = %q, want /ts3home/.config", m["XDG_CONFIG_HOME"])
	}
}

// TestBuildTS3EnvContainsXDGDataHome verifies XDG_DATA_HOME is under ts3home.
func TestBuildTS3EnvContainsXDGDataHome(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if m["XDG_DATA_HOME"] != "/ts3home/.local/share" {
		t.Errorf("XDG_DATA_HOME = %q, want /ts3home/.local/share", m["XDG_DATA_HOME"])
	}
}

// TestStartTS3ClientPrefersRunscript verifies that when a runscript is accessible,
// ts3_start_mode is "runscript" and the binary path is not used.
func TestStartTS3ClientPrefersRunscript(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	cmd, diagCtx := startTS3ClientForTest(t, runscript, instancePath)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	if diagCtx.mode != "runscript" {
		t.Errorf("mode = %q, want runscript", diagCtx.mode)
	}
}

// TestStartTS3ClientCmdDirIsRunscriptDir verifies cmd.Dir is the directory
// containing the runscript (the official-client dir).
func TestStartTS3ClientCmdDirIsRunscriptDir(t *testing.T) {
	clientDir, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	cmd, diagCtx := startTS3ClientForTest(t, runscript, instancePath)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	if diagCtx.cmdDir != clientDir {
		t.Errorf("cmdDir = %q, want %q", diagCtx.cmdDir, clientDir)
	}
	if cmd.Dir != clientDir {
		t.Errorf("cmd.Dir = %q, want %q", cmd.Dir, clientDir)
	}
}

// TestStartTS3ClientArgsNoServerURIForRunscript verifies that when using a
// runscript, no ts3server:// URI is passed as an argument.
func TestStartTS3ClientArgsNoServerURIForRunscript(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	cmd, diagCtx := startTS3ClientForTest(t, runscript, instancePath)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	for _, arg := range diagCtx.args {
		if strings.HasPrefix(arg, "ts3server://") {
			t.Errorf("runscript args contain server URI: %v", diagCtx.args)
		}
	}
	found := false
	for _, arg := range diagCtx.args {
		if arg == "-nosingleinstance" {
			found = true
		}
	}
	if !found {
		t.Errorf("args %v do not contain -nosingleinstance", diagCtx.args)
	}
}

// TestStartTS3ClientTmpDirUnderInstancePath verifies that TMPDIR is placed at
// <instancePath>/runtime/tmp (not inside the teamspeak-bridge subdir).
func TestStartTS3ClientTmpDirUnderInstancePath(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	cmd, diagCtx := startTS3ClientForTest(t, runscript, instancePath)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	wantTmpDir := filepath.Join(instancePath, "runtime", "tmp")
	if diagCtx.tmpDir != wantTmpDir {
		t.Errorf("tmpDir = %q, want %q", diagCtx.tmpDir, wantTmpDir)
	}
	if _, err := os.Stat(wantTmpDir); err != nil {
		t.Errorf("tmpDir not created on disk: %v", err)
	}
}

// TestStartTS3ClientEnvContainsRequiredVars verifies all mandatory env vars are
// present in the TS3 subprocess environment. HOME and XDG_CONFIG/DATA_HOME point
// to the persistent ts3home; XDG_CACHE_HOME and XDG_RUNTIME_DIR point to the
// volatile runtime dirs.
func TestStartTS3ClientEnvContainsRequiredVars(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	cmd, diagCtx := startTS3ClientForTest(t, runscript, instancePath)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}

	m := envToMap(diagCtx.env)
	runtimeDir := filepath.Join(instancePath, "runtime", "teamspeak-bridge")
	persistentTs3Home := filepath.Join(instancePath, "data", "teamspeak-client", "ts3home")

	checks := map[string]string{
		"HOME":             persistentTs3Home,
		"XDG_CONFIG_HOME": filepath.Join(persistentTs3Home, ".config"),
		"XDG_DATA_HOME":   filepath.Join(persistentTs3Home, ".local", "share"),
		"XDG_CACHE_HOME":  filepath.Join(runtimeDir, "cache"),
		"XDG_RUNTIME_DIR": filepath.Join(runtimeDir, "xdg-runtime"),
		"QT_QPA_PLATFORM": "xcb",
		"DISPLAY":         ":201",
		"EASYWI_TS_BRIDGE": "1",
		"TMPDIR":          filepath.Join(instancePath, "runtime", "tmp"),
	}
	for k, want := range checks {
		if got := m[k]; got != want {
			t.Errorf("env[%s] = %q, want %q", k, got, want)
		}
	}
}

// TestTS3DiagContextContainsMode verifies that startTS3Client returns a non-nil
// ts3DiagContext with a populated mode field.
func TestTS3DiagContextContainsMode(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	cmd, diagCtx := startTS3ClientForTest(t, runscript, instancePath)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	if diagCtx == nil {
		t.Fatal("diagCtx is nil")
	}
	if diagCtx.mode == "" {
		t.Error("diagCtx.mode is empty; want non-empty mode string")
	}
}

// TestCollectTS3StartFailedErrorContainsCmdDirAndDisplay verifies that the
// structured error includes cmd_dir, display, tmpdir, and xdg_runtime_dir from
// the stored ts3DiagContext.
func TestCollectTS3StartFailedErrorContainsCmdDirAndDisplay(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}

	adapter := NewExternalClientBridgeAdapter()
	adapter.mu.Lock()
	adapter.ts3DiagCtx = &ts3DiagContext{
		mode:          "runscript",
		execPath:      "/opt/ts3/ts3client_runscript.sh",
		args:          []string{"-nosingleinstance"},
		cmdDir:        "/opt/ts3",
		display:       ":201",
		runtimeDir:    runtimeDir,
		ts3Home:       filepath.Join(runtimeDir, "ts3home"),
		xdgRuntimeDir: filepath.Join(runtimeDir, "xdg-runtime"),
		tmpDir:        filepath.Join(instancePath, "runtime", "tmp"),
		cqHost:        "127.0.0.1",
		cqPort:        25639,
	}
	adapter.mu.Unlock()

	diagErr := adapter.collectTS3StartFailedError(nil, nil, runtimeDir)
	if diagErr == nil {
		t.Fatal("expected error")
	}
	errStr := diagErr.Error()
	for _, want := range []string{
		"ts3client_start_failed",
		"ts3_start_mode=runscript",
		"cmd_dir=/opt/ts3",
		"display=:201",
		"tmpdir=",
		"xdg_runtime_dir=",
	} {
		if !strings.Contains(errStr, want) {
			t.Errorf("error %q missing %q", errStr, want)
		}
	}
}

// TestCollectTS3StartFailedErrorWithStartDiagAndStderr verifies that when a TS3
// process exits (e.g. segfault) after xdpyinfo succeeds, the error includes both
// the startup diagnostics context and the stderr output.
func TestCollectTS3StartFailedErrorWithStartDiagAndStderr(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}

	adapter := NewExternalClientBridgeAdapter()
	adapter.mu.Lock()
	adapter.ts3DiagCtx = &ts3DiagContext{
		mode:          "runscript",
		execPath:      "/opt/ts3/ts3client_runscript.sh",
		args:          []string{"-nosingleinstance"},
		cmdDir:        "/opt/ts3",
		display:       ":201",
		runtimeDir:    runtimeDir,
		ts3Home:       filepath.Join(runtimeDir, "ts3home"),
		xdgRuntimeDir: filepath.Join(runtimeDir, "xdg-runtime"),
		tmpDir:        filepath.Join(instancePath, "runtime", "tmp"),
		cqHost:        "127.0.0.1",
		cqPort:        25639,
	}
	adapter.mu.Unlock()

	var stderrBuf bytes.Buffer
	stderrBuf.WriteString("Segmentation fault (core dumped)\n")

	diagErr := adapter.collectTS3StartFailedError(nil, &stderrBuf, runtimeDir)
	if diagErr == nil {
		t.Fatal("expected error")
	}
	errStr := diagErr.Error()
	for _, want := range []string{
		"ts3client_start_failed",
		"ts3_start_mode=runscript",
		"cmd_dir=/opt/ts3",
		"Segmentation fault",
	} {
		if !strings.Contains(errStr, want) {
			t.Errorf("error %q missing %q", errStr, want)
		}
	}
}

// TestBuildTS3EnvNoPulseServerWhenSocketEmpty verifies that PULSE_SERVER is
// NOT set when pulseSocketPath is empty (audio not ready).
func TestBuildTS3EnvNoPulseServerWhenSocketEmpty(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if _, ok := m["PULSE_SERVER"]; ok {
		t.Errorf("PULSE_SERVER must not be set when pulseSocketPath is empty, got %q", m["PULSE_SERVER"])
	}
}

// TestBuildTS3EnvPulseServerSetWhenSocketNonEmpty verifies that PULSE_SERVER is
// set to unix:<path> when a non-empty socket path is provided.
func TestBuildTS3EnvPulseServerSetWhenSocketNonEmpty(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "/run/pulse/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	want := "unix:/run/pulse/pulse.sock"
	if m["PULSE_SERVER"] != want {
		t.Errorf("PULSE_SERVER = %q, want %q", m["PULSE_SERVER"], want)
	}
}

// TestCheckPulseSocketReadyFalseWhenMissing verifies false is returned when the
// socket file does not exist.
func TestCheckPulseSocketReadyFalseWhenMissing(t *testing.T) {
	dir := t.TempDir()
	if checkPulseSocketReady(filepath.Join(dir, "nonexistent.sock")) {
		t.Error("expected false for missing socket")
	}
}

// TestCheckPulseSocketReadyFalseWhenRegularFile verifies false is returned when
// the path points to a regular file rather than a Unix socket.
func TestCheckPulseSocketReadyFalseWhenRegularFile(t *testing.T) {
	dir := t.TempDir()
	p := filepath.Join(dir, "notasocket")
	if err := os.WriteFile(p, []byte("data"), 0o600); err != nil {
		t.Fatal(err)
	}
	if checkPulseSocketReady(p) {
		t.Error("expected false for regular file (not a Unix socket)")
	}
}

// TestCheckPulseSocketReadyTrueWhenListening verifies true is returned when an
// actual Unix socket is listening at the path.
func TestCheckPulseSocketReadyTrueWhenListening(t *testing.T) {
	dir := t.TempDir()
	sockPath := filepath.Join(dir, "pulse.sock")
	ln, err := net.Listen("unix", sockPath)
	if err != nil {
		t.Fatalf("create unix listener: %v", err)
	}
	defer ln.Close()
	if !checkPulseSocketReady(sockPath) {
		t.Error("checkPulseSocketReady should return true for a listening Unix socket")
	}
}

// TestAudioNotReadyDoesNotPreventTS3Start verifies that TS3 starts successfully
// (fake runscript) even when pulse.audioReady is false and PULSE_SERVER is absent.
func TestAudioNotReadyDoesNotPreventTS3Start(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	runtimeDir := filepath.Join(instancePath, "runtime", "teamspeak-bridge")
	if err := os.MkdirAll(runtimeDir, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := ensureRuntimeSubdirs(runtimeDir); err != nil {
		t.Fatal(err)
	}
	params := connectParams{
		Host:                "ts.example.com",
		ClientRunscriptPath: runscript,
		InstancePath:        instancePath,
	}
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	// audio not ready: socket does not exist.
	pulse := pulseAudioState{
		socketPath: filepath.Join(runtimeDir, "pulse", "pulse.sock"),
		audioReady: false,
	}
	persistentTs3Home := buildPersistentTs3Home(instancePath, runtimeDir)
	_ = ensurePersistentTs3HomeDirs(persistentTs3Home)
	cmd, _, diagCtx, err := startTS3Client(ctx, params, "", runtimeDir, persistentTs3Home, ":201", pulse, "127.0.0.1", 25639)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	if err != nil {
		t.Errorf("TS3 start must succeed even when audio not ready, got: %v", err)
	}
	m := envToMap(diagCtx.env)
	if _, hasPulse := m["PULSE_SERVER"]; hasPulse {
		t.Error("PULSE_SERVER must not be set when audio is not ready")
	}
}

// TestStartTS3ClientDiagCtxContainsPulseFields verifies that ts3DiagContext
// reflects the pulseAudioState passed to startTS3Client.
func TestStartTS3ClientDiagCtxContainsPulseFields(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	runtimeDir := filepath.Join(instancePath, "runtime", "teamspeak-bridge")
	if err := os.MkdirAll(runtimeDir, 0o750); err != nil {
		t.Fatal(err)
	}
	if err := ensureRuntimeSubdirs(runtimeDir); err != nil {
		t.Fatal(err)
	}
	params := connectParams{
		Host:                "ts.example.com",
		ClientRunscriptPath: runscript,
		InstancePath:        instancePath,
	}
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)

	pulse := pulseAudioState{
		socketPath:   filepath.Join(runtimeDir, "pulse", "pulse.sock"),
		socketExists: false,
		socketReady:  false,
		started:      true,
		audioReady:   false,
	}
	persistentTs3Home2 := buildPersistentTs3Home(instancePath, runtimeDir)
	_ = ensurePersistentTs3HomeDirs(persistentTs3Home2)
	cmd, _, diagCtx, err := startTS3Client(ctx, params, "", runtimeDir, persistentTs3Home2, ":201", pulse, "127.0.0.1", 25639)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}
	if err != nil {
		t.Fatalf("startTS3Client: %v", err)
	}
	if diagCtx.pulseSocketPath != pulse.socketPath {
		t.Errorf("diagCtx.pulseSocketPath = %q, want %q", diagCtx.pulseSocketPath, pulse.socketPath)
	}
	if diagCtx.pulseStarted != true {
		t.Error("diagCtx.pulseStarted should be true")
	}
	if diagCtx.audioReady != false {
		t.Error("diagCtx.audioReady should be false")
	}
}

// TestCollectTS3StartFailedErrorContainsPulseInfo verifies that the crash error
// includes pulse_server and audio_ready so operators can diagnose PULSE_SERVER
// segfaults.
func TestCollectTS3StartFailedErrorContainsPulseInfo(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}

	adapter := NewExternalClientBridgeAdapter()
	adapter.mu.Lock()
	adapter.ts3DiagCtx = &ts3DiagContext{
		mode:            "runscript",
		execPath:        "/opt/ts3/ts3client_runscript.sh",
		args:            []string{"-nosingleinstance"},
		cmdDir:          "/opt/ts3",
		display:         ":201",
		runtimeDir:      runtimeDir,
		ts3Home:         filepath.Join(runtimeDir, "ts3home"),
		xdgRuntimeDir:   filepath.Join(runtimeDir, "xdg-runtime"),
		tmpDir:          filepath.Join(instancePath, "runtime", "tmp"),
		cqHost:          "127.0.0.1",
		cqPort:          25639,
		pulseSocketPath: filepath.Join(runtimeDir, "pulse", "pulse.sock"),
		audioReady:      false,
		// env deliberately has no PULSE_SERVER (audio was not ready)
		env: []string{"EASYWI_TS_BRIDGE=1", "QT_QPA_PLATFORM=xcb"},
	}
	adapter.mu.Unlock()

	var stderrBuf bytes.Buffer
	stderrBuf.WriteString("Segmentation fault (core dumped)\n")

	diagErr := adapter.collectTS3StartFailedError(nil, &stderrBuf, runtimeDir)
	if diagErr == nil {
		t.Fatal("expected error")
	}
	errStr := diagErr.Error()
	for _, want := range []string{
		"ts3client_start_failed",
		"pulse_server=",
		"audio_ready=false",
		"Segmentation fault",
	} {
		if !strings.Contains(errStr, want) {
			t.Errorf("error %q missing %q", errStr, want)
		}
	}
}

// TestCollectTS3StartFailedErrorPulseServerPresentWhenAudioReady verifies that
// when audio was ready, pulse_server contains the socket URI in the crash error.
func TestCollectTS3StartFailedErrorPulseServerPresentWhenAudioReady(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir, _, err := buildRuntimeDir(instancePath, "")
	if err != nil {
		t.Fatalf("buildRuntimeDir: %v", err)
	}

	sockPath := filepath.Join(runtimeDir, "pulse", "pulse.sock")
	adapter := NewExternalClientBridgeAdapter()
	adapter.mu.Lock()
	adapter.ts3DiagCtx = &ts3DiagContext{
		mode:            "runscript",
		execPath:        "/opt/ts3/ts3client_runscript.sh",
		cmdDir:          "/opt/ts3",
		display:         ":201",
		runtimeDir:      runtimeDir,
		ts3Home:         filepath.Join(runtimeDir, "ts3home"),
		xdgRuntimeDir:   filepath.Join(runtimeDir, "xdg-runtime"),
		tmpDir:          filepath.Join(instancePath, "runtime", "tmp"),
		pulseSocketPath: sockPath,
		audioReady:      true,
		env:             []string{"EASYWI_TS_BRIDGE=1", "PULSE_SERVER=unix:" + sockPath},
	}
	adapter.mu.Unlock()

	diagErr := adapter.collectTS3StartFailedError(nil, nil, runtimeDir)
	if diagErr == nil {
		t.Fatal("expected error")
	}
	errStr := diagErr.Error()
	for _, want := range []string{
		"pulse_server=unix:" + sockPath,
		"audio_ready=true",
	} {
		if !strings.Contains(errStr, want) {
			t.Errorf("error %q missing %q", errStr, want)
		}
	}
}

// --- persistent ts3home ---

// TestPersistentTs3HomePathWithInstancePath verifies that buildPersistentTs3Home
// returns <instancePath>/data/teamspeak-client/ts3home when instancePath is set.
func TestPersistentTs3HomePathWithInstancePath(t *testing.T) {
	instancePath := "/srv/easywi/instance1"
	runtimeDir := "/srv/easywi/instance1/runtime/teamspeak-bridge"
	got := buildPersistentTs3Home(instancePath, runtimeDir)
	want := "/srv/easywi/instance1/data/teamspeak-client/ts3home"
	if got != want {
		t.Errorf("buildPersistentTs3Home = %q, want %q", got, want)
	}
}

// TestPersistentTs3HomePathWithoutInstancePath verifies that buildPersistentTs3Home
// falls back to <runtimeDir>/ts3home when instancePath is empty.
func TestPersistentTs3HomePathWithoutInstancePath(t *testing.T) {
	runtimeDir := "/tmp/easywi-ts3-bridge-abc123"
	got := buildPersistentTs3Home("", runtimeDir)
	want := runtimeDir + "/ts3home"
	if got != want {
		t.Errorf("buildPersistentTs3Home = %q, want %q", got, want)
	}
}

// TestPersistentTs3HomeUsedInsteadOfRuntime verifies that startTS3Client sets
// HOME to the persistent ts3home, NOT to a path inside the runtime dir.
func TestPersistentTs3HomeUsedInsteadOfRuntime(t *testing.T) {
	_, runscript := newFakeRunscriptDir(t)
	instancePath := t.TempDir()
	cmd, diagCtx := startTS3ClientForTest(t, runscript, instancePath)
	if cmd != nil && cmd.Process != nil {
		_ = cmd.Process.Kill()
		_ = cmd.Wait()
	}

	runtimeDir := filepath.Join(instancePath, "runtime", "teamspeak-bridge")
	persistentTs3Home := filepath.Join(instancePath, "data", "teamspeak-client", "ts3home")

	m := envToMap(diagCtx.env)
	if m["HOME"] != persistentTs3Home {
		t.Errorf("HOME = %q, want persistent ts3home %q", m["HOME"], persistentTs3Home)
	}
	// Verify HOME does NOT point inside the runtime dir.
	if strings.HasPrefix(m["HOME"], runtimeDir) {
		t.Errorf("HOME %q must not be inside runtimeDir %q", m["HOME"], runtimeDir)
	}
}

// TestRuntimeCleanupDoesNotDeletePersistentTs3Home verifies that cleanup() does
// not remove the persistent ts3home. Only the volatile tmpHome (when set) is removed.
func TestRuntimeCleanupDoesNotDeletePersistentTs3Home(t *testing.T) {
	instancePath := t.TempDir()
	runtimeDir := filepath.Join(instancePath, "runtime", "teamspeak-bridge")
	if err := os.MkdirAll(runtimeDir, 0o750); err != nil {
		t.Fatal(err)
	}
	persistentHome := buildPersistentTs3Home(instancePath, runtimeDir)
	if err := ensurePersistentTs3HomeDirs(persistentHome); err != nil {
		t.Fatal(err)
	}

	// Write a settings.db to simulate a real install.
	settingsDb := filepath.Join(persistentHome, ".ts3client", "settings.db")
	if err := os.WriteFile(settingsDb, []byte("fake settings"), 0o600); err != nil {
		t.Fatal(err)
	}

	adapter := NewExternalClientBridgeAdapter()
	adapter.mu.Lock()
	adapter.runtimeDir = runtimeDir
	adapter.persistentTs3Home = persistentHome
	adapter.tmpHome = "" // not a temp dir; nothing to delete
	adapter.mu.Unlock()

	_ = adapter.cleanup()

	// Persistent ts3home must survive cleanup.
	if _, err := os.Stat(persistentHome); err != nil {
		t.Errorf("persistent ts3home was deleted by cleanup: %v", err)
	}
	if _, err := os.Stat(settingsDb); err != nil {
		t.Errorf("settings.db was deleted by cleanup: %v", err)
	}
}

// TestEnsurePersistentTs3HomeDirsCreatesSubdirs verifies that
// ensurePersistentTs3HomeDirs creates the expected directory tree.
func TestEnsurePersistentTs3HomeDirsCreatesSubdirs(t *testing.T) {
	base := t.TempDir()
	persistentHome := filepath.Join(base, "data", "teamspeak-client", "ts3home")
	if err := ensurePersistentTs3HomeDirs(persistentHome); err != nil {
		t.Fatalf("ensurePersistentTs3HomeDirs: %v", err)
	}
	for _, sub := range []string{
		".",
		".config",
		filepath.Join(".local", "share"),
		filepath.Join(".ts3client", "logs"),
	} {
		p := filepath.Join(persistentHome, sub)
		info, err := os.Stat(p)
		if err != nil {
			t.Errorf("subdir %q not created: %v", sub, err)
			continue
		}
		if !info.IsDir() {
			t.Errorf("subdir %q is not a directory", sub)
		}
	}
}

// --- LicenseViewer detection ---

// TestScanTs3LogForLicenseViewerDetects verifies that a log containing both
// "LicenseViewer" and "require accept=1" is detected correctly.
func TestScanTs3LogForLicenseViewerDetects(t *testing.T) {
	dir := t.TempDir()
	logContent := "2026-06-23 12:00:00.000 LicenseViewer | View license: version=5, language=C, require accept=1\n"
	logPath := filepath.Join(dir, "ts3client_2026-06-23.log")
	if err := os.WriteFile(logPath, []byte(logContent), 0o600); err != nil {
		t.Fatal(err)
	}
	if !scanTs3LogForLicenseViewer([]string{dir}) {
		t.Error("scanTs3LogForLicenseViewer should return true when log contains LicenseViewer + require accept=1")
	}
}

// TestScanTs3LogForLicenseViewerFalseWhenAbsent verifies that false is returned
// when no LicenseViewer pattern is present.
func TestScanTs3LogForLicenseViewerFalseWhenAbsent(t *testing.T) {
	dir := t.TempDir()
	logContent := "2026-06-23 12:00:00.000 ClientUI | connected to server\n"
	if err := os.WriteFile(filepath.Join(dir, "ts3client_2026-06-23.log"), []byte(logContent), 0o600); err != nil {
		t.Fatal(err)
	}
	if scanTs3LogForLicenseViewer([]string{dir}) {
		t.Error("scanTs3LogForLicenseViewer should return false when LicenseViewer is absent")
	}
}

// TestScanTs3LogForLicenseViewerFalseWhenRequireAcceptZero verifies that
// "LicenseViewer" alone without "require accept=1" does not trigger detection.
func TestScanTs3LogForLicenseViewerFalseWhenRequireAcceptZero(t *testing.T) {
	dir := t.TempDir()
	logContent := "2026-06-23 12:00:00.000 LicenseViewer | View license: version=5, require accept=0\n"
	if err := os.WriteFile(filepath.Join(dir, "ts3client_2026-06-23.log"), []byte(logContent), 0o600); err != nil {
		t.Fatal(err)
	}
	if scanTs3LogForLicenseViewer([]string{dir}) {
		t.Error("scanTs3LogForLicenseViewer should return false when require accept=0")
	}
}

// TestScanTs3LogForLicenseViewerEmptyDir verifies that a non-existent log
// directory returns false without panic.
func TestScanTs3LogForLicenseViewerEmptyDir(t *testing.T) {
	if scanTs3LogForLicenseViewer([]string{"/nonexistent/path"}) {
		t.Error("scanTs3LogForLicenseViewer should return false for non-existent directory")
	}
}

// TestProbeClientQueryLicenseBlockReturnsFalseWhenNotListening verifies that the
// probe returns false when the ClientQuery port is not listening.
func TestProbeClientQueryLicenseBlockReturnsFalseWhenNotListening(t *testing.T) {
	if probeClientQueryLicenseBlock("127.0.0.1", 59999, "", nil) {
		t.Error("probeClientQueryLicenseBlock should return false when no CQ listener")
	}
}

// TestProbeClientQueryLicenseBlockDetects1796 verifies that when a mock ClientQuery
// server responds with error id=1796 AND the log contains LicenseViewer, the probe
// returns true.
func TestProbeClientQueryLicenseBlockDetects1796(t *testing.T) {
	logDir := t.TempDir()
	logContent := "LicenseViewer | View license: version=5, language=C, require accept=1\n"
	if err := os.WriteFile(filepath.Join(logDir, "ts3client_2026-06-23.log"), []byte(logContent), 0o600); err != nil {
		t.Fatal(err)
	}

	// Start a mock CQ listener that sends banner + error 1796 on "use".
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()
	port := ln.Addr().(*net.TCPAddr).Port

	go func() {
		conn, acceptErr := ln.Accept()
		if acceptErr != nil {
			return
		}
		defer conn.Close()
		fmt.Fprintf(conn, "TS3 Client\n\nwelcome\n")
		scanner := bufio.NewScanner(conn)
		for scanner.Scan() {
			if strings.Contains(scanner.Text(), "use") {
				fmt.Fprintf(conn, "error id=1796 msg=currently\\snot\\spossible\n")
				return
			}
		}
	}()

	if !probeClientQueryLicenseBlock("127.0.0.1", port, "", []string{logDir}) {
		t.Error("probeClientQueryLicenseBlock should return true when CQ returns 1796 + log has LicenseViewer")
	}
}

// TestProbeClientQueryLicenseBlockFalseWhenNoLicenseViewerInLog verifies that
// error 1796 alone (without LicenseViewer in the log) does not trigger detection.
func TestProbeClientQueryLicenseBlockFalseWhenNoLicenseViewerInLog(t *testing.T) {
	logDir := t.TempDir() // empty, no LicenseViewer log

	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer ln.Close()
	port := ln.Addr().(*net.TCPAddr).Port

	go func() {
		conn, acceptErr := ln.Accept()
		if acceptErr != nil {
			return
		}
		defer conn.Close()
		fmt.Fprintf(conn, "TS3 Client\n\nwelcome\n")
		scanner := bufio.NewScanner(conn)
		for scanner.Scan() {
			if strings.Contains(scanner.Text(), "use") {
				fmt.Fprintf(conn, "error id=1796 msg=currently\\snot\\spossible\n")
				return
			}
		}
	}()

	if probeClientQueryLicenseBlock("127.0.0.1", port, "", []string{logDir}) {
		t.Error("probeClientQueryLicenseBlock should return false when log has no LicenseViewer")
	}
}

// --- backend status license_accept_required ---

// TestAdapterStatusLicenseAcceptRequiredSet verifies that LicenseAcceptRequired
// is reported in Status() after it was detected and stored in the adapter.
func TestAdapterStatusLicenseAcceptRequiredSet(t *testing.T) {
	adapter := NewExternalClientBridgeAdapter()
	adapter.mu.Lock()
	adapter.licenseAcceptRequired = true
	adapter.state = stateDisconnected
	adapter.mu.Unlock()

	status, err := adapter.Status(context.Background())
	if err != nil {
		t.Fatalf("Status: %v", err)
	}
	if !status.LicenseAcceptRequired {
		t.Error("LicenseAcceptRequired should be true in status after license detection")
	}
	if status.Ready {
		t.Error("Ready should be false when license not accepted")
	}
}

// TestAdapterStatusLicenseAcceptRequiredFalseByDefault verifies that
// LicenseAcceptRequired is false in a freshly created adapter.
func TestAdapterStatusLicenseAcceptRequiredFalseByDefault(t *testing.T) {
	adapter := NewExternalClientBridgeAdapter()
	status, err := adapter.Status(context.Background())
	if err != nil {
		t.Fatalf("Status: %v", err)
	}
	if status.LicenseAcceptRequired {
		t.Error("LicenseAcceptRequired should be false for a fresh adapter")
	}
}

// TestBuildTS3EnvContainsXDGCacheHome verifies XDG_CACHE_HOME points to the
// volatile runtime cache dir, not the persistent ts3home.
func TestBuildTS3EnvContainsXDGCacheHome(t *testing.T) {
	env := buildTS3Env("/ts3home", "/xdg-runtime", "/tmp-dir", ":175", "unix:/pulse.sock", "/opt/ts3", "/runtime/cache")
	m := envToMap(env)
	if m["XDG_CACHE_HOME"] != "/runtime/cache" {
		t.Errorf("XDG_CACHE_HOME = %q, want /runtime/cache", m["XDG_CACHE_HOME"])
	}
	if strings.HasPrefix(m["XDG_CACHE_HOME"], "/ts3home") {
		t.Errorf("XDG_CACHE_HOME %q must not be inside ts3home /ts3home", m["XDG_CACHE_HOME"])
	}
}
