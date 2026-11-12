package main

import (
	"crypto/sha256"
	"encoding/json"
	"encoding/xml"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"

	"golang.org/x/crypto/ssh"
	"gopkg.in/yaml.v3"
)

type kvmDomain struct {
	XMLName       xml.Name     `xml:"domain"`
	Type          string       `xml:"type,attr"`
	Name          string       `xml:"name"`
	UUID          string       `xml:"uuid"`
	Memory        kvmXMLMemory `xml:"memory"`
	CurrentMemory kvmXMLMemory `xml:"currentMemory"`
	VCPU          int          `xml:"vcpu"`
	OS            struct {
		Type struct {
			Arch    string `xml:"arch,attr"`
			Machine string `xml:"machine,attr"`
			Value   string `xml:",chardata"`
		} `xml:"type"`
		Boot struct {
			Dev string `xml:"dev,attr"`
		} `xml:"boot"`
	} `xml:"os"`
	Features struct {
		ACPI *struct{} `xml:"acpi"`
		APIC *struct{} `xml:"apic"`
	} `xml:"features"`
	Clock struct {
		Offset string `xml:"offset,attr"`
	} `xml:"clock"`
	OnPoweroff string `xml:"on_poweroff"`
	OnReboot   string `xml:"on_reboot"`
	OnCrash    string `xml:"on_crash"`
	Devices    struct {
		Graphics   []kvmGraphics     `xml:"graphics"`
		Disks      []kvmXMLDisk      `xml:"disk"`
		Interfaces []kvmXMLInterface `xml:"interface"`
		Serial     struct {
			Type   string `xml:"type,attr"`
			Target struct {
				Port string `xml:"port,attr"`
			} `xml:"target"`
		} `xml:"serial"`
		Console struct {
			Type   string `xml:"type,attr"`
			Target struct {
				Type string `xml:"type,attr"`
				Port string `xml:"port,attr"`
			} `xml:"target"`
		} `xml:"console"`
	} `xml:"devices"`
}
type kvmXMLMemory struct {
	Unit  string `xml:"unit,attr"`
	Value int    `xml:",chardata"`
}
type kvmXMLDisk struct {
	Type   string `xml:"type,attr"`
	Device string `xml:"device,attr"`
	Driver struct {
		Name string `xml:"name,attr"`
		Type string `xml:"type,attr"`
	} `xml:"driver"`
	Source struct {
		File string `xml:"file,attr"`
	} `xml:"source"`
	Target struct {
		Dev string `xml:"dev,attr"`
		Bus string `xml:"bus,attr"`
	} `xml:"target"`
	Readonly *struct{} `xml:"readonly"`
	Backing  *struct {
		Source *struct{} `xml:"source"`
	} `xml:"backingStore"`
}
type kvmXMLInterface struct {
	MAC struct {
		Address string `xml:"address,attr"`
	} `xml:"mac"`
	Type   string `xml:"type,attr"`
	Source struct {
		Network string `xml:"network,attr,omitempty"`
		Bridge  string `xml:"bridge,attr,omitempty"`
	} `xml:"source"`
	Model struct {
		Type string `xml:"type,attr"`
	} `xml:"model"`
}

