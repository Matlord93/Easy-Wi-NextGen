package main

import (
	"errors"
	"net/netip"
	"slices"
)

func (e *kvmEngine) switchAddress(g kvmGuest, p map[string]any) (map[string]any, error) {
	operation := payloadValue(p, "operation_id")
	current, err := netip.ParseAddr(payloadValue(p, "expected_address"))
	if err != nil || !current.IsGlobalUnicast() || current.Is4In6() || current.Zone() != "" || !kvmUUID.MatchString(operation) || payloadValue(p, "confirm") != "1" {
		return nil, errors.New("confirm the current reserved IP address")
	}
	index := slices.IndexFunc(g.StaticAddresses, func(address kvmStaticAddress) bool { return address.Address.Addr() == current })
	if index < 0 || g.NetworkBootFirst {
		return nil, errors.New("refresh the VM inventory and verify the current reserved address and boot order")
	}
	config, err := e.readCloudInit(g)
	if err != nil {
		return nil, err
	}
	pools, err := e.addressPools()
	if err != nil {
		return nil, err
	}
	poolIndex := slices.IndexFunc(pools, func(pool kvmAddressPool) bool {
		return pool.matches(g.NetworkType, g.Network) && pool.includes(current)
	})
	if poolIndex < 0 {
		return nil, errors.New("no enabled pool matches this VM address")
	}
	pool := pools[poolIndex]
	active := g.StaticAddresses[index]
	if !pool.matchesReservation(active) {
		return nil, errors.New("the pool prefix, gateway or DNS changed")
	}
	if err = e.checkAddressPoolNetwork(pool); err != nil {
		return nil, err
	}
	used, err := e.reservedAddresses()
	if err != nil {
		return nil, err
	}
	alternate := slices.IndexFunc(g.AlternateAddresses, func(address kvmStaticAddress) bool { return address.Address.Addr().Is4() == current.Is4() })
	if alternate < 0 {
		address, allocationErr := pool.freeAddress(used)
		if allocationErr != nil {
			return nil, allocationErr
		}
		g.AlternateAddresses = append(g.AlternateAddresses, address)
		alternate = len(g.AlternateAddresses) - 1
		// Reserve both addresses durably before replacing any guest-visible files.
		if err = kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), g); err != nil {
			return nil, err
		}
	}
	next := g.AlternateAddresses[alternate]
	if !pool.matchesReservation(next) {
		return nil, errors.New("the alternate reservation no longer matches the pool")
	}
	updated := g
	updated.StaticAddresses = slices.Clone(g.StaticAddresses)
	updated.AlternateAddresses = slices.Clone(g.AlternateAddresses)
	updated.StaticAddresses[index], updated.AlternateAddresses[alternate] = next, active
	updated.CloudInitID = g.ID + "-ip-" + operation
	network, err := updated.cloudInitNetwork()
	if err != nil {
		return nil, err
	}
	// A fresh NoCloud instance ID applies the network configuration at boot.
	// Keep the SSH host keys of the existing installation during that boot.
	config["ssh_deletekeys"] = false
	if err = e.replaceCloudInit(g, updated, config, network); err != nil {
		return nil, err
	}
	return map[string]any{"vm_id": g.ID, "address": next.Address.Addr().String()}, nil
}

func (pool kvmAddressPool) matchesReservation(address kvmStaticAddress) bool {
	return pool.includes(address.Address.Addr()) && address.Address.Bits() == pool.Prefix && address.Gateway == pool.Gateway && address.DNS == pool.DNS
}
