package main

import (
	"context"
	"encoding/binary"
	"errors"
	"os"
	"os/exec"
	"strings"
	"testing"
)

func kvmGrowInput() map[string]any {
	return map[string]any{"vm_id": kvmTestVM, "disk_gib": 2, "expected_disk_gib": 1, "confirm": "1"}
}

func TestKVMManagedDiskGrowPreservesDataAndIsIdempotent(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	disk := f.e.guestFile(kvmTestVM, "disk.qcow2")
	if f.native {
		if out, err := exec.Command("qemu-io", "-f", "qcow2", "-c", "write -P 0x5a 0 4096", disk).CombinedOutput(); err != nil {
			t.Fatalf("write sentinel: %s %v", out, err)
		}
	}
	r := f.job("vm.disk_grow", kvmTestOp, kvmGrowInput())
	kvmAssertSuccess(t, r)
	if r.resultPayload["previous_disk_gib"] != 1 || r.resultPayload["disk_gib"] != 2 || r.resultPayload["guest_filesystem_changed"] != false {
		t.Fatalf("unexpected result: %#v", r)
	}
	kvmAssertSuccess(t, f.job("vm.disk_grow", kvmTestOp, kvmGrowInput()))
	size, err := f.e.safeImage(disk)
	if err != nil || size != 2<<30 {
		t.Fatalf("unexpected size: %d %v", size, err)
	}
	var g kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &g); err != nil || g.DiskGiB != 2 {
		t.Fatalf("manifest not updated: %#v %v", g, err)
	}
	if f.native {
		if out, err := exec.Command("qemu-io", "-f", "qcow2", "-c", "read -P 0x5a 0 4096", disk).CombinedOutput(); err != nil {
			t.Fatalf("sentinel changed: %s %v", out, err)
		}
		if out, err := exec.Command("qemu-img", "check", "-f", "qcow2", disk).CombinedOutput(); err != nil {
			t.Fatalf("expanded image invalid: %s %v", out, err)
		}
	}
}

func TestKVMManagedDiskGrowRejectsUnsafeInputAndState(t *testing.T) {
	for _, name := range []string{"shrink", "same", "fraction", "limit", "stale", "confirmation", "running", "snapshots", "orphaned-snapshot", "size-mismatch", "foreign-disk"} {
		t.Run(name, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			p := kvmGrowInput()
			switch name {
			case "shrink":
				p["disk_gib"] = 0
			case "same":
				p["disk_gib"] = 1
			case "fraction":
				p["disk_gib"] = 2.5
			case "limit":
				p["disk_gib"] = 2049
			case "stale":
				p["expected_disk_gib"] = 2
			case "confirmation":
				delete(p, "confirm")
			case "running":
				f.states[kvmTestVM] = "running"
			case "snapshots":
				kvmAssertSuccess(t, f.job("snapshot.create", kvmTestOp2, map[string]any{"vm_id": kvmTestVM, "snapshot": "before-grow"}))
			case "orphaned-snapshot":
				if f.native {
					out, err := exec.Command("qemu-img", "snapshot", "-c", "orphaned", f.e.guestFile(kvmTestVM, "disk.qcow2")).CombinedOutput()
					if err != nil {
						t.Fatalf("snapshot: %s %v", out, err)
					}
				} else {
					path := f.e.guestFile(kvmTestVM, "disk.qcow2")
					data, _ := os.ReadFile(path)
					binary.BigEndian.PutUint32(data[60:64], 1)
					if err := os.WriteFile(path, data, 0600); err != nil {
						t.Fatal(err)
					}
				}
			case "size-mismatch":
				if _, err := f.e.command("qemu-img", "resize", "-f", "qcow2", f.e.guestFile(kvmTestVM, "disk.qcow2"), "3G"); err != nil {
					t.Fatal(err)
				}
			case "foreign-disk":
				f.domains[kvmTestVM] = []byte(strings.Replace(string(f.domains[kvmTestVM]), "disk.qcow2", "other.qcow2", 1))
			}
			before, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "disk.qcow2"))
			manifest, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
			r := f.job("vm.disk_grow", kvmTestOp, p)
			if r.status == "success" {
				t.Fatal("unsafe disk expansion accepted")
			}
			after, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "disk.qcow2"))
			afterManifest, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
			if string(before) != string(after) || string(manifest) != string(afterManifest) {
				t.Fatal("rejected expansion changed data")
			}
		})
	}
}

func TestKVMManagedDiskGrowFailureRemainsPendingWithoutShrinking(t *testing.T) {
	for _, failure := range []string{"resize", "manifest"} {
		t.Run(failure, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			withKVMRunner(t, func(ctx context.Context, binary string, args ...string) ([]byte, error) {
				out, err := f.run(ctx, binary, args...)
				if binary == "qemu-img" && args[0] == "resize" && err == nil {
					if failure == "resize" {
						return nil, errors.New("synthetic response lost after expansion")
					}
					path := f.e.guestFile(kvmTestVM, "manifest.json")
					if err := os.Remove(path); err != nil {
						t.Fatal(err)
					}
					if err := os.Mkdir(path, 0700); err != nil {
						t.Fatal(err)
					}
				}
				return out, err
			})
			r := f.job("vm.disk_grow", kvmTestOp, kvmGrowInput())
			if r.status != "failed" || !strings.Contains(r.errorText, "KVM_OPERATION_UNCONFIRMED") {
				t.Fatalf("uncertainty lost: %#v", r)
			}
			size, err := f.e.safeImage(f.e.guestFile(kvmTestVM, "disk.qcow2"))
			if err != nil || size != 2<<30 {
				t.Fatalf("expanded disk shrunk: %d %v", size, err)
			}
			pending, err := f.e.pendingOperations()
			if err != nil || len(pending) != 1 || pending[0] != kvmTestOp {
				t.Fatalf("missing pending operation: %v %v", pending, err)
			}
			next := f.job("vm.start", kvmTestOp2, map[string]any{"vm_id": kvmTestVM})
			if next.status != "failed" || !strings.Contains(next.errorText, "KVM_OPERATION_UNCONFIRMED") {
				t.Fatal("unsafe follow-up accepted")
			}
		})
	}
}

func TestKVMManagedBackupRestoreAfterDiskGrowRetainsNewCapacity(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	kvmAssertSuccess(t, f.job("backup.create", kvmTestOp, map[string]any{"vm_id": kvmTestVM}))
	kvmAssertSuccess(t, f.job("vm.disk_grow", kvmTestOp2, kvmGrowInput()))
	kvmAssertSuccess(t, f.job("backup.restore", "44444444-4444-4444-8444-444444444444", map[string]any{"vm_id": kvmTestVM, "backup_id": kvmTestOp}))
	size, err := f.e.safeImage(f.e.guestFile(kvmTestVM, "disk.qcow2"))
	if err != nil || size != 2<<30 {
		t.Fatalf("restoring old backup lost expanded capacity: %d %v", size, err)
	}
}
