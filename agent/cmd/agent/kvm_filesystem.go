package main

import (
	"context"
	"os"
	"regexp"
	"sort"
	"strings"
	"sync"
	"time"
)

const kvmFilesystemMaxBytes = float64(9007199254740991)

var kvmMemoryDevice = regexp.MustCompile(`^(?:/dev/)?(?:zram|ram)[0-9]+$`)

func kvmMemoryBackedFilesystem(row map[string]any) bool {
	if name, ok := row["name"].(string); ok && kvmMemoryDevice.MatchString(name) {
		return true
	}
	if disks, ok := row["disk"].([]any); ok && len(disks) <= 128 {
		for _, raw := range disks {
			if disk, ok := raw.(map[string]any); ok {
				if dev, ok := disk["dev"].(string); ok && kvmMemoryDevice.MatchString(dev) {
					return true
				}
			}
		}
	}
	return false
}

func kvmFilesystemUnavailable() map[string]any {
	return map[string]any{"state": "unavailable", "used_bytes": nil, "total_bytes": nil, "filesystems": 0, "partial": false}
}

// No paths, device identifiers or raw guest errors leave this boundary.
func kvmFilesystemUsage(raw any) map[string]any {
	rows, ok := raw.([]any)
	if !ok || len(rows) > 128 {
		return kvmFilesystemUnavailable()
	}
	seen := map[string][2]float64{}
	used, total, partial := float64(0), float64(0), false
	for _, raw := range rows {
		row, ok := raw.(map[string]any)
		if !ok {
			partial = true
			continue
		}
		kind, _ := row["type"].(string)
		kind = strings.ToLower(kind)
		if kvmMemoryBackedFilesystem(row) {
			continue
		}
		switch kind {
		case "ext2", "ext3", "ext4", "erofs", "xfs", "btrfs", "f2fs", "jfs", "reiserfs", "ufs", "ntfs", "refs", "fat", "fat16", "fat32", "vfat", "exfat":
		case "tmpfs", "devtmpfs", "proc", "sysfs", "overlay", "squashfs", "iso9660", "udf", "cdfs", "nfs", "nfs4", "cifs", "smbfs", "9p", "virtiofs":
			continue
		default:
			if disks, ok := row["disk"].([]any); ok && len(disks) > 0 {
				partial = true
			}
			continue
		}
		name, mount := kvmAgentText(row["name"], 512), kvmAgentText(row["mountpoint"], 512)
		size, sizeOK := kvmFilesystemBytes(row["total-bytes"])
		taken, usedOK := kvmFilesystemBytes(row["used-bytes"])
		if name == "" || mount == "" || !sizeOK || size <= 0 || !usedOK || taken > size {
			partial = true
			continue
		}
		key := kind + ":" + name
		value := [2]float64{taken, size}
		if before, exists := seen[key]; exists {
			if before != value {
				return kvmFilesystemUnavailable()
			}
			continue
		}
		seen[key] = value
		used += taken
		total += size
	}
	if len(seen) == 0 || total > kvmFilesystemMaxBytes {
		return kvmFilesystemUnavailable()
	}
	return map[string]any{"state": "available", "used_bytes": used, "total_bytes": total, "filesystems": len(seen), "partial": partial}
}

func kvmFilesystemBytes(raw any) (float64, bool) {
	n, ok := raw.(float64) // QGA JSON is decoded into finite, exactly bounded numbers.
	return n, ok && n >= 0 && n <= kvmFilesystemMaxBytes && n == float64(uint64(n))
}

type kvmFilesystemObservation struct {
	when    time.Time
	cpu     uint64
	reading map[string]any
}

type kvmFilesystemCache struct {
	sync.Mutex
	entries map[string]kvmFilesystemObservation
}

var kvmFilesystems = &kvmFilesystemCache{entries: map[string]kvmFilesystemObservation{}}

// At most 16 due VMs, four workers, a two-second batch budget and a 30-second
// per-VM cooldown. Failed/uninstalled agents cannot serially delay a heartbeat.
// Entries are pruned to the current bounded active-domain snapshot.
func (c *kvmFilesystemCache) collect(rows map[string]map[string]uint64, now time.Time, read func(context.Context, string) map[string]any) map[string]map[string]any {
	c.Lock()
	defer c.Unlock()
	for id, old := range c.entries {
		current, active := rows[id]
		if !active || current["cpu.time"] < old.cpu {
			delete(c.entries, id)
		}
	}
	due := []string{}
	for id, fields := range rows {
		if !kvmUUID.MatchString(id) {
			continue
		}
		old := c.entries[id]
		if old.when.IsZero() || now.Sub(old.when) >= 30*time.Second || now.Before(old.when) {
			due = append(due, id)
		}
		if !old.when.IsZero() {
			old.cpu = fields["cpu.time"]
			c.entries[id] = old
		}
	}
	sort.Slice(due, func(i, j int) bool {
		a, b := c.entries[due[i]].when, c.entries[due[j]].when
		if a.Equal(b) {
			return due[i] < due[j]
		}
		return a.Before(b)
	})
	if len(due) > 16 {
		due = due[:16]
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	readings := make([]map[string]any, len(due))
	var wg sync.WaitGroup
	for worker := 0; worker < 4 && worker < len(due); worker++ {
		wg.Add(1)
		go func(worker int) {
			defer wg.Done()
			for i := worker; i < len(due); i += 4 {
				if ctx.Err() != nil {
					return
				}
				readings[i] = read(ctx, due[i])
			}
		}(worker)
	}
	wg.Wait()
	for i, id := range due {
		if readings[i] != nil {
			readings[i]["observed_at"] = now.UTC().Format(time.RFC3339)
			c.entries[id] = kvmFilesystemObservation{when: now, cpu: rows[id]["cpu.time"], reading: readings[i]}
		}
	}
	result := map[string]map[string]any{}
	for id, entry := range c.entries {
		if now.Sub(entry.when) < 2*time.Minute && !now.Before(entry.when) {
			result[id] = entry.reading
		}
	}
	return result
}

func readKVMFilesystem(ctx context.Context, id string) map[string]any {
	root := os.Getenv("EASYWI_KVM_ROOT")
	if root == "" {
		root = "/var/lib/easywi/kvm"
	}
	if _, err := os.Stat(root); err != nil {
		return kvmFilesystemUnavailable()
	}
	e, err := newKVMEngine(ctx)
	if err != nil {
		return kvmFilesystemUnavailable()
	}
	var guest kvmGuest
	if !kvmUUID.MatchString(id) || kvmReadJSON(e.guestFile(id, "manifest.json"), &guest) != nil || guest.ID != id || !guest.GuestAgentEnabled {
		return kvmFilesystemUnavailable()
	}
	uri := os.Getenv("EASYWI_KVM_URI")
	if uri == "" {
		uri = "qemu:///system"
	}
	if uri != "qemu:///system" && uri != "qemu:///session" {
		return kvmFilesystemUnavailable()
	}
	// Bound XML output and pipe cleanup as well as the QGA response.
	xml, err := kvmGuestAgentRun(ctx, "--connect", uri, "dumpxml", id)
	if err != nil || e.verifyXML(guest, xml) != nil {
		return kvmFilesystemUnavailable()
	}
	raw, err := e.readGuestAgent(id, "guest-get-fsinfo")
	if err != nil {
		return kvmFilesystemUnavailable()
	}
	return kvmFilesystemUsage(raw)
}
