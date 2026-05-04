package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	defaultMailboxMapPath  = "/etc/postfix/virtual_mailboxes"
	defaultDovecotPassPath = "/etc/dovecot/users"
	defaultMailDir         = "/var/mail/vhosts"

	mailboxDirMode  = 0o750
	mailboxFileMode = 0o640
	defaultMailboxPolicyPath = "/etc/easywi/mailbox_policies.json"
)

func handleMailboxCreate(job jobs.Job) (jobs.Result, func() error) {
	if out, ok := mailBackendGuard(job); !ok {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
	}

	address := payloadValue(job.Payload, "address", "email")
	passwordHash := payloadValue(job.Payload, "password_hash", "password")
	quotaValue := payloadValue(job.Payload, "quota_mb", "quota")
	enabledValue := payloadValue(job.Payload, "enabled", "active")
	mapPath, passwdPath, mailDir := resolveMailboxPaths(job.Payload)

	missing := missingValues([]requiredValue{
		{key: "address", value: address},
		{key: "password_hash", value: passwordHash},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	quotaMB := normalizeMailboxQuota(quotaValue)
	enabled := normalizeMailboxEnabled(enabledValue, true)

	if err := ensureDirWithMode(filepath.Dir(mapPath), mailboxDirMode); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureDirWithMode(filepath.Dir(passwdPath), mailboxDirMode); err != nil {
		return failureResult(job.ID, err)
	}

	relPath := mailboxRelPath(address, mailDir)
	if err := ensureDirWithMode(mailDir, mailboxDirMode); err != nil {
		return failureResult(job.ID, err)
	}
	if err := updateMailboxVirtualMap(mapPath, address, relPath, enabled); err != nil {
		return failureResult(job.ID, err)
	}
	if err := postmapAndReload(mapPath); err != nil {
		return failureResult(job.ID, err)
	}

	if err := upsertDovecotUser(passwdPath, address, passwordHash, quotaMB); err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadDovecot(); err != nil {
		return failureResult(job.ID, err)
	}

	absMailPath := filepath.Join(mailDir, relPath)
	if enabled {
		if mkErr := os.MkdirAll(absMailPath, mailboxDirMode); mkErr != nil {
			return jobs.Result{
				JobID:  job.ID,
				Status: "failed",
				Output: map[string]string{
					"address":      address,
					"quota_mb":     strconv.Itoa(quotaMB),
					"enabled":      fmt.Sprintf("%t", enabled),
					"password_set": "hash",
					"mail_dir":     absMailPath,
					"partial_success": "true",
					"warning":      "mail_dir could not be created: " + mkErr.Error(),
				},
				Completed: time.Now().UTC(),
			}, nil
		}
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":      address,
			"quota_mb":     strconv.Itoa(quotaMB),
			"enabled":      fmt.Sprintf("%t", enabled),
			"password_set": "hash",
			"mail_dir":     absMailPath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxPasswordReset(job jobs.Job) (jobs.Result, func() error) {
	if out, ok := mailBackendGuard(job); !ok {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
	}

	address := payloadValue(job.Payload, "address", "email")
	passwordHash := payloadValue(job.Payload, "password_hash", "password")
	_, passwdPath, _ := resolveMailboxPaths(job.Payload)

	if address == "" || passwordHash == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing address or password_hash"},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := updateDovecotPassword(passwdPath, address, passwordHash); err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadDovecot(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":      address,
			"password_set": "hash",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxQuotaUpdate(job jobs.Job) (jobs.Result, func() error) {
	if out, ok := mailBackendGuard(job); !ok {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
	}

	address := payloadValue(job.Payload, "address", "email")
	quotaValue := payloadValue(job.Payload, "quota_mb", "quota")
	_, passwdPath, _ := resolveMailboxPaths(job.Payload)

	if address == "" || quotaValue == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing address or quota"},
			Completed: time.Now().UTC(),
		}, nil
	}

	quotaMB := normalizeMailboxQuota(quotaValue)

	if err := updateDovecotQuota(passwdPath, address, quotaMB); err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadDovecot(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address":  address,
			"quota_mb": strconv.Itoa(quotaMB),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxEnable(job jobs.Job) (jobs.Result, func() error) {
	if out, ok := mailBackendGuard(job); !ok {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
	}

	return handleMailboxStatus(job, true)
}

func handleMailboxDisable(job jobs.Job) (jobs.Result, func() error) {
	if out, ok := mailBackendGuard(job); !ok {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
	}

	return handleMailboxStatus(job, false)
}

func handleMailboxDelete(job jobs.Job) (jobs.Result, func() error) {
	if out, ok := mailBackendGuard(job); !ok {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
	}

	address := payloadValue(job.Payload, "address", "email")
	mapPath, passwdPath, _ := resolveMailboxPaths(job.Payload)

	if address == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing address"},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := removeFromMailboxVirtualMap(mapPath, address); err != nil {
		return failureResult(job.ID, err)
	}
	if err := postmapAndReload(mapPath); err != nil {
		return failureResult(job.ID, err)
	}

	if err := removeFromDovecotUsers(passwdPath, address); err != nil {
		return failureResult(job.ID, err)
	}
	if err := reloadDovecot(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address": address,
			"deleted": "true",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxStatus(job jobs.Job, enabled bool) (jobs.Result, func() error) {
	address := payloadValue(job.Payload, "address", "email")
	mapPath, _, mailDir := resolveMailboxPaths(job.Payload)

	if address == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing address"},
			Completed: time.Now().UTC(),
		}, nil
	}

	relPath := mailboxRelPath(address, mailDir)
	if err := updateMailboxVirtualMap(mapPath, address, relPath, enabled); err != nil {
		return failureResult(job.ID, err)
	}
	if err := postmapAndReload(mapPath); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"address": address,
			"enabled": fmt.Sprintf("%t", enabled),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMailboxPolicyUpdate(job jobs.Job) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "email")))
	if address == "" || !strings.Contains(address, "@") {
		return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{"message": "missing or invalid address"}, Completed: time.Now().UTC()}, nil
	}
	sendLimit := normalizeMailboxQuota(payloadValue(job.Payload, "send_limit_hour"))
	recipientLimit := normalizeMailboxQuota(payloadValue(job.Payload, "recipient_limit"))
	policyPath := payloadValue(job.Payload, "policy_path")
	if policyPath == "" {
		policyPath = defaultMailboxPolicyPath
	}
	policies := map[string]map[string]any{}
	if b, err := os.ReadFile(policyPath); err == nil && len(b) > 0 {
		if unmarshalErr := json.Unmarshal(b, &policies); unmarshalErr != nil {
			return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{"message": "policy file corrupt: " + unmarshalErr.Error()}, Completed: time.Now().UTC()}, nil
		}
	}
	policies[address] = map[string]any{
		"smtp_enabled":         normalizeMailboxEnabled(payloadValue(job.Payload, "smtp_enabled"), false),
		"send_limit_hour":      sendLimit,
		"recipient_limit":      recipientLimit,
		"abuse_policy_enabled": normalizeMailboxEnabled(payloadValue(job.Payload, "abuse_policy_enabled"), false),
	}
	if err := ensureDirWithMode(filepath.Dir(policyPath), mailboxDirMode); err != nil { return failureResult(job.ID, err) }
	encoded, _ := json.MarshalIndent(policies, "", "  ")
	if err := os.WriteFile(policyPath, append(encoded, '\n'), mailboxFileMode); err != nil { return failureResult(job.ID, err) }
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "policy_path": policyPath, "message": "Policy stored", "enforcement": "Enforcement backend not yet active"}, Completed: time.Now().UTC()}, nil
}

