package main

import (
	"strings"
	"testing"
	"time"
)

func TestKVMUsageComputesCPUFromCounterDeltaAndPreservesUnknownMemory(t *testing.T) {
	id := "11111111-1111-4111-8111-111111111111"
	now := time.Now()
	data := []byte("Domain: 'easywi-kvm-" + id + "'\n  cpu.time=1500000000\n  vcpu.current=2\n  balloon.available=2097152\n  balloon.unused=1048576\n  block.count=1\n  block.0.rd.bytes=4096\n  net.count=2\n  net.0.rx.bytes=100\n  net.1.rx.bytes=200\nDomain: 'external-host-secret'\n  cpu.time=123\n")
	parsed := parseKVMUsage(data)
	if len(parsed) != 1 {
		t.Fatal("external domain was included")
	}
	first, previous := kvmUsageSamples(parsed, nil, now)
	if _, ok := first[id]["cpu_percent"]; ok {
		t.Fatal("first sample fabricated a CPU percentage")
	}
	parsed[id]["cpu.time"] = 2500000000
	samples, _ := kvmUsageSamples(parsed, previous, now.Add(time.Second))
	if samples[id]["cpu_percent"] != float64(50) || samples[id]["memory_used_bytes"] != uint64(1073741824) || samples[id]["net_rx_bytes"] != uint64(300) || samples[id]["disk_read_bytes"] != uint64(4096) {
		t.Fatalf("incorrect usage: %#v", samples[id])
	}
	delete(parsed[id], "balloon.unused")
	parsed[id]["cpu.time"] = 1 // Restarted domain: counter reset.
	samples, _ = kvmUsageSamples(parsed, previous, now.Add(time.Second))
	if _, ok := samples[id]["memory_used_bytes"]; ok {
		t.Fatal("missing balloon data fabricated memory usage")
	}
	if _, ok := samples[id]["cpu_percent"]; ok {
		t.Fatal("counter reset fabricated CPU usage")
	}
	samples, next := kvmUsageSamples(map[string]map[string]uint64{}, previous, now)
	if len(samples) != 0 || len(next) != 0 {
		t.Fatal("inactive domain cache was retained")
	}
}

func TestKVMUsageBoundsCommandOutputAndRejectsInvalidNumericValues(t *testing.T) {
	b := &kvmUsageBuffer{}
	if _, err := b.Write([]byte(strings.Repeat("x", 1<<20+1))); err == nil || !b.full || b.Len() > 1<<20 {
		t.Fatal("output was not bounded")
	}
	id := "11111111-1111-4111-8111-111111111111"
	parsed := parseKVMUsage([]byte("Domain: 'easywi-kvm-" + id + "'\n cpu.time=-1\n balloon.available=NaN\n"))
	if len(parsed[id]) != 0 {
		t.Fatal("invalid or negative counters accepted")
	}
}

func TestKVMUsageLabelsHostRSSWhenGuestBalloonDataIsUnavailable(t *testing.T) {
	id := "11111111-1111-4111-8111-111111111111"
	rows := map[string]map[string]uint64{id: {"balloon.current": 2097152, "balloon.rss": 1048576}}
	samples, _ := kvmUsageSamples(rows, nil, time.Now())
	if samples[id]["memory_used_bytes"] != uint64(1073741824) || samples[id]["memory_total_bytes"] != uint64(2147483648) || samples[id]["memory_source"] != "host_rss" {
		t.Fatalf("host RSS fallback was not labelled accurately: %#v", samples[id])
	}
}

