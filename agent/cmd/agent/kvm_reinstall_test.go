package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"reflect"
	"strings"
	"testing"
	"time"
)

func TestKVMReinstallReplacesOnlyTheSystemDiskAndKeepsTheOriginalOnFailedBoot(t *testing.T) {
	for _, failBoot := range []bool{false, true} {
		f := kvmNewFixture(t)
		kvmAssertSuccess(t, f.create())
		cloud := kvmCloudFiles(t, f)
		domain := string(f.domains[kvmTestVM])
		path := f.e.guestFile(kvmTestVM, "disk.qcow2")
		original, err := os.Stat(path)
		if err != nil {
			t.Fatal(err)
		}
		var g kvmGuest
		if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &g); err != nil {
			t.Fatal(err)
		}
		input := map[string]any{"vm_id": kvmTestVM, "template": g.Template, "operation_id": kvmTestOp}
		if _, err := f.e.perform("vm.reinstall", input); err == nil {
			t.Fatal("reinstallation accepted without confirmation")
		}
		input["confirm"] = "1"
		f.snapshots["keep"] = nil
		if _, err := f.e.perform("vm.reinstall", input); err == nil {
			t.Fatal("reinstallation accepted with snapshots")
		}
		delete(f.snapshots, "keep")
		if failBoot {
			f.failVerb = "start"
		}
		kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
			if failBoot {
				t.Fatal("guest agent contacted after start failure")
			}
			switch request := args[6]; {
			case strings.Contains(request, "guest-file-open"):
				return []byte(`{"return":7}`), nil
			case strings.Contains(request, "guest-file-read"):
				pending := filepath.Join(filepath.Dir(path), ".reinstall-*", "previous.qcow2")
				copies, _ := filepath.Glob(pending)
				if len(copies) != 1 {
					t.Fatal("previous disk was removed before guest verification")
				}
				return kvmTestCloudInitMarker(time.Now())
			case strings.Contains(request, "guest-file-close"):
				return []byte(`{"return":{}}`), nil
			default:
				t.Fatal("unexpected guest agent command during reinstall without password")
				return nil, nil
			}
		})
		result, err := f.e.perform("vm.reinstall", input)
		if (err != nil) != failBoot || result["disk_replaced"] != true {
			t.Fatalf("unexpected reinstall result %v %v", result, err)
		}
		current, err := os.Stat(path)
		if err != nil || os.SameFile(original, current) {
			t.Fatal("system disk was not replaced")
		}
		if !reflect.DeepEqual(cloud, kvmCloudFiles(t, f)) || domain != string(f.domains[kvmTestVM]) {
			t.Fatal("reinstallation changed the guest definition or its cloud-init identity")
		}
		copies, err := filepath.Glob(filepath.Join(filepath.Dir(path), ".reinstall-*", "previous.qcow2"))
		if err != nil {
			t.Fatal(err)
		}
		if failBoot {
			if len(copies) != 1 {
				t.Fatal("original disk was not retained after unconfirmed boot")
			}
			if _, err := f.e.perform("vm.reinstall", input); err == nil {
				t.Fatal("unfinished reinstallation can be overwritten")
			}
		} else if len(copies) != 0 || f.states[kvmTestVM] != "running" {
			t.Fatal("successful reinstall failed to boot or retained the original disk")
		}
	}
}

