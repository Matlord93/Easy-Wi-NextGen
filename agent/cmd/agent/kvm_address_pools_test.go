package main

import (
	"encoding/xml"
	"os"
	"strings"
	"testing"

	"gopkg.in/yaml.v3"
)

func TestKVMStaticAddressesFollowTheManifestUntilRetirement(t *testing.T) {
	f := kvmNewFixture(t)
	f.networks["default"] = []byte(`<network><name>default</name>
<ip address='192.0.2.1' netmask='255.255.255.0'><dhcp><range start='192.0.2.100' end='192.0.2.200'/></dhcp></ip>
<ip family='ipv6' address='2001:db8::1' prefix='64'/></network>`)
	kvmAssertSuccess(t, f.job("host.address_pool", kvmTestOp, map[string]any{
		"network": "default", "network_type": "network", "family": 4, "first": "192.0.2.20", "last": "192.0.2.20", "prefix": 24, "gateway": "192.0.2.1", "dns": "192.0.2.1",
	}))
	kvmAssertSuccess(t, f.job("host.address_pool", kvmTestOp2, map[string]any{
		"network": "default", "network_type": "network", "family": 6, "first": "2001:db8::20", "last": "2001:db8::30", "prefix": 64, "gateway": "fe80::1", "dns": "2001:db8::1",
	}))
	f.template()
	p := map[string]any{"label": "Static VM", "template": "ubuntu--24.04-v1", "hostname": "test-vm", "username": "easywi", "network": "default", "network_type": "network", "address_mode": "pool", "vcpus": 2, "memory_mib": 2048, "disk_gib": 1, "ssh_keys": []string{kvmProvisioningKey(t)}}
	kvmAssertSuccess(t, f.job("vm.create", kvmTestVM, p))
	var guest kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &guest); err != nil || len(guest.StaticAddresses) != 2 {
		t.Fatalf("static addresses missing in saved manifest: %#v, %v", guest, err)
	}
	seed, err := os.ReadFile(f.e.guestFile(kvmTestVM, "network-config"))
	if err != nil {
		t.Fatal(err)
	}
	var network struct {
		Ethernets map[string]struct {
			Match       map[string]string
			DHCP4       bool
			DHCP6       bool
			Addresses   []string
			Routes      []map[string]string
			Nameservers map[string][]string
		}
	}
	if err := yaml.Unmarshal(seed, &network); err != nil {
		t.Fatal(err)
	}
	nic := network.Ethernets["primary"]
	if nic.DHCP4 || nic.DHCP6 || nic.Match["macaddress"] != kvmGuestMAC(kvmTestVM) || strings.Join(nic.Addresses, ",") != "192.0.2.20/24,2001:db8::20/64" ||
		strings.Join(nic.Nameservers["addresses"], ",") != "192.0.2.1,2001:db8::1" || len(nic.Routes) != 2 || nic.Routes[1]["via"] != "fe80::1" {
		t.Fatalf("unexpected Cloud-Init network configuration:\n%s", seed)
	}
	move := f.job("vm.network", "44444444-4444-4444-8444-444444444444", map[string]any{"vm_id": kvmTestVM})
	if move.status != "failed" || !strings.Contains(move.errorText, "reserved static addresses") {
		t.Fatal("guest with reserved addresses should not change networks", move)
	}
	definition := f.domains[kvmTestVM]
	delete(f.domains, kvmTestVM)
	next := "55555555-5555-4555-8555-555555555555"
	result := f.job("vm.create", next, p)
	if result.status != "failed" || result.resultPayload["address_pool_rejected"] != true || !strings.Contains(result.errorText, "no free address") {
		t.Fatal("expected VM without definition to retain its addresses", result)
	}
	if _, err := os.Stat(f.e.guestFile(next, "disk.qcow2")); !os.IsNotExist(err) {
		t.Fatal("exhausted pool must abort before disk creation", err)
	}
	f.domains[kvmTestVM] = definition
	kvmAssertSuccess(t, f.job("vm.delete", "66666666-6666-4666-8666-666666666666", map[string]any{"vm_id": kvmTestVM, "confirm": "1"}))
	next = "77777777-7777-4777-8777-777777777777"
	kvmAssertSuccess(t, f.job("vm.create", next, p))
	if err := kvmReadJSON(f.e.guestFile(next, "manifest.json"), &guest); err != nil || len(guest.StaticAddresses) != 2 || guest.StaticAddresses[0].Address.String() != "192.0.2.20/24" {
		t.Fatalf("retired VM address was not reusable: %#v, %v", guest, err)
	}
}

func TestKVMAddressPoolRejectsDHCPConflicts(t *testing.T) {
	f := kvmNewFixture(t)
	f.networks["default"] = []byte(`<network><name>default</name><ip address='192.0.2.1' prefix='24'><dhcp>
<range start='192.0.2.100' end='192.0.2.140'/><range start='192.0.2.200' end='192.0.2.240'/><host ip='192.0.2.23'/>
</dhcp></ip></network>`)
	p := map[string]any{"network": "default", "network_type": "network", "family": 4, "first": "192.0.2.22", "last": "192.0.2.24", "prefix": 24, "gateway": "192.0.2.1", "dns": "192.0.2.1"}
	result := f.job("host.address_pool", kvmTestOp, p)
	if result.status != "failed" || !strings.Contains(result.errorText, "DHCP reservation") {
		t.Fatal("expected failure for DHCP reservation", result)
	}
	p["first"], p["last"] = "192.0.2.203", "192.0.2.208"
	result = f.job("host.address_pool", kvmTestOp2, p)
	if result.status != "failed" || !strings.Contains(result.errorText, "DHCP range") {
		t.Fatal("expected failure for DHCP secondary range", result)
	}
	kvmAssertSuccess(t, f.job("network.create", kvmTestVM, map[string]any{"network": "easywi-test", "cidr": "10.220.240.0/24", "mode": "nat", "dhcp_start": "10.220.240.100"}))
	var network kvmNetwork
	if err := xml.Unmarshal(f.networks["easywi-test"], &network); err != nil || len(network.IPs) != 1 || network.IPs[0].DHCP == nil ||
		len(network.IPs[0].DHCP.Ranges) != 1 || network.IPs[0].DHCP.Ranges[0].Start != "10.220.240.100" {
		t.Fatalf("new network failed to leave space for static IPs: %v, %#v", err, network)
	}
}
