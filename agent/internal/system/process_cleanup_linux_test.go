//go:build linux

package system

import "testing"

func TestCmdlineMatchesAgentConfigRequiresExactBinaryAndConfig(t *testing.T) {
	if !cmdlineMatchesAgentConfig([]string{"/usr/local/bin/easywi-agent", "--config", "/etc/easywi/agent.conf"}, "/etc/easywi/agent.conf") {
		t.Fatal("expected service command line to match")
	}
	if !cmdlineMatchesAgentConfig([]string{"/usr/local/bin/easywi-agent", "--config=/etc/easywi/agent.conf"}, "/etc/easywi/agent.conf") {
		t.Fatal("expected equals-style config command line to match")
	}
}

func TestCmdlineMatchesAgentConfigRejectsUnrelatedProcesses(t *testing.T) {
	cases := [][]string{
		{"/usr/local/bin/easywi-agent"},
		{"/usr/local/bin/easywi-agent", "--config", "/tmp/agent.conf"},
		{"/tmp/easywi-agent", "--config", "/etc/easywi/agent.conf"},
		{"/usr/local/bin/easywi-agent", "--wrapper", "--config", "/etc/easywi/agent.conf"},
		{"/usr/local/bin/easywi-agent", "--version"},
	}
	for _, args := range cases {
		if cmdlineMatchesAgentConfig(args, "/etc/easywi/agent.conf") {
			t.Fatalf("cmdlineMatchesAgentConfig(%v) = true, want false", args)
		}
	}
}
