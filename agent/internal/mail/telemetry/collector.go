package telemetry

import (
	"bufio"
	"context"
	"fmt"
	"io/fs"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"time"
)

const (
	defaultMailboxMapPath = "/etc/postfix/virtual_mailboxes"
	defaultMailRootPath   = "/var/mail/vhosts"
	defaultMailboxMax     = 1000
)

type Collector interface {
	Collect(ctx context.Context) (Snapshot, error)
}

type RuntimeCollector struct{}

var (
	runCommandOutput = func(name string, args ...string) (string, error) {
		cmd := exec.Command(name, args...)
		out, err := cmd.CombinedOutput()
		return strings.TrimSpace(string(out)), err
	}
	countFileEntries = func(path string) (int, error) {
		content, err := os.ReadFile(path)
		if err != nil {
			return 0, err
		}
		count := 0
		for _, line := range strings.Split(string(content), "\n") {
			trimmed := strings.TrimSpace(line)
			if trimmed == "" || strings.HasPrefix(trimmed, "#") {
				continue
			}
			count++
		}
		return count, nil
	}
)

type mailboxEntry struct{ address, path string }

func NewRuntimeCollector() *RuntimeCollector { return &RuntimeCollector{} }

func (c *RuntimeCollector) Collect(_ context.Context) (Snapshot, error) {
	node := strings.TrimSpace(os.Getenv("EASYWI_AGENT_ID"))
	if node == "" {
		node = "unknown"
	}
	now := time.Now().UTC().Truncate(time.Minute)
	mailMetrics, warnings := collectMailMetrics()
	return Snapshot{GeneratedAt: now, NodeID: node, WindowSeconds: 60, Metrics: []MetricPoint{{Name: "queue.depth", Type: "gauge", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now}, {Name: "queue.deferred", Type: "gauge", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now}, {Name: "delivery.bounce", Type: "counter", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now}, {Name: "dkim.failures", Type: "counter", Unit: "events", Value: 0, BucketSize: 60, Timestamp: now}, {Name: "auth.failures", Type: "counter", Unit: "events", Value: 0, BucketSize: 60, Timestamp: now}, {Name: "mail.sent", Type: "counter", Unit: "messages", Value: 0, BucketSize: 60, Timestamp: now}}, Queue: QueueState{Depth: 0, Deferred: 0, Active: 0}, Meta: map[string]any{"source": "agent_mail_telemetry", "mail": mailMetrics, "warnings": warnings}}, nil
}

func collectMailMetrics() (map[string]any, []string) {
	warnings := make([]string, 0)
	appendWarning := func(msg string, err error) {
		if err != nil {
			warnings = append(warnings, fmt.Sprintf("%s: %v", msg, err))
		} else {
			warnings = append(warnings, msg)
		}
	}
	mail := map[string]any{"postfix_active": false, "dovecot_active": false, "queue_total": 0, "queue_deferred": 0, "queue_active": 0, "queue_hold": 0, "maildir_disk_bytes": int64(0), "mailbox_count": 0, "domain_count": 0, "alias_count": 0, "mailbox_usage": map[string]any{}, "mailbox_usage_truncated": false, "ports": map[string]bool{"25": false, "465": false, "587": false, "110": false, "143": false, "993": false, "995": false}}

	if out, err := runCommandOutput("systemctl", "is-active", "postfix"); err == nil {
		mail["postfix_active"] = strings.TrimSpace(out) == "active"
	} else {
		appendWarning("postfix status unavailable", err)
	}
	if out, err := runCommandOutput("systemctl", "is-active", "dovecot"); err == nil {
		mail["dovecot_active"] = strings.TrimSpace(out) == "active"
	} else {
		appendWarning("dovecot status unavailable", err)
	}
	if queue, err := parseQueueMetrics(); err == nil {
		mail["queue_total"], mail["queue_deferred"], mail["queue_active"], mail["queue_hold"] = queue["queue_total"], queue["queue_deferred"], queue["queue_active"], queue["queue_hold"]
	} else {
		appendWarning("mail queue metrics unavailable", err)
	}
	if out, err := runCommandOutput("du", "-sb", defaultMailRootPath); err == nil {
		fields := strings.Fields(out)
		if len(fields) > 0 {
			if b, convErr := strconv.ParseInt(fields[0], 10, 64); convErr == nil {
				mail["maildir_disk_bytes"] = b
			}
		}
	} else {
		appendWarning("maildir disk usage unavailable", err)
	}
	if usage, truncated, uWarn := collectMailboxUsage(defaultMailboxMapPath, defaultMailRootPath, defaultMailboxMax); len(uWarn) > 0 {
		warnings = append(warnings, uWarn...)
		mail["mailbox_usage"] = usage
		mail["mailbox_usage_truncated"] = truncated
	} else {
		mail["mailbox_usage"] = usage
		mail["mailbox_usage_truncated"] = truncated
	}
	if v, err := countFileEntries("/etc/postfix/virtual_mailboxes"); err == nil {
		mail["mailbox_count"] = v
	} else {
		appendWarning("mailbox count unavailable", err)
	}
	if v, err := countFileEntries("/etc/postfix/virtual_domains"); err == nil {
		mail["domain_count"] = v
	} else {
		appendWarning("domain count unavailable", err)
	}
	if v, err := countFileEntries("/etc/postfix/virtual_aliases"); err == nil {
		mail["alias_count"] = v
	} else {
		appendWarning("alias count unavailable", err)
	}
	ports := mail["ports"].(map[string]bool)
	if out, err := runCommandOutput("ss", "-ltnH"); err == nil {
		for _, p := range []string{"25", "465", "587", "110", "143", "993", "995"} {
			ports[p] = strings.Contains(out, ":"+p+" ") || strings.HasSuffix(out, ":"+p)
		}
	} else {
		appendWarning("port summary unavailable", err)
	}
	return mail, warnings
}

