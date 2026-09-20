package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var dockerIdentifierPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$`)
var dockerReferencePattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_./:@-]{0,255}$`)
var dockerRunCommand = func(ctx context.Context, dir, name string, args ...string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, name, args...)
	cmd.Dir = dir
	return cmd.CombinedOutput()
}

func handleDockerJob(job jobs.Job) orchestratorResult {
	switch job.Type {
	case "docker.container.list":
		return dockerJSONList("containers", "ps", "-a", "--format", "{{json .}}")
	case "docker.image.list":
		return dockerJSONList("images", "image", "ls", "--format", "{{json .}}")
	case "docker.volume.list":
		return dockerJSONList("volumes", "volume", "ls", "--format", "{{json .}}")
	case "docker.network.list":
		return dockerJSONList("networks", "network", "ls", "--format", "{{json .}}")
	case "docker.container.action":
		return handleDockerContainerAction(job)
	case "docker.container.logs":
		return handleDockerLogs(job)
	case "docker.container.update":
		return handleDockerUpdate(job)
	case "docker.image.remove":
		return handleDockerRemove(job, "image")
	case "docker.volume.remove":
		return handleDockerRemove(job, "volume")
	case "docker.volume.backup":
		return handleDockerVolumeBackup(job)
	case "docker.volume.restore":
		return handleDockerVolumeRestore(job)
	case "docker.network.remove":
		return handleDockerRemove(job, "network")
	case "docker.compose.action":
		return handleDockerCompose(job)
	default:
		return dockerFailure("unsupported Docker job type")
	}
}

func handleDockerVolumeBackup(job jobs.Job) orchestratorResult {
	volume, failure := dockerPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	root, err := dockerBackupRoot()
	if err != nil {
		return dockerFailure("Docker backup directory is unavailable")
	}
	filename := fmt.Sprintf("%s-%s.tar.gz", volume, time.Now().UTC().Format("20060102T150405Z"))
	if _, result := runDocker("", "run", "--rm", "--network", "none", "-v", volume+":/source:ro", "-v", root+":/backup", "alpine:3.20", "tar", "-C", "/source", "-czf", "/backup/"+filename, "."); result != nil {
		return *result
	}
	return dockerSuccess(map[string]any{"name": volume, "backup": filename})
}

func handleDockerVolumeRestore(job jobs.Job) orchestratorResult {
	volume, failure := dockerPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	filename := strings.TrimSpace(payloadValue(job.Payload, "backup"))
	if !dockerBackupPattern.MatchString(filename) || !strings.HasPrefix(filename, volume+"-") {
		return dockerFailure("invalid Docker backup name")
	}
	root, err := dockerBackupRoot()
	if err != nil {
		return dockerFailure("Docker backup directory is unavailable")
	}
	if info, statErr := os.Stat(filepath.Join(root, filename)); statErr != nil || !info.Mode().IsRegular() {
		return dockerFailure("Docker backup does not exist")
	}
	if _, result := runDocker("", "run", "--rm", "--network", "none", "-e", "BACKUP="+filename, "-v", volume+":/target", "-v", root+":/backup:ro", "alpine:3.20", "sh", "-c", "rm -rf /target/* /target/.[!.]* /target/..?* 2>/dev/null || true; tar -C /target -xzf /backup/$BACKUP"); result != nil {
		return *result
	}
	return dockerSuccess(map[string]any{"name": volume, "backup": filename, "restored": true})
}

var dockerBackupPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.-]{0,180}\.tar\.gz$`)

func dockerBackupRoot() (string, error) {
	root := strings.TrimSpace(os.Getenv("EASYWI_DOCKER_BACKUP_DIR"))
	if root == "" {
		root = "/srv/easywi/backups/docker"
	}
	if err := os.MkdirAll(root, 0o750); err != nil {
		return "", err
	}
	return filepath.EvalSymlinks(root)
}

func handleDockerContainerAction(job jobs.Job) orchestratorResult {
	name, failure := dockerPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	if !containsString([]string{"start", "stop", "restart", "pause", "unpause", "remove"}, action) {
		return dockerFailure("unsupported container action")
	}
	args := []string{action, name}
	if action == "remove" {
		args = []string{"rm", name}
	}
	if _, result := runDocker("", args...); result != nil {
		return *result
	}
	return dockerSuccess(map[string]any{"name": name, "action": action})
}

func handleDockerLogs(job jobs.Job) orchestratorResult {
	name, failure := dockerPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	tail := 200
	if raw := strings.TrimSpace(payloadValue(job.Payload, "tail")); raw != "" {
		parsed, err := strconv.Atoi(raw)
		if err != nil || parsed < 1 || parsed > 5000 {
			return dockerFailure("tail must be between 1 and 5000")
		}
		tail = parsed
	}
	out, result := runDocker("", "logs", "--timestamps", "--tail", strconv.Itoa(tail), name)
	if result != nil {
		return *result
	}
	return dockerSuccess(map[string]any{"name": name, "logs": string(out), "tail": tail})
}

func handleDockerUpdate(job jobs.Job) orchestratorResult {
	name, failure := dockerPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	out, result := runDocker("", "inspect", "--format", "{{.Config.Image}}", name)
	if result != nil {
		return *result
	}
	image := strings.TrimSpace(string(out))
	if image == "" {
		return dockerFailure("container image could not be determined")
	}
	if _, result = runDocker("", "pull", image); result != nil {
		return *result
	}
	return dockerSuccess(map[string]any{"name": name, "image": image, "pulled": true, "recreate_required": true})
}

func handleDockerRemove(job jobs.Job, resource string) orchestratorResult {
	name, failure := dockerPayloadReference(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	if _, result := runDocker("", resource, "rm", name); result != nil {
		return *result
	}
	return dockerSuccess(map[string]any{"name": name, "resource": resource, "removed": true})
}

func handleDockerCompose(job jobs.Job) orchestratorResult {
	project, failure := dockerPayloadIdentifier(job.Payload, "project")
	if failure != nil {
		return *failure
	}
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	allowed := map[string][]string{"up": {"compose", "up", "-d"}, "down": {"compose", "down"}, "start": {"compose", "start"}, "stop": {"compose", "stop"}, "restart": {"compose", "restart"}, "pull": {"compose", "pull"}}
	dir, err := dirForComposeProject(project)
	if err != nil {
		return dockerFailure("Docker Compose project is not provisioned")
	}
	info, err := os.Stat(dir)
	if err != nil || !info.IsDir() {
		return dockerFailure("Docker Compose project is not provisioned")
	}
	if action == "update" {
		if _, result := runDocker(dir, "compose", "pull"); result != nil {
			return *result
		}
		if _, result := runDocker(dir, "compose", "up", "-d", "--remove-orphans"); result != nil {
			return *result
		}
		return dockerSuccess(map[string]any{"project": project, "action": action})
	}
	args, ok := allowed[action]
	if !ok {
		return dockerFailure("unsupported Docker Compose action")
	}
	if _, result := runDocker(dir, args...); result != nil {
		return *result
	}
	return dockerSuccess(map[string]any{"project": project, "action": action})
}

func dirForComposeProject(project string) (string, error) {
	root := strings.TrimSpace(os.Getenv("EASYWI_DOCKER_COMPOSE_DIR"))
	if root == "" {
		root = "/srv/easywi/compose"
	}
	realRoot, err := filepath.EvalSymlinks(root)
	if err != nil {
		return "", err
	}
	realProject, err := filepath.EvalSymlinks(filepath.Join(realRoot, project))
	if err != nil {
		return "", err
	}
	relative, err := filepath.Rel(realRoot, realProject)
	if err != nil || relative == ".." || strings.HasPrefix(relative, ".."+string(filepath.Separator)) {
		return "", fmt.Errorf("compose project escapes configured root")
	}
	return realProject, nil
}

func dockerJSONList(key string, args ...string) orchestratorResult {
	out, result := runDocker("", args...)
	if result != nil {
		return *result
	}
	items := make([]map[string]any, 0)
	for _, line := range nonEmptyLines(string(out)) {
		var item map[string]any
		if json.Unmarshal([]byte(line), &item) == nil {
			items = append(items, item)
		}
	}
	return dockerSuccess(map[string]any{key: items})
}

func runDocker(dir string, args ...string) ([]byte, *orchestratorResult) {
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Minute)
	defer cancel()
	out, err := dockerRunCommand(ctx, dir, "docker", args...)
	if err != nil {
		message := strings.TrimSpace(string(out))
		if message == "" {
			message = err.Error()
		}
		result := dockerFailure("docker command failed: " + message)
		return nil, &result
	}
	return out, nil
}

func dockerPayloadIdentifier(payload map[string]any, key string) (string, *orchestratorResult) {
	value := strings.TrimSpace(payloadValue(payload, key))
	if !dockerIdentifierPattern.MatchString(value) {
		result := dockerFailure(fmt.Sprintf("invalid or missing Docker %s", key))
		return "", &result
	}
	return value, nil
}
func dockerPayloadReference(payload map[string]any, key string) (string, *orchestratorResult) {
	value := strings.TrimSpace(payloadValue(payload, key))
	if !dockerReferencePattern.MatchString(value) {
		result := dockerFailure(fmt.Sprintf("invalid or missing Docker %s", key))
		return "", &result
	}
	return value, nil
}
func dockerSuccess(payload map[string]any) orchestratorResult {
	return orchestratorResult{status: "success", resultPayload: payload}
}
func dockerFailure(message string) orchestratorResult {
	return orchestratorResult{status: "failed", errorText: message}
}
