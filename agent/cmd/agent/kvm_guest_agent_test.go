package main

import (
	"bytes"
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/netip"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func kvmAgentFixture(t *testing.T, fn func(context.Context, ...string) ([]byte, error)) {
	t.Helper()
	old := kvmGuestAgentRun
	kvmGuestAgentRun = fn
	t.Cleanup(func() { kvmGuestAgentRun = old })
}

func TestKVMRootPasswordRequiresRunningManagedGuestAndSendsOnlyTheRootHash(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	hash := "$6$0123456789abcdef$" + strings.Repeat("a", 86)
	calls := 0
	kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
		calls++
		if args[3] != kvmTestVM {
			t.Fatalf("unexpected VM %q", args[3])
		}
		var command struct {
			Execute   string `json:"execute"`
			Arguments struct {
				Username string `json:"username"`
				Password string `json:"password"`
				Crypted  bool   `json:"crypted"`
			} `json:"arguments"`
		}
		if err := json.Unmarshal([]byte(args[6]), &command); err != nil {
			t.Fatal(err)
		}
		if command.Execute != "guest-set-user-password" || command.Arguments.Username != "root" ||
			command.Arguments.Password != base64.StdEncoding.EncodeToString([]byte(hash)) || !command.Arguments.Crypted {
			t.Fatal("unexpected guest agent request")
		}
		return []byte(`{"return":{}}`), nil
	})
	input := map[string]any{"vm_id": kvmTestVM, "username": "untrusted", "password_hash": hash, "confirm": "1"}
	if _, err := f.e.perform("vm.root_password", input); err == nil {
		t.Fatal("paused or stopped VM accepted a root password reset")
	}
	f.states[kvmTestVM] = "running"
	input["confirm"] = "0"
	if _, err := f.e.perform("vm.root_password", input); err == nil || calls != 0 {
		t.Fatal("root password reset succeeded without confirmation")
	}
	input["confirm"] = "1"
	result, err := f.e.perform("vm.root_password", input)
	if err != nil || result["root_password_reset"] != true || calls != 1 {
		t.Fatalf("password reset was not confirmed: %v %v", result, err)
	}
}

