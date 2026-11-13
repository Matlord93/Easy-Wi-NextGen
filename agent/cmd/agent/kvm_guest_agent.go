package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"os/exec"
	"strings"
	"time"
	"unicode/utf8"

	"easywi/agent/internal/jobs"
)

type kvmAgentBuffer struct{ bytes.Buffer }

func (b *kvmAgentBuffer) Write(data []byte) (int, error) {
	if b.Len()+len(data) > 131072 {
		return 0, errors.New("guest agent response exceeds limit")
	}
	return b.Buffer.Write(data)
}

// Keep untrusted guest output bounded before decoding, independently of the
// general provisioning runner. No shell or arbitrary QGA request is accepted.
var kvmGuestAgentRun = func(ctx context.Context, args ...string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, "virsh", args...)
	cmd.Env = append(os.Environ(), "LC_ALL=C", "LANG=C")
	cmd.WaitDelay = time.Second
	out := &kvmAgentBuffer{}
	cmd.Stdout = out
	cmd.Stderr = io.Discard
	err := cmd.Run()
	return out.Bytes(), err
}

func kvmAgentText(value any, limit int) string {
	s, ok := value.(string)
	if !ok || len(s) > limit || !utf8.ValidString(s) {
		return ""
	}
	for _, c := range s {
		if c < 32 || c == 127 {
			return ""
		}
	}
	return s
}
func (e *kvmEngine) readGuestAgent(id, command string) (any, error) {
	switch command {
	case "guest-info", "guest-get-host-name", "guest-get-osinfo", "guest-network-get-interfaces":
	default:
		return nil, errors.New("unsupported guest agent read")
	}
	uri := os.Getenv("EASYWI_KVM_URI")
	if uri == "" {
		uri = "qemu:///system"
	}
	if uri != "qemu:///system" && uri != "qemu:///session" {
		return nil, errors.New("invalid local libvirt connection")
	}
	ctx, cancel := context.WithTimeout(e.ctx, 3*time.Second)
	defer cancel()
	request, _ := json.Marshal(map[string]string{"execute": command})
	output, err := kvmGuestAgentRun(ctx, "--connect", uri, "qemu-agent-command", id, "--timeout", "2", string(request))
	if err != nil {
		return nil, err
	}
	var response struct {
		Return json.RawMessage `json:"return"`
		Error  json.RawMessage `json:"error"`
	}
	if len(output) > 131072 || json.Unmarshal(output, &response) != nil || (len(response.Return) == 0 || string(response.Return) == "null") || len(response.Error) > 0 {
		return nil, errors.New("invalid guest agent response")
	}
	var result any
	if json.Unmarshal(response.Return, &result) != nil {
		return nil, errors.New("invalid guest agent result")
	}
	return result, nil
}
func handleKVMGuestAgentStatus(job jobs.Job) orchestratorResult {
	ctx, cancel := context.WithTimeout(context.Background(), 12*time.Second)
	defer cancel()
	report := func(state string, fields map[string]any) orchestratorResult {
		if fields == nil {
			fields = map[string]any{}
		}
		fields["state"] = state
		fields["observed_at"] = kvmNow()
		return kvmSuccess(map[string]any{"vm_id": payloadValue(job.Payload, "vm_id"), "guest_agent": fields})
	}
	id := payloadValue(job.Payload, "vm_id")
	if !kvmUUID.MatchString(id) {
		return kvmFailure("invalid VM UUID")
	}
	e, err := newKVMEngine(ctx)
	if err != nil {
		return report("unavailable", nil)
	}
	unlock, err := lockKVM(ctx, e.root+"/host.lock")
	if err != nil {
		return report("busy", nil)
	}
	defer unlock()
	var g kvmGuest
	if kvmReadJSON(e.guestFile(id, "manifest.json"), &g) != nil || g.ID != id {
		return report("unavailable", nil)
	}
	xml, err := e.virsh("dumpxml", id, "--inactive")
	if err != nil || e.verifyXML(g, xml) != nil {
		return report("unavailable", nil)
	}
	state, err := e.virsh("domstate", id, "--reason")
	if err != nil {
		return report("unavailable", nil)
	}
	if !strings.HasPrefix(strings.TrimSpace(string(state)), "running (") {
		return report("not_running", nil)
	}
	if !g.GuestAgentEnabled {
		return report("disabled", nil)
	}
	rawInfo, err := e.readGuestAgent(id, "guest-info")
	if err != nil {
		return report("unreachable", nil)
	}
	info, ok := rawInfo.(map[string]any)
	if !ok {
		return report("invalid_response", nil)
	}
	version := kvmAgentText(info["version"], 128)
	if version == "" {
		return report("invalid_response", nil)
	}
	fields := map[string]any{"version": version}
	supported := map[string]bool{}
	if commands, ok := info["supported_commands"].([]any); ok {
		for i, raw := range commands {
			if i >= 256 {
				break
			}
			if row, ok := raw.(map[string]any); ok {
				name, _ := row["name"].(string)
				supported[name] = row["enabled"] == true
			}
		}
	}
	fields["network"] = kvmEmptyGuestNetwork("unsupported")
	if supported["guest-network-get-interfaces"] {
		data, err := e.readGuestAgent(id, "guest-network-get-interfaces")
		if err != nil {
			fields["network"] = kvmEmptyGuestNetwork("unavailable")
		} else {
			fields["network"] = kvmGuestNetwork(data)
		}
	}
	for _, field := range []struct{ command, key, source string }{{"guest-get-host-name", "hostname", "host-name"}, {"guest-get-osinfo", "os", "pretty-name"}} {
		if !supported[field.command] {
			continue
		}
		if result, err := e.readGuestAgent(id, field.command); err == nil {
			if values, ok := result.(map[string]any); ok {
				if value := kvmAgentText(values[field.source], 255); value != "" {
					fields[field.key] = value
				}
			}
		}
	}
	return report("reachable", fields)
}
