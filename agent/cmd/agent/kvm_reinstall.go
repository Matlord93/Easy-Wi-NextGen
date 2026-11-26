package main

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

var kvmReinstallNoManagedSave = regexp.MustCompile(`(?m)^Managed save:[ \t]+no[ \t]*$`)

type kvmReinstallRecovery struct {
	PreviousTemplate    string `json:"previous_template"`
	ReplacementTemplate string `json:"replacement_template"`
	ReplacementRecorded bool   `json:"replacement_recorded,omitempty"`
	GuestConfirmed      bool   `json:"guest_confirmed,omitempty"`
	RootAccessConfirmed bool   `json:"root_access_confirmed,omitempty"`
}

func kvmReinstallRecoverySummary(operation string, result map[string]any) map[string]string {
	vmID, _ := result["vm_id"].(string)
	if result["action"] != "vm.reinstall" || result["reinstall_recovery_available"] != true || !kvmUUID.MatchString(operation) || !kvmUUID.MatchString(vmID) {
		return nil
	}
	return map[string]string{"vm_id": vmID, "operation_id": operation}
}

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
	if !g.GuestAgentEnabled {
		return nil, errors.New("reinstallation requires a guest agent to verify the new system disk")
	}
	operation := payloadValue(p, "operation_id")
	if !kvmUUID.MatchString(operation) {
		return nil, errors.New("invalid reinstallation operation UUID")
	}
	installPassword := payloadValue(p, "install_password_hash")
	if installPassword != "" && !kvmRootPasswordHash.MatchString(installPassword) {
		return nil, errors.New("installation access requires a valid password hash")
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
	if err != nil {
		return nil, errors.New("previous reinstallation needs host review")
	}
	for _, oldStage := range existing {
		id := strings.TrimPrefix(filepath.Base(oldStage), ".reinstall-")
		var old kvmOperation
		if !kvmUUID.MatchString(id) || kvmReadJSON(filepath.Join(e.root, "operations", id+".json"), &old) != nil ||
			old.Pending || old.Status != "success" || old.Result["action"] != "vm.reinstall" || old.Result["vm_id"] != g.ID || old.Result["operation_id"] != id {
			return nil, errors.New("previous reinstallation needs host review")
		}
		if err := kvmDiscardReinstallStage(disk, oldStage); err != nil {
			return nil, fmt.Errorf("previous reinstallation cleanup needs host review: %w", err)
		}
	}
	stage := filepath.Join(dir, ".reinstall-"+operation)
	if err = os.Mkdir(stage, 0700); err != nil {
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
	// Keep an identity reference to the prepared replacement across the rename.
	if err = os.Link(replacement, filepath.Join(stage, "replacement.qcow2")); err != nil {
		return nil, err
	}
	recovery := kvmReinstallRecovery{PreviousTemplate: g.Template, ReplacementTemplate: template, ReplacementRecorded: true}
	recoveryPath := filepath.Join(stage, "recovery.json")
	if err = kvmWriteJSON(recoveryPath, recovery); err != nil {
		return nil, err
	}
	if err = kvmSyncDirectory(dir); err != nil {
		return nil, err
	}
	cleanup = false
	if err = os.Rename(replacement, disk); err != nil {
		cleanup = true
		return nil, err
	}
	updated := g
	updated.Template = template
	result := map[string]any{"vm_id": g.ID, "disk_replaced": true, "reinstall_recovery_available": true}
	if err = kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), updated); err != nil {
		return result, fmt.Errorf("%w: failed to confirm the new VM manifest; previous system disk retained", errKVMUnconfirmed)
	}
	started := time.Now()
	if _, err = e.virsh("start", g.ID); err != nil {
		return result, fmt.Errorf("%w: failed to confirm first boot; previous system disk retained for host review", errKVMUnconfirmed)
	}
	if err := e.confirmInstallation(updated, installPassword, started); err != nil {
		return result, fmt.Errorf("%w: %w; previous system disk retained for host review", errKVMUnconfirmed, err)
	}
	if !kvmMatchesReplacement(disk, stage) {
		return result, fmt.Errorf("%w: replacement disk changed; previous system disk retained", errKVMUnconfirmed)
	}
	recovery.GuestConfirmed = true
	recovery.RootAccessConfirmed = installPassword != ""
	if err := kvmWriteJSON(recoveryPath, recovery); err != nil {
		return result, fmt.Errorf("%w: could not record guest verification; previous system disk retained", errKVMUnconfirmed)
	}
	if recovery.RootAccessConfirmed {
		result["root_access_configured"] = true
	}
	if err = os.Remove(previous); err != nil {
		return result, fmt.Errorf("%w: previous disk cleanup requires host review", errKVMUnconfirmed)
	}
	delete(result, "reinstall_recovery_available")
	return result, nil
}

