package main

// Managed KVM jobs intentionally have their own storage and UUID namespace.
// Existing libvirt domains can be inspected but are never adopted implicitly.
import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var errKVMUnconfirmed = errors.New("KVM_OPERATION_UNCONFIRMED")

var kvmUUID = regexp.MustCompile(`^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$`)
var kvmSlug = regexp.MustCompile(`^[a-z][a-z0-9-]{0,47}$`)
var kvmVersion = regexp.MustCompile(`^[a-z0-9][a-z0-9.-]{0,47}$`)
var kvmSHA = regexp.MustCompile(`^[a-f0-9]{64}$`)

type kvmEngine struct {
	root string
	ctx  context.Context
}
type kvmTemplate struct {
	ID          string `json:"id"`
	Version     string `json:"version"`
	SHA256      string `json:"sha256"`
	VirtualSize int64  `json:"virtual_size"`
	CreatedAt   string `json:"created_at"`
}
type kvmGuest struct {
	ID                string `json:"id"`
	Label             string `json:"label"`
	Template          string `json:"template"`
	VCPUs             int    `json:"vcpus"`
	MemoryMiB         int    `json:"memory_mib"`
	DiskGiB           int    `json:"disk_gib"`
	Network           string `json:"network"`
	NetworkType       string `json:"network_type"`
	Hostname          string `json:"hostname"`
	Username          string `json:"username"`
	CreatedAt         string `json:"created_at"`
	GuestAgentEnabled bool   `json:"guest_agent_enabled,omitempty"`
	NetworkBootFirst  bool   `json:"network_boot_first,omitempty"`
}
type kvmBackup struct {
	ID        string `json:"id"`
	VM        string `json:"vm_id"`
	SHA256    string `json:"sha256"`
	Size      int64  `json:"size"`
	CreatedAt string `json:"created_at"`
}
type kvmOperation struct {
	Hash    string         `json:"hash"`
	Pending bool           `json:"pending"`
	Status  string         `json:"status"`
	Error   string         `json:"error,omitempty"`
	Result  map[string]any `json:"result,omitempty"`
}

var kvmAccelerationAvailable = func() bool { _, err := os.Stat("/dev/kvm"); return err == nil }

func kvmCapabilities() map[string]any {
	missing := []string{}
	for _, b := range []string{"virsh", "qemu-img", "cloud-localds"} {
		if _, err := exec.LookPath(b); err != nil {
			missing = append(missing, b)
		}
	}
	return map[string]any{"version": 1, "console_version": 1, "config_version": 1, "guest_agent_status_version": 1, "guest_network_info_version": 1, "disk_grow_version": 1, "network_config_version": 1, "supported": runtime.GOOS == "linux" && runtime.GOARCH == "amd64", "missing_tools": missing, "acceleration": kvmAccelerationAvailable()}
}

func newKVMEngine(ctx context.Context) (*kvmEngine, error) {
	if runtime.GOOS != "linux" || runtime.GOARCH != "amd64" {
		return nil, errors.New("KVM requires a Linux x86_64 agent")
	}
	root := os.Getenv("EASYWI_KVM_ROOT")
	if root == "" {
		root = "/var/lib/easywi/kvm"
	}
	if !filepath.IsAbs(root) || filepath.Clean(root) != root || root == "/" {
		return nil, errors.New("invalid KVM storage root")
	}
	// Reject symlinks in every existing path component, including the root itself.
	for p := root; p != "/"; p = filepath.Dir(p) {
		i, e := os.Lstat(p)
		if e == nil && i.Mode()&os.ModeSymlink != 0 {
			return nil, errors.New("KVM storage must not contain symlinks")
		}
		if e != nil && !os.IsNotExist(e) {
			return nil, e
		}
	}
	if err := os.MkdirAll(root, 0755); err != nil {
		return nil, err
	}
	e := &kvmEngine{root: root, ctx: ctx}
	for _, d := range []string{"templates", "images", "instances", "backups", "operations", "networks"} {
		mode := os.FileMode(0700)
		if d == "instances" || d == "images" {
			mode = 0755
		}
		path := filepath.Join(root, d)
		if err := os.MkdirAll(path, mode); err != nil {
			return nil, err
		}
		if err := kvmSafeFile(path); err != nil {
			return nil, err
		}
	}
	return e, nil
}