func TestKVMReinstallWithoutPasswordKeepsOriginalUntilFreshCloudInitIsVerified(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	var guest kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &guest); err != nil {
		t.Fatal(err)
	}
	path := f.e.guestFile(kvmTestVM, "disk.qcow2")
	original, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(f.e.ctx)
	defer cancel()
	f.e.ctx = ctx
	input := map[string]any{"vm_id": kvmTestVM, "template": guest.Template, "confirm": "1", "operation_id": kvmTestOp}
	guest.GuestAgentEnabled = false
	if _, err = f.e.reinstallGuest(guest, input); err == nil || !strings.Contains(err.Error(), "requires a guest agent") {
		t.Fatalf("reinstallation without a guest agent was accepted: %v", err)
	}
	kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
		switch request := args[6]; {
		case strings.Contains(request, "guest-file-open"):
			return []byte(`{"return":7}`), nil
		case strings.Contains(request, "guest-file-read"):
			return kvmTestCloudInitMarker(time.Now().Add(-time.Hour))
		case strings.Contains(request, "guest-file-close"):
			cancel()
			return []byte(`{"return":{}}`), nil
		default:
			t.Fatal("unexpected guest agent command during reinstall without password")
			return nil, nil
		}
	})
	result, err := f.e.perform("vm.reinstall", input)
	if !errors.Is(err, errKVMUnconfirmed) || !strings.Contains(err.Error(), "cloud-init completion") || result["disk_replaced"] != true {
		t.Fatalf("stale marker was accepted: %v %v", result, err)
	}
	copies, err := filepath.Glob(filepath.Join(filepath.Dir(path), ".reinstall-*", "previous.qcow2"))
	if err != nil || len(copies) != 1 {
		t.Fatalf("old system disk was not kept: %v %v", copies, err)
	}
	previous, err := os.Stat(copies[0])
	if err != nil || !os.SameFile(original, previous) {
		t.Fatal("retained disk is not the original")
	}
}

func TestKVMReinstallKeepsOriginalWhenTheGuestRejectsThePassword(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	var guest kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &guest); err != nil {
		t.Fatal(err)
	}
	calls := 0
	kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
		request := args[6]
		switch {
		case strings.Contains(request, "guest-file-open"):
			return []byte(`{"return":7}`), nil
		case strings.Contains(request, "guest-file-read"):
			return kvmTestCloudInitMarker(time.Now())
		case strings.Contains(request, "guest-set-user-password"):
			calls++
			return []byte(`{"error":{"desc":"denied"}}`), nil
		default:
			return []byte(`{"return":{}}`), nil
		}
	})
	result, err := f.e.perform("vm.reinstall", map[string]any{
		"vm_id": kvmTestVM, "template": guest.Template, "confirm": "1", "operation_id": kvmTestOp,
		"install_password_hash": "$6$0123456789abcdef$" + strings.Repeat("a", 86),
	})
	if err == nil || calls != 1 || result["root_access_configured"] == true {
		t.Fatalf("failed password operation was confirmed: %v %v (%d calls)", result, err, calls)
	}
	copies, err := filepath.Glob(filepath.Join(filepath.Dir(f.e.guestFile(kvmTestVM, "disk.qcow2")), ".reinstall-*", "previous.qcow2"))
	if err != nil || len(copies) != 1 {
		t.Fatalf("old system disk was not kept: %v %v", copies, err)
	}
}

func kvmTestCloudInitMarker(finished time.Time) ([]byte, error) {
	marker := "2.0 - " + finished.Format("Mon, 2 Jan 2006 15:04:05 -0700") + " - v. 25.1\n"
	return json.Marshal(map[string]any{"return": map[string]any{"buf-b64": base64.StdEncoding.EncodeToString([]byte(marker))}})
}

