package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestSteamCmdInstallSucceededWithAppID(t *testing.T) {
	output := "Success! App '222860' fully installed."
	if !steamCmdInstallSucceeded(output, "222860") {
		t.Fatalf("expected install success for app id")
	}
}

func TestSteamCmdInstallSucceededWithoutAppID(t *testing.T) {
	output := "Success! App '740' already up to date."
	if !steamCmdInstallSucceeded(output, "") {
		t.Fatalf("expected install success without app id")
	}
}

func TestShouldRetrySteamCmdWhenOnlySelfUpdate(t *testing.T) {
	output := "[----] Update complete, launching Steamcmd..."
	if !shouldRetrySteamCmd(output, "222860") {
		t.Fatalf("expected retry when only steamcmd update is present")
	}
}

func TestShouldRetrySteamCmdStopsOnSuccess(t *testing.T) {
	output := "Success! App '222860' fully installed."
	if shouldRetrySteamCmd(output, "222860") {
		t.Fatalf("did not expect retry when install succeeded")
	}
}

func TestSteamCmdInstallSnippetDoesNotOverwrite(t *testing.T) {
	snippet := steamCmdInstallSnippet("/home/gs29/.steamcmd")
	if strings.Contains(snippet, "EASYWISTEAMCMD") || strings.Contains(snippet, "cat >") {
		t.Fatalf("expected steamcmd setup snippet to avoid overwriting steamcmd.sh")
	}
	if !strings.Contains(snippet, "STEAMCMD_EXEC=") {
		t.Fatalf("expected steamcmd setup snippet to export STEAMCMD_EXEC")
	}
}

func TestNormalizeSteamCmdInstallDirOverridesForceInstallDir(t *testing.T) {
	command := "steamcmd +force_install_dir /home/gs29 +login anonymous +app_update 294420 +quit"
	instanceDir := "/home/gs29/instance_9"
	normalized := normalizeSteamCmdInstallDir(command, instanceDir)
	if !strings.Contains(normalized, "+force_install_dir "+instanceDir) {
		t.Fatalf("expected force_install_dir to be replaced with instance dir")
	}
	if strings.Contains(normalized, "+force_install_dir /home/gs29 ") {
		t.Fatalf("expected force_install_dir to avoid user home")
	}
}

func TestSteamCmdInstallErrorSuccess(t *testing.T) {
	output := "Success! App '294420' fully installed."
	if err := steamCmdInstallError(output, "294420"); err != nil {
		t.Fatalf("expected no error on success, got %v", err)
	}
}

func TestSteamCmdInstallErrorFailedLine(t *testing.T) {
	output := "ERROR! Failed to install app '294420' (No subscription)\n"
	err := steamCmdInstallError(output, "294420")
	if err == nil || err.Error() != "ERROR! Failed to install app '294420' (No subscription)" {
		t.Fatalf("expected failed install line, got %v", err)
	}
}

func TestSteamCmdInstallSucceededWithANSI(t *testing.T) {
	output := "\u001b[32mSuccess! App '730' fully installed.\u001b[0m"
	if !steamCmdInstallSucceeded(output, "730") {
		t.Fatalf("expected install success with ansi output")
	}
}

func TestSteamCmdInstallSucceededAlreadyUpToDateWithANSI(t *testing.T) {
	output := "\u001b[32mSuccess! App '730' already up to date.\u001b[0m"
	if !steamCmdInstallSucceeded(output, "730") {
		t.Fatalf("expected already up to date to count as success with ansi output")
	}
}

func TestSteamCmdInstallErrorMissingConfirmationExitZeroNoError(t *testing.T) {
	output := "Update state (0x61) downloading, progress: 100.00\nUpdate state (0x81) verifying update, progress: 100.00"
	if err := steamCmdInstallError(output, "730"); err == nil {
		t.Fatalf("expected error when success confirmation is missing")
	}
}

func TestSteamCmdInstallErrorState602(t *testing.T) {
	output := "Error! App '730' state is 0x602 after update job."
	if err := steamCmdInstallError(output, "730"); err == nil {
		t.Fatalf("expected error for state 0x602")
	}
}

func TestSteamCmdInstallErrorNoSubscription(t *testing.T) {
	output := "ERROR! Failed to install app '730' (No subscription)"
	if err := steamCmdInstallError(output, "730"); err == nil {
		t.Fatalf("expected error for no subscription")
	}
}

func TestSteamCmdInstallErrorDiskWriteFailure(t *testing.T) {
	output := "Error! Disk write failure"
	if err := steamCmdInstallError(output, "730"); err == nil {
		t.Fatalf("expected error for disk write failure")
	}
}