func handleKVMManaged(job jobs.Job) orchestratorResult {
	if payloadValue(job.Payload, "action") == "vm.agent_status" {
		return handleKVMGuestAgentStatus(job)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 12*time.Minute)
	defer cancel()
	e, err := newKVMEngine(ctx)
	if err != nil {
		return kvmFailure(err.Error())
	}
	unlock, err := lockKVM(ctx, filepath.Join(e.root, "host.lock"))
	if err != nil {
		return kvmFailure(err.Error())
	}
	defer unlock()
	action := payloadValue(job.Payload, "action")
	if action == "inspect" {
		v, err := e.inventory()
		if err != nil {
			return kvmFailure(err.Error())
		}
		return kvmSuccess(map[string]any{"inventory": v})
	}
	id := payloadValue(job.Payload, "operation_id")
	if !kvmUUID.MatchString(id) {
		return kvmFailure("invalid KVM operation UUID")
	}
	data, _ := json.Marshal(job.Payload)
	sum := sha256.Sum256(data)
	hash := hex.EncodeToString(sum[:])
	path := filepath.Join(e.root, "operations", id+".json")
	var old kvmOperation
	if err := kvmReadJSON(path, &old); err == nil {
		if old.Hash != hash {
			return kvmFailure("KVM operation UUID already used with different parameters")
		}
		if old.Pending {
			return kvmFailure("KVM_OPERATION_UNCONFIRMED: inspect the host before submitting another operation")
		}
		return orchestratorResult{status: old.Status, errorText: old.Error, resultPayload: old.Result}
	} else if !os.IsNotExist(err) {
		return kvmFailure(err.Error())
	}
	if pending, err := e.pendingOperations(); err != nil {
		return kvmFailure(err.Error())
	} else if len(pending) > 0 {
		return kvmFailure("KVM_OPERATION_UNCONFIRMED: resolve interrupted operation on this host before continuing")
	}
	if err := kvmWriteJSON(path, kvmOperation{Hash: hash, Pending: true}); err != nil {
		return kvmFailure(err.Error())
	}
	result, err := e.perform(action, job.Payload)
	if err != nil && e.ctx.Err() != nil {
		err = fmt.Errorf("%w: operation deadline exceeded; inspect host state", errKVMUnconfirmed)
	}
	status := "success"
	message := ""
	if err != nil {
		status = "failed"
		message = err.Error()
	}
	if result == nil {
		result = map[string]any{}
	}
	result["operation_id"] = id
	result["action"] = action
	if inventory, inventoryErr := e.inventory(); inventoryErr == nil {
		if !errors.Is(err, errKVMUnconfirmed) {
			pending, _ := inventory["pending_operations"].([]string)
			filtered := []string{}
			for _, op := range pending {
				if op != id {
					filtered = append(filtered, op)
				}
			}
			inventory["pending_operations"] = filtered
		}
		result["inventory"] = inventory
	} else {
		result["inventory_error"] = "Inventory refresh failed; request a new inspection."
	}
	if err := kvmWriteJSON(path, kvmOperation{Hash: hash, Pending: errors.Is(err, errKVMUnconfirmed), Status: status, Error: message, Result: result}); err != nil {
		return kvmFailure("KVM_OPERATION_UNCONFIRMED: cannot persist operation result")
	}
	return orchestratorResult{status: status, errorText: message, resultPayload: result}
}

