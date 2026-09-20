package main

import (
	"context"
	"fmt"
	"os/exec"
	"regexp"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var kvmIdentifierPattern = regexp.MustCompile(`^[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}$`)
var kvmRunCommand = func(ctx context.Context, name string, args ...string) ([]byte, error) {
	return exec.CommandContext(ctx, name, args...).CombinedOutput()
}

func handleKVMJob(job jobs.Job) orchestratorResult {
	switch job.Type {
	case "kvm.vm.list":
		return handleKVMList(job)
	case "kvm.vm.status":
		return handleKVMStatus(job)
	case "kvm.vm.action":
		return handleKVMAction(job)
	case "kvm.snapshot.list":
		return handleKVMSnapshotList(job)
	case "kvm.snapshot.create", "kvm.snapshot.delete", "kvm.snapshot.revert":
		return handleKVMSnapshotMutation(job)
	case "kvm.vm.limits.apply":
		return handleKVMLimits(job)
	case "kvm.network.list":
		return handleKVMNetworkList(job)
	case "kvm.network.action":
		return handleKVMNetworkAction(job)
	default:
		return kvmFailure("unsupported KVM job type")
	}
}

func handleKVMList(_ jobs.Job) orchestratorResult {
	out, result := runVirsh("list", "--all", "--name")
	if result != nil {
		return *result
	}
	guests := make([]map[string]any, 0)
	for _, name := range nonEmptyLines(string(out)) {
		if !validKVMIdentifier(name) {
			continue
		}
		stateOut, stateResult := runVirsh("domstate", name)
		state := "unknown"
		if stateResult == nil {
			state = strings.TrimSpace(string(stateOut))
		}
		guests = append(guests, map[string]any{"name": name, "state": state})
	}
	return kvmSuccess(map[string]any{"guests": guests})
}

func handleKVMStatus(job jobs.Job) orchestratorResult {
	name, failure := kvmPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	out, result := runVirsh("dominfo", name)
	if result != nil {
		return *result
	}
	return kvmSuccess(map[string]any{"name": name, "details": parseVirshKeyValues(string(out))})
}

func handleKVMAction(job jobs.Job) orchestratorResult {
	name, failure := kvmPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	argsByAction := map[string][]string{
		"start": {"start", name}, "shutdown": {"shutdown", name}, "stop": {"destroy", name},
		"reboot": {"reboot", name}, "suspend": {"suspend", name}, "resume": {"resume", name},
		"autostart": {"autostart", name}, "autostart-disable": {"autostart", name, "--disable"},
	}
	args, ok := argsByAction[action]
	if !ok {
		return kvmFailure("unsupported KVM VM action")
	}
	if _, result := runVirsh(args...); result != nil {
		return *result
	}
	return kvmSuccess(map[string]any{"name": name, "action": action})
}

func handleKVMSnapshotList(job jobs.Job) orchestratorResult {
	name, failure := kvmPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	out, result := runVirsh("snapshot-list", name, "--name")
	if result != nil {
		return *result
	}
	return kvmSuccess(map[string]any{"name": name, "snapshots": nonEmptyLines(string(out))})
}

func handleKVMSnapshotMutation(job jobs.Job) orchestratorResult {
	name, failure := kvmPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	snapshot, failure := kvmPayloadIdentifier(job.Payload, "snapshot")
	if failure != nil {
		return *failure
	}
	command := strings.TrimPrefix(job.Type, "kvm.snapshot.")
	verb := map[string]string{"create": "snapshot-create-as", "delete": "snapshot-delete", "revert": "snapshot-revert"}[command]
	if verb == "" {
		return kvmFailure("unsupported KVM snapshot action")
	}
	if _, result := runVirsh(verb, name, snapshot); result != nil {
		return *result
	}
	return kvmSuccess(map[string]any{"name": name, "snapshot": snapshot, "action": command})
}

func handleKVMLimits(job jobs.Job) orchestratorResult {
	name, failure := kvmPayloadIdentifier(job.Payload, "name")
	if failure != nil {
		return *failure
	}
	vcpus, err := positivePayloadInt(job.Payload, "vcpus", 1, 1024)
	if err != nil {
		return kvmFailure(err.Error())
	}
	memoryMiB, err := positivePayloadInt(job.Payload, "memory_mib", 128, 16*1024*1024)
	if err != nil {
		return kvmFailure(err.Error())
	}
	if _, result := runVirsh("setvcpus", name, strconv.Itoa(vcpus), "--config", "--maximum"); result != nil {
		return *result
	}
	if _, result := runVirsh("setvcpus", name, strconv.Itoa(vcpus), "--config"); result != nil {
		return *result
	}
	if _, result := runVirsh("setmaxmem", name, fmt.Sprintf("%dMiB", memoryMiB), "--config"); result != nil {
		return *result
	}
	if _, result := runVirsh("setmem", name, fmt.Sprintf("%dMiB", memoryMiB), "--config"); result != nil {
		return *result
	}
	return kvmSuccess(map[string]any{"name": name, "vcpus": vcpus, "memory_mib": memoryMiB})
}

func handleKVMNetworkList(_ jobs.Job) orchestratorResult {
	out, result := runVirsh("net-list", "--all", "--name")
	if result != nil {
		return *result
	}
	return kvmSuccess(map[string]any{"networks": nonEmptyLines(string(out))})
}

func handleKVMNetworkAction(job jobs.Job) orchestratorResult {
	name, failure := kvmPayloadIdentifier(job.Payload, "network")
	if failure != nil {
		return *failure
	}
	action := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "action")))
	argsByAction := map[string][]string{
		"start": {"net-start", name}, "stop": {"net-destroy", name},
		"autostart": {"net-autostart", name}, "autostart-disable": {"net-autostart", name, "--disable"},
	}
	args, ok := argsByAction[action]
	if !ok {
		return kvmFailure("unsupported KVM network action")
	}
	if _, result := runVirsh(args...); result != nil {
		return *result
	}
	return kvmSuccess(map[string]any{"network": name, "action": action})
}

