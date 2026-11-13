package main

import (
	"bytes"
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"encoding/json"
	"encoding/xml"
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"golang.org/x/crypto/ssh"
	"gopkg.in/yaml.v3"
)

func kvmProvisioningKey(t *testing.T) string {
	t.Helper()
	pub, _, err := ed25519.GenerateKey(rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	key, err := ssh.NewPublicKey(pub)
	if err != nil {
		t.Fatal(err)
	}
	return strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key)))
}
func kvmCloudFiles(t *testing.T, f *kvmFixture) map[string][]byte {
	t.Helper()
	result := map[string][]byte{}
	for _, name := range []string{"disk.qcow2", "seed.iso", "user-data", "meta-data", "network-config", "manifest.json"} {
		data, err := os.ReadFile(f.e.guestFile(kvmTestVM, name))
		if err != nil {
			t.Fatal(err)
		}
		result[name] = data
	}
	return result
}
func TestKVMManagedCloudInitUpdatePreservesIdentityAndData(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	path := f.e.guestFile(kvmTestVM, "user-data")
	data, _ := os.ReadFile(path)
	data = append(data, []byte("write_files:\n  - path: /etc/custom\n    content: keep-me\n")...)
	if err := os.WriteFile(path, data, 0600); err != nil {
		t.Fatal(err)
	}
	before := kvmCloudFiles(t, f)
	key := kvmProvisioningKey(t)
	kvmAssertSuccess(t, f.job("vm.cloud_init", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "hostname": "changed-vm", "ssh_keys": []string{key}}))
	after := kvmCloudFiles(t, f)
	for _, name := range []string{"disk.qcow2", "network-config"} {
		if !bytes.Equal(before[name], after[name]) {
			t.Fatalf("%s changed", name)
		}
	}
	var meta map[string]any
	if err := yaml.Unmarshal(after["meta-data"], &meta); err != nil {
		t.Fatal(err)
	}
	if meta["instance-id"] != kvmTestVM || meta["local-hostname"] != "changed-vm" {
		t.Fatalf("metadata: %#v", meta)
	}
	var g kvmGuest
	_ = json.Unmarshal(after["manifest.json"], &g)
	view, err := f.e.cloudInitView(g)
	if err != nil || view["hostname"] != "changed-vm" || !reflect.DeepEqual(view["ssh_keys"], []string{key}) {
		t.Fatalf("view %#v %v", view, err)
	}
	if !strings.Contains(string(after["user-data"]), "keep-me") {
		t.Fatal("unrelated config lost")
	}
	if f.native {
		iso, err := exec.Command("isoinfo", "-R", "-i", f.e.guestFile(kvmTestVM, "seed.iso"), "-x", "/user-data").CombinedOutput()
		if err != nil || !strings.Contains(string(iso), "changed-vm") {
			t.Fatalf("updated ISO: %s %v", iso, err)
		}
	}
}
func TestKVMManagedCloudInitInvalidAndFailedUpdatesLeaveFilesUntouched(t *testing.T) {
	for _, scenario := range []string{"invalid-key", "key-options", "running", "changed-identity", "cloud-localds-fails", "oversize-file"} {
		t.Run(scenario, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			key := kvmProvisioningKey(t)
			switch scenario {
			case "invalid-key":
				key = "ssh-ed25519 AAAA"
			case "key-options":
				key = "command=\"danger\" " + key
			case "running":
				f.states[kvmTestVM] = "running"
			case "changed-identity":
				_ = os.WriteFile(f.e.guestFile(kvmTestVM, "meta-data"), []byte("instance-id: other\n"), 0600)
			case "cloud-localds-fails":
				withKVMRunner(t, func(ctx context.Context, binary string, args ...string) ([]byte, error) {
					if binary == "cloud-localds" {
						return nil, errors.New("synthetic failure")
					}
					return f.run(ctx, binary, args...)
				})
			case "oversize-file":
				_ = os.WriteFile(f.e.guestFile(kvmTestVM, "meta-data"), bytes.Repeat([]byte("x"), (1<<20)+1), 0600)
			}
			before := kvmCloudFiles(t, f)
			result := f.job("vm.cloud_init", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "hostname": "updated", "ssh_keys": []string{key}})
			if result.status != "failed" {
				t.Fatal("unsafe update accepted")
			}
			if !reflect.DeepEqual(before, kvmCloudFiles(t, f)) {
				t.Fatal("failed update changed files")
			}
			stages, _ := filepath.Glob(filepath.Join(f.e.root, "instances", kvmTestVM, ".cloud-init-*"))
			if len(stages) > 0 {
				t.Fatal("abandoned staging")
			}
		})
	}
}
func TestKVMManagedBootAndAgentPrepareAreOfflineAndSurviveSnapshot(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	// Simulate a legacy managed VM without a channel and take its snapshot.
	var g kvmGuest
	_ = kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &g)
	g.GuestAgentEnabled = false
	if err := f.e.define(g); err != nil {
		t.Fatal(err)
	}
	_ = kvmWriteJSON(f.e.guestFile(kvmTestVM, "manifest.json"), g)
	kvmAssertSuccess(t, f.job("snapshot.create", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "snapshot": "legacy"}))
	kvmAssertSuccess(t, f.job("vm.guest_agent", kvmTestOp2, map[string]any{"vm_id": kvmTestVM}))
	kvmAssertSuccess(t, f.job("vm.boot_order", "44444444-4444-4444-8444-444444444444", map[string]any{"vm_id": kvmTestVM, "boot_order": "network"}))
	kvmAssertSuccess(t, f.job("snapshot.revert", "55555555-5555-4555-8555-555555555555", map[string]any{"vm_id": kvmTestVM, "snapshot": "legacy"}))
	var d kvmDomain
	_ = xml.Unmarshal(f.domains[kvmTestVM], &d)
	if len(d.Devices.Channels) != 1 || !reflect.DeepEqual(d.OS.Boot, []kvmBootDevice{{"network"}, {"hd"}}) {
		t.Fatalf("current settings lost: %#v", d)
	}
	_ = kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &g)
	d.Devices.Channels[0].Source.Path = "/tmp/untrusted.sock"
	xmlData, _ := xml.Marshal(d)
	if f.e.verifyXML(g, xmlData) == nil {
		t.Fatal("foreign guest-agent socket accepted")
	}
	f.states[kvmTestVM] = "running"
	for _, action := range []string{"vm.guest_agent", "vm.boot_order"} {
		if _, err := f.e.perform(action, map[string]any{"vm_id": kvmTestVM, "boot_order": "disk"}); err == nil {
			t.Fatal("live mutation accepted")
		}
	}
}