func (e *kvmEngine) perform(action string, p map[string]any) (map[string]any, error) {
	switch action {
	case "template.import":
		return e.importTemplate(p)
	case "vm.create":
		return e.createGuest(p)
	case "network.create":
		return e.createNetwork(p)
	case "network.start", "network.stop", "network.autostart", "network.autostart-disable":
		return e.networkAction(action, p)
	}
	id := payloadValue(p, "vm_id")
	if !kvmUUID.MatchString(id) {
		return nil, errors.New("invalid VM UUID")
	}
	var g kvmGuest
	if err := kvmReadJSON(e.guestFile(id, "manifest.json"), &g); err != nil {
		return nil, errors.New("managed VM not found")
	}
	if g.ID != id {
		return nil, errors.New("VM manifest identity mismatch")
	}
	if err := e.verifyGuest(g); err != nil {
		return nil, err
	}
	if strings.HasPrefix(action, "vm.") {
		verb := strings.TrimPrefix(action, "vm.")
		verbs := map[string]string{"start": "start", "shutdown": "shutdown", "stop": "destroy", "reboot": "reboot", "suspend": "suspend", "resume": "resume", "autostart": "autostart", "autostart-disable": "autostart"}
		if v, ok := verbs[verb]; ok {
			args := []string{v, id}
			if verb == "autostart-disable" {
				args = append(args, "--disable")
			}
			_, err := e.virsh(args...)
			return map[string]any{"vm_id": id}, err
		}
	}
	if err := e.requireStopped(id); err != nil {
		return nil, err
	}
	switch action {
	case "vm.network":
		return e.updateGuestNetwork(g, p)
	case "vm.disk_grow":
		return e.growDisk(g, p)
	case "vm.cloud_init":
		return e.updateCloudInit(g, p)
	case "vm.guest_agent", "vm.boot_order":
		old := g
		if action == "vm.guest_agent" {
			g.GuestAgentEnabled = true
		} else {
			order := payloadValue(p, "boot_order")
			if order != "disk" && order != "network" {
				return nil, errors.New("invalid boot order")
			}
			g.NetworkBootFirst = order == "network"
		}
		if err := e.define(g); err != nil {
			return nil, err
		}
		if err := kvmWriteJSON(e.guestFile(id, "manifest.json"), g); err != nil {
			if rollback := e.define(old); rollback != nil {
				return nil, fmt.Errorf("%w: definition rollback failed", errKVMUnconfirmed)
			}
			return nil, err
		}
		return map[string]any{"vm_id": id}, nil
	case "vm.console.configure":
		return map[string]any{"vm_id": id}, e.define(g)
	case "vm.limits":
		cpu, err := positivePayloadInt(p, "vcpus", 1, 256)
		if err != nil {
			return nil, err
		}
		ram, err := positivePayloadInt(p, "memory_mib", 128, 1048576)
		if err != nil {
			return nil, err
		}
		old := g
		g.VCPUs = cpu
		g.MemoryMiB = ram
		if err := e.define(g); err != nil {
			return nil, err
		}
		if err := kvmWriteJSON(e.guestFile(id, "manifest.json"), g); err != nil {
			_ = e.define(old)
			return nil, err
		}
		return map[string]any{"vm_id": id}, nil
	case "snapshot.create", "snapshot.revert", "snapshot.delete":
		name := payloadValue(p, "snapshot")
		if !kvmSlug.MatchString(name) {
			return nil, errors.New("invalid snapshot name")
		}
		if action == "snapshot.create" {
			_, err := e.virsh("snapshot-create-as", id, name, "--atomic")
			return nil, err
		}
		if action == "snapshot.delete" {
			_, err := e.virsh("snapshot-delete", id, name)
			return nil, err
		}
		if err := e.validateSnapshot(g, name); err != nil {
			return nil, err
		}
		if _, err := e.virsh("snapshot-revert", id, name); err != nil {
			return nil, err
		}
		return nil, e.define(g)
	case "backup.create":
		return e.backup(g, p)
	case "backup.restore":
		return e.restore(g, p)
	case "backup.delete":
		b, err := e.readBackup(id, payloadValue(p, "backup_id"))
		if err != nil {
			return nil, err
		}
		return nil, os.RemoveAll(filepath.Join(e.root, "backups", id, b.ID))
	default:
		return nil, errors.New("unsupported managed KVM action")
	}
}

