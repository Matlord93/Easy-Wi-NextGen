package main

import (
	"encoding/xml"
	"errors"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"strings"

	"gopkg.in/yaml.v3"
)

type kvmAddressPool struct {
	NetworkType string     `json:"network_type"`
	Network     string     `json:"network"`
	Family      int        `json:"family"`
	First       netip.Addr `json:"first"`
	Last        netip.Addr `json:"last"`
	Prefix      int        `json:"prefix"`
	Gateway     netip.Addr `json:"gateway"`
	DNS         netip.Addr `json:"dns"`
}

type kvmStaticAddress struct {
	Address netip.Prefix `json:"address"`
	Gateway netip.Addr   `json:"gateway"`
	DNS     netip.Addr   `json:"dns"`
}

func (pool kvmAddressPool) matches(kind, network string) bool {
	return pool.NetworkType == kind && pool.Network == network
}

func (pool kvmAddressPool) includes(address netip.Addr) bool {
	return address.IsValid() && address.BitLen() == pool.First.BitLen() && pool.First.Compare(address) <= 0 && pool.Last.Compare(address) >= 0
}

func (pool kvmAddressPool) overlaps(first, last netip.Addr) bool {
	return first.BitLen() == pool.First.BitLen() && pool.First.Compare(last) <= 0 && pool.Last.Compare(first) >= 0
}

func (pool kvmAddressPool) validate() error {
	family := pool.Family
	if (pool.NetworkType != "bridge" && pool.NetworkType != "network") || !validKVMIdentifier(pool.Network) || (family != 4 && family != 6) {
		return errors.New("invalid IP pool network or address family")
	}
	if pool.Prefix < 1 || (family == 4 && pool.Prefix > 30) || (family == 6 && pool.Prefix > 127) {
		return errors.New("invalid IP pool prefix length")
	}
	if !poolAddressUsable(pool.First, family, false) || !poolAddressUsable(pool.Last, family, false) || !poolAddressUsable(pool.Gateway, family, true) {
		return errors.New("IP pool requires usable addresses in the selected family")
	}
	if !poolAddressUsable(pool.DNS, family, false) || pool.includes(pool.DNS) || (family == 4 && (pool.DNS.As4()[0] == 0 || pool.DNS.As4()[0] >= 224)) {
		return errors.New("IP pool requires a DNS server in the selected family outside the pool")
	}
	subnet := netip.PrefixFrom(pool.First, pool.Prefix).Masked()
	if pool.First.Compare(pool.Last) > 0 || !subnet.Contains(pool.Last) || pool.First == subnet.Addr() || pool.includes(pool.Gateway) ||
		(!pool.Gateway.IsLinkLocalUnicast() && (!subnet.Contains(pool.Gateway) || pool.Gateway == subnet.Addr())) {
		return errors.New("IP range and gateway must fit the subnet; the gateway must be outside the pool")
	}
	if family == 4 {
		// Exclude the broadcast address, and reject ranges crossing reserved IPv4 space.
		if !subnet.Contains(pool.Last.Next()) || !subnet.Contains(pool.Gateway.Next()) ||
			pool.overlaps(netip.AddrFrom4([4]byte{169, 254, 0, 0}), netip.AddrFrom4([4]byte{169, 254, 255, 255})) ||
			pool.First.As4()[0] == 0 || pool.Last.As4()[0] >= 224 || pool.Gateway.As4()[0] == 0 || pool.Gateway.As4()[0] >= 224 {
			return errors.New("IP pool includes reserved IPv4 addresses")
		}
	}
	return nil
}

func poolAddressUsable(address netip.Addr, family int, gateway bool) bool {
	return address.IsValid() && !address.Is4In6() && address.Zone() == "" && (family == 4) == address.Is4() &&
		(address.IsGlobalUnicast() || (gateway && family == 6 && address.IsLinkLocalUnicast()))
}

