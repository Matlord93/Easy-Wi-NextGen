package main

import (
	"context"
	"encoding/xml"
	"errors"
	"os"
	"reflect"
	"strings"
	"testing"
)

func kvmNetworkInput() map[string]any {
	return map[string]any{"vm_id": kvmTestVM, "network_type": "network", "network": "isolated", "expected_network_type": "network", "expected_network": "default", "confirm": "1"}
}

func TestKVMManagedNetworkChangePreservesMacDiskAndCloudInit(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	beforeDisk, _ := f.e.fileHash(f.e.guestFile(kvmTestVM, "disk.qcow2"))
	beforeSeed, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "network-config"))
	var before kvmDomain
	if err := xml.Unmarshal(f.domains[kvmTestVM], &before); err != nil {
		t.Fatal(err)
	}
	r := f.job("vm.network", kvmTestOp, kvmNetworkInput())
	kvmAssertSuccess(t, r)
	kvmAssertSuccess(t, f.job("vm.network", kvmTestOp, kvmNetworkInput()))
	var g kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &g); err != nil || g.Network != "isolated" {
		t.Fatalf("manifest not updated: %#v %v", g, err)
	}
	var after kvmDomain
	if err := xml.Unmarshal(f.domains[kvmTestVM], &after); err != nil {
		t.Fatal(err)
	}
	if before.Devices.Interfaces[0].MAC.Address != after.Devices.Interfaces[0].MAC.Address || after.Devices.Interfaces[0].Source.Network != "isolated" {
		t.Fatal("NIC MAC or target network differs")
	}
	afterDisk, _ := f.e.fileHash(f.e.guestFile(kvmTestVM, "disk.qcow2"))
	afterSeed, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "network-config"))
	if beforeDisk != afterDisk || !reflect.DeepEqual(beforeSeed, afterSeed) || f.states[kvmTestVM] != "shut off" || r.resultPayload["guest_ip_configuration_changed"] != false {
		t.Fatal("network change modified guest data, seed, or power state")
	}
}

func TestKVMManagedNetworkChangeRejectsUnconfirmedStaleAndUnsafeTargets(t *testing.T) {
	for _, name := range []string{"confirmation", "stale", "same", "unsafe", "bridge", "running", "snapshot", "inactive"} {
		t.Run(name, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			p := kvmNetworkInput()
			switch name {
			case "confirmation":
				p["confirm"] = ""
			case "stale":
				p["expected_network"] = "stale"
			case "same":
				p["network"] = "default"
			case "unsafe":
				p["network"] = "../../etc"
			case "bridge":
				p["network_type"], p["network"] = "bridge", "unapproved"
			case "running":
				f.states[kvmTestVM] = "running"
			case "snapshot":
				kvmAssertSuccess(t, f.job("snapshot.create", kvmTestOp2, map[string]any{"vm_id": kvmTestVM, "snapshot": "before-network"}))
			case "inactive":
				f.failVerb = "net-info"
			}
			before, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
			writes := len(f.writes)
			r := f.job("vm.network", kvmTestOp, p)
			after, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
			if r.status != "failed" || writes != len(f.writes) || !reflect.DeepEqual(before, after) {
				t.Fatalf("unsafe network change was applied: %#v", r)
			}
		})
	}
}

func TestKVMManagedNetworkChangeRollsBackAFailedDefinition(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	before, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
	failed := false
	withKVMRunner(t, func(ctx context.Context, binary string, args ...string) ([]byte, error) {
		if binary == "virsh" && len(args) > 2 && args[2] == "define" && !failed {
			failed = true
			return nil, errors.New("synthetic definition failure")
		}
		return f.run(ctx, binary, args...)
	})
	r := f.job("vm.network", kvmTestOp, kvmNetworkInput())
	after, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
	if r.status != "failed" || !strings.Contains(r.errorText, "original configuration restored") || !reflect.DeepEqual(before, after) {
		t.Fatalf("failed network change was not restored: %#v", r)
	}
	var g kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &g); err != nil || f.e.verifyGuest(g) != nil {
		t.Fatal("original manifest and domain no longer match")
	}
}

func TestKVMManagedNetworkRollbackFailureRetainsThePendingOperation(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	f.failVerb = "define"
	r := f.job("vm.network", kvmTestOp, kvmNetworkInput())
	if r.status != "failed" || !strings.Contains(r.errorText, "KVM_OPERATION_UNCONFIRMED") {
		t.Fatalf("rollback failure must require inspection: %#v", r)
	}
	var operation kvmOperation
	if err := kvmReadJSON(f.e.root+"/operations/"+kvmTestOp+".json", &operation); err != nil || !operation.Pending {
		t.Fatal("unconfirmed operation was unlocked")
	}
}
