package main

import (
	"encoding/json"
	"fmt"
	"os/exec"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleFirewallOpen(jobID string, payload map[string]any) (jobs.Result, func() error) {
	ports, err := portsFromPayload(payload)
	if err != nil {
		return failureResult(jobID, err)
	}

	if err := openPorts(ports); err != nil {
		return failureResult(jobID, err)
	}

	return jobs.Result{
		JobID:     jobID,
		Status:    "success",
		Output:    firewallRuleOutput(ports, "open"),
		Completed: time.Now().UTC(),
	}, nil
}

func handleFirewallClose(jobID string, payload map[string]any) (jobs.Result, func() error) {
	ports, err := portsFromPayload(payload)
	if err != nil {
		return failureResult(jobID, err)
	}

	if err := closePorts(ports); err != nil {
		return failureResult(jobID, err)
	}

	return jobs.Result{
		JobID:     jobID,
		Status:    "success",
		Output:    firewallRuleOutput(ports, "closed"),
		Completed: time.Now().UTC(),
	}, nil
}

type firewallRule struct {
	Port     int    `json:"port"`
	Protocol string `json:"protocol"`
	Status   string `json:"status"`
}

func firewallRuleOutput(ports []int, status string) map[string]string {
	output := map[string]string{
		"ports": strings.Join(intSliceToStrings(ports), ","),
	}
	if len(ports) == 0 {
		return output
	}

	rules := make([]firewallRule, 0, len(ports)*2)
	for _, port := range ports {
		rules = append(rules, firewallRule{Port: port, Protocol: "tcp", Status: status})
		rules = append(rules, firewallRule{Port: port, Protocol: "udp", Status: status})
	}

	encoded, err := json.Marshal(rules)
	if err != nil {
		return output
	}
	output["rules"] = string(encoded)
	return output
}

func portsFromPayload(payload map[string]any) ([]int, error) {
	portsRaw := payloadValue(payload, "port_block_ports", "ports")
	if portsRaw == "" {
		return []int{}, nil
	}
	return parsePorts(portsRaw)
}

func parsePorts(raw string) ([]int, error) {
	fields := strings.FieldsFunc(raw, func(r rune) bool {
		return r == ',' || r == ';' || r == ' ' || r == '\n' || r == '\t'
	})
	ports := make([]int, 0, len(fields))
	for _, field := range fields {
		field = strings.TrimSpace(field)
		if field == "" {
			continue
		}
		port, err := strconv.Atoi(field)
		if err != nil {
			return nil, fmt.Errorf("invalid port: %s", field)
		}
		if port <= 0 || port > 65535 {
			return nil, fmt.Errorf("port out of range: %d", port)
		}
		ports = append(ports, port)
	}
	return ports, nil
}

func openPorts(ports []int) error {
	if len(ports) == 0 {
		return nil
	}

	if ok, err := ensureNft(); ok {
		if err != nil {
			return err
		}
		for _, port := range ports {
			portStr := strconv.Itoa(port)
			if err := runCommandIgnoreExists("nft", "add", "rule", "inet", "easywi", "input", "tcp", "dport", portStr, "accept"); err != nil {
				return err
			}
			if err := runCommandIgnoreExists("nft", "add", "rule", "inet", "easywi", "input", "udp", "dport", portStr, "accept"); err != nil {
				return err
			}
		}
		return nil
	}

	if commandAvailable("ufw") {
		for _, port := range ports {
			if err := runCommand("ufw", "allow", strconv.Itoa(port)); err != nil {
				return err
			}
		}
		return nil
	}

	if commandAvailable("firewall-cmd") {
		for _, port := range ports {
			if err := runCommand("firewall-cmd", "--add-port", fmt.Sprintf("%d/tcp", port), "--permanent"); err != nil {
				return err
			}
			if err := runCommand("firewall-cmd", "--add-port", fmt.Sprintf("%d/udp", port), "--permanent"); err != nil {
				return err
			}
		}
		return runCommand("firewall-cmd", "--reload")
	}

	if commandAvailable("iptables") {
		for _, port := range ports {
			portStr := strconv.Itoa(port)
			if err := runCommand("iptables", "-C", "INPUT", "-p", "tcp", "--dport", portStr, "-j", "ACCEPT"); err != nil {
				if err := runCommand("iptables", "-I", "INPUT", "-p", "tcp", "--dport", portStr, "-j", "ACCEPT"); err != nil {
					return err
				}
			}
			if err := runCommand("iptables", "-C", "INPUT", "-p", "udp", "--dport", portStr, "-j", "ACCEPT"); err != nil {
				if err := runCommand("iptables", "-I", "INPUT", "-p", "udp", "--dport", portStr, "-j", "ACCEPT"); err != nil {
					return err
				}
			}
		}
		return nil
	}

	return fmt.Errorf("no supported firewall tool found")
}

func closePorts(ports []int) error {
	if len(ports) == 0 {
		return nil
	}

	if ok, err := ensureNft(); ok {
		if err != nil {
			return err
		}
		for _, port := range ports {
			portStr := strconv.Itoa(port)
			if err := runCommandIgnoreMissing("nft", "delete", "rule", "inet", "easywi", "input", "tcp", "dport", portStr, "accept"); err != nil {
				return err
			}
			if err := runCommandIgnoreMissing("nft", "delete", "rule", "inet", "easywi", "input", "udp", "dport", portStr, "accept"); err != nil {
				return err
			}
		}
		return nil
	}

	if commandAvailable("ufw") {
		for _, port := range ports {
			if err := runCommandIgnoreMissing("ufw", "--force", "delete", "allow", strconv.Itoa(port)); err != nil {
				return err
			}
		}
		return nil
	}

	if commandAvailable("firewall-cmd") {
		for _, port := range ports {
			if err := runCommandIgnoreMissing("firewall-cmd", "--remove-port", fmt.Sprintf("%d/tcp", port), "--permanent"); err != nil {
				return err
			}
			if err := runCommandIgnoreMissing("firewall-cmd", "--remove-port", fmt.Sprintf("%d/udp", port), "--permanent"); err != nil {
				return err
			}
		}
		return runCommand("firewall-cmd", "--reload")
	}

	if commandAvailable("iptables") {
		for _, port := range ports {
			portStr := strconv.Itoa(port)
			if err := runCommandIgnoreMissing("iptables", "-D", "INPUT", "-p", "tcp", "--dport", portStr, "-j", "ACCEPT"); err != nil {
				return err
			}
			if err := runCommandIgnoreMissing("iptables", "-D", "INPUT", "-p", "udp", "--dport", portStr, "-j", "ACCEPT"); err != nil {
				return err
			}
		}
		return nil
	}

	return fmt.Errorf("no supported firewall tool found")
}

func ensureNft() (bool, error) {
	if !commandAvailable("nft") {
		return false, nil
	}
	if err := runCommandIgnoreExists("nft", "add", "table", "inet", "easywi"); err != nil {
		return true, err
	}
	if err := runCommandIgnoreExists("nft", "add", "chain", "inet", "easywi", "input", "{", "type", "filter", "hook", "input", "priority", "0", ";", "}"); err != nil {
		return true, err
	}
	return true, nil
}

func commandAvailable(name string) bool {
	_, err := exec.LookPath(name)
	return err == nil
}

func runCommandIgnoreExists(name string, args ...string) error {
	return runCommandWithIgnore(name, args, []string{"exists", "File exists", "already"})
}

func runCommandIgnoreMissing(name string, args ...string) error {
	return runCommandWithIgnore(name, args, []string{"No such", "not found", "does not exist"})
}

func runCommandWithIgnore(name string, args []string, ignore []string) error {
	cmd := exec.Command(name, args...)
	output, err := cmd.CombinedOutput()
	if err == nil {
		return nil
	}
	outputStr := strings.TrimSpace(string(output))
	for _, entry := range ignore {
		if strings.Contains(outputStr, entry) {
			return nil
		}
	}
	return fmt.Errorf("%s %s failed: %w (%s)", name, strings.Join(args, " "), err, outputStr)
}