// ── path helpers ──────────────────────────────────────────────────────────────

func resolveMailboxPaths(payload map[string]any) (mapPath, passwdPath, mailDir string) {
	mapPath = payloadValue(payload, "map_path", "mailbox_map_path")
	passwdPath = payloadValue(payload, "passwd_path", "dovecot_passwd_path")
	mailDir = payloadValue(payload, "mail_dir", "mail_storage_path")
	if mapPath == "" {
		mapPath = defaultMailboxMapPath
	}
	if passwdPath == "" {
		passwdPath = defaultDovecotPassPath
	}
	if mailDir == "" {
		mailDir = defaultMailDir
	}
	return
}

// mailboxRelPath returns the relative maildir path for the given address.
// Example: "user@example.com" → "example.com/user/"
func mailboxRelPath(address, _ string) string {
	parts := strings.SplitN(address, "@", 2)
	if len(parts) != 2 || parts[0] == "" || parts[1] == "" {
		return strings.ReplaceAll(address, "@", "_") + "/"
	}
	return parts[1] + "/" + parts[0] + "/"
}

// ── virtual mailbox map (Postfix) ─────────────────────────────────────────────

func readMailboxVirtualMap(path string) (entries map[string]string, order []string, err error) {
	entries = make(map[string]string)
	f, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return entries, order, nil
		}
		return nil, nil, fmt.Errorf("open mailbox map %s: %w", path, err)
	}
	defer func() {
		if closeErr := f.Close(); closeErr != nil && err == nil {
			err = closeErr
		}
	}()
	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		addr := fields[0]
		if _, exists := entries[addr]; !exists {
			order = append(order, addr)
		}
		entries[addr] = fields[1]
	}
	if scanErr := scanner.Err(); scanErr != nil {
		return nil, nil, fmt.Errorf("scan mailbox map %s: %w", path, scanErr)
	}
	return entries, order, nil
}