func TestKVMInstallPasswordWaitsForFreshCloudInitCompletion(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	f.states[kvmTestVM] = "running"
	g := kvmGuest{ID: kvmTestVM, GuestAgentEnabled: true, StaticAddresses: []kvmStaticAddress{{Address: netip.MustParsePrefix("192.0.2.5/24")}}}
	started := time.Now().Truncate(time.Second)
	finished := started.Add(-time.Minute)
	hash := "$6$0123456789abcdef$" + strings.Repeat("a", 86)
	var commands []string
	kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
		var request struct {
			Execute   string         `json:"execute"`
			Arguments map[string]any `json:"arguments"`
		}
		if err := json.Unmarshal([]byte(args[6]), &request); err != nil {
			t.Fatal(err)
		}
		commands = append(commands, request.Execute)
		switch request.Execute {
		case "guest-file-open":
			if request.Arguments["path"] != "/var/lib/cloud/instance/boot-finished" {
				t.Fatal("unexpected guest file")
			}
			return []byte(`{"return":7}`), nil
		case "guest-file-read":
			marker := "2.0 - " + finished.Format("Mon, 2 Jan 2006 15:04:05 -0700") + " - v. 25.1\n"
			return json.Marshal(map[string]any{"return": map[string]any{"buf-b64": base64.StdEncoding.EncodeToString([]byte(marker))}})
		case "guest-file-close":
			return []byte(`{"return":{}}`), nil
		case "guest-network-get-interfaces":
			return []byte(`{"return":[{"name":"eth0","hardware-address":"` + kvmGuestMAC(g.ID) + `","ip-addresses":[{"ip-address-type":"ipv4","ip-address":"192.0.2.5","prefix":24}]}]}`), nil
		case "guest-set-user-password":
			if request.Arguments["username"] != "root" || request.Arguments["password"] != base64.StdEncoding.EncodeToString([]byte(hash)) || request.Arguments["crypted"] != true {
				t.Fatal("installation did not send the expected root hash")
			}
			return []byte(`{"return":{}}`), nil
		default:
			t.Fatalf("unexpected command %s", request.Execute)
			return nil, nil
		}
	})
	if f.e.cloudInitFinished(g.ID, started) {
		t.Fatal("a baked image's old cloud-init marker was accepted")
	}
	commands = nil
	finished = started.Add(time.Second)
	if err := f.e.confirmInstallation(g, hash, started); err != nil {
		t.Fatal(err)
	}
	if want := []string{"guest-file-open", "guest-file-read", "guest-file-close", "guest-network-get-interfaces", "guest-set-user-password"}; !reflect.DeepEqual(commands, want) {
		t.Fatalf("got guest commands %v, want %v", commands, want)
	}
}
func TestKVMGuestAgentReadsOnlySelectedGuestAndPreservesFiles(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	f.states[kvmTestVM] = "running"
	before := kvmCloudFiles(t, f)
	ops, _ := filepath.Glob(filepath.Join(f.e.root, "operations", "*.json"))
	calls := []string{}
	kvmAgentFixture(t, func(ctx context.Context, args ...string) ([]byte, error) {
		if len(args) != 7 || args[0] != "--connect" || args[1] != "qemu:///system" || args[2] != "qemu-agent-command" || args[3] != kvmTestVM || args[4] != "--timeout" || args[5] != "2" {
			t.Fatalf("unsafe request: %v", args)
		}
		deadline, ok := ctx.Deadline()
		if !ok || time.Until(deadline) > 3*time.Second {
			t.Fatal("unbounded guest request")
		}
		var request map[string]string
		_ = json.Unmarshal([]byte(args[6]), &request)
		calls = append(calls, request["execute"])
		switch request["execute"] {
		case "guest-info":
			return []byte(`{"return":{"version":"9.2.0","supported_commands":[{"name":"guest-get-host-name","enabled":true},{"name":"guest-get-osinfo","enabled":true},{"name":"guest-exec","enabled":true},{"name":"guest-network-get-interfaces","enabled":true}]}}`), nil
		case "guest-network-get-interfaces":
			return []byte(`{"return":[{"name":"eth0","hardware-address":"52:54:00:12:34:56","ip-addresses":[{"ip-address-type":"ipv4","ip-address":"192.0.2.10","prefix":24}]}]}`), nil
		case "guest-get-host-name":
			return []byte(`{"return":{"host-name":"actual-guest","host_path":"/secret"}}`), nil
		case "guest-get-osinfo":
			return []byte(`{"return":{"pretty-name":"Debian GNU/Linux 13","private":"secret"}}`), nil
		default:
			t.Fatal("unexpected command")
			return nil, nil
		}
	})
	// Read-only diagnostics must not enumerate the node or inspect qcow2 files.
	withKVMRunner(t, func(ctx context.Context, binary string, args ...string) ([]byte, error) {
		if binary != "virsh" || (args[2] != "dumpxml" && args[2] != "domstate") {
			t.Fatalf("unrelated inspection: %s %v", binary, args)
		}
		return f.run(ctx, binary, args...)
	})
	r := f.job("vm.agent_status", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "command": "guest-exec"})
	kvmAssertSuccess(t, r)
	report := r.resultPayload["guest_agent"].(map[string]any)
	if report["state"] != "reachable" || report["hostname"] != "actual-guest" || report["os"] != "Debian GNU/Linux 13" {
		t.Fatalf("observation %#v", report)
	}
	network := report["network"].(map[string]any)
	if network["state"] != "available" || len(network["interfaces"].([]any)) != 1 {
		t.Fatalf("network: %#v", network)
	}
	if !reflect.DeepEqual(calls, []string{"guest-info", "guest-network-get-interfaces", "guest-get-host-name", "guest-get-osinfo"}) {
		t.Fatal(calls)
	}
	if !reflect.DeepEqual(before, kvmCloudFiles(t, f)) {
		t.Fatal("read changed VM files")
	}
	after, _ := filepath.Glob(filepath.Join(f.e.root, "operations", "*.json"))
	if !reflect.DeepEqual(ops, after) {
		t.Fatal("read wrote operation journal")
	}
	if _, ok := r.resultPayload["inventory"]; ok {
		t.Fatal("read returned node inventory")
	}
}
func TestKVMGuestAgentStateAndInvalidResponses(t *testing.T) {
	for _, scenario := range []string{"stopped", "disabled", "foreign-disk", "offline", "invalid-json", "invalid-version", "oversized", "optional-failure"} {
		t.Run(scenario, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			f.states[kvmTestVM] = "running"
			expected := "unreachable"
			calls := 0
			switch scenario {
			case "stopped":
				f.states[kvmTestVM] = "shut off"
				expected = "not_running"
			case "disabled":
				var g kvmGuest
				_ = kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &g)
				g.GuestAgentEnabled = false
				_ = f.e.define(g)
				_ = kvmWriteJSON(f.e.guestFile(kvmTestVM, "manifest.json"), g)
				expected = "disabled"
			case "foreign-disk":
				f.domains[kvmTestVM] = bytes.ReplaceAll(f.domains[kvmTestVM], []byte(f.e.guestFile(kvmTestVM, "disk.qcow2")), []byte("/private/foreign.qcow2"))
				expected = "unavailable"
			case "invalid-version":
				expected = "invalid_response"
			case "optional-failure":
				expected = "reachable"
			}
			kvmAgentFixture(t, func(ctx context.Context, args ...string) ([]byte, error) {
				calls++
				switch scenario {
				case "invalid-json":
					return []byte("broken"), nil
				case "invalid-version":
					return []byte(`{"return":{"version":"bad\nversion"}}`), nil
				case "oversized":
					return bytes.Repeat([]byte("x"), 131073), nil
				case "optional-failure":
					if calls == 1 {
						return []byte(`{"return":{"version":"9.2","supported_commands":[{"name":"guest-get-osinfo","enabled":true}]}}`), nil
					}
				}
				return nil, errors.New("private transport error")
			})
			r := f.job("vm.agent_status", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
			kvmAssertSuccess(t, r)
			report := r.resultPayload["guest_agent"].(map[string]any)
			if report["state"] != expected {
				t.Fatalf("want %s got %#v", expected, report)
			}
			if (scenario == "stopped" || scenario == "disabled" || scenario == "foreign-disk") && calls != 0 {
				t.Fatal("guest read bypassed state/identity checks")
			}
			data, _ := json.Marshal(r.resultPayload)
			if strings.Contains(string(data), "private transport") {
				t.Fatal("raw error exposed")
			}
		})
	}
}
func TestKVMGuestAgentOutputLimitAndCommandAllowlist(t *testing.T) {
	b := &kvmAgentBuffer{}
	_, err := b.Write(make([]byte, 131073))
	if err == nil || b.Len() != 0 {
		t.Fatal("unbounded output")
	}
	f := kvmNewFixture(t)
	if _, err := f.e.readGuestAgent(kvmTestVM, "guest-exec"); err == nil {
		t.Fatal("arbitrary command accepted")
	}
	if r := f.job("vm.agent_status", kvmTestOp, map[string]any{"vm_id": "../../foreign"}); r.status != "failed" {
		t.Fatal("unsafe VM accepted")
	}
	entries, err := os.ReadDir(filepath.Join(f.e.root, "operations"))
	if err != nil || len(entries) != 0 {
		t.Fatal("invalid diagnostic wrote operation")
	}
}
