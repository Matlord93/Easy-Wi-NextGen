package main

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
)

var kvmReinstallNoManagedSave = regexp.MustCompile(`(?m)^Managed save:[ \t]+no[ \t]*$`)

func (e *kvmEngine) assertReinstallable(g kvmGuest) error {
	if err := e.verifyGuest(g); err != nil {
		return err
	}
	if err := e.requireStopped(g.ID); err != nil {
		return err
	}
	if g.NetworkBootFirst {
		return errors.New("reinstallation requires booting from the system disk")
	}
	info, err := e.virsh("dominfo", g.ID)
	if err != nil || !kvmReinstallNoManagedSave.Match(info) {
		return errors.New("reinstallation requires a guest without a managed save")
	}
	snapshots, err := e.virsh("snapshot-list", g.ID, "--name")
	if err != nil || strings.TrimSpace(string(snapshots)) != "" {
		return errors.New("reinstallation requires a guest without snapshots")
	}
	return nil
}

func (e *kvmEngine) reinstallGuest(g kvmGuest, p map[string]any) (map[string]any, error) {
	if payloadValue(p, "confirm") != "1" {
		return nil, errors.New("explicit system disk replacement confirmation required")
	}
	template := payloadValue(p, "template")
	parts := strings.Split(template, "--")
	if len(parts) != 2 || !kvmSlug.MatchString(parts[0]) || !kvmVersion.MatchString(parts[1]) {
		return nil, errors.New("invalid template version")
	}
	var t kvmTemplate
	if err := kvmReadJSON(filepath.Join(e.root, "templates", template+".json"), &t); err != nil ||
		t.ID != parts[0] || t.Version != parts[1] || !kvmSHA.MatchString(t.SHA256) {
		return nil, errors.New("template version not found")
	}
	if err := e.assertReinstallable(g); err != nil {
		return nil, err
	}
	if _, err := e.cloudInitView(g); err != nil {
		return nil, err
	}
	if err := kvmSafeFile(e.guestFile(g.ID, "seed.iso")); err != nil {
		return nil, err
	}
	base := filepath.Join(e.root, "images", t.SHA256+".qcow2")
	size, err := e.safeImage(base)
	if err != nil || size != t.VirtualSize || size > int64(g.DiskGiB)<<30 {
		return nil, errors.New("system disk cannot accommodate the selected template")
	}
	hash, err := e.fileHash(base)
	if err != nil || hash != t.SHA256 {
		return nil, errors.New("template checksum mismatch")
	}
	disk := e.guestFile(g.ID, "disk.qcow2")
	if _, err = e.safeImage(disk); err != nil {
		return nil, err
	}
	dir := filepath.Dir(disk)
	existing, err := filepath.Glob(filepath.Join(dir, ".reinstall-*"))
	if err != nil || len(existing) != 0 {
		return nil, errors.New("previous reinstallation needs host review")
	}
	stage, err := os.MkdirTemp(dir, ".reinstall-")
	if err != nil {
		return nil, err
	}
	cleanup := true
	defer func() {
		if cleanup {
			_ = os.RemoveAll(stage)
		}
	}()
	replacement := filepath.Join(stage, "disk.qcow2")
	if _, err = e.command("qemu-img", "convert", "-f", "qcow2", "-O", "qcow2", base, replacement); err != nil {
		return nil, err
	}
	if _, err = e.command("qemu-img", "resize", "-f", "qcow2", replacement, fmt.Sprintf("%dG", g.DiskGiB)); err != nil {
		return nil, err
	}
	if err = os.Chmod(replacement, 0600); err != nil {
		return nil, err
	}
	if err = e.assertReinstallable(g); err != nil {
		return nil, err
	}
	previous := filepath.Join(stage, "previous.qcow2")
	if err = os.Link(disk, previous); err != nil {
		return nil, err
	}
	cleanup = false
	if err = os.Rename(replacement, disk); err != nil {
		cleanup = true
		return nil, err
	}
	updated := g
	updated.Template = template
	if err = kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), updated); err != nil {
		if rollback := os.Rename(previous, disk); rollback == nil {
			cleanup = true
			return nil, err
		}
		return nil, fmt.Errorf("%w: disk rollback failed", errKVMUnconfirmed)
	}
	result := map[string]any{"vm_id": g.ID, "disk_replaced": true}
	if _, err = e.virsh("start", g.ID); err != nil {
		return result, fmt.Errorf("%w: failed to confirm first boot; previous system disk retained for host review", errKVMUnconfirmed)
	}
	if err = os.Remove(previous); err != nil {
		return result, fmt.Errorf("%w: previous disk cleanup requires host review", errKVMUnconfirmed)
	}
	cleanup = true
	return result, nil
}
