package main

import (
	"crypto/sha256"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"sort"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

type securityRuleSet struct {
	Version   int            `json:"version"`
	CreatedBy string         `json:"created_by"`
	Rules     []securityRule `json:"rules"`
}

type securityRule struct {
	ID        string         `json:"id"`
	Type      string         `json:"type"`
	Action    string         `json:"action"`
	Protocol  string         `json:"protocol"`
	Port      int            `json:"port"`
	Priority  int            `json:"priority"`
	Enabled   bool           `json:"enabled"`
	Reason    string         `json:"reason"`
	SourceIP  string         `json:"source_ip,omitempty"`
	SourceASN string         `json:"source_asn,omitempty"`
	Target    securityTarget `json:"target"`
}

type securityTarget struct {
	Scope   string `json:"scope"`
	NodeID  string `json:"node_id,omitempty"`
	Service string `json:"service,omitempty"`
}

type securityRuleSetState struct {
	AppliedAt string          `json:"applied_at"`
	NodeID    string          `json:"node_id,omitempty"`
	Target    string          `json:"target,omitempty"`
	Backend   string          `json:"backend,omitempty"`
	Hash      string          `json:"hash,omitempty"`
	RuleSet   securityRuleSet `json:"ruleset"`
}

const securityRuleSetStatePath = "/var/lib/easywi/security/ruleset_state.json"
const securityRuleSetLastGoodPath = "/var/lib/easywi/security/ruleset_last_good.json"
const securityRuleSetLkgDir = "/var/lib/easywi/security/lkg"
const securityEventsMaxPayloadBytes = 256 * 1024
const securityEventsMaxEntries = 1024
const securityEventsRetentionTTL = 24 * time.Hour

func handleSecurityRuleSetApply(job jobs.Job) (jobs.Result, func() error) {
	ruleset, err := parseSecurityRuleSet(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := validateSecurityRuleSet(ruleset); err != nil {
		return failureResult(job.ID, err)
	}
	ruleset = normalizeSecurityRuleSet(ruleset)
	targetKey := resolveRuleSetTarget(job.Payload)
	backend := resolveFirewallBackend()
	nodeID := strings.TrimSpace(payloadValue(job.Payload, "agent_id", "node_id"))
	hash := hashSecurityRuleSet(ruleset)
	cleanupLegacySecurityStatePaths()
	statePath := activeRuleSetStatePath(nodeID, targetKey, backend)
	lkgPath := lastKnownGoodRuleSetStatePath(nodeID, targetKey, backend)

	current, _ := readSecurityRuleSetState(statePath)
	if securityRuleSetsEqual(current.RuleSet, ruleset) {
		return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"message": "ruleset already active", "changed": "false", "version": strconv.Itoa(ruleset.Version), "hash": hash, "active_backend": backend}, Completed: time.Now().UTC()}, nil
	}

	if current.RuleSet.Version > 0 {
		_ = writeSecurityRuleSetState(lkgPath, current)
	}

	if err := applySecurityRuleSetAtomically(ruleset, current.RuleSet); err != nil {
		if previous, ok := readSecurityRuleSetState(lkgPath); ok {
			_ = applySecurityRuleSet(previous.RuleSet)
			_ = writeSecurityRuleSetState(statePath, previous)
		}
		return failureResult(job.ID, err)
	}

	state := securityRuleSetState{AppliedAt: time.Now().UTC().Format(time.RFC3339), NodeID: nodeID, Target: targetKey, Backend: backend, Hash: hash, RuleSet: ruleset}
	if err := writeSecurityRuleSetState(statePath, state); err != nil {
		return failureResult(job.ID, err)
	}
	_ = writeSecurityRuleSetState(lkgPath, state)

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"message": "ruleset applied", "changed": "true", "version": strconv.Itoa(ruleset.Version), "hash": hash, "target": targetKey, "active_backend": backend, "active_rules": strconv.Itoa(len(ruleset.Rules))}, Completed: time.Now().UTC()}, nil
}