func TestNormalizeSteamCmdInstallDirInjectsForceInstallDir(t *testing.T) {
	command := "steamcmd +login anonymous +app_update 222860 validate +quit"
	instanceDir := "/home/gs21"
	normalized := normalizeSteamCmdInstallDir(command, instanceDir)
	if !strings.Contains(normalized, "steamcmd +force_install_dir "+instanceDir+" +login anonymous") {
		t.Fatalf("expected force_install_dir to be injected, got %q", normalized)
	}
}

func TestBuildInstanceInstallShellCommandBootstrapsSteamCmd(t *testing.T) {
	instanceDir := "/home/gs21"
	command := replaceSteamCmdExecutable(
		normalizeSteamCmdInstallDir("steamcmd +login anonymous +app_update 222860 validate +quit", instanceDir),
		"$STEAMCMD_EXEC",
	)
	shellCmd := buildInstanceInstallShellCommand(instanceDir, command, true)

	for _, expected := range []string{
		"STEAMCMD_EXEC=",
		"steamcmd_linux.tar.gz",
		"cd " + instanceDir + " && $STEAMCMD_EXEC +force_install_dir " + instanceDir,
		instanceDir + "/.steam/sdk32/steamclient.so",
	} {
		if !strings.Contains(shellCmd, expected) {
			t.Fatalf("expected shell command to contain %q, got %q", expected, shellCmd)
		}
	}
	if strings.Contains(shellCmd, " /usr/local/bin/steamcmd ") {
		t.Fatalf("expected reinstall command to avoid broken global steamcmd, got %q", shellCmd)
	}
}

func TestStripWineBootstrapRemovesPrivilegedSetup(t *testing.T) {
	command := `bash -lc "set -e; if ! command -v wine >/dev/null 2>&1; then if command -v apt-get >/dev/null 2>&1; then export DEBIAN_FRONTEND=noninteractive; apt-get update -y; apt-get install -y wine; fi; fi; steamcmd +login anonymous +quit"`
	stripped := stripWineBootstrap(command)
	if strings.Contains(stripped, "apt-get") || strings.Contains(stripped, "command -v wine") {
		t.Fatalf("expected bootstrap to be removed, got %q", stripped)
	}
	if !strings.Contains(stripped, "steamcmd +login anonymous +quit") {
		t.Fatalf("expected steamcmd command to be preserved, got %q", stripped)
	}
}

func TestStripWineBootstrapRemovesPrivilegedSetupAfterPrefixCommand(t *testing.T) {
	command := `rm -rf /tmp/dumps /tmp/dumps-* 2>/dev/null || true; bash -lc "set -e; if ! command -v wine >/dev/null 2>&1; then if command -v apt-get >/dev/null 2>&1; then export DEBIAN_FRONTEND=noninteractive; apt-get update -y; apt-get install -y wine; fi; fi; steamcmd +login anonymous +quit"`
	stripped := stripWineBootstrap(command)
	if strings.Contains(stripped, "apt-get") || strings.Contains(stripped, "command -v wine") {
		t.Fatalf("expected bootstrap to be removed, got %q", stripped)
	}
	if !strings.Contains(stripped, "rm -rf /tmp/dumps") {
		t.Fatalf("expected prefix command to be preserved, got %q", stripped)
	}
	if !strings.Contains(stripped, "steamcmd +login anonymous +quit") {
		t.Fatalf("expected steamcmd command to be preserved, got %q", stripped)
	}
}

func TestStripWineBootstrapRemovesDanglingQuoteAfterBootstrapRemoval(t *testing.T) {
	command := `bash -lc "set -e; if ! command -v wine >/dev/null 2>&1; then if command -v apt-get >/dev/null 2>&1; then export DEBIAN_FRONTEND=noninteractive; apt-get update; apt-get install -y --no-install-recommends wine-stable screen; elif command -v dnf >/dev/null 2>&1; then dnf install -y wine screen; else echo \"wine missing\" >&2; exit 1; fi; fi; steamcmd +force_install_dir /home/gs46 +login anonymous +app_update 4129620 validate +quit; cd /home/gs46; if [ ! -f R5/ServerDescription.json ]; then timeout 30s env DISPLAY=:0 wine R5/Binaries/Win64/WindroseServer-Win64-Shipping.exe >/dev/null 2>&1 || true; fi"`
	stripped := stripWineBootstrap(command)
	if strings.Count(stripped, `"`)%2 == 1 {
		t.Fatalf("expected balanced quotes after stripping bootstrap, got %q", stripped)
	}
	if strings.Contains(stripped, "command -v wine") || strings.Contains(stripped, "apt-get") {
		t.Fatalf("expected bootstrap setup to be removed, got %q", stripped)
	}
	if !strings.Contains(stripped, "steamcmd +force_install_dir /home/gs46 +login anonymous +app_update 4129620 validate +quit") {
		t.Fatalf("expected steamcmd command to remain, got %q", stripped)
	}
}