func (e *kvmEngine) completedReinstallRecord(operation string, source kvmOperation) (kvmReinstallRecovery, bool) {
	var recovery kvmReinstallRecovery
	if !source.Pending || source.Status != "" || !kvmSHA.MatchString(source.Hash) || !kvmUUID.MatchString(source.ReinstallVMID) || !kvmUUID.MatchString(operation) {
		return recovery, false
	}
	stage := e.guestFile(source.ReinstallVMID, ".reinstall-"+operation)
	if err := kvmReadJSON(filepath.Join(stage, "recovery.json"), &recovery); err != nil || !recovery.ReplacementRecorded || !recovery.GuestConfirmed || recovery.ReplacementTemplate == "" {
		return recovery, false
	}
	_, err := os.Lstat(filepath.Join(stage, "previous.qcow2"))
	return recovery, os.IsNotExist(err)
}

// Called under the host lock. The previous disk must already be gone.
func (e *kvmEngine) confirmInterruptedReinstall(operation string, source *kvmOperation) error {
	recovery, ok := e.completedReinstallRecord(operation, *source)
	if !ok {
		return nil
	}
	vmID := source.ReinstallVMID
	stage := e.guestFile(vmID, ".reinstall-"+operation)
	var guest kvmGuest
	if err := kvmReadJSON(e.guestFile(vmID, "manifest.json"), &guest); err != nil || guest.ID != vmID || guest.Template != recovery.ReplacementTemplate {
		return nil
	}
	disk := e.guestFile(vmID, "disk.qcow2")
	if !kvmMatchesReplacement(disk, stage) {
		return nil
	}
	if err := e.verifyGuest(guest); err != nil {
		return err
	}
	definition, err := e.virsh("dumpxml", guest.ID)
	if err != nil {
		return err
	}
	if err := e.verifyXML(guest, definition); err != nil {
		return err
	}
	if err := kvmSyncDirectory(stage); err != nil {
		return err
	}
	result := map[string]any{"vm_id": vmID, "operation_id": operation, "action": "vm.reinstall", "disk_replaced": true}
	if recovery.RootAccessConfirmed {
		result["root_access_configured"] = true
	}
	finished := *source
	finished.Pending, finished.Status, finished.Error, finished.Result = false, "success", "", result
	if err := kvmWriteJSON(filepath.Join(e.root, "operations", operation+".json"), finished); err != nil {
		return err
	}
	*source = finished
	return kvmDiscardReinstallStage(disk, stage)
}

func kvmMatchesReplacement(disk, stage string) bool {
	current, err := kvmRegularRecoveryDisk(disk)
	if err != nil {
		return false
	}
	replacement, err := kvmRegularRecoveryDisk(filepath.Join(stage, "replacement.qcow2"))
	return err == nil && os.SameFile(current, replacement)
}

func kvmDiscardReinstallStage(disk, stage string) error {
	if err := kvmSafeFile(stage); os.IsNotExist(err) {
		return nil
	} else if err != nil {
		return err
	}
	if _, err := os.Lstat(filepath.Join(stage, "previous.qcow2")); !os.IsNotExist(err) {
		return errors.New("the previous system disk needs host review")
	}
	if _, err := os.Lstat(filepath.Join(stage, "replacement.qcow2")); !os.IsNotExist(err) && !kvmMatchesReplacement(disk, stage) {
		return errors.New("the replacement disk needs host review")
	}
	for _, name := range []string{"replacement.qcow2", "recovery.json"} {
		if err := os.Remove(filepath.Join(stage, name)); err != nil && !os.IsNotExist(err) {
			return err
		}
	}
	if err := os.Remove(stage); err != nil {
		return err
	}
	return kvmSyncDirectory(filepath.Dir(stage))
}

