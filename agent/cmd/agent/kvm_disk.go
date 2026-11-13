package main

import (
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"os"
)

// Expansion is intentionally irreversible: never try shrinking on an error.
// A failure after invoking qemu-img retains the pending operation for inspection.
func (e *kvmEngine) growDisk(g kvmGuest, p map[string]any) (map[string]any, error) {
	size, err := positivePayloadInt(p, "disk_gib", 1, 2048)
	if err != nil {
		return nil, err
	}
	expected, err := positivePayloadInt(p, "expected_disk_gib", 1, 2048)
	if err != nil || expected != g.DiskGiB {
		return nil, errors.New("VM disk size changed; refresh inventory")
	}
	if payloadValue(p, "confirm") != "1" || size <= g.DiskGiB {
		return nil, errors.New("confirm a strictly larger disk size")
	}
	disk := e.guestFile(g.ID, "disk.qcow2")
	actual, err := e.safeImage(disk)
	if err != nil || actual != int64(g.DiskGiB)<<30 {
		return nil, errors.New("VM disk does not match manifest; inspect host")
	}
	snapshots, err := e.virsh("snapshot-list", g.ID, "--name")
	if err != nil {
		return nil, err
	}
	if len(nonEmptyLines(string(snapshots))) != 0 {
		return nil, errors.New("VM disk expansion requires no snapshots")
	}
	// libvirt metadata can miss orphaned internal qcow2 snapshots.
	f, err := os.Open(disk)
	if err != nil {
		return nil, err
	}
	header := make([]byte, 104)
	_, readErr := io.ReadFull(f, header)
	_ = f.Close()
	if readErr != nil || binary.BigEndian.Uint32(header[60:64]) != 0 {
		return nil, errors.New("VM disk expansion requires no internal snapshots")
	}
	if err := e.requireStopped(g.ID); err != nil {
		return nil, err
	}
	// No --force-share: qemu-img must acquire its exclusive image lock.
	if _, err := e.command("qemu-img", "resize", "-f", "qcow2", disk, fmt.Sprintf("%dG", size)); err != nil {
		return nil, fmt.Errorf("%w: disk expansion could not be confirmed; inspect disk and manifest", errKVMUnconfirmed)
	}
	actual, err = e.safeImage(disk)
	if err != nil || actual != int64(size)<<30 {
		return nil, fmt.Errorf("%w: expanded disk size could not be verified", errKVMUnconfirmed)
	}
	previous := g.DiskGiB
	g.DiskGiB = size
	if err := kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), g); err != nil {
		return nil, fmt.Errorf("%w: disk expanded but manifest update failed; inspect host", errKVMUnconfirmed)
	}
	return map[string]any{"vm_id": g.ID, "previous_disk_gib": previous, "disk_gib": size, "guest_filesystem_changed": false}, nil
}