func TestKVMReinstallRollbackPreservesBothDisksAndReleasesTheHost(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	var template kvmTemplate
	if err := kvmReadJSON(filepath.Join(f.e.root, "templates", "ubuntu--24.04-v1.json"), &template); err != nil {
		t.Fatal(err)
	}
	template.Version = "24.04-v2"
	if err := kvmWriteJSON(filepath.Join(f.e.root, "templates", "ubuntu--24.04-v2.json"), template); err != nil {
		t.Fatal(err)
	}
	disk := f.e.guestFile(kvmTestVM, "disk.qcow2")
	original, err := os.Stat(disk)
	if err != nil {
		t.Fatal(err)
	}
	f.failVerb = "start"
	failed := f.job("vm.reinstall", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "template": "ubuntu--24.04-v2", "confirm": "1"})
	if failed.status != "failed" || failed.resultPayload["reinstall_recovery_available"] != true {
		t.Fatalf("missing recovery for failed reinstall: %#v", failed)
	}
	for _, observation := range []orchestratorResult{failed, f.job("inspect", "", nil)} {
		inventory := observation.resultPayload["inventory"].(map[string]any)
		recovery, _ := inventory["reinstall_rollback"].(map[string]string)
		if recovery["operation_id"] != kvmTestOp || recovery["vm_id"] != kvmTestVM {
			t.Fatalf("recovery missing from inventory: %v", recovery)
		}
	}
	replacement, err := os.Stat(disk)
	if err != nil {
		t.Fatal(err)
	}
	f.failVerb = ""
	f.states[kvmTestVM] = "running"
	input := map[string]any{"vm_id": kvmTestVM, "reinstall_operation_id": kvmTestOp, "confirm": "1"}
	blocked := f.job("vm.start", kvmTestOp2, map[string]any{"vm_id": kvmTestVM})
	if blocked.status != "failed" || !strings.Contains(blocked.errorText, "resolve interrupted operation") {
		t.Fatalf("host accepted a regular mutation during recovery: %#v", blocked)
	}
	wrongID := "44444444-4444-4444-8444-444444444444"
	if rejected := f.job("vm.reinstall_rollback", wrongID, map[string]any{"vm_id": kvmTestVM, "reinstall_operation_id": kvmTestOp2, "confirm": "1"}); rejected.status != "failed" || f.states[kvmTestVM] != "running" {
		t.Fatal("a rollback for an unrelated operation reached the VM")
	}
	f.failVerb = "shutdown"
	if rejected := f.job("vm.reinstall_rollback", "55555555-5555-4555-8555-555555555555", input); rejected.status != "failed" || f.states[kvmTestVM] != "running" {
		t.Fatal("rollback proceeded without a confirmed graceful shutdown")
	}
	current, err := os.Stat(disk)
	if err != nil || !os.SameFile(current, replacement) {
		t.Fatal("the running guest's system disk was replaced")
	}
	f.failVerb = ""
	rollback := f.job("vm.reinstall_rollback", kvmTestOp2, input)
	kvmAssertSuccess(t, rollback)
	if rollback.resultPayload["reinstall_rolled_back"] != true || f.states[kvmTestVM] != "shut off" {
		t.Fatalf("rollback did not leave the original boot disk ready: %#v", rollback)
	}
	var restored kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &restored); err != nil || restored.Template != "ubuntu--24.04-v1" {
		t.Fatalf("original template was not restored: %v %v", restored.Template, err)
	}
	current, err = os.Stat(disk)
	if err != nil || !os.SameFile(current, original) {
		t.Fatal("the restored disk is not the original")
	}
	archive, err := os.Stat(f.e.guestFile(kvmTestVM, "before-reinstall-rollback-"+kvmTestOp+".qcow2"))
	if err != nil || !os.SameFile(archive, replacement) {
		t.Fatal("the replacement disk was not preserved")
	}
	pending, err := f.e.pendingOperations()
	if err != nil || len(pending) != 0 {
		t.Fatalf("host lock was not released: %v %v", pending, err)
	}
	before := len(f.writes)
	kvmAssertSuccess(t, f.job("vm.reinstall_rollback", kvmTestOp2, input))
	kvmAssertSuccess(t, f.job("vm.reinstall_rollback", wrongID, input))
	if len(f.writes) != before {
		t.Fatal("a repeated rollback changed the VM again")
	}

	// A process interrupted after disk restoration and before releasing the lock
	// must be able to finish even if the staging directory was already removed.
	journal := filepath.Join(f.e.root, "operations", kvmTestOp+".json")
	var source kvmOperation
	if err := kvmReadJSON(journal, &source); err != nil {
		t.Fatal(err)
	}
	source.Pending = true
	source.Result["reinstall_recovery_available"] = true
	if err := kvmWriteJSON(journal, source); err != nil {
		t.Fatal(err)
	}
	kvmAssertSuccess(t, f.job("vm.reinstall_rollback", "66666666-6666-4666-8666-666666666666", input))
	pending, err = f.e.pendingOperations()
	if err != nil || len(pending) != 0 || len(f.writes) != before {
		t.Fatalf("resuming the recorded rollback did not safely release the lock: %v %v", pending, err)
	}
}