func (e *kvmEngine) rollbackReinstallation(g kvmGuest, p map[string]any) (map[string]any, error) {
	origin := payloadValue(p, "reinstall_operation_id")
	if payloadValue(p, "confirm") != "1" || !kvmUUID.MatchString(origin) || origin == payloadValue(p, "operation_id") {
		return nil, errors.New("explicit confirmation and the source reinstallation operation are required")
	}
	journal := filepath.Join(e.root, "operations", origin+".json")
	var source kvmOperation
	if err := kvmReadJSON(journal, &source); err != nil {
		return nil, errors.New("the reinstallation operation does not match this VM")
	}
	if e.interruptedReinstallationVM(origin, source) == g.ID {
		source.Status = "failed"
		source.Error = "Agent stopped before recording the reinstallation result"
		source.Result = map[string]any{"action": "vm.reinstall", "operation_id": origin, "vm_id": g.ID, "reinstall_recovery_available": true}
	}
	if source.Status != "failed" || source.Result["action"] != "vm.reinstall" || source.Result["vm_id"] != g.ID {
		return nil, errors.New("the reinstallation operation does not match this VM")
	}
	result := map[string]any{"vm_id": g.ID, "reinstall_rolled_back": true}
	if !source.Pending && source.Result["reinstall_rolled_back"] == true {
		return result, nil
	}
	if !source.Pending || source.Result["reinstall_recovery_available"] != true {
		return nil, errors.New("the reinstallation has no recoverable system disk")
	}
	stage := e.guestFile(g.ID, ".reinstall-"+origin)
	if source.Result["reinstall_rolled_back"] != true {
		if err := e.restorePreviousSystemDisk(g, stage, origin); err != nil {
			return nil, err
		}
		source.Result["reinstall_rolled_back"] = true
		if err := kvmWriteJSON(journal, source); err != nil {
			return nil, fmt.Errorf("%w: could not record the restored disk", errKVMUnconfirmed)
		}
	}
	if err := os.RemoveAll(stage); err != nil {
		return nil, fmt.Errorf("%w: recovery files could not be cleaned up", errKVMUnconfirmed)
	}
	if err := kvmSyncDirectory(filepath.Dir(stage)); err != nil {
		return nil, fmt.Errorf("%w: recovery cleanup could not be confirmed", errKVMUnconfirmed)
	}
	source.Pending = false
	delete(source.Result, "reinstall_recovery_available")
	if err := kvmWriteJSON(journal, source); err != nil {
		return nil, fmt.Errorf("%w: could not release the reinstallation lock", errKVMUnconfirmed)
	}
	return result, nil
}

func (e *kvmEngine) interruptedReinstallationVM(operation string, source kvmOperation) string {
	vmID := source.ReinstallVMID
	if !source.Pending || source.Status != "" || !kvmSHA.MatchString(source.Hash) || !kvmUUID.MatchString(operation) || !kvmUUID.MatchString(vmID) {
		return ""
	}
	var guest kvmGuest
	if err := kvmReadJSON(e.guestFile(vmID, "manifest.json"), &guest); err != nil || guest.ID != vmID {
		return ""
	}
	stage := e.guestFile(vmID, ".reinstall-"+operation)
	recovery, err := e.reinstallRecovery(guest, stage)
	if err != nil || !recovery.ReplacementRecorded {
		return ""
	}
	return vmID
}

func (e *kvmEngine) reinstallRecovery(g kvmGuest, stage string) (kvmReinstallRecovery, error) {
	var recovery kvmReinstallRecovery
	if err := kvmReadJSON(filepath.Join(stage, "recovery.json"), &recovery); err != nil || recovery.PreviousTemplate == "" || (g.Template != recovery.PreviousTemplate && g.Template != recovery.ReplacementTemplate) {
		return recovery, errors.New("the reinstallation recovery record does not match this VM")
	}
	if recovery.ReplacementRecorded && !kvmReinstallDiskIdentitiesMatch(e.guestFile(g.ID, "disk.qcow2"), stage) {
		return recovery, errors.New("the system disks do not match the reinstallation record")
	}
	return recovery, nil
}

