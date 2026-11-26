package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"sync"
	"time"

	"easywi/agent/internal/api"
	"easywi/agent/internal/jobs"
)

type kvmReinstallReport struct {
	Operation string `json:"operation"`
	VM        string `json:"vm"`
	Hash      string `json:"hash"`
}

type kvmReinstallReports struct {
	scope  string
	sender func(context.Context, string, orchestratorResult) error
	mu     sync.Mutex
}

func newKVMReinstallReports(panel, agent string, send func(context.Context, string, orchestratorResult) error) *kvmReinstallReports {
	scope := sha256.Sum256([]byte(panel + "\x00" + agent))
	return &kvmReinstallReports{scope: hex.EncodeToString(scope[:]), sender: send}
}

// Keep the panel job ID before KVM starts. The operation journal remains the sole source of the result.
func (r *kvmReinstallReports) track(job jobs.Job) (string, error) {
	if job.Type != "kvm.managed" || payloadValue(job.Payload, "action") != "vm.reinstall" {
		return "", nil
	}
	if runtime.GOOS != "linux" || runtime.GOARCH != "amd64" {
		return "", nil // The KVM handler reports the unsupported platform.
	}
	op, vm := payloadValue(job.Payload, "operation_id"), payloadValue(job.Payload, "vm_id")
	if !kvmUUID.MatchString(job.ID) || !kvmUUID.MatchString(op) || !kvmUUID.MatchString(vm) {
		return "", errors.New("invalid KVM reinstallation job identity")
	}
	hash, err := kvmPayloadHash(job.Payload)
	if err != nil {
		return "", err
	}
	root, err := kvmStorageRoot()
	if err != nil {
		return "", err
	}
	parent := filepath.Join(root, "reinstall-reports")
	dir := filepath.Join(parent, r.scope)
	for _, path := range []string{parent, dir} {
		if err := os.Mkdir(path, 0700); err == nil {
			if err := kvmSyncDirectory(filepath.Dir(path)); err != nil {
				return "", err
			}
		} else if !os.IsExist(err) {
			return "", err
		}
		if err := kvmSafeFile(path); err != nil {
			return "", err
		}
	}
	path := filepath.Join(dir, job.ID+".json")
	if err := kvmWriteJSON(path, kvmReinstallReport{Operation: op, VM: vm, Hash: hash}); err != nil {
		return "", err
	}
	return path, nil
}

func (r *kvmReinstallReports) finish(ctx context.Context, path, jobID string, result orchestratorResult) error {
	if result.deferDelivery {
		return errors.New(result.errorText)
	}
	if path == "" {
		return r.sender(ctx, jobID, result)
	}
	r.mu.Lock()
	defer r.mu.Unlock()
	if _, err := os.Lstat(path); os.IsNotExist(err) {
		return nil // A replay already delivered the result.
	} else if err != nil {
		return err
	}
	return r.deliver(ctx, path, jobID, result)
}

func (r *kvmReinstallReports) retry(ctx context.Context) error {
	if runtime.GOOS != "linux" || runtime.GOARCH != "amd64" {
		return nil
	}
	root, err := kvmStorageRoot()
	if err != nil {
		return err
	}
	dir := filepath.Join(root, "reinstall-reports", r.scope)
	if err := kvmSafeFile(dir); os.IsNotExist(err) {
		return nil
	} else if err != nil {
		return err
	}
	if !r.mu.TryLock() {
		return nil
	}
	defer r.mu.Unlock()
	entries, err := os.ReadDir(dir)
	if err != nil {
		return err
	}
	engine := &kvmEngine{root: root, ctx: ctx}
	for _, entry := range entries {
		jobID := strings.TrimSuffix(entry.Name(), ".json")
		if jobID == entry.Name() || !entry.Type().IsRegular() || !kvmUUID.MatchString(jobID) {
			continue
		}
		path := filepath.Join(dir, entry.Name())
		var record kvmReinstallReport
		if kvmReadJSON(path, &record) == nil && kvmUUID.MatchString(record.Operation) && kvmUUID.MatchString(record.VM) && kvmSHA.MatchString(record.Hash) {
			var result kvmOperation
			journal := filepath.Join(root, "operations", record.Operation+".json")
			if kvmReadJSON(journal, &result) == nil && result.Hash == record.Hash {
				if result.Pending && result.Status == "" && result.ReinstallVMID == record.VM {
					if _, ready := engine.completedReinstallRecord(record.Operation, result); ready {
						if err := engine.reconcileReinstallReport(journal, record, &result); err != nil {
							return err
						}
					}
					if result.Pending && result.Status == "" {
						continue
					}
				}
				if result.Hash == record.Hash && result.Result["operation_id"] == record.Operation && result.Result["vm_id"] == record.VM &&
					result.Result["action"] == "vm.reinstall" && ((result.Status == "success" && !result.Pending) || result.Status == "failed") {
					if err := kvmSyncDirectory(filepath.Dir(journal)); err != nil {
						return err
					}
					return r.deliver(ctx, path, jobID, orchestratorResult{status: result.Status, errorText: result.Error, resultPayload: result.Result})
				}
			}
		}
		// Retire stale references that have no matching host operation to recover.
		if info, err := entry.Info(); err == nil && time.Since(info.ModTime()) > time.Hour {
			if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
				return err
			}
		}
	}
	return nil
}

func (e *kvmEngine) reconcileReinstallReport(journal string, record kvmReinstallReport, result *kvmOperation) error {
	unlock, err := lockKVM(e.ctx, filepath.Join(e.root, "host.lock"))
	if err != nil {
		return err
	}
	defer unlock()
	var current kvmOperation
	if err := kvmReadJSON(journal, &current); err != nil {
		return err
	}
	*result = current
	if current.Hash != record.Hash || current.ReinstallVMID != record.VM {
		return nil
	}
	return e.confirmInterruptedReinstall(record.Operation, result)
}

func (r *kvmReinstallReports) deliver(ctx context.Context, path, jobID string, result orchestratorResult) error {
	err := r.sender(ctx, jobID, result)
	if err != nil && !errors.Is(err, api.ErrAgentJobNotFound) {
		return err
	}
	if err := os.Remove(path); err != nil && !os.IsNotExist(err) {
		return err
	}
	return kvmSyncDirectory(filepath.Dir(path))
}
