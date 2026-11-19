//go:build !windows

package main

import (
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestSyncInstanceRuntimeLimitsPersistsNativeHeadroom(t *testing.T) {
	original := commandOutputRunner
	t.Cleanup(func() { commandOutputRunner = original })
	var commands [][]string
	commandOutputRunner = func(name string, args ...string) (string, error) {
		commands = append(commands, append([]string{name}, args...))
		return "", nil
	}
	if err := syncInstanceRuntimeLimits("gs-42", map[string]any{"cpu_limit": "200", "ram_limit": "5120"}); err != nil {
		t.Fatal(err)
	}
	want := [][]string{{"systemctl", "set-property", "--", "gs-42", "CPUQuota=200%", "MemoryMax=5120M", "MemorySwapMax=0"}}
	if !reflect.DeepEqual(commands, want) {
		t.Fatalf("persistent properties: got %v, want %v", commands, want)
	}
	commands = nil
	if err := syncInstanceRuntimeLimits("gs-42", nil); err != nil {
		t.Fatal(err)
	}
	if len(commands) != 0 {
		t.Fatalf("legacy payload must not change limits: %v", commands)
	}
	for _, payload := range []map[string]any{
		{"ram_limit": "0"}, {"ram_limit": "-1"}, {"ram_limit": "5120M"}, {"cpu_limit": "0"},
		{"cpu_limit": "100", "ram_limit": "invalid"},
	} {
		if err := syncInstanceRuntimeLimits("gs-42", payload); err == nil {
			t.Fatalf("invalid limits accepted: %v", payload)
		}
	}
	if len(commands) != 0 {
		t.Fatalf("invalid limits must not be partially applied: %v", commands)
	}
}

func TestInstancePowerUpdatesLimitsBeforePublishingHeap(t *testing.T) {
	for _, action := range []string{"start", "restart"} {
		for _, rejectLimits := range []bool{false, true} {
			name := action
			if rejectLimits {
				name += "-rejected-limits"
			}
			t.Run(name, func(t *testing.T) {
				dir := t.TempDir()
				scripts := filepath.Join(dir, "_easywi")
				if err := os.MkdirAll(scripts, 0750); err != nil {
					t.Fatal(err)
				}
				script := filepath.Join(scripts, "start.sh")
				old := "#!/bin/sh\njava -Xmx3072M\n"
				if err := os.WriteFile(script, []byte(old), 0750); err != nil {
					t.Fatal(err)
				}
				original := commandOutputRunner
				t.Cleanup(func() { commandOutputRunner = original })
				var commands []string
				memoryMax := "4096M" // A service installed before the panel update.
				commandOutputRunner = func(command string, args ...string) (string, error) {
					commands = append(commands, command+" "+strings.Join(args, " "))
					current, err := os.ReadFile(script)
					if err != nil {
						t.Fatal(err)
					}
					if args[0] == "set-property" {
						if string(current) != old {
							t.Fatal("heap was rewritten before the service limits were accepted")
						}
						if rejectLimits {
							return "", errors.New("simulated property failure")
						}
						memoryMax = "5120M"
						return "", nil
					}
					if args[0] != action || memoryMax != "5120M" || !strings.Contains(string(current), "-Xmx4096M") {
						t.Fatalf("unsafe ordering: command=%v, memory=%s, script=%s", args, memoryMax, current)
					}
					// Stop before actual health checks, process execution or log streaming.
					return "", errors.New("simulated service action")
				}
				job := jobs.Job{ID: "runtime-test", Payload: map[string]any{"instance_id": "42", "install_path": dir, "cpu_limit": "100", "ram_limit": "5120", "start_params": "java -Xmx4096M"}}
				var result jobs.Result
				if action == "start" {
					result, _ = handleInstanceStart(job, nil)
				} else {
					result, _ = handleInstanceRestart(job, nil)
				}
				if result.Status != "failed" {
					t.Fatalf("expected simulated failure, got %s", result.Status)
				}
				if rejectLimits {
					if len(commands) != 1 {
						t.Fatalf("start attempted after limit failure: %v", commands)
					}
					current, err := os.ReadFile(script)
					if err != nil {
						t.Fatal(err)
					}
					if string(current) != old {
						t.Fatal("failed property update replaced the safe old heap")
					}
				} else if len(commands) != 2 || !strings.Contains(result.Output["message"], "simulated service action") {
					t.Fatalf("did not reach the safe service action: %v, %v", commands, result.Output)
				}
			})
		}
	}
}