func (e *kvmEngine) addressPools() ([]kvmAddressPool, error) {
	pools := []kvmAddressPool{}
	err := kvmReadJSON(filepath.Join(e.root, "address-pools.json"), &pools)
	if os.IsNotExist(err) {
		return pools, nil
	}
	if err != nil {
		return nil, err
	}
	for _, pool := range pools {
		if err := pool.validate(); err != nil {
			return nil, err
		}
	}
	return pools, nil
}

func (e *kvmEngine) configureAddressPool(p map[string]any) (map[string]any, error) {
	networkType, network := payloadValue(p, "network_type"), payloadValue(p, "network")
	family, err := positivePayloadInt(p, "family", 4, 6)
	if err != nil || family == 5 || !validKVMIdentifier(network) || (networkType != "bridge" && networkType != "network") {
		return nil, errors.New("invalid IP pool network or address family")
	}
	pools, err := e.addressPools()
	if err != nil {
		return nil, err
	}
	updated := make([]kvmAddressPool, 0, len(pools)+1)
	for _, pool := range pools {
		if !pool.matches(networkType, network) || pool.Family != family {
			updated = append(updated, pool)
		}
	}
	if payloadValue(p, "remove") != "1" {
		first, firstErr := netip.ParseAddr(strings.TrimSpace(payloadValue(p, "first")))
		last, lastErr := netip.ParseAddr(strings.TrimSpace(payloadValue(p, "last")))
		gateway, gatewayErr := netip.ParseAddr(strings.TrimSpace(payloadValue(p, "gateway")))
		dns, dnsErr := netip.ParseAddr(strings.TrimSpace(payloadValue(p, "dns")))
		prefix, prefixErr := positivePayloadInt(p, "prefix", 1, 127)
		if firstErr != nil || lastErr != nil || gatewayErr != nil || dnsErr != nil || prefixErr != nil {
			return nil, errors.New("invalid IP pool address or prefix")
		}
		pool := kvmAddressPool{NetworkType: networkType, Network: network, Family: family, First: first, Last: last, Prefix: prefix, Gateway: gateway, DNS: dns}
		if err := pool.validate(); err != nil {
			return nil, err
		}
		if err := e.checkNetwork(networkType, network); err != nil {
			return nil, err
		}
		for _, other := range updated {
			if pool.overlaps(other.First, other.Last) {
				return nil, errors.New("IP range overlaps another pool on this host")
			}
		}
		if err := e.checkAddressPoolNetwork(pool); err != nil {
			return nil, err
		}
		updated = append(updated, pool)
	}
	return map[string]any{"address_pools": updated}, kvmWriteJSON(filepath.Join(e.root, "address-pools.json"), updated)
}

func (e *kvmEngine) checkAddressPoolNetwork(pool kvmAddressPool) error {
	local, err := net.InterfaceAddrs()
	if err != nil {
		return err
	}
	for _, address := range local {
		prefix, err := netip.ParsePrefix(address.String())
		if err != nil {
			return errors.New("cannot verify host IP addresses")
		}
		if pool.includes(prefix.Addr()) {
			return errors.New("IP pool contains an address used by this host")
		}
	}
	if pool.NetworkType == "bridge" {
		return nil
	}
	raw, err := e.virsh("net-dumpxml", pool.Network)
	if err != nil {
		return err
	}
	var network kvmNetwork
	if xml.Unmarshal(raw, &network) != nil || network.Name != pool.Network {
		return errors.New("cannot verify the libvirt IP configuration")
	}
	// A libvirt bridge without its own IP settings uses external network policy.
	foundSubnet := len(network.IPs) == 0 && network.Forward != nil && network.Forward.Mode == "bridge"
	for _, setting := range network.IPs {
		subnet, err := setting.subnet()
		if err != nil {
			return err
		}
		if pool.includes(subnet.Addr()) {
			return errors.New("IP pool includes a libvirt interface address")
		}
		if subnet.Bits() == pool.Prefix && subnet.Contains(pool.First) && subnet.Contains(pool.Last) &&
			(subnet.Addr() == pool.Gateway || pool.Gateway.IsLinkLocalUnicast()) {
			foundSubnet = true
		}
		if setting.DHCP == nil {
			continue
		}
		for _, span := range setting.DHCP.Ranges {
			first, firstErr := netip.ParseAddr(span.Start)
			last, lastErr := netip.ParseAddr(span.End)
			if firstErr != nil || lastErr != nil || pool.overlaps(first, last) {
				return errors.New("IP pool conflicts with the libvirt DHCP range")
			}
		}
		for _, reservation := range setting.DHCP.Hosts {
			address, err := netip.ParseAddr(reservation.IP)
			if err != nil || pool.includes(address) {
				return errors.New("IP pool conflicts with a libvirt DHCP reservation")
			}
		}
	}
	if !foundSubnet {
		return errors.New("IP pool subnet, prefix or gateway does not match the selected libvirt network")
	}
	return nil
}

