//go:build !windows

package main

import (
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func TestGameserverCommandPreservesQuotedAndUnquotedPathVariables(t *testing.T) {
	game := filepath.Join(t.TempDir(), "Steam $game's 100% files")
	if err := os.Mkdir(game, 0750); err != nil {
		t.Fatal(err)
	}
	binary := filepath.Join(game, "cs2.sh")
	if err := os.WriteFile(binary, []byte("#!/bin/sh\nprintf '%s\\n' \"$@\"\n"), 0750); err != nil {
		t.Fatal(err)
	}
	values := buildInstanceTemplateValues(game, game, "", nil, nil)
	for _, executable := range []string{`{{INSTANCE_DIR}}/cs2.sh`, `"{{INSTANCE_DIR}}/cs2.sh"`, `'{{INSTANCE_DIR}}/cs2.sh'`} {
		command, err := renderGameserverCommand(executable+` "{{GAME_DIR}}/server.cfg" '{{USER_HOME_DIR}}'`, values)
		if err != nil {
			t.Fatal(err)
		}
		path, _, required, err := resolveExpectedStartExecutable(game, command, "")
		if err != nil || !required || path != binary {
			t.Fatalf("unexpected executable path: %q (required=%v, err=%v)", path, required, err)
		}
		if err := validateBinaryExists(game, command); err != nil {
			t.Fatal(err)
		}
		args, err := exec.Command("/bin/sh", "-c", command).CombinedOutput()
		if err != nil || string(args) != filepath.Join(game, "server.cfg")+"\n"+game+"\n" {
			t.Fatalf("start arguments were changed: %s (%v)", args, err)
		}
	}
}

func TestSteamCmdInstallKeepsTheConfiguredPathInOneArgument(t *testing.T) {
	game := filepath.Join(t.TempDir(), "Game $files' folder")
	steam := instanceDirSteamCmdDir(game)
	if err := os.MkdirAll(steam, 0750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(steam, "steamcmd.sh"), []byte("#!/bin/sh\nprintf '%s\\n' \"$@\" > steam-args.txt\n"), 0750); err != nil {
		t.Fatal(err)
	}
	const template = `steamcmd +force_install_dir {{INSTANCE_DIR}} +login anonymous +app_update 730 +quit`
	values := buildInstanceTemplateValues(game, game, "", nil, nil)
	command, err := renderGameserverCommand(template, values)
	if err != nil {
		t.Fatal(err)
	}
	command = normalizeSteamCmdInstallDir(normalizeSteamCmdInstallDir(command, game), game)
	command = replaceSteamCmdExecutable(command, "$STEAMCMD_EXEC")
	shell := buildInstanceInstallShellCommand(game, command, true)
	if output, err := exec.Command("/bin/sh", "-c", shell).CombinedOutput(); err != nil {
		t.Fatalf("SteamCMD fixture failed: %s (%v)", output, err)
	}
	args, err := os.ReadFile(filepath.Join(game, "steam-args.txt"))
	if err != nil || string(args) != "+force_install_dir\n"+game+"\n+login\nanonymous\n+app_update\n730\n+quit\n" {
		t.Fatalf("SteamCMD received incorrect arguments: %q (%v)", args, err)
	}
	if err := os.WriteFile(filepath.Join(steam, "steamcmd.sh"), []byte("#!/bin/sh\nexit 23\n"), 0750); err != nil {
		t.Fatal(err)
	}
	output, err := exec.Command("/bin/sh", "-c", shell).CombinedOutput()
	var exit *exec.ExitError
	if !errors.As(err, &exit) || exit.ExitCode() != 23 {
		t.Fatalf("SteamCMD exit code was lost: %s (%v)", output, err)
	}
}

func TestGameserverSystemdUnitQuotesPathArgumentsAndEnvironment(t *testing.T) {
	home := filepath.Join(t.TempDir(), "Games 100% ${USER}")
	game := filepath.Join(home, "cs 2")
	if err := os.MkdirAll(game, 0750); err != nil {
		t.Fatal(err)
	}
	invocation, err := writeStartScriptInGameDir(home, game, "exit 0")
	if err != nil {
		t.Fatal(err)
	}
	unit := systemdUnitTemplate("gs-42", "easywi", game, game, invocation, "", 50, 4096)
	literalGame := strings.ReplaceAll(game, "%", "%%")
	for _, line := range []string{
		"WorkingDirectory=" + literalGame,
		`Environment="HOME=` + literalGame + `"`,
		`Environment="XDG_CONFIG_HOME=` + literalGame + `/.config"`,
		`ReadWritePaths="` + literalGame + `"`,
		`ExecStartPre=/usr/bin/test -d "` + strings.ReplaceAll(literalGame, "$", "$$") + `"`,
		` -- /bin/bash "` + strings.ReplaceAll(strings.ReplaceAll(home, "%", "%%"), "$", "$$") + `/_easywi/start.sh"`,
	} {
		if !strings.Contains(unit, line+"\n") {
			t.Fatalf("expected %q in systemd unit:\n%s", line, unit)
		}
	}
}