func (e *kvmEngine) domain(g kvmGuest) kvmDomain {
	d := kvmDomain{Type: "kvm", Name: "easywi-kvm-" + g.ID, UUID: g.ID, Memory: kvmXMLMemory{"MiB", g.MemoryMiB}, CurrentMemory: kvmXMLMemory{"MiB", g.MemoryMiB}, VCPU: g.VCPUs, OnPoweroff: "destroy", OnReboot: "restart", OnCrash: "destroy"}
	d.OS.Type.Arch = "x86_64"
	d.OS.Type.Machine = "pc"
	d.OS.Type.Value = "hvm"
	d.OS.Boot.Dev = "hd"
	d.Features.ACPI = &struct{}{}
	d.Features.APIC = &struct{}{}
	d.Clock.Offset = "utc"
	disk := kvmXMLDisk{Type: "file", Device: "disk"}
	disk.Driver.Name = "qemu"
	disk.Driver.Type = "qcow2"
	disk.Source.File = e.guestFile(g.ID, "disk.qcow2")
	disk.Target.Dev = "vda"
	disk.Target.Bus = "virtio"
	seed := kvmXMLDisk{Type: "file", Device: "cdrom", Readonly: &struct{}{}}
	seed.Driver.Name = "qemu"
	seed.Driver.Type = "raw"
	seed.Source.File = e.guestFile(g.ID, "seed.iso")
	seed.Target.Dev = "hda"
	seed.Target.Bus = "ide"
	d.Devices.Disks = []kvmXMLDisk{disk, seed}
	graphics := kvmGraphics{Type: "vnc"}
	graphics.Listen.Type = "socket"
	// libvirt generates a socket in its private domain runtime directory.
	// QEMU needs no write access to the managed disk/manifest directory.
	d.Devices.Graphics = []kvmGraphics{graphics}
	nic := kvmXMLInterface{Type: g.NetworkType}
	nic.Model.Type = "virtio"
	nic.MAC.Address = kvmGuestMAC(g.ID)
	if g.NetworkType == "bridge" {
		nic.Source.Bridge = g.Network
	} else {
		nic.Source.Network = g.Network
	}
	d.Devices.Interfaces = []kvmXMLInterface{nic}
	d.Devices.Serial.Type = "pty"
	d.Devices.Serial.Target.Port = "0"
	d.Devices.Console.Type = "pty"
	d.Devices.Console.Target.Type = "serial"
	d.Devices.Console.Target.Port = "0"
	return d
}
func (e *kvmEngine) define(g kvmGuest) error {
	data, err := xml.MarshalIndent(e.domain(g), "", "  ")
	if err != nil {
		return err
	}
	path := e.guestFile(g.ID, "domain.xml")
	if err := kvmAtomicWrite(path, data, 0600); err != nil {
		return err
	}
	_, err = e.virsh("define", path)
	return err
}

func (e *kvmEngine) verifyXML(g kvmGuest, data []byte) error {
	var d kvmDomain
	if xml.Unmarshal(data, &d) != nil || d.UUID != g.ID || d.Name != "easywi-kvm-"+g.ID || d.Type != "kvm" {
		return errors.New("libvirt VM identity mismatch")
	}
	// Fail closed if an operator changed disk sources or added passthrough/shared
	// host files. Such domains must be repaired by the host operator first.
	dec := xml.NewDecoder(strings.NewReader(string(data)))
	for {
		token, err := dec.Token()
		if err != nil {
			break
		}
		if t, ok := token.(xml.StartElement); ok {
			switch t.Name.Local {
			case "hostdev", "filesystem", "shmem", "redirdev", "commandline", "launchSecurity", "dataStore":
				return errors.New("unsupported host device or external data in managed domain")
			}
			if t.Name.Space != "" && t.Name.Space != "http://libvirt.org/schemas/domain/1.0" {
				return errors.New("domain XML extensions are not allowed")
			}
		}
	}
	if len(d.Devices.Disks) != 2 || len(d.Devices.Interfaces) != 1 {
		return errors.New("managed VM device configuration changed")
	}
	found := 0
	for _, disk := range d.Devices.Disks {
		if disk.Type != "file" || disk.Backing != nil && disk.Backing.Source != nil {
			return errors.New("external VM disk source is not allowed")
		}
		if disk.Device == "disk" && disk.Source.File == e.guestFile(g.ID, "disk.qcow2") && disk.Driver.Type == "qcow2" {
			found |= 1
		} else if disk.Device == "cdrom" && disk.Source.File == e.guestFile(g.ID, "seed.iso") && disk.Readonly != nil {
			found |= 2
		} else {
			return errors.New("managed VM disk source changed")
		}
		if err := kvmSafeFile(disk.Source.File); err != nil {
			return err
		}
	}
	if len(d.Devices.Graphics) > 0 && !e.consoleConfigured(g, d) {
		return errors.New("managed VNC must use its private Unix socket")
	}
	nic := d.Devices.Interfaces[0]
	if found != 3 || nic.MAC.Address != kvmGuestMAC(g.ID) || nic.Type != g.NetworkType || (g.NetworkType == "bridge" && nic.Source.Bridge != g.Network) || (g.NetworkType == "network" && nic.Source.Network != g.Network) {
		return errors.New("managed VM network configuration changed")
	}
	return nil
}
func (e *kvmEngine) verifyGuest(g kvmGuest) error {
	out, err := e.virsh("dumpxml", g.ID, "--inactive")
	if err != nil {
		return err
	}
	if err := e.verifyXML(g, out); err != nil {
		return err
	}
	_, err = e.safeImage(e.guestFile(g.ID, "disk.qcow2"))
	return err
}
func (e *kvmEngine) validateSnapshot(g kvmGuest, name string) error {
	out, err := e.virsh("snapshot-dumpxml", g.ID, name)
	if err != nil {
		return err
	}
	var snapshot struct {
		Domain struct {
			Type  string `xml:"type,attr"`
			Inner string `xml:",innerxml"`
		} `xml:"domain"`
		Disks []struct {
			Source   *struct{} `xml:"source"`
			Snapshot string    `xml:"snapshot,attr"`
		} `xml:"disks>disk"`
	}
	if xml.Unmarshal(out, &snapshot) != nil {
		return errors.New("invalid snapshot XML")
	}
	for _, disk := range snapshot.Disks {
		if disk.Source != nil || disk.Snapshot == "external" {
			return errors.New("external snapshot restore is not supported")
		}
	}
	// Preserve the original inner XML so forbidden devices/extensions are not
	// silently removed by unmarshalling into a restricted domain struct.
	data := []byte("<domain type=" + strconv.Quote(snapshot.Domain.Type) + ">" + snapshot.Domain.Inner + "</domain>")
	return e.verifyXML(g, data)
}

