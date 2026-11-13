package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"encoding/xml"
	"errors"
	"fmt"
	"net"
	"net/netip"
	"os"
	"path/filepath"
	"strconv"
	"strings"
)

type kvmNetwork struct {
	XMLName xml.Name `xml:"network"`
	Name    string   `xml:"name"`
	UUID    string   `xml:"uuid"`
	Bridge  struct {
		Name  string `xml:"name,attr"`
		STP   string `xml:"stp,attr,omitempty"`
		Delay string `xml:"delay,attr,omitempty"`
	} `xml:"bridge"`
	Forward *struct {
		Mode string `xml:"mode,attr"`
	} `xml:"forward"`
	IPs []kvmNetworkIP `xml:"ip"`
}
type kvmNetworkIP struct {
	Address string   `xml:"address,attr"`
	Netmask string   `xml:"netmask,attr,omitempty"`
	Prefix  string   `xml:"prefix,attr,omitempty"`
	DHCP    *kvmDHCP `xml:"dhcp"`
}
type kvmDHCP struct {
	Range struct {
		Start string `xml:"start,attr"`
		End   string `xml:"end,attr"`
	} `xml:"range"`
}

func (e *kvmEngine) checkNetwork(kind, name string) error {
	if kind == "bridge" {
		allowed := false
		for _, b := range strings.Split(os.Getenv("EASYWI_KVM_BRIDGES"), ",") {
			if strings.TrimSpace(b) == name {
				allowed = true
			}
		}
		if !allowed {
			return errors.New("bridge is not approved in EASYWI_KVM_BRIDGES")
		}
		if _, err := os.Stat(filepath.Join("/sys/class/net", name, "bridge")); err != nil {
			return errors.New("approved bridge does not exist")
		}
		return nil
	}
	if kind != "network" || !validKVMIdentifier(name) {
		return errors.New("invalid libvirt network")
	}
	out, err := e.virsh("net-info", name)
	if err != nil {
		return err
	}
	if strings.ToLower(parseVirshKeyValues(string(out))["Active"]) != "yes" {
		return errors.New("libvirt network is not active")
	}
	return nil
}
func (e *kvmEngine) networkAction(action string, p map[string]any) (map[string]any, error) {
	name := payloadValue(p, "network")
	if !strings.HasPrefix(name, "easywi-") || !kvmSlug.MatchString(name) {
		return nil, errors.New("only EasyWI-managed networks can be changed")
	}
	var stored kvmNetwork
	if err := kvmReadJSON(filepath.Join(e.root, "networks", name+".json"), &stored); err != nil || stored.Name != name {
		return nil, errors.New("managed network manifest missing")
	}
	raw, err := e.virsh("net-dumpxml", name)
	if err != nil {
		return nil, err
	}
	var actual kvmNetwork
	if xml.Unmarshal(raw, &actual) != nil || actual.UUID != stored.UUID || actual.Name != stored.Name || actual.Bridge.Name != stored.Bridge.Name {
		return nil, errors.New("network identity mismatch")
	}
	if action == "network.stop" {
		guests, err := e.virsh("list", "--all", "--uuid")
		if err != nil {
			return nil, err
		}
		for _, id := range nonEmptyLines(string(guests)) {
			for _, args := range [][]string{{"dumpxml", id}, {"dumpxml", id, "--inactive"}} {
				out, err := e.virsh(args...)
				if err != nil {
					return nil, err
				}
				var d kvmDomain
				if xml.Unmarshal(out, &d) != nil {
					return nil, errors.New("cannot inspect attached VM network")
				}
				for _, nic := range d.Devices.Interfaces {
					if nic.Source.Network == name || nic.Source.Bridge == stored.Bridge.Name {
						return nil, errors.New("network is still assigned to a VM")
					}
				}
			}
		}
	}
	verb := map[string]string{"network.start": "net-start", "network.stop": "net-destroy", "network.autostart": "net-autostart", "network.autostart-disable": "net-autostart"}[action]
	if verb == "" {
		return nil, errors.New("invalid network action")
	}
	args := []string{verb, name}
	if action == "network.autostart-disable" {
		args = append(args, "--disable")
	}
	_, err = e.virsh(args...)
	return nil, err
}
func (e *kvmEngine) createNetwork(p map[string]any) (map[string]any, error) {
	name := payloadValue(p, "network")
	mode := payloadValue(p, "mode")
	prefix, err := netip.ParsePrefix(payloadValue(p, "cidr"))
	if err != nil || !prefix.Addr().Is4() || prefix.Bits() < 24 || prefix.Bits() > 28 || !prefix.Addr().IsPrivate() || prefix != prefix.Masked() {
		return nil, errors.New("network requires an aligned private IPv4 /24 to /28 subnet")
	}
	if !kvmSlug.MatchString(name) || !strings.HasPrefix(name, "easywi-") || len(name) > 40 || (mode != "nat" && mode != "isolated") {
		return nil, errors.New("network name must start with easywi-; mode must be nat or isolated")
	}
	if err := e.ensureNoNetworkOverlap(prefix); err != nil {
		return nil, err
	}
	out, err := e.virsh("net-list", "--all", "--name")
	if err != nil {
		return nil, err
	}
	for _, n := range nonEmptyLines(string(out)) {
		if n == name {
			return nil, errors.New("network already exists")
		}
	}
	var n kvmNetwork
	n.Name = name
	n.UUID = payloadValue(p, "operation_id")
	hash := sha256.Sum256([]byte(name))
	n.Bridge.Name = "ewk" + hex.EncodeToString(hash[:5])
	n.Bridge.STP = "on"
	n.Bridge.Delay = "0"
	if mode == "nat" {
		n.Forward = &struct {
			Mode string `xml:"mode,attr"`
		}{"nat"}
	}
	gateway := prefix.Addr().Next()
	start := gateway.Next()
	last := prefix.Addr().As4()
	count := 1 << (32 - prefix.Bits())
	last[3] += byte(count - 2)
	n.IPs = append(n.IPs, kvmNetworkIP{Address: gateway.String(), Prefix: strconv.Itoa(prefix.Bits()), DHCP: &kvmDHCP{}})
	n.IPs[0].DHCP.Range.Start = start.String()
	n.IPs[0].DHCP.Range.End = netip.AddrFrom4(last).String()
	if _, err := net.InterfaceByName(n.Bridge.Name); err == nil {
		return nil, errors.New("generated network bridge already exists")
	}
	data, _ := xml.MarshalIndent(n, "", "  ")
	path := filepath.Join(e.root, "networks", name+".xml")
	if _, err := os.Lstat(path); !os.IsNotExist(err) {
		return nil, errors.New("network definition path already exists")
	}
	if err := kvmAtomicWrite(path, data, 0600); err != nil {
		return nil, err
	}
	if err := kvmWriteJSON(filepath.Join(e.root, "networks", name+".json"), n); err != nil {
		return nil, err
	}
	if _, err := e.virsh("net-define", path); err != nil {
		return nil, err
	}
	if _, err := e.virsh("net-start", name); err != nil {
		return nil, err
	}
	if _, err := e.virsh("net-autostart", name); err != nil {
		return nil, err
	}
	return map[string]any{"network": name}, nil
}
func (e *kvmEngine) ensureNoNetworkOverlap(candidate netip.Prefix) error {
	check := func(s string) error {
		p, err := netip.ParsePrefix(s)
		if err == nil && p.Bits() != 0 && p.Overlaps(candidate) {
			return errors.New("subnet overlaps a host route, interface or libvirt network")
		}
		return nil
	}
	interfaces, err := net.Interfaces()
	if err != nil {
		return err
	}
	for _, i := range interfaces {
		addresses, err := i.Addrs()
		if err != nil {
			return err
		}
		for _, a := range addresses {
			if err := check(a.String()); err != nil {
				return err
			}
		}
	}
	routes, err := os.ReadFile("/proc/net/route")
	if err != nil {
		return err
	}
	for _, line := range nonEmptyLines(string(routes)) {
		f := strings.Fields(line)
		if len(f) < 8 {
			continue
		}
		dst, e1 := strconv.ParseUint(f[1], 16, 32)
		mask, e2 := strconv.ParseUint(f[7], 16, 32)
		if e1 != nil || e2 != nil || mask == 0 {
			continue
		}
		m := net.IPMask{byte(mask), byte(mask >> 8), byte(mask >> 16), byte(mask >> 24)}
		ones, bits := m.Size()
		if bits != 32 {
			return errors.New("unrecognized host route mask")
		}
		ip := netip.AddrFrom4([4]byte{byte(dst), byte(dst >> 8), byte(dst >> 16), byte(dst >> 24)})
		if err := check(fmt.Sprintf("%s/%d", ip, ones)); err != nil {
			return err
		}
	}
	names, err := e.virsh("net-list", "--all", "--name")
	if err != nil {
		return err
	}
	for _, name := range nonEmptyLines(string(names)) {
		out, err := e.virsh("net-dumpxml", name)
		if err != nil {
			return err
		}
		var n kvmNetwork
		if xml.Unmarshal(out, &n) != nil {
			return errors.New("invalid existing libvirt network XML")
		}
		for _, ip := range n.IPs {
			bits := ip.Prefix
			if bits == "" {
				ones, total := net.IPMask(net.ParseIP(ip.Netmask).To4()).Size()
				if total != 32 {
					continue
				}
				bits = strconv.Itoa(ones)
			}
			if err := check(ip.Address + "/" + bits); err != nil {
				return err
			}
		}
	}
	return nil
}

