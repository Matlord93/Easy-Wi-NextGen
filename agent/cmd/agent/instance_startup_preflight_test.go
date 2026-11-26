//go:build !windows

package main

import (
	"encoding/base64"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestSniperChecksStartParametersBeforePreparingInstance(t *testing.T) {
	original := commandOutputRunner
	t.Cleanup(func() { commandOutputRunner = original })
	commandOutputRunner = func(name string, args ...string) (string, error) {
		t.Fatalf("host command called with invalid start parameters: %s %v", name, args)
		return "", nil
	}

	for _, action := range []string{"install", "update"} {
		t.Run(action, func(t *testing.T) {
			job := jobs.Job{ID: "source-preflight", Payload: map[string]any{
				"instance_id": "42", "customer_id": "3", "base_dir": "/home",
				"start_params": "./cs2.sh +map {{MAP}}", "cpu_limit": "50", "ram_limit": "4096",
			}}
			result, _ := handleSniperAction(job, action, nil)
			if result.Status != "failed" || !strings.Contains(result.Output["message"], "start parameters: missing template variable MAP") {
				t.Fatalf("unexpected result: %+v", result)
			}
		})
	}
}

func TestReinstallChecksCommandsBeforeStoppingOrRemovingInstance(t *testing.T) {
	for _, invalidCommand := range []string{"start_params", "install_command"} {
		t.Run(invalidCommand, func(t *testing.T) {
			base := t.TempDir()
			instance := filepath.Join(base, buildInstanceUsername("3", "42"))
			if err := os.Mkdir(instance, 0750); err != nil {
				t.Fatal(err)
			}
			data := filepath.Join(instance, "world.dat")
			if err := os.WriteFile(data, []byte("world"), 0640); err != nil {
				t.Fatal(err)
			}
			original := commandOutputRunner
			t.Cleanup(func() { commandOutputRunner = original })
			commandOutputRunner = func(name string, args ...string) (string, error) {
				t.Fatalf("host command called with invalid reinstall parameters: %s %v", name, args)
				return "", nil
			}
			job := jobs.Job{ID: "reinstall-preflight", Payload: map[string]any{
				"instance_id": "42", "customer_id": "3", "base_dir": base,
				"start_params": "./cs2.sh", "install_command": "steamcmd +quit",
				"cpu_limit": "50", "ram_limit": "4096", "disk_limit": "10240",
			}}
			job.Payload[invalidCommand] = "command {{UNKNOWN}}"
			result, _ := handleInstanceReinstall(job, nil)
			if result.Status != "failed" || !strings.Contains(result.Output["message"], "missing template variable UNKNOWN") {
				t.Fatalf("unexpected result: %+v", result)
			}
			content, err := os.ReadFile(data)
			if err != nil || string(content) != "world" {
				t.Fatalf("original world changed: %q (%v)", content, err)
			}
		})
	}
}

func TestRuntimeChecksStartParametersBeforeChangingConfig(t *testing.T) {
	dir := t.TempDir()
	config := filepath.Join(dir, "server.cfg")
	if err := os.WriteFile(config, []byte("old config"), 0640); err != nil {
		t.Fatal(err)
	}
	_, err := applyInstanceRuntimeOverrides(map[string]any{
		"install_path": dir, "base_dir": dir, "start_params": "./cs2.sh +map {{MAP}}",
		"config_files": []any{map[string]any{
			"path": "server.cfg", "content_base64": base64.StdEncoding.EncodeToString([]byte("new config")),
		}},
	})
	if err == nil || !strings.Contains(err.Error(), "missing template variable MAP") {
		t.Fatalf("unexpected error: %v", err)
	}
	content, err := os.ReadFile(config)
	if err != nil || string(content) != "old config" {
		t.Fatalf("configuration changed: %q (%v)", content, err)
	}
}

func TestRuntimeUpdatesExistingUnitScriptAndUsesTheConfiguredGameDirectory(t *testing.T) {
	base := t.TempDir()
	home := filepath.Join(base, "gs342")
	game := filepath.Join(home, "game $source")
	if err := os.MkdirAll(game, 0750); err != nil {
		t.Fatal(err)
	}
	invocation, err := writeStartScriptInGameDir(home, game, "exit 99")
	if err != nil {
		t.Fatal(err)
	}
	result, err := applyInstanceRuntimeOverrides(map[string]any{
		"instance_id": "42", "customer_id": "3", "base_dir": base,
		"install_path": game, "start_params": `printf '%s\n' "$PWD" > launch-directory.txt`,
	})
	if err != nil || result["start_script_path"] != invocation {
		t.Fatalf("did not refresh the script used by the unit: %v (%v)", result, err)
	}
	if output, err := exec.Command("/bin/bash", filepath.Join(home, "_easywi", "start.sh")).CombinedOutput(); err != nil {
		t.Fatalf("start script failed: %s (%v)", output, err)
	}
	content, err := os.ReadFile(filepath.Join(game, "launch-directory.txt"))
	if err != nil || string(content) != game+"\n" {
		t.Fatalf("wrong game working directory: %q (%v)", content, err)
	}
	if _, err := os.Stat(filepath.Join(game, "_easywi", "start.sh")); !os.IsNotExist(err) {
		t.Fatalf("unexpected second script in game directory: %v", err)
	}
}

func TestGameserverRejectsSymlinkGameDirectoryAndConfinesStartScript(t *testing.T) {
	base := t.TempDir()
	home := filepath.Join(base, "gs342")
	if err := os.Mkdir(home, 0750); err != nil {
		t.Fatal(err)
	}
	out := t.TempDir()
	linked := filepath.Join(home, "game")
	if err := os.Symlink(out, linked); err != nil {
		t.Fatal(err)
	}
	if _, _, err := resolveGameserverDirectories(base, linked, "gs342"); err == nil {
		t.Fatal("accepted a symlink as game directory")
	}
	if err := os.Symlink(out, filepath.Join(home, "_easywi")); err != nil {
		t.Fatal(err)
	}
	if _, err := writeStartScriptInGameDir(home, home, "exit 0"); err == nil {
		t.Fatal("published a start script through a symlink outside the account directory")
	}
	if _, err := os.Stat(filepath.Join(out, "start.sh")); !os.IsNotExist(err) {
		t.Fatalf("start script outside account directory: %v", err)
	}
}

func TestGameserverStorageKeepsExistingBaseDirectoryMode(t *testing.T) {
	base := filepath.Join(t.TempDir(), "games")
	if err := os.Mkdir(base, 0700); err != nil {
		t.Fatal(err)
	}
	if err := prepareGameserverBaseDir(base); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(base)
	if err != nil || info.Mode().Perm() != 0700 {
		t.Fatalf("storage base changed: %v (%v)", info, err)
	}
}

func TestReinstallOnlyToleratesStopFailureForMissingService(t *testing.T) {
	for _, scenario := range []struct {
		name    string
		state   string
		allowed bool
	}{
		{"missing", "LoadState=not-found\nActiveState=inactive", true},
		{"active without unit file", "LoadState=not-found\nActiveState=active", false},
		{"existing", "LoadState=loaded\nActiveState=inactive", false},
		{"unavailable", "", false},
	} {
		t.Run(scenario.name, func(t *testing.T) {
			original := commandOutputRunner
			t.Cleanup(func() { commandOutputRunner = original })
			commandOutputRunner = func(_ string, args ...string) (string, error) {
				if args[0] == "stop" {
					return "", errors.New("stop rejected")
				}
				return scenario.state, nil
			}
			err := stopGameserverForReinstall("gs-42")
			if (err == nil) != scenario.allowed {
				t.Fatalf("unexpected stop result for %q: %v", scenario.state, err)
			}
		})
	}
}
