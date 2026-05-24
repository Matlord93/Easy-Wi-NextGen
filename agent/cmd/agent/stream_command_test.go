package main

import (
	"errors"
	"os/exec"
	"strings"
	"testing"
	"time"
)

func TestStreamCommandNoProgressTimeout(t *testing.T) {
	t.Setenv("EASYWI_STEAMCMD_IDLE_TIMEOUT", "100ms")
	cmd := exec.Command("bash", "-lc", "exec -a steamcmd sleep 7")
	_, err := StreamCommand(cmd, "", nil)
	if !errors.Is(err, errNoProgress) {
		t.Fatalf("expected errNoProgress, got %v", err)
	}
}

func TestStreamCommandTimeout(t *testing.T) {
	t.Setenv("EASYWI_COMMAND_TIMEOUT", "100ms")
	cmd := exec.Command("bash", "-lc", "sleep 1")
	_, err := StreamCommand(cmd, "", nil)
	if !errors.Is(err, errCommandTimeout) {
		t.Fatalf("expected errCommandTimeout, got %v", err)
	}
}

func TestStreamCommandZeroExitSucceeds(t *testing.T) {
	t.Setenv("EASYWI_COMMAND_TIMEOUT", "")
	t.Setenv("EASYWI_STEAMCMD_IDLE_TIMEOUT", "")
	cmd := exec.Command("bash", "-lc", "echo ok")
	_, err := StreamCommand(cmd, "", nil)
	if err != nil {
		t.Fatalf("expected success, got %v", err)
	}
}

func TestStreamCommandNonZeroExitFails(t *testing.T) {
	cmd := exec.Command("bash", "-lc", "exit 3")
	_, err := StreamCommand(cmd, "", nil)
	if err == nil {
		t.Fatal("expected non-zero exit error")
	}
}

func TestParseDurationEnv(t *testing.T) {
	t.Setenv("EASYWI_COMMAND_TIMEOUT", "2s")
	if got := parseDurationEnv("EASYWI_COMMAND_TIMEOUT"); got != 2*time.Second {
		t.Fatalf("unexpected duration %v", got)
	}
}

func TestStreamCommandSteamCmdFailedRunScriptStopsImmediately(t *testing.T) {
	cmd := exec.Command("bash", "-lc", "echo 'Failed to load script file /tmp/a.txt'; sleep 10 # steamcmd +runscript /x")
	_, err := StreamCommand(cmd, "", nil)
	if err == nil || !strings.Contains(err.Error(), "steamcmd_runscript_failed") {
		t.Fatalf("expected steamcmd_runscript_failed, got %v", err)
	}
}

func TestStreamCommandSteamCmdInteractivePromptFailsWithoutUpdate(t *testing.T) {
	cmd := exec.Command("bash", "-lc", "echo 'Steam>'; sleep 10 # steamcmd +runscript /x")
	_, err := StreamCommand(cmd, "", nil)
	if err == nil || !strings.Contains(err.Error(), "steamcmd_interactive_prompt_or_runscript_failed") {
		t.Fatalf("expected steamcmd_interactive_prompt_or_runscript_failed, got %v", err)
	}
}
