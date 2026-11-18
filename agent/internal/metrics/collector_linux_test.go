//go:build linux

package metrics

import (
	"math"
	"syscall"
	"testing"
)

func TestDiskUsageDistinguishesAvailableAndReservedSpace(t *testing.T) {
	total, used, free, percent, err := diskUsageFromStat(syscall.Statfs_t{Blocks: 1000, Bfree: 200, Bavail: 100, Bsize: 1024})
	if err != nil {
		t.Fatal(err)
	}
	if total != 1024000 || used != 819200 || free != 102400 || total-used-free != 102400 {
		t.Fatalf("inconsistent byte counts: %d %d %d", total, used, free)
	}
	if math.Abs(percent-(800.0/900.0*100)) > 0.000001 {
		t.Fatalf("percent=%f; expected df-compatible percentage", percent)
	}
}

func TestDiskUsageHandlesFullAndInvalidFilesystems(t *testing.T) {
	for _, stat := range []syscall.Statfs_t{{Blocks: 0, Bsize: 4096}, {Blocks: 100, Bfree: 101, Bsize: 4096}, {Blocks: 100, Bsize: 0}, {Blocks: ^uint64(0), Bsize: 4096}} {
		if _, _, _, _, err := diskUsageFromStat(stat); err == nil {
			t.Fatalf("invalid filesystem accepted: %#v", stat)
		}
	}
	for _, available := range []uint64{0, ^uint64(0)} {
		total, used, free, pct, err := diskUsageFromStat(syscall.Statfs_t{Blocks: 100, Bfree: 10, Bavail: available, Bsize: 4096})
		if err != nil || free != 0 || pct != 100 || used > total {
			t.Fatalf("exhausted filesystem mishandled: total=%d used=%d free=%d pct=%f err=%v", total, used, free, pct, err)
		}
	}
}

func TestDiskUsageReadsTheRequestedFilesystemAndRejectsMissingPaths(t *testing.T) {
	total, used, free, pct, err := diskUsage(t.TempDir())
	if err != nil || total == 0 || used > total || free > total-used || pct < 0 || pct > 100 {
		t.Fatalf("invalid native snapshot: total=%d used=%d free=%d pct=%f err=%v", total, used, free, pct, err)
	}
	if _, _, _, _, err := diskUsage(t.TempDir() + "/missing"); err == nil {
		t.Fatal("missing filesystem reported as measured")
	}
}