func kvmRateRows(id string, cpu, read, rx uint64) map[string]map[string]uint64 {
	return map[string]map[string]uint64{id: {"cpu.time": cpu, "vcpu.current": 2, "block.count": 1, "block.0.name.fingerprint": 100, "block.0.rd.bytes": read, "block.0.wr.bytes": read / 2, "net.count": 1, "net.0.name.fingerprint": 200, "net.0.rx.bytes": rx, "net.0.tx.bytes": rx / 2}}
}
func TestKVMUsageRatesUseCompleteCounterDeltasAndPreserveZero(t *testing.T) {
	id := kvmTestVM
	now := time.Now()
	rows := kvmRateRows(id, 1000, 4096, 2048)
	_, previous := kvmUsageSamples(rows, nil, now)
	samples, next := kvmUsageSamples(kvmRateRows(id, 2000, 8192, 4096), previous, now.Add(2*time.Second))
	if samples[id]["disk_read_bytes_per_second"] != float64(2048) || samples[id]["net_rx_bytes_per_second"] != float64(1024) || samples[id]["io_interval_seconds"] != float64(2) {
		t.Fatalf("incorrect rates: %#v", samples[id])
	}
	steady, _ := kvmUsageSamples(kvmRateRows(id, 3000, 8192, 4096), next, now.Add(4*time.Second))
	if steady[id]["net_rx_bytes_per_second"] != float64(0) {
		t.Fatal("zero throughput was lost")
	}
}
func TestKVMUsageResetsAndDeviceChangesCannotFabricateRates(t *testing.T) {
	for _, scenario := range []string{"cpu-reset", "counter-reset", "device-replaced", "missing-counter", "missing-name", "long-gap", "backwards-time", "overflow"} {
		t.Run(scenario, func(t *testing.T) {
			now := time.Now()
			_, previous := kvmUsageSamples(kvmRateRows(kvmTestVM, 1000, 4096, 2048), nil, now)
			rows := kvmRateRows(kvmTestVM, 2000, 8192, 4096)
			at := now.Add(time.Second)
			switch scenario {
			case "cpu-reset":
				rows[kvmTestVM]["cpu.time"] = 1
			case "counter-reset":
				rows[kvmTestVM]["net.0.rx.bytes"] = 1
			case "device-replaced":
				rows[kvmTestVM]["net.0.name.fingerprint"] = 999
			case "missing-counter":
				rows[kvmTestVM]["net.count"] = 2
				rows[kvmTestVM]["net.1.name.fingerprint"] = 300
			case "missing-name":
				delete(rows[kvmTestVM], "net.0.name.fingerprint")
			case "long-gap":
				at = now.Add(3 * time.Minute)
			case "backwards-time":
				at = now.Add(-time.Second)
			case "overflow":
				rows[kvmTestVM]["net.count"] = 2
				rows[kvmTestVM]["net.0.rx.bytes"] = ^uint64(0)
				rows[kvmTestVM]["net.1.rx.bytes"] = 1
				rows[kvmTestVM]["net.1.name.fingerprint"] = 300
			}
			result, _ := kvmUsageSamples(rows, previous, at)
			if _, ok := result[kvmTestVM]["net_rx_bytes_per_second"]; ok {
				t.Fatalf("fabricated rate: %#v", result[kvmTestVM])
			}
			if scenario == "missing-counter" || scenario == "overflow" {
				if _, ok := result[kvmTestVM]["net_rx_bytes"]; ok {
					t.Fatal("partial/overflowing aggregate returned")
				}
			}
		})
	}
}
func TestKVMUsageParserFingerprintsDeviceNamesWithoutExposingThem(t *testing.T) {
	text := "Domain: 'easywi-kvm-" + kvmTestVM + "'\n cpu.time=1\n net.count=1\n net.0.name=vnet0\n net.0.rx.bytes=10\n"
	rows := parseKVMUsage([]byte(text))
	_, previous := kvmUsageSamples(rows, nil, time.Now())
	if rows[kvmTestVM]["net.0.name.fingerprint"] == 0 {
		t.Fatal("device identity not observed")
	}
	next := parseKVMUsage([]byte(strings.ReplaceAll(text, "vnet0", "vnet1")))
	if next[kvmTestVM]["net.0.name.fingerprint"] == rows[kvmTestVM]["net.0.name.fingerprint"] {
		t.Fatal("device replacement not detected")
	}
	if len(previous) != 1 {
		t.Fatal("invalid baseline")
	}
}
