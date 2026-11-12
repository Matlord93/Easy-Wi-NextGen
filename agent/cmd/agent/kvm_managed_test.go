package main

import (
	"context"
	"crypto/ed25519"
	"crypto/rand"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"encoding/xml"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"reflect"
	"strings"
	"testing"

	"easywi/agent/internal/jobs"
	"golang.org/x/crypto/ssh"
	"gopkg.in/yaml.v3"
)

const kvmTestVM = "11111111-1111-4111-8111-111111111111"
const kvmTestOp = "22222222-2222-4222-8222-222222222222"
const kvmTestOp2 = "33333333-3333-4333-8333-333333333333"

type kvmFixture struct {
	t         *testing.T
	e         *kvmEngine
	domains   map[string][]byte
	states    map[string]string
	snapshots map[string][]byte
	networks  map[string][]byte
	writes    []string
	native    bool
	failVerb  string
}

func kvmNewFixture(t *testing.T) *kvmFixture {
	t.Helper()
	t.Setenv("EASYWI_KVM_ROOT", t.TempDir())
	t.Setenv("EASYWI_KVM_URI", "")
	t.Setenv("EASYWI_KVM_BRIDGES", "")
	original := kvmAccelerationAvailable
	kvmAccelerationAvailable = func() bool { return true }
	t.Cleanup(func() { kvmAccelerationAvailable = original })
	e, err := newKVMEngine(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	f := &kvmFixture{t: t, e: e, domains: map[string][]byte{}, states: map[string]string{}, snapshots: map[string][]byte{}, networks: map[string][]byte{}, native: os.Getenv("EASYWI_KVM_NATIVE_TESTS") == "1"}
	withKVMRunner(t, f.run)
	return f
}
func (f *kvmFixture) run(ctx context.Context, binary string, args ...string) ([]byte, error) {
	if binary != "virsh" {
		if f.native {
			return exec.CommandContext(ctx, binary, args...).CombinedOutput()
		}
		if binary == "cloud-localds" {
			return nil, os.WriteFile(args[1], []byte("FAKE ISO"), 0600)
		}
		if binary != "qemu-img" {
			f.t.Fatalf("unexpected binary %s", binary)
		}
		switch args[0] {
		case "info":
			data, err := os.ReadFile(args[len(args)-1])
			if err != nil {
				return nil, err
			}
			return []byte(fmt.Sprintf(`{"format":"qcow2","virtual-size":%d}`, binaryBigSize(data))), nil
		case "convert":
			source := args[len(args)-2]
			target := args[len(args)-1]
			data, err := os.ReadFile(source)
			if err != nil {
				return nil, err
			}
			return nil, os.WriteFile(target, data, 0600)
		case "resize":
			path := args[len(args)-2]
			data, err := os.ReadFile(path)
			if err != nil {
				return nil, err
			}
			var gib uint64
			fmt.Sscanf(args[len(args)-1], "%dG", &gib)
			binaryPutSize(data, gib<<30)
			return nil, os.WriteFile(path, data, 0600)
		}
		f.t.Fatalf("unexpected qemu arguments %v", args)
	}
	if len(args) < 3 || args[0] != "--connect" || args[1] != "qemu:///system" {
		f.t.Fatalf("unsafe libvirt connection %v", args)
	}
	args = args[2:]
	verb := args[0]
	if verb == f.failVerb {
		return []byte("synthetic libvirt failure"), errors.New("failed")
	}
	switch verb {
	case "list":
		names := []string{}
		for id := range f.domains {
			names = append(names, id)
		}
		return []byte(strings.Join(names, "\n")), nil
	case "define":
		data, err := os.ReadFile(args[1])
		if err != nil {
			return nil, err
		}
		var d kvmDomain
		if err := xml.Unmarshal(data, &d); err != nil {
			return nil, err
		}
		f.domains[d.UUID] = data
		if f.states[d.UUID] == "" {
			f.states[d.UUID] = "shut off"
		}
		f.writes = append(f.writes, verb)
		if f.native {
			if out, err := exec.Command("virt-xml-validate", args[1], "domain").CombinedOutput(); err != nil {
				f.t.Fatalf("invalid domain XML: %s", out)
			}
		}
		return nil, nil
	case "dumpxml":
		data, ok := f.domains[args[1]]
		if !ok {
			return nil, errors.New("not found")
		}
		return data, nil
	case "dominfo":
		return []byte("Name: easywi-kvm-" + args[1] + "\nCPU(s): 2\nMax memory: 2097152 KiB\n"), nil
	case "domstate":
		state := f.states[args[1]]
		if len(args) > 2 {
			state += " (unknown)"
		}
		return []byte(state), nil
	case "start", "shutdown", "destroy", "reboot", "suspend", "resume", "autostart":
		f.writes = append(f.writes, verb)
		switch verb {
		case "start", "resume":
			f.states[args[1]] = "running"
		case "shutdown", "destroy":
			f.states[args[1]] = "shut off"
		case "suspend":
			f.states[args[1]] = "paused"
		}
		return nil, nil
	case "snapshot-list":
		names := []string{}
		for name := range f.snapshots {
			names = append(names, name)
		}
		return []byte(strings.Join(names, "\n")), nil
	case "snapshot-create-as":
		f.snapshots[args[2]] = append([]byte(nil), f.domains[args[1]]...)
		f.writes = append(f.writes, verb)
		if f.native {
			return exec.Command("qemu-img", "snapshot", "-c", args[2], f.e.guestFile(args[1], "disk.qcow2")).CombinedOutput()
		}
		return nil, nil
	case "snapshot-dumpxml":
		data, ok := f.snapshots[args[2]]
		if !ok {
			return nil, errors.New("not found")
		}
		return []byte("<domainsnapshot>" + string(data) + "<disks><disk name='vda' snapshot='internal'/></disks></domainsnapshot>"), nil
	case "snapshot-revert":
		f.domains[args[1]] = f.snapshots[args[2]]
		f.writes = append(f.writes, verb)
		if f.native {
			return exec.Command("qemu-img", "snapshot", "-a", args[2], f.e.guestFile(args[1], "disk.qcow2")).CombinedOutput()
		}
		return nil, nil
	case "snapshot-delete":
		delete(f.snapshots, args[2])
		f.writes = append(f.writes, verb)
		if f.native {
			return exec.Command("qemu-img", "snapshot", "-d", args[2], f.e.guestFile(args[1], "disk.qcow2")).CombinedOutput()
		}
		return nil, nil
	case "net-list":
		names := []string{"default"}
		for name := range f.networks {
			names = append(names, name)
		}
		return []byte(strings.Join(names, "\n")), nil
	case "net-info":
		return []byte("Name: " + args[1] + "\nActive: yes\nBridge: virbr0\n"), nil
	case "net-dumpxml":
		if data, ok := f.networks[args[1]]; ok {
			return data, nil
		}
		return []byte("<network><name>default</name><ip address='192.168.122.1' netmask='255.255.255.0'/></network>"), nil
	case "net-define":
		data, err := os.ReadFile(args[1])
		if err != nil {
			return nil, err
		}
		var n kvmNetwork
		if err := xml.Unmarshal(data, &n); err != nil {
			return nil, err
		}
		f.networks[n.Name] = data
		f.writes = append(f.writes, verb)
		if f.native {
			if out, err := exec.Command("virt-xml-validate", args[1], "network").CombinedOutput(); err != nil {
				f.t.Fatalf("invalid network XML: %s", out)
			}
		}
		return nil, nil
	case "net-start", "net-destroy", "net-autostart":
		f.writes = append(f.writes, verb)
		return nil, nil
	}
	f.t.Fatalf("unexpected virsh arguments: %v", args)
	return nil, nil
}
func binaryBigSize(data []byte) uint64    { return binary.BigEndian.Uint64(data[24:32]) }
func binaryPutSize(data []byte, n uint64) { binary.BigEndian.PutUint64(data[24:32], n) }
func (f *kvmFixture) image(path string) {
	f.t.Helper()
	if f.native {
		if out, err := exec.Command("qemu-img", "create", "-f", "qcow2", path, "64M").CombinedOutput(); err != nil {
			f.t.Fatalf("qemu-img: %s", out)
		}
		return
	}
	data := make([]byte, 104)
	copy(data, []byte("QFI\xfb"))
	binary.BigEndian.PutUint32(data[4:8], 3)
	binaryPutSize(data, 64<<20)
	if err := os.WriteFile(path, data, 0600); err != nil {
		f.t.Fatal(err)
	}
}
func (f *kvmFixture) template() {
	f.t.Helper()
	temp := filepath.Join(f.e.root, "image.qcow2")
	f.image(temp)
	hash, err := f.e.fileHash(temp)
	if err != nil {
		f.t.Fatal(err)
	}
	if err := os.Rename(temp, filepath.Join(f.e.root, "images", hash+".qcow2")); err != nil {
		f.t.Fatal(err)
	}
	if err := kvmWriteJSON(filepath.Join(f.e.root, "templates", "ubuntu--24.04-v1.json"), kvmTemplate{ID: "ubuntu", Version: "24.04-v1", SHA256: hash, VirtualSize: 64 << 20}); err != nil {
		f.t.Fatal(err)
	}
}
func (f *kvmFixture) create() orchestratorResult {
	f.t.Helper()
	f.template()
	pub, _, _ := ed25519.GenerateKey(rand.Reader)
	key, _ := ssh.NewPublicKey(pub)
	return f.job("vm.create", kvmTestVM, map[string]any{"label": "VM <test>", "template": "ubuntu--24.04-v1", "hostname": "test-vm", "username": "easywi", "network": "default", "network_type": "network", "vcpus": 2, "memory_mib": 2048, "disk_gib": 1, "ssh_keys": []string{strings.TrimSpace(string(ssh.MarshalAuthorizedKey(key)))}})
}
func (f *kvmFixture) job(action, id string, extra map[string]any) orchestratorResult {
	p := map[string]any{"action": action, "operation_id": id}
	for k, v := range extra {
		p[k] = v
	}
	return handleKVMJob(jobs.Job{Type: "kvm.managed", Payload: p})
}
func kvmAssertSuccess(t *testing.T, r orchestratorResult) {
	t.Helper()
	if r.status != "success" {
		t.Fatalf("expected success: %s", r.errorText)
	}
}

func TestKVMManagedProvisionAndCloudInit(t *testing.T) {
	f := kvmNewFixture(t)
	r := f.create()
	kvmAssertSuccess(t, r)
	data, err := os.ReadFile(f.e.guestFile(kvmTestVM, "user-data"))
	if err != nil {
		t.Fatal(err)
	}
	var config map[string]any
	if err := yaml.Unmarshal(data, &config); err != nil {
		t.Fatal(err)
	}
	if config["ssh_pwauth"] != false || config["disable_root"] != true || config["hostname"] != "test-vm" {
		t.Fatalf("unsafe cloud-init: %s", data)
	}
	meta, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "meta-data"))
	if !strings.Contains(string(meta), kvmTestVM) {
		t.Fatal("instance identity missing")
	}
	if f.native {
		iso, err := exec.Command("isoinfo", "-R", "-i", f.e.guestFile(kvmTestVM, "seed.iso"), "-x", "/user-data").CombinedOutput()
		if err != nil || !strings.Contains(string(iso), "ssh_authorized_keys") {
			t.Fatalf("Cloud-Init ISO missing user data: %s %v", iso, err)
		}
	}
	before := len(f.writes)
	result := f.job("vm.start", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
	kvmAssertSuccess(t, result)
	again := f.job("vm.start", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
	kvmAssertSuccess(t, again)
	if len(f.writes) != before+1 {
		t.Fatal("duplicate power action executed")
	}
	bad := f.job("vm.stop", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
	if bad.status != "failed" || !strings.Contains(bad.errorText, "different parameters") {
		t.Fatal("operation collision accepted")
	}
	// Running guests must still permit shutdown, but forbid offline mutations.
	denied := f.job("vm.limits", kvmTestOp2, map[string]any{"vm_id": kvmTestVM, "vcpus": 4, "memory_mib": 4096})
	if denied.status != "failed" || !strings.Contains(denied.errorText, "shut off") {
		t.Fatalf("live limit change: %#v", denied)
	}
}

func TestKVMManagedSnapshotsReapplyCurrentLimits(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	kvmAssertSuccess(t, f.job("snapshot.create", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "snapshot": "before-change"}))
	kvmAssertSuccess(t, f.job("vm.limits", kvmTestOp2, map[string]any{"vm_id": kvmTestVM, "vcpus": 4, "memory_mib": 4096}))
	kvmAssertSuccess(t, f.job("snapshot.revert", "44444444-4444-4444-8444-444444444444", map[string]any{"vm_id": kvmTestVM, "snapshot": "before-change"}))
	var d kvmDomain
	_ = xml.Unmarshal(f.domains[kvmTestVM], &d)
	if d.VCPU != 4 || d.Memory.Value != 4096 {
		t.Fatal("snapshot restored old resource allocation")
	}
	if n := strings.Join(f.writes, ","); strings.Contains(n, "setvcpus") || strings.Contains(n, "setmem") {
		t.Fatal("partial resource changes used")
	}
}
func TestKVMManagedBackupRestoreIntegrityAndRollback(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	kvmAssertSuccess(t, f.job("backup.create", kvmTestOp, map[string]any{"vm_id": kvmTestVM}))
	source := filepath.Join(f.e.root, "backups", kvmTestVM, kvmTestOp, "disk.qcow2")
	data, _ := os.ReadFile(source)
	if err := os.WriteFile(source, append(data, 42), 0600); err != nil {
		t.Fatal(err)
	}
	diskBefore, _ := f.e.fileHash(f.e.guestFile(kvmTestVM, "disk.qcow2"))
	bad := f.job("backup.restore", kvmTestOp2, map[string]any{"vm_id": kvmTestVM, "backup_id": kvmTestOp})
	if bad.status != "failed" || !strings.Contains(bad.errorText, "checksum") {
		t.Fatalf("corrupt restore accepted: %#v", bad)
	}
	diskAfter, _ := f.e.fileHash(f.e.guestFile(kvmTestVM, "disk.qcow2"))
	if diskBefore != diskAfter {
		t.Fatal("original disk changed on checksum failure")
	}
	_ = os.WriteFile(source, data, 0600)
	f.failVerb = "define"
	failed := f.job("backup.restore", "44444444-4444-4444-8444-444444444444", map[string]any{"vm_id": kvmTestVM, "backup_id": kvmTestOp})
	if failed.status != "failed" {
		t.Fatal("define failure ignored")
	}
	diskAfter, _ = f.e.fileHash(f.e.guestFile(kvmTestVM, "disk.qcow2"))
	if diskBefore != diskAfter {
		t.Fatal("disk was not rolled back after define failure")
	}
	f.failVerb = ""
	kvmAssertSuccess(t, f.job("backup.restore", "55555555-5555-4555-8555-555555555555", map[string]any{"vm_id": kvmTestVM, "backup_id": kvmTestOp}))
	if _, err := os.Stat(f.e.guestFile(kvmTestVM, "before-restore-55555555-5555-4555-8555-555555555555.qcow2")); err != nil {
		t.Fatal("recovery disk was not retained")
	}
	kvmAssertSuccess(t, f.job("backup.delete", "66666666-6666-4666-8666-666666666666", map[string]any{"vm_id": kvmTestVM, "backup_id": kvmTestOp}))
	if _, err := os.Stat(source); !os.IsNotExist(err) {
		t.Fatal("backup not deleted")
	}
}
func TestKVMManagedRejectsForeignDiskAndSnapshotDevices(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	original := append([]byte(nil), f.domains[kvmTestVM]...)
	f.domains[kvmTestVM] = []byte(strings.ReplaceAll(string(original), f.e.guestFile(kvmTestVM, "disk.qcow2"), "/etc/shadow"))
	before := len(f.writes)
	r := f.job("vm.start", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
	if r.status != "failed" || len(f.writes) != before {
		t.Fatal("foreign disk accepted")
	}
	f.domains[kvmTestVM] = original
	f.snapshots["bad-snapshot"] = []byte(strings.Replace(string(original), "</devices>", "<hostdev mode='subsystem' type='pci'/></devices>", 1))
	r = f.job("snapshot.revert", kvmTestOp2, map[string]any{"vm_id": kvmTestVM, "snapshot": "bad-snapshot"})
	if r.status != "failed" || !strings.Contains(r.errorText, "host device") {
		t.Fatalf("forbidden snapshot device accepted: %#v", r)
	}
}
func TestKVMManagedSymlinkAndUnconfirmedOperations(t *testing.T) {
	f := kvmNewFixture(t)
	if err := os.Remove(filepath.Join(f.e.root, "backups")); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(t.TempDir(), filepath.Join(f.e.root, "backups")); err != nil {
		t.Fatal(err)
	}
	r := f.job("inspect", "", nil)
	if r.status != "failed" || !strings.Contains(r.errorText, "symlink") {
		t.Fatal("symlink root accepted")
	}
	os.Remove(filepath.Join(f.e.root, "backups"))
	os.Mkdir(filepath.Join(f.e.root, "backups"), 0700)
	kvmWriteJSON(filepath.Join(f.e.root, "operations", kvmTestOp+".json"), kvmOperation{Hash: "synthetic", Pending: true})
	r = f.job("network.create", kvmTestOp2, map[string]any{"network": "easywi-test", "cidr": "10.220.240.0/24", "mode": "nat"})
	if r.status != "failed" || !strings.Contains(r.errorText, "UNCONFIRMED") {
		t.Fatal("interrupted operation did not block mutations")
	}
	kvmAssertSuccess(t, f.job("inspect", "", nil))
}
func TestKVMManagedNetworkCreationAndAttachmentGuard(t *testing.T) {
	f := kvmNewFixture(t)
	r := f.job("network.create", kvmTestOp, map[string]any{"network": "easywi-test", "cidr": "10.220.240.0/24", "mode": "nat"})
	kvmAssertSuccess(t, r)
	var n kvmNetwork
	xml.Unmarshal(f.networks["easywi-test"], &n)
	if n.IPs[0].DHCP.Range.Start != "10.220.240.2" || n.IPs[0].DHCP.Range.End != "10.220.240.254" {
		t.Fatalf("invalid DHCP range: %#v", n)
	}
	// A domain assigned to the libvirt bridge must also prevent network teardown.
	f.domains[kvmTestVM] = []byte("<domain><uuid>" + kvmTestVM + "</uuid><devices><interface type='bridge'><source bridge='" + n.Bridge.Name + "'/></interface></devices></domain>")
	r = f.job("network.stop", kvmTestOp2, map[string]any{"network": "easywi-test"})
	if r.status != "failed" || !strings.Contains(r.errorText, "assigned") {
		t.Fatal("attached bridge stopped")
	}
}
func TestKVMManagedNetworkRejectsOverlapAndUnapprovedBridges(t *testing.T) {
	f := kvmNewFixture(t)
	r := f.job("network.create", kvmTestOp, map[string]any{"network": "easywi-test", "cidr": "192.168.122.0/24", "mode": "nat"})
	if r.status != "failed" || !strings.Contains(r.errorText, "overlap") {
		t.Fatal("overlapping network accepted")
	}
	if err := f.e.checkNetwork("bridge", "lo"); err == nil {
		t.Fatal("unapproved bridge accepted")
	}
}
func TestKVMManagedImageValidationRejectsExternalBacking(t *testing.T) {
	f := kvmNewFixture(t)
	path := filepath.Join(f.e.root, "bad.qcow2")
	f.image(path)
	data, _ := os.ReadFile(path)
	binary.BigEndian.PutUint64(data[8:16], 4096)
	os.WriteFile(path, data, 0600)
	if _, err := f.e.safeImage(path); err == nil {
		t.Fatal("external backing file allowed")
	}
}
func TestKVMManagedTemplateImportPinsChecksumAndVersion(t *testing.T) {
	f := kvmNewFixture(t)
	path := filepath.Join(f.e.root, "download.qcow2")
	f.image(path)
	data, _ := os.ReadFile(path)
	sum := sha256.Sum256(data)
	hash := hex.EncodeToString(sum[:])
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write(data) }))
	defer server.Close()
	original := kvmNewImageClient
	kvmNewImageClient = func() *http.Client { return server.Client() }
	t.Cleanup(func() { kvmNewImageClient = original })
	p := map[string]any{"template_id": "ubuntu", "version": "24.04-v1", "url": server.URL + "/image.qcow2", "sha256": hash}
	kvmAssertSuccess(t, f.job("template.import", kvmTestOp, p))
	r := f.job("template.import", kvmTestOp2, p)
	if r.status != "failed" || !strings.Contains(r.errorText, "already exists") {
		t.Fatal("immutable template overwritten")
	}
	p["version"] = "24.04-v2"
	p["sha256"] = strings.Repeat("0", 64)
	r = f.job("template.import", "44444444-4444-4444-8444-444444444444", p)
	if r.status != "failed" || !strings.Contains(r.errorText, "SHA256") {
		t.Fatal("checksum mismatch accepted")
	}
}
func TestKVMImageURLsAndPrivateDialAreRestricted(t *testing.T) {
	for _, u := range []string{"http://example.org/x", "https://u:p@example.org/x", "https://example.org/x?token=abc", "https://example.org/x#fragment"} {
		if _, err := kvmImageURL(u); err == nil {
			t.Fatalf("unsafe URL: %s", u)
		}
	}
	t.Setenv("EASYWI_KVM_IMAGE_HOSTS", "")
	_, err := kvmImageClient().Get("https://127.0.0.1/test")
	if err == nil || !strings.Contains(err.Error(), "allowlist") {
		t.Fatalf("private dial not blocked: %v", err)
	}
}
func TestKVMManagedExternalDomainsAreReadOnly(t *testing.T) {
	f := kvmNewFixture(t)
	f.domains[kvmTestVM] = []byte("<domain/>")
	f.states[kvmTestVM] = "running"
	r := f.job("inspect", "", nil)
	kvmAssertSuccess(t, r)
	inventory := r.resultPayload["inventory"].(map[string]any)
	guests := inventory["guests"].([]map[string]any)
	if len(guests) != 1 || guests[0]["managed"] != false {
		t.Fatal("external domain silently adopted")
	}
	r = f.job("vm.stop", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
	if r.status != "failed" || len(f.writes) != 0 {
		t.Fatal("external domain mutated")
	}
}
func TestKVMManagedLimitFailureLeavesManifestUnchanged(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	before, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
	f.failVerb = "define"
	r := f.job("vm.limits", kvmTestOp, map[string]any{"vm_id": kvmTestVM, "vcpus": 4, "memory_mib": 4096})
	if r.status != "failed" {
		t.Fatal("failure ignored")
	}
	after, _ := os.ReadFile(f.e.guestFile(kvmTestVM, "manifest.json"))
	if !reflect.DeepEqual(before, after) {
		t.Fatal("failed limit change persisted")
	}
}
func TestKVMManagedTraversalRejected(t *testing.T) {
	f := kvmNewFixture(t)
	r := f.job("vm.start", kvmTestOp, map[string]any{"vm_id": "../../etc"})
	if r.status != "failed" || len(f.writes) > 0 {
		t.Fatal("VM traversal accepted")
	}
	r = f.job("backup.delete", kvmTestOp2, map[string]any{"vm_id": kvmTestVM, "backup_id": "../../etc"})
	if r.status != "failed" {
		t.Fatal("backup traversal accepted")
	}
}

// Ensure test JSON serialization matches the wire representation, too.
func TestKVMManagedJSONNumericResources(t *testing.T) {
	var p map[string]any
	json.Unmarshal([]byte(`{"vcpus":2,"memory_mib":2048}`), &p)
	n, err := positivePayloadInt(p, "vcpus", 1, 256)
	if err != nil || n != 2 {
		t.Fatalf("wire number rejected: %v", err)
	}
}

func TestKVMManagedConcurrentDuplicateExecutesOnce(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	before := len(f.writes)
	results := make(chan orchestratorResult, 8)
	for i := 0; i < 8; i++ {
		go func() { results <- f.job("vm.start", kvmTestOp, map[string]any{"vm_id": kvmTestVM}) }()
	}
	for i := 0; i < 8; i++ {
		kvmAssertSuccess(t, <-results)
	}
	if len(f.writes) != before+1 {
		t.Fatalf("duplicate start executed %d times", len(f.writes)-before)
	}
}
func TestKVMManagedNetworkReplacementIsRejected(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.job("network.create", kvmTestOp, map[string]any{"network": "easywi-test", "cidr": "10.220.240.0/24", "mode": "isolated"}))
	f.networks["easywi-test"] = []byte(strings.Replace(string(f.networks["easywi-test"]), kvmTestOp, kvmTestOp2, 1))
	before := len(f.writes)
	r := f.job("network.start", kvmTestOp2, map[string]any{"network": "easywi-test"})
	if r.status != "failed" || !strings.Contains(r.errorText, "identity") || len(f.writes) != before {
		t.Fatal("replacement network was accepted")
	}
}

func TestKVMManagedProvisionNeverReplacesExistingUUID(t *testing.T) {
	f := kvmNewFixture(t)
	f.domains[kvmTestVM] = []byte("<domain type='kvm'><uuid>" + kvmTestVM + "</uuid><name>unrelated-production</name></domain>")
	before := append([]byte(nil), f.domains[kvmTestVM]...)
	r := f.create()
	if r.status != "failed" || !strings.Contains(r.errorText, "already exists") || !reflect.DeepEqual(before, f.domains[kvmTestVM]) || len(f.writes) > 0 {
		t.Fatal("existing domain was replaced")
	}
}