func runVirsh(args ...string) ([]byte, *orchestratorResult) {
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()
	out, err := kvmRunCommand(ctx, "virsh", args...)
	if err != nil {
		message := strings.TrimSpace(string(out))
		if message == "" {
			message = err.Error()
		}
		result := kvmFailure("virsh command failed: " + message)
		return nil, &result
	}
	return out, nil
}

func kvmPayloadIdentifier(payload map[string]any, key string) (string, *orchestratorResult) {
	value := strings.TrimSpace(payloadValue(payload, key))
	if !validKVMIdentifier(value) {
		result := kvmFailure(fmt.Sprintf("invalid or missing KVM %s", key))
		return "", &result
	}
	return value, nil
}

func validKVMIdentifier(value string) bool { return kvmIdentifierPattern.MatchString(value) }

func positivePayloadInt(payload map[string]any, key string, minimum, maximum int) (int, error) {
	value, err := strconv.Atoi(strings.TrimSpace(payloadValue(payload, key)))
	if err != nil || value < minimum || value > maximum {
		return 0, fmt.Errorf("%s must be between %d and %d", key, minimum, maximum)
	}
	return value, nil
}

func nonEmptyLines(value string) []string {
	items := make([]string, 0)
	for _, line := range strings.Split(strings.ReplaceAll(value, "\r\n", "\n"), "\n") {
		if line = strings.TrimSpace(line); line != "" {
			items = append(items, line)
		}
	}
	return items
}

func parseVirshKeyValues(value string) map[string]string {
	result := make(map[string]string)
	for _, line := range nonEmptyLines(value) {
		parts := strings.SplitN(line, ":", 2)
		if len(parts) == 2 {
			result[strings.TrimSpace(parts[0])] = strings.TrimSpace(parts[1])
		}
	}
	return result
}

func kvmSuccess(payload map[string]any) orchestratorResult {
	return orchestratorResult{status: "success", resultPayload: payload}
}

func kvmFailure(message string) orchestratorResult {
	return orchestratorResult{status: "failed", errorText: message}
}
