package main

import (
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
