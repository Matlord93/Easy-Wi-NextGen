package main

import (
	"encoding/json"
	"fmt"
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
	RuleSet   securityRuleSet `json:"ruleset"`
}

const securityRuleSetStatePath = "/var/lib/easywi/security/ruleset_state.json"
const securityRuleSetLastGoodPath = "/var/lib/easywi/security/ruleset_last_good.json"

func handleSecurityRuleSetApply(job jobs.Job) (jobs.Result, func() error) {
	ruleset, err := parseSecurityRuleSet(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := validateSecurityRuleSet(ruleset); err != nil {
		return failureResult(job.ID, err)
	}

	current, _ := readSecurityRuleSetState(securityRuleSetStatePath)
	if securityRuleSetsEqual(current.RuleSet, ruleset) {
		return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"message": "ruleset already active", "changed": "false", "version": strconv.Itoa(ruleset.Version)}, Completed: time.Now().UTC()}, nil
	}

	if current.RuleSet.Version > 0 {
		_ = writeSecurityRuleSetState(securityRuleSetLastGoodPath, current)
	}

	if err := applySecurityRuleSet(ruleset); err != nil {
		if previous, ok := readSecurityRuleSetState(securityRuleSetLastGoodPath); ok {
			_ = applySecurityRuleSet(previous.RuleSet)
			_ = writeSecurityRuleSetState(securityRuleSetStatePath, previous)
		}
		return failureResult(job.ID, err)
	}

	state := securityRuleSetState{AppliedAt: time.Now().UTC().Format(time.RFC3339), RuleSet: ruleset}
	if err := writeSecurityRuleSetState(securityRuleSetStatePath, state); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"message": "ruleset applied", "changed": "true", "version": strconv.Itoa(ruleset.Version), "active_backend": resolveFirewallBackend(), "active_rules": strconv.Itoa(len(ruleset.Rules))}, Completed: time.Now().UTC()}, nil
}

func handleSecurityRuleSetRollback(job jobs.Job) (jobs.Result, func() error) {
	lastGood, ok := readSecurityRuleSetState(securityRuleSetLastGoodPath)
	if !ok {
		return failureResult(job.ID, fmt.Errorf("no last known good ruleset found"))
	}
	if err := applySecurityRuleSet(lastGood.RuleSet); err != nil {
		return failureResult(job.ID, fmt.Errorf("rollback failed: %w", err))
	}
	if err := writeSecurityRuleSetState(securityRuleSetStatePath, lastGood); err != nil {
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
					events = append(events, map[string]any{"source": "fail2ban", "direction": "blocked", "rule": jail, "ip": ip, "reason": "jail:" + jail})
				}
			}
			if encoded, err := json.Marshal(events); err == nil {
				banned = string(encoded)
			}
		}
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"events": banned, "collected_at": time.Now().UTC().Format(time.RFC3339)}, Completed: time.Now().UTC()}, nil
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
	return ruleset, nil
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
	leftRules := append([]securityRule{}, left.Rules...)
	rightRules := append([]securityRule{}, right.Rules...)
	sort.Slice(leftRules, func(i, j int) bool { return leftRules[i].ID < leftRules[j].ID })
	sort.Slice(rightRules, func(i, j int) bool { return rightRules[i].ID < rightRules[j].ID })
	leftEncoded, _ := json.Marshal(leftRules)
	rightEncoded, _ := json.Marshal(rightRules)
	return string(leftEncoded) == string(rightEncoded)
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