func (e *kvmEngine) command(binary string, args ...string) ([]byte, error) {
	if err := e.ctx.Err(); err != nil {
		return nil, err
	}
	ctx, cancel := context.WithTimeout(e.ctx, 10*time.Minute)
	defer cancel()
	out, err := kvmRunCommand(ctx, binary, args...)
	if err != nil {
		if ctx.Err() != nil {
			return nil, fmt.Errorf("%w: %s timed out; inspect host state before retrying", errKVMUnconfirmed, binary)
		}
		return nil, fmt.Errorf("%s failed: %.1200s", binary, strings.TrimSpace(string(out)))
	}
	return out, nil
}
func (e *kvmEngine) virsh(args ...string) ([]byte, error) {
	uri := os.Getenv("EASYWI_KVM_URI")
	if uri == "" {
		uri = "qemu:///system"
	}
	if uri != "qemu:///system" && uri != "qemu:///session" {
		return nil, errors.New("only local libvirt connections are supported")
	}
	// Numeric --reason states below avoid locale dependent safety decisions.
	return e.command("virsh", append([]string{"--connect", uri}, args...)...)
}
func (e *kvmEngine) guestFile(id, name string) string {
	return filepath.Join(e.root, "instances", id, name)
}
func (e *kvmEngine) requireStopped(id string) error {
	out, err := e.virsh("domstate", id, "--reason")
	if err != nil {
		return err
	}
	state := strings.ToLower(strings.TrimSpace(string(out)))
	if state != "shut off (unknown)" && !strings.HasPrefix(state, "shut off (") {
		return errors.New("VM must be shut off for this operation")
	}
	return nil
}

func kvmReadJSON(path string, v any) error {
	if err := kvmSafeFile(path); err != nil {
		return err
	}
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer func() { _ = f.Close() }()
	return json.NewDecoder(io.LimitReader(f, 4<<20)).Decode(v)
}
func kvmSafeFile(path string) error {
	for p := path; p != "/" && p != "."; p = filepath.Dir(p) {
		i, err := os.Lstat(p)
		if err != nil {
			return err
		}
		if i.Mode()&os.ModeSymlink != 0 {
			return errors.New("symlinks are not allowed in managed KVM storage")
		}
	}
	return nil
}
func kvmWriteJSON(path string, v any) error {
	data, err := json.MarshalIndent(v, "", "  ")
	if err != nil {
		return err
	}
	return kvmAtomicWrite(path, data, 0600)
}
func kvmAtomicWrite(path string, data []byte, mode os.FileMode) error {
	if err := kvmSafeFile(filepath.Dir(path)); err != nil {
		return err
	}
	if i, err := os.Lstat(path); err == nil && i.Mode()&os.ModeSymlink != 0 {
		return errors.New("symlink target rejected")
	}
	f, err := os.CreateTemp(filepath.Dir(path), ".pending-")
	if err != nil {
		return err
	}
	defer func() { _ = os.Remove(f.Name()) }()
	if err = f.Chmod(mode); err == nil {
		_, err = f.Write(data)
	}
	if err == nil {
		err = f.Sync()
	}
	closeErr := f.Close()
	if err == nil {
		err = closeErr
	}
	if err != nil {
		return err
	}
	if err = os.Rename(f.Name(), path); err != nil {
		return err
	}
	d, err := os.Open(filepath.Dir(path))
	if err != nil {
		return err
	}
	defer func() { _ = d.Close() }()
	return d.Sync()
}
func kvmNow() string { return time.Now().UTC().Format(time.RFC3339) }
func kvmStringList(v any) []string {
	switch x := v.(type) {
	case []any:
		r := []string{}
		for _, s := range x {
			if t, ok := s.(string); ok {
				r = append(r, t)
			}
		}
		return r
	case []string:
		return x
	}
	return nil
}

func (e *kvmEngine) pendingOperations() ([]string, error) {
	pending := []string{}
	entries, err := os.ReadDir(filepath.Join(e.root, "operations"))
	if err != nil {
		return nil, err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var op kvmOperation
		if err := kvmReadJSON(filepath.Join(e.root, "operations", entry.Name()), &op); err != nil {
			return nil, err
		}
		if op.Pending {
			pending = append(pending, strings.TrimSuffix(entry.Name(), ".json"))
		}
	}
	return pending, nil
}
