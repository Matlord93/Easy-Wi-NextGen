package main

import (
	"context"
	"errors"
	"reflect"
	"strings"
	"testing"
)

func TestKVMHostSetupInstallsOnlyRequiredPackagesAndCanRunAgain(t *testing.T) {
	preflight, missing, privileged := kvmHostPreflight, kvmHostMissingTools, kvmHostCanInstall
	t.Cleanup(func() { kvmHostPreflight, kvmHostMissingTools, kvmHostCanInstall = preflight, missing, privileged })
	kvmHostPreflight = func() error { return nil }
	kvmHostCanInstall = func() bool { return true }
	installed := false
	kvmHostMissingTools = func() []string {
		if installed {
			return nil
		}
		return []string{"virsh"}
	}
	var calls []string
	withKVMRunner(t, func(_ context.Context, name string, args ...string) ([]byte, error) {
		calls = append(calls, name+" "+strings.Join(args, " "))
		if name == "apt-get" && args[0] != "update" {
			installed = true
		}
		return nil, nil
	})
	t.Setenv("EASYWI_KVM_URI", "qemu:///system")
	e := &kvmEngine{ctx: context.Background()}
	if _, err := e.perform("host.setup", nil); err == nil || len(calls) != 0 {
		t.Fatal("unconfirmed host setup must do no work")
	}
	if _, err := e.perform("host.setup", map[string]any{"confirm": "1"}); err == nil || len(calls) != 0 {
		t.Fatal("host setup without the KVM role must do no work")
	}
	for range 2 {
		if result, err := e.perform("host.setup", map[string]any{"confirm": "1", "role": "KVM"}); err != nil || result["host_setup"] != "ready" {
			t.Fatalf("setup did not complete: %#v %v", result, err)
		}
	}
	want := []string{
		"apt-get update",
		"apt-get -o DPkg::Lock::Timeout=60 install -y qemu-system-x86 libvirt-daemon-system libvirt-clients qemu-utils cloud-image-utils",
		"virsh --connect qemu:///system list --all --uuid", "virsh --connect qemu:///system domcapabilities --virttype kvm",
		"virsh --connect qemu:///system list --all --uuid", "virsh --connect qemu:///system domcapabilities --virttype kvm",
	}
	if !reflect.DeepEqual(calls, want) {
		t.Fatalf("unexpected setup commands: %v", calls)
	}
	t.Setenv("EASYWI_KVM_ROOT", t.TempDir())
	written := false
	if _, err := ensureKVMRole(func(role string, output *strings.Builder) error {
		written = role == "kvm" && strings.Contains(output.String(), "host_setup=ready")
		return nil
	}); err != nil || !written {
		t.Fatal("the role is recorded only after setup succeeds")
	}
	kvmHostPreflight = func() error { return errors.New("unsupported hypervisor") }
	if _, err := ensureKVMRole(func(string, *strings.Builder) error {
		t.Fatal("a failed setup must not record a configured role")
		return nil
	}); err == nil || len(calls) != len(want)+2 {
		t.Fatal("failed preflight must perform no host changes")
	}
}

func TestKVMNumberAllocationUsesManifestsAndPerHostRange(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.job("host.vm_number_range", kvmTestOp, map[string]any{"first": 1000, "last": 1001}))
	kvmAssertSuccess(t, f.create())
	var guest kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &guest); err != nil || guest.Number != 1000 {
		t.Fatalf("wrong saved number: %+v %v", guest, err)
	}
	if number, err := f.e.chooseNumber(nil); err != nil || number != 1001 {
		t.Fatalf("wrong next number: %d %v", number, err)
	}
	for _, invalid := range []int{999, 1000, 1002} {
		if _, err := f.e.chooseNumber(map[string]any{"vm_number": invalid}); err == nil {
			t.Fatalf("accepted conflicting number %d", invalid)
		}
	}
}