func (e *kvmEngine) restorePreviousSystemDisk(g kvmGuest, stage, origin string) error {
	recovery, err := e.reinstallRecovery(g, stage)
	if err != nil {
		return err
	}
	previous := filepath.Join(stage, "previous.qcow2")
	if _, err := e.safeImage(previous); err != nil {
		return errors.New("the previous system disk could not be verified")
	}
	if err := e.stopGuestForReinstallRollback(g); err != nil {
		return err
	}
	if err := e.assertReinstallable(g); err != nil {
		return err
	}
	disk := e.guestFile(g.ID, "disk.qcow2")
	if _, err := e.safeImage(disk); err != nil {
		return errors.New("the current system disk could not be verified")
	}
	if err := e.restoreReinstallDisk(disk, previous, stage, origin, recovery.ReplacementRecorded); err != nil {
		return fmt.Errorf("%w: could not restore the previous system disk: %w", errKVMUnconfirmed, err)
	}
	g.Template = recovery.PreviousTemplate
	if err := kvmWriteJSON(e.guestFile(g.ID, "manifest.json"), g); err != nil {
		return fmt.Errorf("%w: could not restore the previous template in the VM manifest", errKVMUnconfirmed)
	}
	return nil
}

func (e *kvmEngine) stopGuestForReinstallRollback(g kvmGuest) error {
	if e.requireStopped(g.ID) == nil {
		return nil
	}
	ctx, cancel := context.WithTimeout(e.ctx, 35*time.Second)
	defer cancel()
	engine := *e
	engine.ctx = ctx
	if _, err := engine.virsh("shutdown", g.ID); err != nil {
		return errors.New("shut down the VM before retrying the rollback")
	}
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for engine.requireStopped(g.ID) != nil {
		select {
		case <-ctx.Done():
			return errors.New("the VM did not shut down; the system disks were preserved")
		case <-ticker.C:
		}
	}
	return nil
}

func kvmReinstallDiskIdentitiesMatch(disk, stage string) bool {
	previous, err := kvmRegularRecoveryDisk(filepath.Join(stage, "previous.qcow2"))
	if err != nil {
		return false
	}
	replacement, err := kvmRegularRecoveryDisk(filepath.Join(stage, "replacement.qcow2"))
	if err != nil || os.SameFile(previous, replacement) {
		return false
	}
	current, err := kvmRegularRecoveryDisk(disk)
	return err == nil && (os.SameFile(current, previous) || os.SameFile(current, replacement))
}

func kvmRegularRecoveryDisk(path string) (os.FileInfo, error) {
	if err := kvmSafeFile(path); err != nil {
		return nil, err
	}
	info, err := os.Lstat(path)
	if err != nil {
		return nil, err
	}
	if !info.Mode().IsRegular() {
		return nil, errors.New("recovery disk is not a regular file")
	}
	return info, nil
}

func (e *kvmEngine) restoreReinstallDisk(disk, previous, stage, origin string, replacementRecorded bool) error {
	oldInfo, err := os.Stat(previous)
	if err != nil {
		return err
	}
	currentInfo, err := os.Stat(disk)
	if err != nil {
		return err
	}
	alreadyRestored := os.SameFile(oldInfo, currentInfo)
	replacement, replacementInfo := disk, currentInfo
	if replacementRecorded {
		replacement = filepath.Join(stage, "replacement.qcow2")
		replacementInfo, err = kvmRegularRecoveryDisk(replacement)
		if err != nil || os.SameFile(oldInfo, replacementInfo) || (!alreadyRestored && !os.SameFile(currentInfo, replacementInfo)) {
			return errors.New("the replacement disk does not match the reinstallation record")
		}
	} else if alreadyRestored {
		return nil
	}
	archive := filepath.Join(filepath.Dir(disk), "before-reinstall-rollback-"+origin+".qcow2")
	if err := linkKVMRecoveryDisk(replacement, archive, replacementInfo); err != nil {
		return err
	}
	if err := kvmSyncDirectory(filepath.Dir(disk)); err != nil {
		return err
	}
	if alreadyRestored {
		return nil
	}
	restore := filepath.Join(stage, "restore.qcow2")
	if err := linkKVMRecoveryDisk(previous, restore, oldInfo); err != nil {
		return err
	}
	return os.Rename(restore, disk)
}

func linkKVMRecoveryDisk(source, target string, sourceInfo os.FileInfo) error {
	err := os.Link(source, target)
	if !os.IsExist(err) {
		return err
	}
	targetInfo, statErr := os.Lstat(target)
	if statErr != nil || !os.SameFile(sourceInfo, targetInfo) {
		return errors.New("an unrelated disk already exists at the recovery location")
	}
	return nil
}
