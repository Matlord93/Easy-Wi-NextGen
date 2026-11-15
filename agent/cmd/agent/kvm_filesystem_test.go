package main

import (
	"context"
	_ "embed"
	"encoding/json"
	"fmt"
	"reflect"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

//go:embed testdata/guest-filesystem-erofs-zram.json
var kvmErofsFilesystemFixture []byte

func TestKVMFilesystemIncludesErofsAndExcludesExt4Zram(t *testing.T) {
	var rows any
	if err := json.Unmarshal(kvmErofsFilesystemFixture, &rows); err != nil {
		t.Fatal(err)
	}
	r := kvmFilesystemUsage(rows)
	if r["state"] != "available" || r["partial"] != false || r["filesystems"] != 4 || r["used_bytes"] != float64(17901221888) || r["total_bytes"] != float64(129377415168) {
		t.Fatalf("incorrect filesystem classification: %#v", r)
	}
}

func TestKVMFilesystemRamAliasesAndPersistentTmp(t *testing.T) {
	for _, device := range []string{"zram0", "/dev/zram12", "ram0", "/dev/ram12"} {
		for _, byDisk := range []bool{false, true} {
			persistent := map[string]any{"name": "sda1", "mountpoint": "/tmp", "type": "ext4", "total-bytes": float64(1000), "used-bytes": float64(250)}
			ram := map[string]any{"name": device, "mountpoint": "/elsewhere", "type": "ext4", "total-bytes": float64(2000), "used-bytes": float64(500)}
			if byDisk {
				ram["name"] = "alias"
				ram["disk"] = []any{map[string]any{"dev": device}}
			}
			r := kvmFilesystemUsage([]any{persistent, ram})
			if r["partial"] != false || r["filesystems"] != 1 || r["used_bytes"] != float64(250) || r["total_bytes"] != float64(1000) {
				t.Fatalf("device classification %s: %#v", device, r)
			}
		}
	}
}

func TestKVMFilesystemLinuxWindowsAndInvalidReadings(t *testing.T) {
	for _, tc := range []struct {
		name, json  string
		state       string
		used, total float64
		count       int
		partial     bool
	}{
		{"linux-bind", `[{"name":"sda1","mountpoint":"/","type":"ext4","total-bytes":1000,"used-bytes":250},{"name":"sda1","mountpoint":"/bind","type":"ext4","total-bytes":1000,"used-bytes":250},{"name":"sdb1","mountpoint":"/data","type":"xfs","total-bytes":2000,"used-bytes":1000},{"name":"ram","mountpoint":"/run","type":"tmpfs","total-bytes":9000,"used-bytes":1000},{"name":"cd","type":"iso9660","disk":[{}]}]`, "available", 1250, 3000, 2, false},
		{"windows", `[{"name":"volume-c","mountpoint":"C:\\","type":"NTFS","total-bytes":1000,"used-bytes":200},{"name":"volume-d","mountpoint":"D:\\","type":"ReFS","total-bytes":3000,"used-bytes":0}]`, "available", 200, 4000, 2, false},
		{"partial", `[{"name":"root","mountpoint":"/","type":"ext4","total-bytes":1000,"used-bytes":0},{"name":"bad","mountpoint":"/data","type":"ext4","total-bytes":1000,"used-bytes":1001}]`, "available", 0, 1000, 1, true},
		{"strings", `[{"name":"root","mountpoint":"/","type":"ext4","total-bytes":1000,"used-bytes":"0"}]`, "unavailable", 0, 0, 0, false},
		{"network-only", `[{"name":"server:/data","mountpoint":"/data","type":"nfs4","total-bytes":1000,"used-bytes":1}]`, "unavailable", 0, 0, 0, false},
		{"inconsistent-duplicate", `[{"name":"root","mountpoint":"/","type":"ext4","total-bytes":1000,"used-bytes":0},{"name":"root","mountpoint":"/bind","type":"ext4","total-bytes":1000,"used-bytes":1}]`, "unavailable", 0, 0, 0, false},
		{"invalid", `{"secret":"private"}`, "unavailable", 0, 0, 0, false},
	} {
		t.Run(tc.name, func(t *testing.T) {
			var raw any
			if err := json.Unmarshal([]byte(tc.json), &raw); err != nil {
				t.Fatal(err)
			}
			r := kvmFilesystemUsage(raw)
			if r["state"] != tc.state || r["filesystems"] != tc.count || r["partial"] != tc.partial {
				t.Fatalf("unexpected report: %#v", r)
			}
			if tc.state == "available" && (r["used_bytes"] != tc.used || r["total_bytes"] != tc.total) {
				t.Fatalf("incorrect bytes: %#v", r)
			}
			data, _ := json.Marshal(r)
			if strings.Contains(string(data), "mountpoint") || strings.Contains(string(data), "secret") {
				t.Fatal("raw guest data leaked")
			}
		})
	}
	if r := kvmFilesystemUsage(make([]any, 129)); r["state"] != "unavailable" {
		t.Fatal("unbounded filesystem list accepted")
	}
}

func TestKVMFilesystemCacheBoundsWorkPrunesAndInvalidatesRestartedGuests(t *testing.T) {
	c := &kvmFilesystemCache{entries: map[string]kvmFilesystemObservation{}}
	rows := map[string]map[string]uint64{}
	for i := 0; i < 32; i++ {
		rows[fmt.Sprintf("%08x-1111-4111-8111-111111111111", i)] = map[string]uint64{"cpu.time": 100}
	}
	var calls atomic.Int32
	read := func(context.Context, string) map[string]any { calls.Add(1); return kvmFilesystemUnavailable() }
	now := time.Now()
	c.collect(rows, now, read)
	if calls.Load() != 16 {
		t.Fatal("unbounded work or missing first batch", calls.Load())
	}
	c.collect(rows, now.Add(time.Second), read)
	if calls.Load() != 32 || len(c.entries) != 32 {
		t.Fatal("starvation of second batch")
	}
	c.collect(rows, now.Add(2*time.Second), read)
	if calls.Load() != 32 {
		t.Fatal("cooldown ignored")
	}
	id := "00000000-1111-4111-8111-111111111111"
	c.collect(map[string]map[string]uint64{id: {"cpu.time": 1}}, now.Add(3*time.Second), read)
	if len(c.entries) != 1 || calls.Load() != 33 {
		t.Fatal("stopped/restarted VM retained cached observation")
	}
	c.collect(map[string]map[string]uint64{}, now.Add(4*time.Second), read)
	if len(c.entries) != 0 {
		t.Fatal("inactive cache retained")
	}
}

func TestKVMFilesystemCacheDoesNotRunMoreThanFourRequestsAndHasDeadline(t *testing.T) {
	c := &kvmFilesystemCache{entries: map[string]kvmFilesystemObservation{}}
	rows := map[string]map[string]uint64{}
	for i := 0; i < 32; i++ {
		rows[fmt.Sprintf("%08x-1111-4111-8111-111111111111", i)] = map[string]uint64{}
	}
	var active, maximum atomic.Int32
	started := time.Now()
	c.collect(rows, started, func(ctx context.Context, _ string) map[string]any {
		n := active.Add(1)
		defer active.Add(-1)
		for old := maximum.Load(); n > old; old = maximum.Load() {
			if maximum.CompareAndSwap(old, n) {
				break
			}
		}
		deadline, ok := ctx.Deadline()
		if !ok || time.Until(deadline) > 2*time.Second {
			t.Error("missing batch deadline")
		}
		<-ctx.Done()
		return kvmFilesystemUnavailable()
	})
	if maximum.Load() > 4 || time.Since(started) > 4*time.Second {
		t.Fatal("guest timeouts blocked collection")
	}
}

func TestKVMFilesystemReadsOnlyVerifiedManagedDomain(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	f.states[kvmTestVM] = "running"
	before := kvmCloudFiles(t, f)
	withKVMRunner(t, f.run)
	var calls int
	kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
		if len(args) == 4 && args[2] == "dumpxml" {
			return f.domains[args[3]], nil
		}
		calls++
		if args[3] != kvmTestVM || args[6] != `{"execute":"guest-get-fsinfo"}` {
			t.Fatalf("wrong request: %v", args)
		}
		return []byte(`{"return":[{"name":"vda1","mountpoint":"/","type":"ext4","used-bytes":250,"total-bytes":1000,"private":"secret"}]}`), nil
	})
	r := readKVMFilesystem(context.Background(), kvmTestVM)
	if r["state"] != "available" || r["used_bytes"] != float64(250) || calls != 1 {
		t.Fatalf("incorrect reading: %#v", r)
	}
	if !reflect.DeepEqual(before, kvmCloudFiles(t, f)) {
		t.Fatal("filesystem read changed guest files")
	}
	f.domains[kvmTestVM] = []byte("<domain><name>foreign</name></domain>")
	if r := readKVMFilesystem(context.Background(), kvmTestVM); r["state"] != "unavailable" || calls != 1 {
		t.Fatal("unverified guest was queried")
	}
}
