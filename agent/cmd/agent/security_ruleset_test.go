package main

import (
	"strings"
	"testing"
)

func TestSecurityRuleSetValidationRejectsUnsafeService(t *testing.T) {
	ruleset := securityRuleSet{Version: 1, Rules: []securityRule{{ID: "1", Type: "fail2ban", Action: "ban", Enabled: true, Target: securityTarget{Service: "ssh.*"}}}}
	if err := validateSecurityRuleSet(ruleset); err == nil {
		t.Fatalf("expected validation error for unsafe service")
	}
}

func TestSecurityRuleSetValidationRejectsLockoutPortBlock(t *testing.T) {
	ruleset := securityRuleSet{Version: 1, Rules: []securityRule{{ID: "1", Type: "firewall", Action: "block", Port: 22, Enabled: true}}}
	if err := validateSecurityRuleSet(ruleset); err == nil {
		t.Fatalf("expected self-lockout validation error")
	}
}

func TestSecurityRuleSetComparisonIdempotent(t *testing.T) {
	left := securityRuleSet{Version: 2, Rules: []securityRule{{ID: "b", Type: "FIREWALL", Action: "allow", Port: 443, Enabled: true, SourceIP: "10.0.0.1/24"}, {ID: "a", Type: "firewall", Action: "allow", Port: 80, Enabled: true, SourceIP: "10.0.0.0/24"}}}
	right := securityRuleSet{Version: 2, Rules: []securityRule{{ID: "a", Type: "firewall", Action: "allow", Port: 80, Enabled: true, SourceIP: "10.0.0.0/24"}, {ID: "b", Type: "firewall", Action: "allow", Port: 443, Enabled: true, SourceIP: "10.0.0.0/24"}}}
	if !securityRuleSetsEqual(left, right) {
		t.Fatalf("expected equal rulesets")
	}
}

func TestNormalizeSecurityRuleSetCanonicalizesCIDRAndStrings(t *testing.T) {
	ruleset := securityRuleSet{Version: 1, CreatedBy: " Admin ", Rules: []securityRule{{Type: " FIREWALL ", Action: " ALLOW ", Protocol: " TCP ", Port: 80, Enabled: true, Reason: "hello\n world", SourceIP: "10.0.0.1/24", Target: securityTarget{Scope: " Global ", Service: " SSHD "}}}}
	normalized := normalizeSecurityRuleSet(ruleset)
	if normalized.CreatedBy != "Admin" {
		t.Fatalf("unexpected creator normalization: %q", normalized.CreatedBy)
	}
	if normalized.Rules[0].SourceIP != "10.0.0.0/24" {
		t.Fatalf("unexpected cidr normalization: %q", normalized.Rules[0].SourceIP)
	}
	if normalized.Rules[0].Type != "firewall" || normalized.Rules[0].Target.Service != "sshd" {
		t.Fatalf("unexpected string normalization")
	}
}

func TestStatePathsIncludeNodeTargetBackend(t *testing.T) {
	active := activeRuleSetStatePath("Node-1", "Global Scope", "nftables")
	lkg := lastKnownGoodRuleSetStatePath("Node-1", "Global Scope", "nftables")
	if !strings.Contains(active, "node-1__global_scope__nftables") {
		t.Fatalf("unexpected active state path: %s", active)
	}
	if !strings.Contains(lkg, "node-1__global_scope__nftables") {
		t.Fatalf("unexpected lkg state path: %s", lkg)
	}
}

func TestUniqueIntSliceSorted(t *testing.T) {
	ports := uniqueIntSlice([]int{443, 80, 443, 22})
	expected := []int{22, 80, 443}
	if len(ports) != len(expected) {
		t.Fatalf("unexpected size")
	}
	for i := range ports {
		if ports[i] != expected[i] {
			t.Fatalf("unexpected value at %d", i)
		}
	}
}
