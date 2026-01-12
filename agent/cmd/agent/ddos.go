package main

import (
	"fmt"
	"runtime"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	ddosModeRateLimit = "rate-limit"
	ddosModeSynCookie = "syn-cookie"
	ddosModeConnLimit = "conn-limit"
	ddosModeOff       = "off"

	ddosChainName     = "EASYWI_DDOS"
	ddosConnThreshold = 20000
	ddosSynThreshold  = 200
)

func handleDdosPolicyApply(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return handleWindowsDdosPolicyApply(job)
	}
	if runtime.GOOS != "linux" {
		return failureResult(job.ID, fmt.Errorf("ddos policy apply is only supported on linux agents"))
	}

	mode := strings.ToLower(payloadValue(job.Payload, "mode"))
	ports, err := ddosPortsFromPayload(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	protocols := ddosProtocolsFromPayload(job.Payload)

	if mode == "" {
		return failureResult(job.ID, fmt.Errorf("mode is required"))
	}
	if ports == nil {
		ports = []int{}
	}
	if protocols == nil {
		protocols = []string{}
	}

	if err := applyDdosPolicy(mode, ports, protocols); err != nil {
		return failureResult(job.ID, err)
	}

	output := map[string]string{
		"mode":       mode,
		"ports":      strings.Join(intSliceToStrings(ports), ","),
		"protocols":  strings.Join(protocols, ","),
		"enabled":    strconv.FormatBool(mode != ddosModeOff),
		"applied_at": time.Now().UTC().Format(time.RFC3339),
		"message":    "ddos policy applied",
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleDdosStatusCheck(job jobs.Job) (jobs.Result, func() error) {
	if runtime.GOOS == "windows" {
		return handleWindowsDdosStatusCheck(job)
	}
	if runtime.GOOS != "linux" {
		return failureResult(job.ID, fmt.Errorf("ddos status check is only supported on linux agents"))
	}

	connCount, _ := ddosConntrackCount()
	synRecv, _ := ddosSynRecvCount()

	attackActive := connCount >= ddosConnThreshold || synRecv >= ddosSynThreshold

	ports, _ := ddosPortsFromPayload(job.Payload)
	protocols := ddosProtocolsFromPayload(job.Payload)
	mode := strings.ToLower(payloadValue(job.Payload, "mode"))

	output := map[string]string{
		"attack_active": strconv.FormatBool(attackActive),
		"conn_count":    strconv.Itoa(connCount),
		"pps":           strconv.Itoa(synRecv),
		"syn_recv":      strconv.Itoa(synRecv),
		"ports":         strings.Join(intSliceToStrings(ports), ","),
		"protocols":     strings.Join(protocols, ","),
		"mode":          mode,
		"reported_at":   time.Now().UTC().Format(time.RFC3339),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleWindowsDdosPolicyApply(job jobs.Job) (jobs.Result, func() error) {
	mode := strings.ToLower(payloadValue(job.Payload, "mode"))
	ports, err := ddosPortsFromPayload(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	protocols := ddosProtocolsFromPayload(job.Payload)

	if mode == "" {
		return failureResult(job.ID, fmt.Errorf("mode is required"))
	}
	if ports == nil {
		ports = []int{}
	}
	if protocols == nil {
		protocols = []string{}
	}

	if err := applyWindowsDdosPolicy(mode, ports, protocols); err != nil {
		return failureResult(job.ID, err)
	}

	output := map[string]string{
		"mode":       mode,
		"ports":      strings.Join(intSliceToStrings(ports), ","),
		"protocols":  strings.Join(protocols, ","),
		"enabled":    strconv.FormatBool(mode != ddosModeOff),
		"applied_at": time.Now().UTC().Format(time.RFC3339),
		"message":    "ddos policy applied",
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleWindowsDdosStatusCheck(job jobs.Job) (jobs.Result, func() error) {
	connCount, synRecv := ddosWindowsNetstatCounts()
	attackActive := connCount >= ddosConnThreshold || synRecv >= ddosSynThreshold

	ports, _ := ddosPortsFromPayload(job.Payload)
	protocols := ddosProtocolsFromPayload(job.Payload)
	mode := strings.ToLower(payloadValue(job.Payload, "mode"))

	output := map[string]string{
		"attack_active": strconv.FormatBool(attackActive),
		"conn_count":    strconv.Itoa(connCount),
		"pps":           strconv.Itoa(synRecv),
		"syn_recv":      strconv.Itoa(synRecv),
		"ports":         strings.Join(intSliceToStrings(ports), ","),
		"protocols":     strings.Join(protocols, ","),
		"mode":          mode,
		"reported_at":   time.Now().UTC().Format(time.RFC3339),
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func applyWindowsDdosPolicy(mode string, ports []int, protocols []string) error {
	mode = strings.ToLower(strings.TrimSpace(mode))
	if mode == "" {
		return fmt.Errorf("invalid ddos mode")
	}

	if mode == ddosModeSynCookie || mode == ddosModeOff {
		if err := setWindowsSynAttackProtect(mode == ddosModeSynCookie); err != nil {
			return err
		}
	}

	if err := clearWindowsDdosRules(); err != nil {
		return err
	}

	if mode == ddosModeOff || mode == ddosModeSynCookie {
		return nil
	}
	if ports == nil || len(ports) == 0 {
		return fmt.Errorf("ports are required for ddos policy")
	}
	if protocols == nil || len(protocols) == 0 {
		return fmt.Errorf("protocols are required for ddos policy")
	}

	return applyWindowsDdosRules(mode, ports, protocols)
}

func applyWindowsDdosRules(mode string, ports []int, protocols []string) error {
	for _, protocol := range protocols {
		protocol = strings.ToUpper(protocol)
		for _, port := range ports {
			ruleName := windowsDdosRuleName(mode, protocol, port)
			if err := runCommand("netsh", "advfirewall", "firewall", "add", "rule", "name="+ruleName, "dir=in", "action=block", "protocol="+protocol, "localport="+strconv.Itoa(port)); err != nil {
				return err
			}
		}
	}
	return nil
}

func clearWindowsDdosRules() error {
	return deleteWindowsFirewallRulesByPrefix("EasyWi DDoS")
}

func windowsDdosRuleName(mode, protocol string, port int) string {
	return fmt.Sprintf("EasyWi DDoS %s %s %d", mode, protocol, port)
}

func deleteWindowsFirewallRulesByPrefix(prefix string) error {
	output, err := runCommandOutput("netsh", "advfirewall", "firewall", "show", "rule", "name=all")
	if err != nil {
		return err
	}
	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "Rule Name:") {
			continue
		}
		name := strings.TrimSpace(strings.TrimPrefix(line, "Rule Name:"))
		if !strings.HasPrefix(name, prefix) {
			continue
		}
		_, _ = runCommandOutput("netsh", "advfirewall", "firewall", "delete", "rule", "name="+name)
	}
	return nil
}

func setWindowsSynAttackProtect(enabled bool) error {
	value := "disabled"
	if enabled {
		value = "enabled"
	}
	return runCommand("netsh", "int", "tcp", "set", "global", "synattackprotect="+value)
}

func ddosWindowsNetstatCounts() (int, int) {
	output, err := runCommandOutput("netstat", "-an")
	if err != nil {
		return 0, 0
	}
	var connCount int
	var synRecv int
	for _, line := range strings.Split(output, "\n") {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 4 {
			continue
		}
		state := strings.ToUpper(fields[len(fields)-1])
		switch state {
		case "ESTABLISHED":
			connCount++
		case "SYN_RECEIVED":
			synRecv++
		}
	}
	return connCount, synRecv
}

func applyDdosPolicy(mode string, ports []int, protocols []string) error {
	mode = strings.ToLower(strings.TrimSpace(mode))
	if mode == "" {
		return fmt.Errorf("invalid ddos mode")
	}

	if mode == ddosModeSynCookie || mode == ddosModeOff {
		if err := applySynCookies(mode == ddosModeSynCookie); err != nil {
			return err
		}
	}

	if mode == ddosModeOff || mode == ddosModeSynCookie {
		return clearDdosRules()
	}

	if ok, err := ensureNftDdosChain(); ok {
		if err != nil {
			return err
		}
		if err := applyNftDdosRules(mode, ports, protocols); err != nil {
			return err
		}
		return nil
	}

	if commandAvailable("iptables") {
		if err := applyIptablesDdosRules(mode, ports, protocols); err != nil {
			return err
		}
		return nil
	}

	return fmt.Errorf("no supported firewall tool found")
}

func applySynCookies(enabled bool) error {
	if !commandAvailable("sysctl") {
		return nil
	}
	value := "0"
	if enabled {
		value = "1"
	}
	return runCommand("sysctl", "-w", "net.ipv4.tcp_syncookies="+value)
}

func ensureNftDdosChain() (bool, error) {
	if !commandAvailable("nft") {
		return false, nil
	}
	if err := runCommandIgnoreExists("nft", "add", "table", "inet", "easywi"); err != nil {
		return true, err
	}
	if err := runCommandIgnoreExists("nft", "add", "chain", "inet", "easywi", "ddos", "{", "type", "filter", "hook", "input", "priority", "-20", ";", "}"); err != nil {
		return true, err
	}
	if err := runCommandIgnoreMissing("nft", "flush", "chain", "inet", "easywi", "ddos"); err != nil {
		return true, err
	}
	return true, nil
}

func applyNftDdosRules(mode string, ports []int, protocols []string) error {
	if mode == ddosModeOff {
		return nil
	}
	if ports == nil || len(ports) == 0 {
		return fmt.Errorf("ports are required for ddos policy")
	}
	if protocols == nil || len(protocols) == 0 {
		return fmt.Errorf("protocols are required for ddos policy")
	}

	for _, protocol := range protocols {
		for _, port := range ports {
			portStr := strconv.Itoa(port)
			switch mode {
			case ddosModeRateLimit:
				if err := runCommand("nft", "add", "rule", "inet", "easywi", "ddos", protocol, "dport", portStr, "ct", "state", "new", "limit", "rate", "100/second", "burst", "200", "packets", "accept"); err != nil {
					return err
				}
				if err := runCommand("nft", "add", "rule", "inet", "easywi", "ddos", protocol, "dport", portStr, "ct", "state", "new", "drop"); err != nil {
					return err
				}
			case ddosModeConnLimit:
				if protocol != "tcp" {
					continue
				}
				if err := runCommand("nft", "add", "rule", "inet", "easywi", "ddos", protocol, "dport", portStr, "ct", "state", "new", "ct", "count", "over", "200", "drop"); err != nil {
					return err
				}
			default:
				return fmt.Errorf("unsupported ddos mode: %s", mode)
			}
		}
	}

	return nil
}

func applyIptablesDdosRules(mode string, ports []int, protocols []string) error {
	if ports == nil || len(ports) == 0 {
		return fmt.Errorf("ports are required for ddos policy")
	}
	if protocols == nil || len(protocols) == 0 {
		return fmt.Errorf("protocols are required for ddos policy")
	}

	if err := runCommandIgnoreExists("iptables", "-N", ddosChainName); err != nil {
		return err
	}
	if err := runCommand("iptables", "-C", "INPUT", "-j", ddosChainName); err != nil {
		if err := runCommand("iptables", "-I", "INPUT", "-j", ddosChainName); err != nil {
			return err
		}
	}
	if err := runCommandIgnoreMissing("iptables", "-F", ddosChainName); err != nil {
		return err
	}

	for _, protocol := range protocols {
		for _, port := range ports {
			portStr := strconv.Itoa(port)
			switch mode {
			case ddosModeRateLimit:
				if err := runCommand("iptables", "-A", ddosChainName, "-p", protocol, "--dport", portStr, "-m", "conntrack", "--ctstate", "NEW", "-m", "limit", "--limit", "100/second", "--limit-burst", "200", "-j", "ACCEPT"); err != nil {
					return err
				}
				if err := runCommand("iptables", "-A", ddosChainName, "-p", protocol, "--dport", portStr, "-m", "conntrack", "--ctstate", "NEW", "-j", "DROP"); err != nil {
					return err
				}
			case ddosModeConnLimit:
				if protocol != "tcp" {
					continue
				}
				if err := runCommand("iptables", "-A", ddosChainName, "-p", "tcp", "--dport", portStr, "-m", "connlimit", "--connlimit-above", "200", "-j", "DROP"); err != nil {
					return err
				}
			default:
				return fmt.Errorf("unsupported ddos mode: %s", mode)
			}
		}
	}

	return nil
}

func clearDdosRules() error {
	if commandAvailable("nft") {
		if _, err := ensureNftDdosChain(); err != nil {
			return err
		}
		return runCommandIgnoreMissing("nft", "flush", "chain", "inet", "easywi", "ddos")
	}
	if commandAvailable("iptables") {
		if err := runCommandIgnoreMissing("iptables", "-F", ddosChainName); err != nil {
			return err
		}
		return nil
	}
	return nil
}

func ddosPortsFromPayload(payload map[string]any) ([]int, error) {
	if raw, ok := payload["ports"]; ok {
		switch typed := raw.(type) {
		case []any:
			ports, err := normalizePayloadPorts(typed)
			if err != nil {
				return nil, err
			}
			return normalizeDdosPorts(ports), nil
		case []int:
			return normalizeDdosPorts(typed), nil
		case []float64:
			ports := make([]int, 0, len(typed))
			for _, entry := range typed {
				ports = append(ports, int(entry))
			}
			return normalizeDdosPorts(ports), nil
		case string:
			ports, err := parsePorts(typed)
			if err != nil {
				return nil, err
			}
			return normalizeDdosPorts(ports), nil
		}
	}

	return []int{}, nil
}

func normalizePayloadPorts(values []any) ([]int, error) {
	ports := make([]int, 0, len(values))
	for _, value := range values {
		switch typed := value.(type) {
		case float64:
			ports = append(ports, int(typed))
		case int:
			ports = append(ports, typed)
		case string:
			parsed, err := strconv.Atoi(strings.TrimSpace(typed))
			if err != nil {
				return nil, fmt.Errorf("invalid port: %s", typed)
			}
			ports = append(ports, parsed)
		}
	}
	return ports, nil
}

func normalizeDdosPorts(ports []int) []int {
	normalized := make([]int, 0, len(ports))
	seen := map[int]bool{}
	for _, port := range ports {
		if port <= 0 || port > 65535 {
			continue
		}
		if seen[port] {
			continue
		}
		seen[port] = true
		normalized = append(normalized, port)
	}
	return normalized
}

func ddosProtocolsFromPayload(payload map[string]any) []string {
	if raw, ok := payload["protocols"]; ok {
		switch typed := raw.(type) {
		case []any:
			values := make([]string, 0, len(typed))
			for _, entry := range typed {
				if value, ok := entry.(string); ok && value != "" {
					values = append(values, strings.ToLower(value))
				}
			}
			return normalizeProtocols(values)
		case []string:
			return normalizeProtocols(typed)
		case string:
			return normalizeProtocols(strings.Split(typed, ","))
		}
	}
	return []string{}
}

func normalizeProtocols(values []string) []string {
	protocols := make([]string, 0, len(values))
	seen := map[string]bool{}
	for _, value := range values {
		normalized := strings.ToLower(strings.TrimSpace(value))
		if normalized == "" {
			continue
		}
		if normalized != "tcp" && normalized != "udp" {
			continue
		}
		if seen[normalized] {
			continue
		}
		seen[normalized] = true
		protocols = append(protocols, normalized)
	}
	return protocols
}

func ddosConntrackCount() (int, error) {
	if !commandAvailable("conntrack") {
		return 0, nil
	}
	output, err := runCommandOutput("conntrack", "-C")
	if err != nil {
		return 0, err
	}
	value := strings.TrimSpace(output)
	if value == "" {
		return 0, nil
	}
	parsed, err := strconv.Atoi(value)
	if err != nil {
		return 0, err
	}
	return parsed, nil
}

func ddosSynRecvCount() (int, error) {
	if !commandAvailable("ss") {
		return 0, nil
	}
	output, err := runCommandOutput("ss", "-H", "-n", "state", "syn-recv")
	if err != nil {
		return 0, err
	}
	lines := strings.Split(strings.TrimSpace(output), "\n")
	if len(lines) == 1 && strings.TrimSpace(lines[0]) == "" {
		return 0, nil
	}
	return len(lines), nil
}
