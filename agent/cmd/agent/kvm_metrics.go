package main

import (
	"bufio"
	"bytes"
	"context"
	"encoding/binary"
	"errors"
	"hash/fnv"
	"io"
	"math"
	"os"
	"os/exec"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"
)

type kvmCPUObservation struct {
	counter uint64
	when    time.Time
	io      map[string]uint64
	devices map[string]uint64
}

var kvmUsageState = struct {
	sync.Mutex
	previous map[string]kvmCPUObservation
}{previous: map[string]kvmCPUObservation{}}

// Read only one bounded libvirt snapshot per heartbeat. CPU usage is derived
// from consecutive counters, never from allocated limits or invented samples.
func collectKVMUsage() map[string]any {
	if runtime.GOOS != "linux" || runtime.GOARCH != "amd64" {
		return nil
	}
	if _, err := exec.LookPath("virsh"); err != nil {
		return nil
	}
	uri := os.Getenv("EASYWI_KVM_URI")
	if uri == "" {
		uri = "qemu:///system"
	}
	if uri != "qemu:///system" && uri != "qemu:///session" {
		return nil
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	cmd := exec.CommandContext(ctx, "virsh", "-c", uri, "domstats", "--raw", "--cpu-total", "--vcpu", "--balloon", "--block", "--interface", "--list-active")
	cmd.Env = append(os.Environ(), "LC_ALL=C", "LANG=C")
	output := &kvmUsageBuffer{}
	cmd.Stdout = output
	cmd.Stderr = io.Discard
	if err := cmd.Run(); err != nil || output.full {
		return nil
	}
	now := time.Now()
	kvmUsageState.Lock()
	defer kvmUsageState.Unlock()
	rows, next := kvmUsageSamples(parseKVMUsage(output.Bytes()), kvmUsageState.previous, now)
	kvmUsageState.previous = next // Bound memory to the current set of at most 512 domains.
	return map[string]any{"observed_at": now.UTC().Format(time.RFC3339), "guests": rows}
}

type kvmUsageBuffer struct {
	bytes.Buffer
	full bool
}

func (b *kvmUsageBuffer) Write(p []byte) (int, error) {
	if b.Len()+len(p) > 1<<20 {
		b.full = true
		return 0, errors.New("KVM statistics exceed limit")
	}
	return b.Buffer.Write(p)
}

func parseKVMUsage(data []byte) map[string]map[string]uint64 {
	result := map[string]map[string]uint64{}
	scanner := bufio.NewScanner(bytes.NewReader(data))
	var current map[string]uint64
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if strings.HasPrefix(line, "Domain:") {
			current = nil
			name := strings.Trim(strings.TrimSpace(strings.TrimPrefix(line, "Domain:")), "'\"")
			id := strings.TrimPrefix(name, "easywi-kvm-")
			if id != name && kvmUUID.MatchString(id) && len(result) < 512 {
				current = map[string]uint64{}
				result[id] = current
			}
			continue
		}
		if current == nil || len(current) >= 2048 {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		if (strings.HasPrefix(key, "net.") || strings.HasPrefix(key, "block.")) && strings.HasSuffix(key, ".name") && len(value) > 0 && len(value) <= 256 {
			h := fnv.New64a()
			_, _ = h.Write([]byte(strings.TrimSpace(value)))
			current[key+".fingerprint"] = h.Sum64()
			continue
		}
		if n, err := strconv.ParseUint(strings.TrimSpace(value), 10, 64); err == nil {
			current[strings.TrimSpace(key)] = n
		}
	}
	return result
}

func kvmUsageSamples(rows map[string]map[string]uint64, previous map[string]kvmCPUObservation, now time.Time) (map[string]map[string]any, map[string]kvmCPUObservation) {
	result := map[string]map[string]any{}
	next := map[string]kvmCPUObservation{}
	for id, fields := range rows {
		m := map[string]any{}
		counter, present := fields["cpu.time"]
		if present {
			next[id] = kvmCPUObservation{counter: counter, when: now}
			prior, ok := previous[id]
			elapsed := now.Sub(prior.when)
			cpus := fields["vcpu.current"]
			if ok && counter >= prior.counter && elapsed > 0 && elapsed <= 2*time.Minute && cpus > 0 {
				percent := float64(counter-prior.counter) / float64(elapsed.Nanoseconds()) / float64(cpus) * 100
				if !math.IsNaN(percent) && !math.IsInf(percent, 0) {
					m["cpu_percent"] = math.Min(100, percent)
				}
			}
		}
		if total, ok := fields["balloon.available"]; ok && total <= 1<<50 {
			m["memory_total_bytes"] = total * 1024
			if unused, ok := fields["balloon.unused"]; ok && unused <= total {
				m["memory_used_bytes"] = (total - unused) * 1024
				m["memory_source"] = "guest_balloon"
			}
		}
		if _, measured := m["memory_used_bytes"]; !measured {
			if rss, ok := fields["balloon.rss"]; ok && rss <= 1<<50 {
				m["memory_used_bytes"] = rss * 1024
				m["memory_source"] = "host_rss"
				if allocated, ok := fields["balloon.current"]; ok && allocated <= 1<<50 {
					m["memory_total_bytes"] = allocated * 1024
				}
			}
		}
		currentIO := map[string]uint64{}
		devices := map[string]uint64{}
		for field, output := range map[string]string{"block.rd.bytes": "disk_read", "block.wr.bytes": "disk_write", "net.rx.bytes": "net_rx", "net.tx.bytes": "net_tx"} {
			prefix, suffix, _ := strings.Cut(field, ".")
			total, complete, signature, named := kvmIOCounter(fields, prefix, suffix)
			if !complete {
				continue
			}
			m[output+"_bytes"] = total
			if named {
				currentIO[output] = total
				devices[prefix] = signature
			}
		}
		if present {
			prior, ok := previous[id]
			elapsed := now.Sub(prior.when)
			if ok && counter >= prior.counter && elapsed > 0 && elapsed <= 2*time.Minute {
				for key, total := range currentIO {
					prefix := "net"
					if strings.HasPrefix(key, "disk_") {
						prefix = "block"
					}
					before, hadCounter := prior.io[key]
					beforeDevices, hadDevices := prior.devices[prefix]
					if !hadCounter || !hadDevices || beforeDevices != devices[prefix] || total < before {
						continue
					}
					rate := float64(total-before) / elapsed.Seconds()
					if !math.IsNaN(rate) && !math.IsInf(rate, 0) {
						m[key+"_bytes_per_second"] = rate
						m["io_observed_at"] = now.UTC().Format(time.RFC3339)
						m["io_interval_seconds"] = elapsed.Seconds()
						m["io_source"] = "counter_delta"
					}
				}
			}
			next[id] = kvmCPUObservation{counter: counter, when: now, io: currentIO, devices: devices}
		}

		result[id] = m
	}
	return result, next
}

// An aggregate is unknown if any constituent counter is missing. Rates also
// require device names, so replacing/reordering an equally sized device set
// cannot turn unrelated totals into a plausible throughput.
func kvmIOCounter(fields map[string]uint64, prefix, suffix string) (uint64, bool, uint64, bool) {
	count, present := fields[prefix+".count"]
	if !present || count > 256 {
		return 0, false, 0, false
	}
	h := fnv.New64a()
	var buf [8]byte
	binary.LittleEndian.PutUint64(buf[:], count)
	_, _ = h.Write(buf[:])
	total := uint64(0)
	named := true
	for i := uint64(0); i < count; i++ {
		base := prefix + "." + strconv.FormatUint(i, 10)
		value, ok := fields[base+"."+suffix]
		if !ok || value > math.MaxUint64-total {
			return 0, false, 0, false
		}
		total += value
		fingerprint, ok := fields[base+".name.fingerprint"]
		if !ok {
			named = false
		}
		binary.LittleEndian.PutUint64(buf[:], fingerprint)
		_, _ = h.Write(buf[:])
	}
	return total, true, h.Sum64(), named
}