// stripNewlines removes CR and LF so a crafted address or path cannot inject
// additional lines into a Postfix map file.
func stripNewlines(s string) string {
	return strings.Map(func(r rune) rune {
		if r == '\n' || r == '\r' {
			return -1
		}
		return r
	}, s)
}

func writeMailboxVirtualMap(path string, entries map[string]string, order []string) error {
	if err := ensureDirWithMode(filepath.Dir(path), mailboxDirMode); err != nil {
		return err
	}
	var b strings.Builder
	b.WriteString("## Managed by Easy-Wi agent\n")
	used := make(map[string]struct{})
	for _, addr := range order {
		dest, ok := entries[addr]
		if !ok {
			continue
		}
		_, _ = fmt.Fprintf(&b, "%s %s\n", stripNewlines(addr), stripNewlines(dest))
		used[addr] = struct{}{}
	}
	var remaining []string
	for addr := range entries {
		if _, ok := used[addr]; !ok {
			remaining = append(remaining, addr)
		}
	}
	sort.Strings(remaining)
	for _, addr := range remaining {
		_, _ = fmt.Fprintf(&b, "%s %s\n", stripNewlines(addr), stripNewlines(entries[addr]))
	}
	if err := os.WriteFile(path, []byte(b.String()), mailboxFileMode); err != nil {
		return fmt.Errorf("write mailbox map %s: %w", path, err)
	}
	return nil
}

func updateMailboxVirtualMap(path, address, relPath string, enabled bool) error {
	entries, order, err := readMailboxVirtualMap(path)
	if err != nil {
		return err
	}
	if enabled {
		entries[address] = relPath
		if !containsOrder(order, address) {
			order = append(order, address)
		}
	} else {
		delete(entries, address)
		order = filterOrder(order, entries)
	}
	return writeMailboxVirtualMap(path, entries, order)
}

func removeFromMailboxVirtualMap(path, address string) error {
	entries, order, err := readMailboxVirtualMap(path)
	if err != nil {
		return err
	}
	delete(entries, address)
	order = filterOrder(order, entries)
	return writeMailboxVirtualMap(path, entries, order)
}

// ── Dovecot passwd-file ───────────────────────────────────────────────────────

type dovecotUserEntry struct {
	address  string
	hash     string
	quotaMB  int
	hasQuota bool
}

func readDovecotUsers(path string) (entries map[string]*dovecotUserEntry, order []string, err error) {
	entries = make(map[string]*dovecotUserEntry)
	f, err := os.Open(path)
	if err != nil {
		if os.IsNotExist(err) {
			return entries, order, nil
		}
		return nil, nil, fmt.Errorf("open dovecot users %s: %w", path, err)
	}
	defer func() {
		if closeErr := f.Close(); closeErr != nil && err == nil {
			err = closeErr
		}
	}()
	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		parts := strings.SplitN(line, ":", 2)
		if len(parts) < 2 {
			continue
		}
		addr := parts[0]
		rest := parts[1]
		entry := &dovecotUserEntry{address: addr}

		hashAndExtra := strings.SplitN(rest, " ", 2)
		entry.hash = hashAndExtra[0]
		if len(hashAndExtra) > 1 {
			for _, field := range strings.Fields(hashAndExtra[1]) {
				if strings.HasPrefix(field, "quota_rule=*:bytes=") {
					bytesStr := strings.TrimPrefix(field, "quota_rule=*:bytes=")
					bytesStr = strings.TrimSuffix(bytesStr, "M")
					if mb, parseErr := strconv.Atoi(bytesStr); parseErr == nil {
						entry.quotaMB = mb
						entry.hasQuota = true
					}
				}
			}
		}

		if _, exists := entries[addr]; !exists {
			order = append(order, addr)
		}
		entries[addr] = entry
	}
	if scanErr := scanner.Err(); scanErr != nil {
		return nil, nil, fmt.Errorf("scan dovecot users %s: %w", path, scanErr)
	}
	return entries, order, nil
}

