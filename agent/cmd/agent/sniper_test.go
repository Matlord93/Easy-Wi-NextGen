package main

import "testing"

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