func handleSecurityRuleSetRollback(job jobs.Job) (jobs.Result, func() error) {
	targetKey := resolveRuleSetTarget(job.Payload)
	backend := resolveFirewallBackend()
	nodeID := strings.TrimSpace(payloadValue(job.Payload, "agent_id", "node_id"))
	lastGood, ok := readSecurityRuleSetState(lastKnownGoodRuleSetStatePath(nodeID, targetKey, backend))
	if !ok {
		return failureResult(job.ID, fmt.Errorf("no last known good ruleset found"))
	}
	if err := applySecurityRuleSet(lastGood.RuleSet); err != nil {
		return failureResult(job.ID, fmt.Errorf("rollback failed: %w", err))
	}
	if err := writeSecurityRuleSetState(activeRuleSetStatePath(nodeID, targetKey, backend), lastGood); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"message": "rollback applied", "version": strconv.Itoa(lastGood.RuleSet.Version)}, Completed: time.Now().UTC()}, nil
}

func handleSecurityEventsCollect(job jobs.Job) (jobs.Result, func() error) {
	banned := "[]"
	if commandExists("fail2ban-client") && runtime.GOOS == "linux" {
		jails, err := listFail2banJails()
		if err == nil {
			events := make([]map[string]any, 0)
			for _, jail := range jails {
				status, err := fetchFail2banJailStatus(jail)
				if err != nil {
					continue
				}
				for _, ip := range status.BannedIPs {
					if len(events) >= securityEventsMaxEntries {
						break
					}
					events = append(events, map[string]any{"source": "fail2ban", "direction": "blocked", "rule": jail, "ip": ip, "reason": "jail:" + jail})
				}
				if len(events) >= securityEventsMaxEntries {
					break
				}
			}
			if encoded, err := json.Marshal(events); err == nil {
				banned = string(encoded)
			}
		}
	}

	if len(banned) > securityEventsMaxPayloadBytes {
		banned = "[]"
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"events": banned, "collected_at": time.Now().UTC().Format(time.RFC3339), "schema": "security.events.v1", "retention_ttl": securityEventsRetentionTTL.String()}, Completed: time.Now().UTC()}, nil
}

func applySecurityRuleSetAtomically(target securityRuleSet, current securityRuleSet) error {
	if err := applySecurityRuleSet(target); err != nil {
		return err
	}
	if err := verifySecurityRuleSetApplied(target); err != nil {
		_ = applySecurityRuleSet(current)
		return fmt.Errorf("verification failed; rolled back to previous ruleset: %w", err)
	}
	return nil
}

func verifySecurityRuleSetApplied(ruleset securityRuleSet) error {
	if err := ensureNoSelfLockout(ruleset); err != nil {
		return err
	}
	return nil
}

func applySecurityRuleSet(ruleset securityRuleSet) error {
	portsToOpen := make([]int, 0)
	portsToClose := make([]int, 0)
	jails := make([]string, 0)
	for _, rule := range ruleset.Rules {
		if !rule.Enabled {
			continue
		}
		switch rule.Type {
		case "firewall":
			if rule.Action == "allow" {
				portsToOpen = append(portsToOpen, rule.Port)
			} else {
				portsToClose = append(portsToClose, rule.Port)
			}
		case "fail2ban":
			if rule.Target.Service != "" {
				jails = append(jails, rule.Target.Service)
			}
		}
	}

	portsToOpen = uniqueIntSlice(portsToOpen)
	portsToClose = uniqueIntSlice(portsToClose)
	if err := closePorts(portsToClose); err != nil {
		return err
	}
	if err := openPorts(portsToOpen); err != nil {
		return err
	}

	if len(jails) > 0 && commandExists("fail2ban-client") && runtime.GOOS == "linux" {
		policy := fail2banPolicy{Enabled: true, BanTime: "10m", FindTime: "10m", MaxRetry: 5, IgnoreIPs: []string{"127.0.0.1/8"}, Jails: uniqueStringSlice(jails)}
		config := buildFail2banConfig(policy)
		configPath := "/etc/fail2ban/jail.d/easywi.conf"
		previousConfig, previousExists := readFail2banConfig(configPath)
		if err := os.MkdirAll(filepath.Dir(configPath), 0o755); err != nil {
			return err
		}
		if err := os.WriteFile(configPath, []byte(config), 0o640); err != nil {
			return err
		}
		if err := reloadFail2ban(); err != nil {
			_ = rollbackFail2banConfig(configPath, previousConfig, previousExists)
			return err
		}
	}

	return nil
}