func (e *kvmEngine) createGuest(p map[string]any) (map[string]any, error) {
	id := payloadValue(p, "operation_id")
	label := strings.TrimSpace(payloadValue(p, "label"))
	host := payloadValue(p, "hostname")
	user := payloadValue(p, "username")
	template := payloadValue(p, "template")
	network := payloadValue(p, "network")
	networkType := payloadValue(p, "network_type")
	if label == "" || len(label) > 120 || !kvmSlug.MatchString(host) || !regexp.MustCompile(`^[a-z][a-z0-9_-]{0,30}$`).MatchString(user) || user == "root" || !validKVMIdentifier(network) || (networkType != "bridge" && networkType != "network") {
		return nil, errors.New("invalid VM name, hostname, user or network")
	}
	parts := strings.Split(template, "--")
	if len(parts) != 2 || !kvmSlug.MatchString(parts[0]) || !kvmVersion.MatchString(parts[1]) {
		return nil, errors.New("invalid template version")
	}
	var t kvmTemplate
	if err := kvmReadJSON(filepath.Join(e.root, "templates", template+".json"), &t); err != nil || t.ID != parts[0] || t.Version != parts[1] || !kvmSHA.MatchString(t.SHA256) {
		return nil, errors.New("template version not found")
	}
	cpu, err := positivePayloadInt(p, "vcpus", 1, 256)
	if err != nil {
		return nil, err
	}
	ram, err := positivePayloadInt(p, "memory_mib", 128, 1048576)
	if err != nil {
		return nil, err
	}
	disk, err := positivePayloadInt(p, "disk_gib", 1, 2048)
	if err != nil {
		return nil, err
	}
	if int64(disk)<<30 < t.VirtualSize {
		return nil, errors.New("disk is smaller than template virtual size")
	}
	keys := kvmStringList(p["ssh_keys"])
	if len(keys) < 1 || len(keys) > 8 {
		return nil, errors.New("one to eight SSH public keys required")
	}
	for i, key := range keys {
		pub, _, options, rest, err := ssh.ParseAuthorizedKey([]byte(key))
		if err != nil || len(options) > 0 || len(strings.TrimSpace(string(rest))) > 0 {
			return nil, errors.New("invalid SSH public key; authorized_keys options are not allowed")
		}
		keys[i] = strings.TrimSpace(string(ssh.MarshalAuthorizedKey(pub)))
	}
	if err := e.checkNetwork(networkType, network); err != nil {
		return nil, err
	}
	if !kvmAccelerationAvailable() {
		return nil, errors.New("KVM acceleration /dev/kvm is unavailable on this host")
	}
	base := filepath.Join(e.root, "images", t.SHA256+".qcow2")
	if _, err := e.safeImage(base); err != nil {
		return nil, err
	}
	hash, err := e.fileHash(base)
	if err != nil || hash != t.SHA256 {
		return nil, errors.New("template checksum mismatch")
	}
	// UUID and name collisions must never redefine an unrelated existing domain.
	for _, query := range []struct {
		args     []string
		expected string
	}{{[]string{"list", "--all", "--uuid"}, id}, {[]string{"list", "--all", "--name"}, "easywi-kvm-" + id}} {
		out, err := e.virsh(query.args...)
		if err != nil {
			return nil, err
		}
		for _, existing := range nonEmptyLines(string(out)) {
			if existing == query.expected {
				return nil, errors.New("VM UUID or libvirt name already exists; existing domain retained")
			}
		}
	}
	dir := filepath.Join(e.root, "instances", id)
	if err := os.Mkdir(dir, 0755); err != nil {
		return nil, errors.New("VM storage already exists or cannot be created; inspect existing files")
	}
	// A failed definition retains its data for inspection; never remove storage
	// that may already have become attached to a domain.
	image := e.guestFile(id, "disk.qcow2")
	if _, err := e.command("qemu-img", "convert", "-f", "qcow2", "-O", "qcow2", base, image); err != nil {
		return nil, err
	}
	if _, err := e.command("qemu-img", "resize", "-f", "qcow2", image, fmt.Sprintf("%dG", disk)); err != nil {
		return nil, err
	}
	if err := os.Chmod(image, 0600); err != nil {
		return nil, err
	}
	g := kvmGuest{ID: id, Label: label, Template: template, VCPUs: cpu, MemoryMiB: ram, DiskGiB: disk, Network: network, NetworkType: networkType, Hostname: host, Username: user, CreatedAt: kvmNow()}
	if err := e.cloudInit(g, keys); err != nil {
		return nil, err
	}
	if err := kvmWriteJSON(e.guestFile(id, "manifest.json"), g); err != nil {
		return nil, err
	}
	if err := e.define(g); err != nil {
		return nil, err
	}
	return map[string]any{"vm_id": id}, nil
}