func writeDovecotUsers(path string, entries map[string]*dovecotUserEntry, order []string) error {
	if err := ensureDirWithMode(filepath.Dir(path), mailboxDirMode); err != nil {
		return err
	}
	var b strings.Builder
	b.WriteString("## Managed by Easy-Wi agent\n")
	used := make(map[string]struct{})
	for _, addr := range order {
		entry, ok := entries[addr]
		if !ok {
			continue
		}
		writeDovecotLine(&b, entry)
		used[addr] = struct{}{}
	}
	var remaining []string
	for addr := range entries {
		if _, ok := used[addr]; !ok {
			remaining = append(remaining, addr)
		}
	}
	sort.Strings(remaining)
	for _, addr := range remaining {
		writeDovecotLine(&b, entries[addr])
	}
	if err := os.WriteFile(path, []byte(b.String()), mailboxFileMode); err != nil {
		return fmt.Errorf("write dovecot users %s: %w", path, err)
	}
	return nil
}

func writeDovecotLine(b *strings.Builder, e *dovecotUserEntry) {
	// Strip any newlines that would corrupt the passwd-file format.
	addr := strings.Map(func(r rune) rune {
		if r == '\n' || r == '\r' {
			return -1
		}
		return r
	}, e.address)
	hash := strings.Map(func(r rune) rune {
		if r == '\n' || r == '\r' {
			return -1
		}
		return r
	}, e.hash)
	_, _ = fmt.Fprintf(b, "%s:%s", addr, hash)
	if e.hasQuota && e.quotaMB > 0 {
		_, _ = fmt.Fprintf(b, " quota_rule=*:bytes=%dM", e.quotaMB)
	}
	b.WriteString("\n")
}

func upsertDovecotUser(path, address, hash string, quotaMB int) error {
	entries, order, err := readDovecotUsers(path)
	if err != nil {
		return err
	}
	existing, ok := entries[address]
	if !ok {
		existing = &dovecotUserEntry{address: address}
		order = append(order, address)
		entries[address] = existing
	}
	existing.hash = hash
	if quotaMB > 0 {
		existing.quotaMB = quotaMB
		existing.hasQuota = true
	}
	return writeDovecotUsers(path, entries, order)
}

func updateDovecotPassword(path, address, hash string) error {
	entries, order, err := readDovecotUsers(path)
	if err != nil {
		return err
	}
	existing, ok := entries[address]
	if !ok {
		existing = &dovecotUserEntry{address: address}
		order = append(order, address)
		entries[address] = existing
	}
	existing.hash = hash
	return writeDovecotUsers(path, entries, order)
}

func updateDovecotQuota(path, address string, quotaMB int) error {
	entries, order, err := readDovecotUsers(path)
	if err != nil {
		return err
	}
	existing, ok := entries[address]
	if !ok {
		return fmt.Errorf("user %s not found in dovecot users", address)
	}
	existing.quotaMB = quotaMB
	existing.hasQuota = quotaMB > 0
	return writeDovecotUsers(path, entries, order)
}

func removeFromDovecotUsers(path, address string) error {
	entries, order, err := readDovecotUsers(path)
	if err != nil {
		return err
	}
	delete(entries, address)
	order = filterOrder(order, toStringMap(entries))
	return writeDovecotUsers(path, entries, order)
}

func toStringMap(entries map[string]*dovecotUserEntry) map[string]string {
	m := make(map[string]string, len(entries))
	for k := range entries {
		m[k] = k
	}
	return m
}

// ── Dovecot reload ────────────────────────────────────────────────────────────

func reloadDovecot() error {
	if err := runCommand("systemctl", "reload", "dovecot"); err != nil {
		if fallbackErr := runCommand("dovecot", "reload"); fallbackErr != nil {
			return fmt.Errorf("reload dovecot: %w", fallbackErr)
		}
	}
	return nil
}

// ── shared helpers ────────────────────────────────────────────────────────────

func normalizeMailboxQuota(value string) int {
	if value == "" {
		return 0
	}
	parsed, err := strconv.Atoi(value)
	if err != nil || parsed < 0 {
		return 0
	}
	return parsed
}

func normalizeMailboxEnabled(value string, defaultValue bool) bool {
	if value == "" {
		return defaultValue
	}
	switch value {
	case "true", "1", "yes", "on":
		return true
	case "false", "0", "no", "off":
		return false
	default:
		return defaultValue
	}
}