func validateSecurityRuleSet(ruleset securityRuleSet) error {
	ruleset = normalizeSecurityRuleSet(ruleset)
	if err := ensureNoSelfLockout(ruleset); err != nil {
		return err
	}
	for _, rule := range ruleset.Rules {
		if !rule.Enabled {
			continue
		}
		if rule.Type != "firewall" && rule.Type != "fail2ban" {
			return fmt.Errorf("invalid rule type: %s", rule.Type)
		}
		if rule.Action != "allow" && rule.Action != "block" && rule.Action != "ban" {
			return fmt.Errorf("invalid rule action: %s", rule.Action)
		}
		if rule.Type == "firewall" && (rule.Port < 1 || rule.Port > 65535) {
			return fmt.Errorf("invalid firewall port: %d", rule.Port)
		}
		if strings.Contains(rule.Reason, "\n") || strings.Contains(rule.Reason, "\r") {
			return fmt.Errorf("invalid rule reason")
		}
		if strings.ContainsAny(rule.Target.Service, "*+?[]{}()\\") {
			return fmt.Errorf("service contains unsafe regex characters")
		}
	}
	return nil
}

func parseSecurityRuleSet(payload map[string]any) (securityRuleSet, error) {
	raw := payload
	if nested, ok := payload["ruleset"].(map[string]any); ok {
		raw = nested
	}
	encoded, err := json.Marshal(raw)
	if err != nil {
		return securityRuleSet{}, err
	}
	var ruleset securityRuleSet
	if err := json.Unmarshal(encoded, &ruleset); err != nil {
		return securityRuleSet{}, err
	}
	if ruleset.Version <= 0 {
		ruleset.Version = 1
	}
	return normalizeSecurityRuleSet(ruleset), nil
}

func readSecurityRuleSetState(path string) (securityRuleSetState, bool) {
	data, err := os.ReadFile(path)
	if err != nil {
		return securityRuleSetState{}, false
	}
	var state securityRuleSetState
	if err := json.Unmarshal(data, &state); err != nil {
		return securityRuleSetState{}, false
	}
	return state, true
}

func writeSecurityRuleSetState(path string, state securityRuleSetState) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return err
	}
	encoded, err := json.MarshalIndent(state, "", "  ")
	if err != nil {
		return err
	}
	return os.WriteFile(path, encoded, 0o640)
}

func securityRuleSetsEqual(left, right securityRuleSet) bool {
	return hashSecurityRuleSet(left) == hashSecurityRuleSet(right)
}

func hashSecurityRuleSet(ruleset securityRuleSet) string {
	canonical := normalizeSecurityRuleSet(ruleset)
	encoded, _ := json.Marshal(canonical)
	checksum := sha256.Sum256(encoded)
	return fmt.Sprintf("%x", checksum)
}

func normalizeSecurityRuleSet(ruleset securityRuleSet) securityRuleSet {
	normalized := securityRuleSet{Version: ruleset.Version, CreatedBy: strings.TrimSpace(ruleset.CreatedBy), Rules: make([]securityRule, 0, len(ruleset.Rules))}
	for idx, rule := range ruleset.Rules {
		n := securityRule{
			ID:        strings.TrimSpace(rule.ID),
			Type:      strings.ToLower(strings.TrimSpace(rule.Type)),
			Action:    strings.ToLower(strings.TrimSpace(rule.Action)),
			Protocol:  strings.ToLower(strings.TrimSpace(rule.Protocol)),
			Port:      normalizePort(rule.Port),
			Priority:  rule.Priority,
			Enabled:   rule.Enabled,
			Reason:    strings.Join(strings.Fields(rule.Reason), " "),
			SourceIP:  normalizeIPorCIDR(rule.SourceIP),
			SourceASN: strings.ToUpper(strings.TrimSpace(rule.SourceASN)),
			Target: securityTarget{
				Scope:   strings.ToLower(strings.TrimSpace(rule.Target.Scope)),
				NodeID:  strings.TrimSpace(rule.Target.NodeID),
				Service: strings.ToLower(strings.TrimSpace(rule.Target.Service)),
			},
		}
		if n.ID == "" {
			n.ID = fmt.Sprintf("rule-%d", idx+1)
		}
		if n.Protocol == "" {
			n.Protocol = "tcp"
		}
		if n.Priority <= 0 {
			n.Priority = 100
		}
		if n.Target.Scope == "" {
			n.Target.Scope = "global"
		}
		normalized.Rules = append(normalized.Rules, n)
	}
	sort.Slice(normalized.Rules, func(i, j int) bool {
		left := normalized.Rules[i]
		right := normalized.Rules[j]
		return fmt.Sprintf("%04d|%s|%s|%s|%05d|%s|%s|%s", left.Priority, left.ID, left.Type, left.Action, left.Port, left.Protocol, left.Target.Scope, left.Target.Service) <
			fmt.Sprintf("%04d|%s|%s|%s|%05d|%s|%s|%s", right.Priority, right.ID, right.Type, right.Action, right.Port, right.Protocol, right.Target.Scope, right.Target.Service)
	})
	return normalized
}

