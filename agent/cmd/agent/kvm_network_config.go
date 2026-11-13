package main

import (
	"errors"
	"fmt"
)

// Reconfigure the existing NIC while preserving its stable MAC. Guest IP settings
// are not changed; the guest remains stopped until the operator starts it.
func (e *kvmEngine) updateGuestNetwork(g kvmGuest, p map[string]any) (map[string]any, error) {
	if payloadValue(p, "confirm") != "1" || payloadValue(p, "expected_network") != g.Network || payloadValue(p, "expected_network_type") != g.NetworkType {
		return nil, errors.New("confirm the current VM network; refresh inventory")
	}
	kind, name := payloadValue(p, "network_type"), payloadValue(p, "network")
	if (kind != "network" && kind != "bridge") || !validKVMIdentifier(name) || (kind == g.NetworkType && name == g.Network) {
		return nil, errors.New("select a different approved VM network")
	}
	if err := e.checkNetwork(kind, name); err != nil {
		return nil, err
	}
	snapshots, err := e.virsh("snapshot-list", g.ID, "--name")
	if err != nil {
		return nil, err
	}
	if len(nonEmptyLines(string(snapshots))) != 0 {
		return nil, errors.New("VM network changes require no snapshots")
	}
	if err := e.requireStopped(g.ID); err != nil {
		return nil, err
	}
	old := g
	g.NetworkType, g.Network = kind, name
	rollback := func(cause error) (map[string]any, error) {
		definitionErr := e.define(old)
		// AtomicWrite can fail during directory fsync after a rename. Restore
		// the manifest explicitly as well, then verify the original domain.
		manifestErr := kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), old)
		verificationErr := e.verifyGuest(old)
		if definitionErr != nil || manifestErr != nil || verificationErr != nil {
			return nil, fmt.Errorf("%w: network configuration rollback requires host inspection", errKVMUnconfirmed)
		}
		return nil, fmt.Errorf("network change failed; original configuration restored: %v", cause)
	}
	if err := e.define(g); err != nil {
		return rollback(err)
	}
	if err := e.verifyGuest(g); err != nil {
		return rollback(err)
	}
	if err := kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), g); err != nil {
		return rollback(err)
	}
	return map[string]any{"vm_id": g.ID, "network": g.Network, "network_type": g.NetworkType, "guest_ip_configuration_changed": false}, nil
}