func TestKVMInterruptedReinstallationRequiresRecordedDiskIdentities(t *testing.T) {
	for _, scenario := range []string{"replacement attached", "original still attached", "unrelated disk attached", "replacement proof missing"} {
		t.Run(scenario, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			disk := f.e.guestFile(kvmTestVM, "disk.qcow2")
			original, err := os.Stat(disk)
			if err != nil {
				t.Fatal(err)
			}
			interrupted := errors.New("simulated agent interruption")
			withKVMRunner(t, func(ctx context.Context, binary string, args ...string) ([]byte, error) {
				if binary == "virsh" && len(args) > 2 && args[2] == "start" {
					panic(interrupted)
				}
				return f.run(ctx, binary, args...)
			})
			reinstall := map[string]any{"vm_id": kvmTestVM, "template": "ubuntu--24.04-v1", "confirm": "1"}
			func() {
				defer func() {
					if recovered := recover(); recovered != interrupted {
						t.Fatalf("expected the agent to stop at first boot, got: %v", recovered)
					}
				}()
				f.job("vm.reinstall", kvmTestOp, reinstall)
			}()

			journal := filepath.Join(f.e.root, "operations", kvmTestOp+".json")
			var source kvmOperation
			if err := kvmReadJSON(journal, &source); err != nil || !source.Pending || source.Status != "" || source.ReinstallVMID != kvmTestVM || len(source.Result) != 0 {
				t.Fatalf("unexpected unfinished journal: %#v (%v)", source, err)
			}
			stage := f.e.guestFile(kvmTestVM, ".reinstall-"+kvmTestOp)
			witness := filepath.Join(stage, "replacement.qcow2")
			replacement, err := os.Stat(witness)
			if err != nil {
				t.Fatal(err)
			}
			switch scenario {
			case "original still attached":
				link := filepath.Join(stage, "original-link.qcow2")
				if err := os.Link(filepath.Join(stage, "previous.qcow2"), link); err != nil {
					t.Fatal(err)
				}
				if err := os.Rename(link, disk); err != nil {
					t.Fatal(err)
				}
			case "unrelated disk attached":
				foreign := f.e.guestFile(kvmTestVM, "unrelated.qcow2")
				f.image(foreign)
				if err := os.Rename(foreign, disk); err != nil {
					t.Fatal(err)
				}
			case "replacement proof missing":
				if err := os.Remove(witness); err != nil {
					t.Fatal(err)
				}
			}

			before, err := os.Stat(disk)
			if err != nil {
				t.Fatal(err)
			}
			inspection := f.job("inspect", "", nil)
			kvmAssertSuccess(t, inspection)
			inventory := inspection.resultPayload["inventory"].(map[string]any)
			recovery, _ := inventory["reinstall_rollback"].(map[string]string)
			input := map[string]any{"vm_id": kvmTestVM, "reinstall_operation_id": kvmTestOp, "confirm": "1"}
			if scenario == "unrelated disk attached" || scenario == "replacement proof missing" {
				if len(recovery) != 0 || f.job("vm.reinstall_rollback", kvmTestOp2, input).status != "failed" {
					t.Fatal("rollback accepted despite missing or inconsistent disk evidence")
				}
				current, err := os.Stat(disk)
				if err != nil || !os.SameFile(before, current) {
					t.Fatal("rejecting recovery changed the current disk")
				}
				pending, err := f.e.pendingOperations()
				if err != nil || !reflect.DeepEqual(pending, []string{kvmTestOp}) {
					t.Fatalf("the unresolved source lock was lost: %v %v", pending, err)
				}
				return
			}
			if recovery["vm_id"] != kvmTestVM || recovery["operation_id"] != kvmTestOp {
				t.Fatalf("valid recovery missing from inventory: %v", recovery)
			}
			kvmAssertSuccess(t, f.job("vm.reinstall_rollback", kvmTestOp2, input))
			current, err := os.Stat(disk)
			if err != nil || !os.SameFile(original, current) {
				t.Fatal("the original disk was not restored")
			}
			archive, err := os.Stat(f.e.guestFile(kvmTestVM, "before-reinstall-rollback-"+kvmTestOp+".qcow2"))
			if err != nil || !os.SameFile(replacement, archive) {
				t.Fatal("the prepared replacement was not preserved")
			}
			if result := f.job("vm.reinstall", kvmTestOp, reinstall); result.status != "failed" || result.resultPayload["reinstall_rolled_back"] != true {
				t.Fatalf("retry did not return the recorded outcome: %#v", result)
			}
		})
	}
}