func parseVirtualMailboxes(path string) ([]mailboxEntry, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	out := make([]mailboxEntry, 0)
	s := bufio.NewScanner(f)
	for s.Scan() {
		line := strings.TrimSpace(s.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		parts := strings.Fields(line)
		if len(parts) < 2 || !strings.Contains(parts[0], "@") {
			continue
		}
		out = append(out, mailboxEntry{address: strings.ToLower(parts[0]), path: parts[1]})
	}
	if err := s.Err(); err != nil {
		return nil, err
	}
	return out, nil
}

func collectMailboxUsage(mapPath, root string, max int) (map[string]any, bool, []string) {
	entries, err := parseVirtualMailboxes(mapPath)
	if err != nil {
		return map[string]any{}, false, []string{fmt.Sprintf("mailbox usage unavailable: %v", err)}
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].address < entries[j].address })
	usage := map[string]any{}
	warnings := []string{}
	rootClean := filepath.Clean(root)
	truncated := false
	for i, e := range entries {
		if i >= max {
			truncated = true
			break
		}
		rel := filepath.Clean(strings.TrimSpace(e.path))
		if rel == "." || strings.HasPrefix(rel, "../") || filepath.IsAbs(rel) {
			warnings = append(warnings, fmt.Sprintf("mailbox %s ignored invalid path", e.address))
			continue
		}
		full := filepath.Join(rootClean, rel)
		if !strings.HasPrefix(full, rootClean+string(os.PathSeparator)) && full != rootClean {
			warnings = append(warnings, fmt.Sprintf("mailbox %s path escapes root", e.address))
			continue
		}
		u, warn := countMaildirBytes(full)
		if warn != "" {
			warnings = append(warnings, fmt.Sprintf("mailbox %s: %s", e.address, warn))
		}
		usage[e.address] = map[string]any{"used_bytes": u}
	}
	return usage, truncated, warnings
}

func countMaildirBytes(root string) (int64, string) {
	info, err := os.Stat(root)
	if err != nil {
		if os.IsNotExist(err) {
			return 0, "maildir missing"
		}
		return 0, err.Error()
	}
	if !info.IsDir() {
		return 0, "maildir path is not directory"
	}
	var total int64
	walkErr := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
		if err != nil {
			return nil
		}
		if d.Type()&os.ModeSymlink != 0 {
			if d.IsDir() {
				return filepath.SkipDir
			}
			return nil
		}
		if d.IsDir() {
			return nil
		}
		fi, statErr := d.Info()
		if statErr != nil {
			return nil
		}
		total += fi.Size()
		return nil
	})
	if walkErr != nil {
		return total, walkErr.Error()
	}
	return total, ""
}

func parseQueueMetrics() (map[string]int, error) {
	output, err := runCommandOutput("postqueue", "-p")
	if err != nil {
		output, err = runCommandOutput("mailq")
		if err != nil {
			return nil, err
		}
	}
	result := map[string]int{"queue_total": 0, "queue_deferred": 0, "queue_active": 0, "queue_hold": 0}
	if strings.Contains(output, "Mail queue is empty") {
		return result, nil
	}
	reTotal := regexp.MustCompile(`(?m)--\s*(\d+)\s+Kbytes\s+in\s+(\d+)\s+Requests\.?`)
	if m := reTotal.FindStringSubmatch(output); len(m) == 3 {
		if v, convErr := strconv.Atoi(m[2]); convErr == nil {
			result["queue_total"] = v
		}
	}
	result["queue_deferred"] = strings.Count(output, "!\n")
	result["queue_hold"] = strings.Count(output, "*\n")
	return result, nil
}