func TestBuildSniperInstallShellCommandUsesSharedWorkDirWhenEnabled(t *testing.T) {
	instanceDir := "/home/gs21"
	sharedDir := "/srv/gs/Shared/cs2/server"
	command := replaceSteamCmdExecutable(
		normalizeSteamCmdInstallDir("steamcmd +login anonymous +app_update 730 validate +quit", sharedDir),
		"$STEAMCMD_EXEC",
	)

	steamCmdDir := instanceDirSteamCmdDir(sharedDir)
	installSnippet := steamCmdInstallSnippet(steamCmdDir)
	postInstallSnippet := steamCmdClientSnippet(steamCmdDir, sharedDir)

	shellCmd := buildSniperInstallShellCommand(sharedDir, command, installSnippet, postInstallSnippet)
	if !strings.Contains(shellCmd, "cd "+sharedDir+" &&") {
		t.Fatalf("expected shared install shell command to cd into shared dir, got %q", shellCmd)
	}
	if strings.Contains(shellCmd, "cd "+instanceDir+" &&") {
		t.Fatalf("did not expect shared install shell command to cd into instance dir, got %q", shellCmd)
	}
	if !strings.Contains(shellCmd, "+force_install_dir "+sharedDir) {
		t.Fatalf("expected force_install_dir to point to shared dir, got %q", shellCmd)
	}
}

func TestValidateSteamCmdCommandRequiresAppUpdateAndQuit(t *testing.T) {
	if err := validateSteamCmdCommand("/usr/games/steamcmd +force_install_dir /srv/shared +login anonymous +app_update 730 +quit"); err != nil {
		t.Fatalf("expected valid command, got %v", err)
	}
	if err := validateSteamCmdCommand("steamcmd +force_install_dir /srv/shared +login anonymous +quit"); err == nil || !strings.Contains(err.Error(), "missing_app_update") {
		t.Fatalf("expected missing_app_update, got %v", err)
	}
	if err := validateSteamCmdCommand("steamcmd +force_install_dir /srv/shared +login anonymous +app_update 730"); err == nil || !strings.Contains(err.Error(), "missing_quit") {
		t.Fatalf("expected missing_quit, got %v", err)
	}
}

func TestBuildSniperInstallShellCommandUsesInstanceDirWhenNotShared(t *testing.T) {
	instanceDir := "/home/gs21"
	command := replaceSteamCmdExecutable(
		normalizeSteamCmdInstallDir("steamcmd +login anonymous +app_update 222860 validate +quit", instanceDir),
		"$STEAMCMD_EXEC",
	)

	steamCmdDir := instanceDirSteamCmdDir(instanceDir)
	installSnippet := steamCmdInstallSnippet(steamCmdDir)
	postInstallSnippet := steamCmdClientSnippet(steamCmdDir, instanceDir)

	shellCmd := buildSniperInstallShellCommand(instanceDir, command, installSnippet, postInstallSnippet)
	if !strings.Contains(shellCmd, "cd "+instanceDir+" &&") {
		t.Fatalf("expected non-shared install shell command to cd into instance dir, got %q", shellCmd)
	}
}

func TestPrepareSharedStoragePermissionsCreatesExpectedDirs(t *testing.T) {
	tmp := t.TempDir()
	sharedKey := "1"
	osUsername := os.Getenv("USER")
	if osUsername == "" {
		t.Skip("USER not set")
	}

	origGroup := ensureSharedGroupAndMembershipFn
	ensureSharedGroupAndMembershipFn = func(_, _ string) error { return nil }
	defer func() { ensureSharedGroupAndMembershipFn = origGroup }()

	sharedServer, err := prepareSharedStoragePermissions(tmp, sharedKey, osUsername)
	if err != nil {
		t.Fatalf("prepareSharedStoragePermissions failed: %v", err)
	}
	if sharedServer != filepath.Join(tmp, "Shared", sharedKey, "server") {
		t.Fatalf("unexpected shared server path: %s", sharedServer)
	}
	for _, dir := range []string{
		filepath.Join(tmp, "Shared"),
		filepath.Join(tmp, "Shared", sharedKey),
		filepath.Join(tmp, "Shared", sharedKey, "server"),
		filepath.Join(tmp, "Shared", sharedKey, "server", ".steam"),
		filepath.Join(tmp, "Shared", sharedKey, "server", ".steam", "sdk32"),
		filepath.Join(tmp, "Shared", sharedKey, "server", ".steam", "sdk64"),
		filepath.Join(tmp, "Shared", sharedKey, "server", ".steamcmd"),
		filepath.Join(tmp, "Shared", ".locks"),
	} {
		if st, err := os.Stat(dir); err != nil || !st.IsDir() {
			t.Fatalf("expected directory %s to exist (err=%v)", dir, err)
		}
	}
}