func TestKVMManagedCloudInitRollbackFailureKeepsRecoveryAndBlocksFurtherChanges(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	before := kvmCloudFiles(t, f)
	// An obstruction after ISO construction prevents both committing and
	// restoring user-data. The operation must remain unconfirmed.
	withKVMRunner(t, func(ctx context.Context, binary string, args ...string) ([]byte, error) {
		output, err := f.run(ctx, binary, args...)
		if err == nil && binary == "cloud-localds" {
			path := f.e.guestFile(kvmTestVM, "user-data")
			if err = os.Remove(path); err == nil {
				err = os.Mkdir(path, 0700)
			}
		}
		return output, err
	})
	result := f.job("vm.cloud_init", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "hostname": "updated", "ssh_keys": []string{kvmProvisioningKey(t)}})
	if result.status != "failed" || !strings.Contains(result.errorText, "rollback failed") {
		t.Fatalf("unsafe recovery status: %#v", result)
	}
	for _, name := range []string{"seed.iso", "disk.qcow2", "meta-data", "network-config", "manifest.json"} {
		after, err := os.ReadFile(f.e.guestFile(kvmTestVM, name))
		if err != nil || !bytes.Equal(before[name], after) {
			t.Fatalf("%s not restored: %v", name, err)
		}
	}
	pending, err := f.e.pendingOperations()
	if err != nil || len(pending) != 1 {
		t.Fatalf("recovery journal not retained: %v %v", pending, err)
	}
	stages, _ := filepath.Glob(filepath.Join(f.e.root, "instances", kvmTestVM, ".cloud-init-*"))
	if len(stages) != 1 {
		t.Fatal("recovery files discarded")
	}
	blocked := f.job("vm.start", kvmTestOp2, map[string]any{"vm_id": kvmTestVM})
	if blocked.status != "failed" || !strings.Contains(blocked.errorText, "UNCONFIRMED") {
		t.Fatalf("unconfirmed operation did not block changes: %#v", blocked)
	}
}