func (e *kvmEngine) inventory() (map[string]any, error) {
	out, err := e.virsh("list", "--all", "--uuid")
	if err != nil {
		return nil, err
	}
	ids := nonEmptyLines(string(out))
	if len(ids) > 512 {
		return nil, errors.New("KVM inventory exceeds 512 domains")
	}
	guests := []map[string]any{}
	seen := map[string]bool{}
	for _, id := range ids {
		if !regexpUUIDLoose(id) {
			continue
		}
		seen[id] = true
		v := map[string]any{"id": id, "managed": false, "state": "unknown", "snapshots": []string{}, "backups": []kvmBackup{}}
		if info, err := e.virsh("dominfo", id); err == nil {
			v["details"] = parseVirshKeyValues(string(info))
			v["label"] = parseVirshKeyValues(string(info))["Name"]
		} else {
			v["error"] = "VM details unavailable"
		}
		if state, err := e.virsh("domstate", id); err == nil {
			v["state"] = strings.TrimSpace(string(state))
		}
		var g kvmGuest
		if kvmUUID.MatchString(id) && kvmReadJSON(e.guestFile(id, "manifest.json"), &g) == nil && g.ID == id {
			data, _ := json.Marshal(g)
			_ = json.Unmarshal(data, &v)
			v["managed"] = true
			if cloud, err := e.cloudInitView(g); err == nil {
				v["cloud_init"] = cloud
			}
			var definition kvmDomain
			if raw, err := e.virsh("dumpxml", id, "--inactive"); err == nil && xml.Unmarshal(raw, &definition) == nil {
				v["console_enabled"] = e.consoleConfigured(g, definition)
			}
			if err := e.verifyGuest(g); err != nil {
				v["error"] = err.Error()
			} else {
				if snaps, err := e.virsh("snapshot-list", id, "--name"); err == nil {
					v["snapshots"] = nonEmptyLines(string(snaps))
				} else {
					v["error"] = "Snapshot inventory unavailable"
				}
			}
			backups := []kvmBackup{}
			entries, _ := os.ReadDir(filepath.Join(e.root, "backups", id))
			for _, entry := range entries {
				if !entry.IsDir() || !kvmUUID.MatchString(entry.Name()) {
					continue
				}
				if b, err := e.readBackup(id, entry.Name()); err == nil {
					backups = append(backups, b)
				}
			}
			v["backups"] = backups
		}
		guests = append(guests, v)
	}
	entries, _ := os.ReadDir(filepath.Join(e.root, "instances"))
	for _, entry := range entries {
		if !kvmUUID.MatchString(entry.Name()) || seen[entry.Name()] {
			continue
		}
		var g kvmGuest
		if kvmReadJSON(e.guestFile(entry.Name(), "manifest.json"), &g) != nil {
			continue
		}
		data, _ := json.Marshal(g)
		var v map[string]any
		_ = json.Unmarshal(data, &v)
		v["managed"] = true
		v["state"] = "definition-missing"
		v["error"] = "VM definition missing; operator inspection required"
		v["snapshots"] = []string{}
		v["backups"] = []kvmBackup{}
		guests = append(guests, v)
	}
	templates := []kvmTemplate{}
	entries, err = os.ReadDir(filepath.Join(e.root, "templates"))
	if err != nil {
		return nil, err
	}
	for _, entry := range entries {
		if !strings.HasSuffix(entry.Name(), ".json") {
			continue
		}
		var t kvmTemplate
		if err := kvmReadJSON(filepath.Join(e.root, "templates", entry.Name()), &t); err == nil {
			templates = append(templates, t)
		}
	}
	networks := []map[string]any{}
	names, err := e.virsh("net-list", "--all", "--name")
	if err != nil {
		return nil, err
	}
	for _, name := range nonEmptyLines(string(names)) {
		v := map[string]any{"name": name, "managed": false}
		if info, err := e.virsh("net-info", name); err == nil {
			v["details"] = parseVirshKeyValues(string(info))
		}
		var n kvmNetwork
		if kvmReadJSON(filepath.Join(e.root, "networks", name+".json"), &n) == nil && n.Name == name {
			v["managed"] = true
			v["bridge"] = n.Bridge.Name
		}
		networks = append(networks, v)
	}
	bridges := []string{}
	for _, b := range strings.Split(os.Getenv("EASYWI_KVM_BRIDGES"), ",") {
		b = strings.TrimSpace(b)
		if validKVMIdentifier(b) {
			if _, err := os.Stat(filepath.Join("/sys/class/net", b, "bridge")); err == nil {
				bridges = append(bridges, b)
			}
		}
	}
	pending, err := e.pendingOperations()
	if err != nil {
		return nil, err
	}
	return map[string]any{"pending_operations": pending, "guests": guests, "templates": templates, "networks": networks, "bridges": bridges, "capabilities": kvmCapabilities(), "observed_at": kvmNow()}, nil
}
func regexpUUIDLoose(s string) bool {
	if len(s) != 36 {
		return false
	}
	for i, c := range s {
		if i == 8 || i == 13 || i == 18 || i == 23 {
			if c != '-' {
				return false
			}
		} else if !strings.ContainsRune("0123456789abcdef", c) {
			return false
		}
	}
	return true
}