func TestPrepareSharedStoragePermissionsUsesInjectableChown(t *testing.T) {
	tmp := t.TempDir()
	calls := 0
	orig := chownRecursiveFn
	chownRecursiveFn = func(path string, uid, gid int) error {
		calls++
		return nil
	}
	defer func() { chownRecursiveFn = orig }()

	origGroup := ensureSharedGroupAndMembershipFn
	ensureSharedGroupAndMembershipFn = func(_, _ string) error { return nil }
	defer func() { ensureSharedGroupAndMembershipFn = origGroup }()

	osUsername := os.Getenv("USER")
	if osUsername == "" {
		t.Skip("USER not set")
	}
	if _, err := prepareSharedStoragePermissions(tmp, "k1", osUsername); err != nil {
		t.Fatalf("prepareSharedStoragePermissions failed: %v", err)
	}
	if calls == 0 {
		t.Fatalf("expected chownRecursiveFn to be called")
	}
}

func TestPrepareSteamClientRuntimeLinks(t *testing.T) {
	base := t.TempDir()
	shared := filepath.Join(base, "shared")
	game := filepath.Join(base, "game")
	must := func(err error) {
		if err != nil {
			t.Fatal(err)
		}
	}
	must(os.MkdirAll(filepath.Join(shared, ".steam", "sdk64"), 0o755))
	must(os.MkdirAll(filepath.Join(shared, ".steam", "sdk32"), 0o755))
	must(os.WriteFile(filepath.Join(shared, ".steam", "sdk64", "steamclient.so"), []byte("64"), 0o644))
	must(os.WriteFile(filepath.Join(shared, ".steam", "sdk32", "steamclient.so"), []byte("32"), 0o644))
	must(os.MkdirAll(game, 0o755))
	if err := prepareSteamClientRuntimeLinks(shared, game, os.Getenv("USER")); err != nil {
		t.Fatalf("prepareSteamClientRuntimeLinks failed: %v", err)
	}
	for _, rel := range []string{".steam/sdk64/steamclient.so", ".steam/sdk32/steamclient.so"} {
		p := filepath.Join(game, rel)
		info, err := os.Lstat(p)
		if err != nil || info.Mode()&os.ModeSymlink == 0 {
			t.Fatalf("%s must be symlink", rel)
		}
		if _, err := os.Stat(p); err != nil {
			t.Fatalf("%s broken: %v", rel, err)
		}
	}
}

func TestPrepareSteamClientRuntimeLinksFallbackSources(t *testing.T) {
	base := t.TempDir()
	shared := filepath.Join(base, "shared")
	game := filepath.Join(base, "game")
	_ = os.MkdirAll(filepath.Join(shared, "Steam", "linux64"), 0o755)
	_ = os.MkdirAll(filepath.Join(shared, "Steam", "linux32"), 0o755)
	_ = os.WriteFile(filepath.Join(shared, "Steam", "linux64", "steamclient.so"), []byte("64"), 0o644)
	_ = os.WriteFile(filepath.Join(shared, "Steam", "linux32", "steamclient.so"), []byte("32"), 0o644)
	_ = os.MkdirAll(game, 0o755)
	if err := prepareSteamClientRuntimeLinks(shared, game, os.Getenv("USER")); err != nil {
		t.Fatalf("fallback failed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(game, ".steam", "sdk64", "steamclient.so")); err != nil {
		t.Fatal(err)
	}
}

func TestPrepareSteamClientRuntimeLinksMissingSDK64(t *testing.T) {
	base := t.TempDir()
	shared := filepath.Join(base, "shared")
	game := filepath.Join(base, "game")
	_ = os.MkdirAll(shared, 0o755)
	_ = os.MkdirAll(game, 0o755)
	err := prepareSteamClientRuntimeLinks(shared, game, os.Getenv("USER"))
	if err == nil || !strings.Contains(err.Error(), "STEAMCLIENT_SDK64_MISSING") {
		t.Fatalf("expected STEAMCLIENT_SDK64_MISSING, got %v", err)
	}
}