func (e *kvmEngine) cloudInit(g kvmGuest, keys []string) error {
	user := map[string]any{"name": g.Username, "lock_passwd": true, "shell": "/bin/bash", "sudo": []string{"ALL=(ALL) NOPASSWD:ALL"}, "ssh_authorized_keys": keys}
	config := map[string]any{"hostname": g.Hostname, "manage_etc_hosts": true, "disable_root": true, "ssh_pwauth": false, "users": []any{user}, "ssh_deletekeys": true}
	data, err := yaml.Marshal(config)
	if err != nil {
		return err
	}
	meta, _ := json.Marshal(map[string]any{"instance-id": g.ID, "local-hostname": g.Hostname})
	netData := []byte("version: 2\nethernets:\n  primary:\n    match:\n      name: 'e*'\n    dhcp4: true\n    dhcp6: true\n")
	for name, content := range map[string][]byte{"user-data": append([]byte("#cloud-config\n"), data...), "meta-data": meta, "network-config": netData} {
		if err := kvmAtomicWrite(e.guestFile(g.ID, name), content, 0600); err != nil {
			return err
		}
	}
	_, err = e.command("cloud-localds", "--network-config="+e.guestFile(g.ID, "network-config"), e.guestFile(g.ID, "seed.iso"), e.guestFile(g.ID, "user-data"), e.guestFile(g.ID, "meta-data"))
	if err != nil {
		return err
	}
	return os.Chmod(e.guestFile(g.ID, "seed.iso"), 0600)
}

// Stable locally administered address survives redefinition and restore.
func kvmGuestMAC(id string) string {
	sum := sha256.Sum256([]byte(id))
	return fmt.Sprintf("02:%02x:%02x:%02x:%02x:%02x", sum[0], sum[1], sum[2], sum[3], sum[4])
}