func normalizePort(port int) int {
	if port < 0 {
		return 0
	}
	if port > 65535 {
		return 65535
	}
	return port
}

func normalizeIPorCIDR(raw string) string {
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return ""
	}
	if ip := net.ParseIP(raw); ip != nil {
		return ip.String()
	}
	if _, network, err := net.ParseCIDR(raw); err == nil {
		return network.String()
	}
	return raw
}

func ensureNoSelfLockout(ruleset securityRuleSet) error {
	protectedPorts := map[int]struct{}{22: {}, 8080: {}, 8443: {}, 9443: {}}
	for _, rule := range ruleset.Rules {
		if !rule.Enabled || rule.Type != "firewall" {
			continue
		}
		if rule.Action == "block" {
			if _, blocked := protectedPorts[rule.Port]; blocked {
				return fmt.Errorf("self-lockout protection: port %d cannot be blocked", rule.Port)
			}
		}
	}
	return nil
}

func resolveRuleSetTarget(payload map[string]any) string {
	target := strings.TrimSpace(payloadValue(payload, "target", "target_scope"))
	if target == "" {
		return "global"
	}
	return strings.ToLower(strings.ReplaceAll(target, " ", "_"))
}

func sanitizePathKey(value string) string {
	value = strings.ToLower(strings.TrimSpace(value))
	if value == "" {
		return "default"
	}
	clean := make([]rune, 0, len(value))
	for _, ch := range value {
		if (ch >= 'a' && ch <= 'z') || (ch >= '0' && ch <= '9') || ch == '-' || ch == '_' {
			clean = append(clean, ch)
		} else {
			clean = append(clean, '_')
		}
	}
	return string(clean)
}

func activeRuleSetStatePath(nodeID, target, backend string) string {
	file := fmt.Sprintf("%s__%s__%s.active.json", sanitizePathKey(nodeID), sanitizePathKey(target), sanitizePathKey(backend))
	return filepath.Join(securityRuleSetLkgDir, file)
}

func lastKnownGoodRuleSetStatePath(nodeID, target, backend string) string {
	file := fmt.Sprintf("%s__%s__%s.lkg.json", sanitizePathKey(nodeID), sanitizePathKey(target), sanitizePathKey(backend))
	return filepath.Join(securityRuleSetLkgDir, file)
}

func cleanupLegacySecurityStatePaths() {
	_ = os.Remove(securityRuleSetStatePath)
	_ = os.Remove(securityRuleSetLastGoodPath)
}

func resolveFirewallBackend() string {
	if runtime.GOOS == "windows" {
		return "windows-firewall"
	}
	if commandAvailable("nft") {
		return "nftables"
	}
	if commandAvailable("ufw") {
		return "ufw"
	}
	if commandAvailable("firewall-cmd") {
		return "firewalld"
	}
	if commandAvailable("iptables") {
		return "iptables"
	}
	return "unknown"
}

func uniqueIntSlice(values []int) []int {
	seen := map[int]struct{}{}
	result := make([]int, 0, len(values))
	for _, value := range values {
		if _, ok := seen[value]; ok {
			continue
		}
		seen[value] = struct{}{}
		result = append(result, value)
	}
	sort.Ints(result)
	return result
}

func uniqueStringSlice(values []string) []string {
	seen := map[string]struct{}{}
	result := make([]string, 0, len(values))
	for _, value := range values {
		value = strings.TrimSpace(value)
		if value == "" {
			continue
		}
		if _, ok := seen[value]; ok {
			continue
		}
		seen[value] = struct{}{}
		result = append(result, value)
	}
	sort.Strings(result)
	return result
}
