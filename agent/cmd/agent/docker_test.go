package main

import (
	"context"
	"os"
	"path/filepath"
	"reflect"
	"testing"

	"easywi/agent/internal/jobs"
)

func withDockerRunner(t *testing.T, runner func(context.Context, string, string, ...string) ([]byte, error)) {
	t.Helper()
	original := dockerRunCommand
	dockerRunCommand = runner
	t.Cleanup(func() { dockerRunCommand = original })
}

func TestDockerContainerListParsesJSONLines(t *testing.T) {
	withDockerRunner(t, func(_ context.Context, dir, binary string, args ...string) ([]byte, error) {
		if dir != "" || binary != "docker" || !reflect.DeepEqual(args, []string{"ps", "-a", "--format", "{{json .}}"}) {
			t.Fatalf("unexpected command: %s %s %#v", dir, binary, args)
		}
		return []byte("{\"Names\":\"web\",\"State\":\"running\"}\n"), nil
	})
	result := handleDockerJob(jobs.Job{Type: "docker.container.list"})
	items := result.resultPayload["containers"].([]map[string]any)
	if result.status != "success" || len(items) != 1 || items[0]["Names"] != "web" {
		t.Fatalf("unexpected result: %#v", result)
	}
}

func TestDockerActionRejectsShellInput(t *testing.T) {
	withDockerRunner(t, func(context.Context, string, string, ...string) ([]byte, error) {
		t.Fatal("command must not run")
		return nil, nil
	})
	result := handleDockerJob(jobs.Job{Type: "docker.container.action", Payload: map[string]any{"name": "web;rm", "action": "stop"}})
	if result.status != "failed" {
		t.Fatalf("unexpected result: %#v", result)
	}
}

func TestDockerLogsBoundsTail(t *testing.T) {
	withDockerRunner(t, func(context.Context, string, string, ...string) ([]byte, error) {
		t.Fatal("command must not run")
		return nil, nil
	})
	result := handleDockerJob(jobs.Job{Type: "docker.container.logs", Payload: map[string]any{"name": "web", "tail": 5001}})
	if result.errorText != "tail must be between 1 and 5000" {
		t.Fatalf("unexpected result: %#v", result)
	}
}

func TestDockerComposeUpdateUsesProvisionedProject(t *testing.T) {
	root := t.TempDir()
	projectDir := filepath.Join(root, "game-1")
	if err := os.Mkdir(projectDir, 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("EASYWI_DOCKER_COMPOSE_DIR", root)
	calls := make([][]string, 0)
	withDockerRunner(t, func(_ context.Context, dir, binary string, args ...string) ([]byte, error) {
		if dir != projectDir || binary != "docker" {
			t.Fatalf("unsafe working directory")
		}
		calls = append(calls, args)
		return nil, nil
	})
	result := handleDockerJob(jobs.Job{Type: "docker.compose.action", Payload: map[string]any{"project": "game-1", "action": "update"}})
	if result.status != "success" || len(calls) != 2 || !reflect.DeepEqual(calls[0], []string{"compose", "pull"}) || !reflect.DeepEqual(calls[1], []string{"compose", "up", "-d", "--remove-orphans"}) {
		t.Fatalf("unexpected result/calls: %#v %#v", result, calls)
	}
}

func TestDockerVolumeRestoreRejectsForeignBackup(t *testing.T) {
	withDockerRunner(t, func(context.Context, string, string, ...string) ([]byte, error) {
		t.Fatal("command must not run")
		return nil, nil
	})
	result := handleDockerJob(jobs.Job{Type: "docker.volume.restore", Payload: map[string]any{"name": "ts-42-data", "backup": "other-20260920T120000Z.tar.gz"}})
	if result.errorText != "invalid Docker backup name" {
		t.Fatalf("unexpected result: %#v", result)
	}
}
