package main

import (
	"bytes"
	"os"
	"testing"

	"gopkg.in/yaml.v3"
)

func TestKVMAddressSwitchKeepsBothReservationsAndChangesOnlyOneFamily(t *testing.T) {
	f := kvmNewFixture(t)
	f.networks["default"] = []byte(`<network><name>default</name><ip address='192.0.2.1' prefix='24'/><ip family='ipv6' address='2001:db8::1' prefix='64'/></network>`)
	kvmAssertSuccess(t, f.job("host.address_pool", kvmTestOp, map[string]any{
		"network": "default", "network_type": "network", "family": 4, "first": "192.0.2.20", "last": "192.0.2.21", "prefix": 24, "gateway": "192.0.2.1", "dns": "192.0.2.1",
	}))
	kvmAssertSuccess(t, f.job("host.address_pool", kvmTestOp2, map[string]any{
		"network": "default", "network_type": "network", "family": 6, "first": "2001:db8::20", "last": "2001:db8::21", "prefix": 64, "gateway": "fe80::1", "dns": "2001:db8::1",
	}))
	f.template()
	spec := map[string]any{"label": "Static VM", "template": "ubuntu--24.04-v1", "hostname": "test-vm", "username": "easywi", "network": "default", "network_type": "network", "address_mode": "pool", "vcpus": 2, "memory_mib": 2048, "disk_gib": 1, "ssh_keys": []string{kvmProvisioningKey(t)}}
	kvmAssertSuccess(t, f.job("vm.create", kvmTestVM, spec))
	before := kvmCloudFiles(t, f)
	input := map[string]any{"vm_id": kvmTestVM, "expected_address": "192.0.2.20", "confirm": "1"}
	f.states[kvmTestVM] = "running"
	running := f.job("vm.address_switch", "44444444-4444-4444-8444-444444444440", input)
	if running.status != "failed" {
		t.Fatal("running guest accepted an offline address change")
	}
	f.states[kvmTestVM] = "shut off"
	op := "44444444-4444-4444-8444-444444444441"
	result := f.job("vm.address_switch", op, input)
	kvmAssertSuccess(t, result)
	if result.resultPayload["address"] != "192.0.2.21" {
		t.Fatal("first switch did not select the free address", result)
	}
	after := kvmCloudFiles(t, f)
	if !bytes.Equal(before["disk.qcow2"], after["disk.qcow2"]) {
		t.Fatal("the address change rewrote the system disk")
	}
	var manifest kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &manifest); err != nil {
		t.Fatal(err)
	}
	if len(manifest.AlternateAddresses) != 1 || manifest.AlternateAddresses[0].Address.String() != "192.0.2.20/24" || manifest.StaticAddresses[1].Address.String() != "2001:db8::20/64" {
		t.Fatalf("unexpected reservations: %#v", manifest)
	}
	var meta map[string]any
	if err := yaml.Unmarshal(after["meta-data"], &meta); err != nil || meta["instance-id"] != kvmTestVM+"-ip-"+op {
		t.Fatalf("NoCloud metadata: %#v, %v", meta, err)
	}
	if !bytes.Contains(after["user-data"], []byte("ssh_deletekeys: false")) || !bytes.Contains(after["network-config"], []byte("192.0.2.21/24")) {
		t.Fatal("the replacement seed did not preserve host keys or include the new address")
	}
	if retry := f.job("vm.address_switch", op, input); retry.status != "success" || retry.resultPayload["address"] != "192.0.2.21" {
		t.Fatal("duplicate job did not reuse its result", retry)
	}
	another := "44444444-4444-4444-8444-444444444443"
	if result := f.job("vm.create", another, spec); result.status != "failed" || result.resultPayload["address_pool_rejected"] != true {
		t.Fatal("another VM could allocate a retained address", result)
	}
	if _, err := os.Stat(f.e.guestFile(another, "disk.qcow2")); !os.IsNotExist(err) {
		t.Fatal("exhausted pool should stop before provisioning", err)
	}
	input["expected_address"] = "192.0.2.21"
	result = f.job("vm.address_switch", "44444444-4444-4444-8444-444444444444", input)
	kvmAssertSuccess(t, result)
	if result.resultPayload["address"] != "192.0.2.20" {
		t.Fatal("switching back failed", result)
	}
	kvmAssertSuccess(t, f.job("vm.cloud_init", "44444444-4444-4444-8444-444444444445", map[string]any{"vm_id": kvmTestVM, "hostname": "updated", "ssh_keys": []string{kvmProvisioningKey(t)}}))
}