// The caller holds the host lock until it writes the guest manifest. A manifest
// with a missing or failed libvirt definition still claims its addresses.
func (e *kvmEngine) chooseAddresses(p map[string]any, kind, network string) ([]kvmStaticAddress, error) {
	switch payloadValue(p, "address_mode") {
	case "", "dhcp":
		return nil, nil
	case "pool":
	default:
		return nil, errors.New("invalid IP assignment mode")
	}
	pools, err := e.addressPools()
	if err != nil {
		return nil, err
	}
	used, err := e.reservedAddresses()
	if err != nil {
		return nil, err
	}
	var addresses []kvmStaticAddress
	for _, pool := range pools {
		if !pool.matches(kind, network) {
			continue
		}
		if err := e.checkAddressPoolNetwork(pool); err != nil {
			return nil, err
		}
		address, err := pool.freeAddress(used)
		if err != nil {
			return nil, err
		}
		addresses = append(addresses, address)
	}
	if len(addresses) == 0 {
		return nil, errors.New("no IP pool configured for this network")
	}
	return addresses, nil
}

func (e *kvmEngine) reservedAddresses() (map[netip.Addr]bool, error) {
	used := make(map[netip.Addr]bool)
	err := e.eachGuestManifest(func(guest kvmGuest) error {
		for _, addresses := range [][]kvmStaticAddress{guest.StaticAddresses, guest.AlternateAddresses} {
			for _, address := range addresses {
				if !address.Address.IsValid() || used[address.Address.Addr()] {
					return errors.New("invalid or duplicate static address in a VM manifest")
				}
				used[address.Address.Addr()] = true
			}
		}
		return nil
	})
	return used, err
}

func (pool kvmAddressPool) freeAddress(used map[netip.Addr]bool) (kvmStaticAddress, error) {
	candidate := pool.First
	for pool.includes(candidate) && used[candidate] {
		candidate = candidate.Next()
	}
	if !pool.includes(candidate) {
		return kvmStaticAddress{}, errors.New("no free address in a pool for this network")
	}
	return kvmStaticAddress{Address: netip.PrefixFrom(candidate, pool.Prefix), Gateway: pool.Gateway, DNS: pool.DNS}, nil
}

func (g kvmGuest) cloudInitNetwork() ([]byte, error) {
	if len(g.StaticAddresses) == 0 {
		return []byte("version: 2\nethernets:\n  primary:\n    match:\n      name: 'e*'\n    dhcp4: true\n    dhcp6: true\n"), nil
	}
	addresses := make([]string, 0, len(g.StaticAddresses))
	routes := make([]map[string]string, 0, len(g.StaticAddresses))
	dns := make([]string, 0, len(g.StaticAddresses))
	for _, address := range g.StaticAddresses {
		destination := "::/0"
		if address.Address.Addr().Is4() {
			destination = "0.0.0.0/0"
		}
		addresses = append(addresses, address.Address.String())
		routes = append(routes, map[string]string{"to": destination, "via": address.Gateway.String()})
		dns = append(dns, address.DNS.String())
	}
	primary := map[string]any{"match": map[string]string{"macaddress": kvmGuestMAC(g.ID)}, "dhcp4": false, "dhcp6": false, "accept-ra": false, "addresses": addresses, "routes": routes, "nameservers": map[string][]string{"addresses": dns}}
	return yaml.Marshal(map[string]any{"version": 2, "ethernets": map[string]any{"primary": primary}})
}
