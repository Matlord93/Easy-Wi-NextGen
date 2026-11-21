package main

import (
	"os"
	"path/filepath"
	"reflect"
	"testing"
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
		input := map[string]any{"vm_id": kvmTestVM, "template": g.Template}
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
