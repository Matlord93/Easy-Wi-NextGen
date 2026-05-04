package telemetry

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCountFileEntriesIgnoresCommentsAndBlankLines(t *testing.T) { /* unchanged */
	d := t.TempDir()
	p := filepath.Join(d, "map")
	_ = os.WriteFile(p, []byte("# comment\n\nuser one\n  # inline\na b\n"), 0o644)
	count, err := countFileEntries(p)
	if err != nil || count != 2 {
		t.Fatalf("count=%d err=%v", count, err)
	}
}

func TestParseVirtualMailboxesIgnoresInvalidLines(t *testing.T) {
	d := t.TempDir()
	p := filepath.Join(d, "virtual_mailboxes")
	content := "# comment\n\ninvalid\nuser@example.com example.com/user/\nno-at example.com/no\nsecond@example.com example.com/second/ extra\n"
	if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
	entries, err := parseVirtualMailboxes(p)
	if err != nil {
		t.Fatal(err)
	}
	if len(entries) != 2 {
		t.Fatalf("expected 2 entries, got %d", len(entries))
	}
}

func TestCollectMailboxUsageCountsBytesAndMissingAsZero(t *testing.T) {
	d := t.TempDir()
	root := filepath.Join(d, "vhosts")
	_ = os.MkdirAll(filepath.Join(root, "example.com", "user", "cur"), 0o755)
	_ = os.WriteFile(filepath.Join(root, "example.com", "user", "cur", "a"), []byte("12345"), 0o644)
	mapPath := filepath.Join(d, "virtual_mailboxes")
	_ = os.WriteFile(mapPath, []byte("user@example.com example.com/user\nmissing@example.com example.com/missing\n"), 0o644)
	usage, truncated, warns := collectMailboxUsage(mapPath, root, 1000)
	if truncated {
		t.Fatal("did not expect truncation")
	}
	if usage["user@example.com"].(map[string]any)["used_bytes"].(int64) != 5 {
		t.Fatalf("unexpected bytes %#v", usage)
	}
	if usage["missing@example.com"].(map[string]any)["used_bytes"].(int64) != 0 {
		t.Fatal("missing should be zero")
	}
	if len(warns) == 0 {
		t.Fatal("expected warning for missing")
	}
}

func TestCollectMailboxUsagePreventsSymlinkAndTraversal(t *testing.T) {
	d := t.TempDir()
	root := filepath.Join(d, "vhosts")
	_ = os.MkdirAll(filepath.Join(root, "example.com", "safe"), 0o755)
	_ = os.WriteFile(filepath.Join(root, "example.com", "safe", "m"), []byte("1"), 0o644)
	outside := filepath.Join(d, "outside")
	_ = os.WriteFile(outside, []byte("123456"), 0o644)
	_ = os.Symlink(outside, filepath.Join(root, "example.com", "safe", "link"))
	mapPath := filepath.Join(d, "virtual_mailboxes")
	_ = os.WriteFile(mapPath, []byte("safe@example.com example.com/safe\ntraverse@example.com ../etc\n"), 0o644)
	usage, _, warns := collectMailboxUsage(mapPath, root, 1000)
	if usage["safe@example.com"].(map[string]any)["used_bytes"].(int64) != 1 {
		t.Fatalf("symlink should not be counted: %#v", usage)
	}
	if _, ok := usage["traverse@example.com"]; ok {
		t.Fatal("traversal entry should be ignored")
	}
	if len(warns) == 0 {
		t.Fatal("expected warnings")
	}
}

func TestParseQueueMetricsEmptyQueue(t *testing.T) {
	old := runCommandOutput
	defer func() { runCommandOutput = old }()
	runCommandOutput = func(string, ...string) (string, error) { return "Mail queue is empty", nil }
	m, err := parseQueueMetrics()
	if err != nil || m["queue_total"] != 0 {
		t.Fatal(err, m)
	}
}

func TestCollectMailMetricsRobustWhenFilesMissing(t *testing.T) {
	oldRun, oldCount := runCommandOutput, countFileEntries
	defer func() { runCommandOutput, countFileEntries = oldRun, oldCount }()
	runCommandOutput = func(name string, args ...string) (string, error) {
		switch name {
		case "systemctl":
			return "active", nil
		case "postqueue":
			return "Mail queue is empty", nil
		case "du":
			return "123 /var/mail/vhosts", nil
		case "ss":
			return "LISTEN 0 4096 0.0.0.0:25", nil
		default:
			return "", nil
		}
	}
	countFileEntries = func(path string) (int, error) { return 0, errors.New("missing") }
	mail, warnings := collectMailMetrics()
	if mail["mailbox_count"].(int) != 0 || len(warnings) < 3 {
		t.Fatalf("unexpected")
	}
}

func TestSnapshotPrivacyGuard(t *testing.T) {
	oldRun, oldCount := runCommandOutput, countFileEntries
	defer func() { runCommandOutput, countFileEntries = oldRun, oldCount }()
	runCommandOutput = func(name string, args ...string) (string, error) { return "Mail queue is empty", nil }
	countFileEntries = func(path string) (int, error) { return 0, nil }
	s, _ := NewRuntimeCollector().Collect(context.Background())
	b, _ := json.Marshal(s)
	blob := strings.ToLower(string(b))
	for _, forbidden := range []string{`"subject"`, `"body"`, `"from"`, `"to"`, `"recipient"`, `"sender"`, `"filename"`} {
		if strings.Contains(blob, forbidden) {
			t.Fatalf("found %s", forbidden)
		}
	}
}
