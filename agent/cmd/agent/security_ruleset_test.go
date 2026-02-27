package main

import "testing"

func TestSecurityRuleSetValidationRejectsUnsafeService(t *testing.T) {
	ruleset := securityRuleSet{Version: 1, Rules: []securityRule{{ID: "1", Type: "fail2ban", Action: "ban", Enabled: true, Target: securityTarget{Service: "ssh.*"}}}}
	if err := validateSecurityRuleSet(ruleset); err == nil {
		t.Fatalf("expected validation error for unsafe service")
	}
}

func TestSecurityRuleSetComparisonIdempotent(t *testing.T) {
	left := securityRuleSet{Version: 2, Rules: []securityRule{{ID: "b", Type: "firewall", Action: "allow", Port: 443, Enabled: true}, {ID: "a", Type: "firewall", Action: "allow", Port: 80, Enabled: true}}}
	right := securityRuleSet{Version: 2, Rules: []securityRule{{ID: "a", Type: "firewall", Action: "allow", Port: 80, Enabled: true}, {ID: "b", Type: "firewall", Action: "allow", Port: 443, Enabled: true}}}
	if !securityRuleSetsEqual(left, right) {
		t.Fatalf("expected equal rulesets")
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
