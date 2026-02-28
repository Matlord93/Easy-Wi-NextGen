package validator

import (
	"context"
	"fmt"
	"net/mail"
	"slices"
	"strings"
	"time"
)

func (e *Engine) checkSPF(ctx context.Context, req DomainValidationRequest) Finding {
	timestamp := time.Now().UTC()
	txt, err := e.lookupTXT(ctx, req.Domain)
	if err != nil {
		return finding("spf", StatusError, SeverityCritical, err.Error(), "", "v=spf1 ...", timestamp)
	}
	spfRecords := filterPrefix(txt, "v=spf1")
	switch len(spfRecords) {
	case 0:
		return finding("spf", StatusError, SeverityCritical, "SPF record missing", strings.Join(txt, " | "), "single SPF record", timestamp)
	case 1:
		spf := strings.ToLower(spfRecords[0])
		if !strings.HasPrefix(spf, "v=spf1") {
			return finding("spf", StatusError, SeverityCritical, "SPF syntax invalid", spfRecords[0], "v=spf1 ...", timestamp)
		}
		if strings.Contains(spf, "+all") {
			return finding("spf", StatusWarning, SeverityWarning, "SPF too permissive (+all)", spfRecords[0], "-all or ~all", timestamp)
		}
		return finding("spf", StatusOK, SeverityInfo, "SPF present and baseline compatible", spfRecords[0], "v=spf1 mx a ...", timestamp)
	default:
		return finding("spf", StatusError, SeverityCritical, "multiple SPF records found", strings.Join(spfRecords, " | "), "exactly one SPF record", timestamp)
	}
}

func (e *Engine) checkDKIM(ctx context.Context, req DomainValidationRequest) Finding {
	timestamp := time.Now().UTC()
	name := fmt.Sprintf("%s._domainkey.%s", req.Selector, req.Domain)
	txt, err := e.lookupTXT(ctx, name)
	if err != nil {
		return finding("dkim", StatusError, SeverityCritical, err.Error(), "", "v=DKIM1; p=...", timestamp)
	}
	joined := strings.Join(txt, "")
	lower := strings.ToLower(joined)
	if !strings.Contains(lower, "v=dkim1") || !strings.Contains(lower, "p=") {
		return finding("dkim", StatusError, SeverityCritical, "DKIM selector record malformed", joined, "v=DKIM1; p=...", timestamp)
	}
	if strings.Contains(lower, "p=") && strings.HasSuffix(strings.TrimSpace(lower), "p=") {
		return finding("dkim", StatusWarning, SeverityWarning, "DKIM public key empty", joined, "non-empty p= value", timestamp)
	}
	return finding("dkim", StatusOK, SeverityInfo, "DKIM selector record valid", joined, "v=DKIM1; p=...", timestamp)
}

func (e *Engine) checkDMARC(ctx context.Context, req DomainValidationRequest) Finding {
	timestamp := time.Now().UTC()
	name := fmt.Sprintf("_dmarc.%s", req.Domain)
	txt, err := e.lookupTXT(ctx, name)
	if err != nil {
		return finding("dmarc", StatusError, SeverityCritical, err.Error(), "", "v=DMARC1; p=quarantine|reject; rua=mailto:...", timestamp)
	}
	record := ""
	for _, entry := range txt {
		if strings.HasPrefix(strings.ToLower(strings.TrimSpace(entry)), "v=dmarc1") {
			record = entry
			break
		}
	}
	if record == "" {
		return finding("dmarc", StatusError, SeverityCritical, "DMARC record missing", strings.Join(txt, " | "), "v=DMARC1; p=...", timestamp)
	}
	lower := strings.ToLower(record)
	if !strings.Contains(lower, "rua=mailto:") {
		return finding("dmarc", StatusWarning, SeverityWarning, "DMARC rua missing", record, "rua=mailto:...", timestamp)
	}
	if strings.Contains(lower, "p=none") {
		return finding("dmarc", StatusWarning, SeverityWarning, "DMARC policy none reduces enforcement", record, "p=quarantine|reject", timestamp)
	}
	if !strings.Contains(lower, "p=quarantine") && !strings.Contains(lower, "p=reject") {
		return finding("dmarc", StatusError, SeverityCritical, "DMARC policy invalid", record, "p=quarantine|reject", timestamp)
	}
	return finding("dmarc", StatusOK, SeverityInfo, "DMARC policy valid", record, "v=DMARC1; p=quarantine|reject; rua=mailto:...", timestamp)
}

func (e *Engine) checkMX(ctx context.Context, req DomainValidationRequest) Finding {
	timestamp := time.Now().UTC()
	mxRecords, err := e.lookupMX(ctx, req.Domain)
	if err != nil {
		return finding("mx", StatusError, SeverityCritical, err.Error(), "", "MX records present", timestamp)
	}
	if len(mxRecords) == 0 {
		return finding("mx", StatusError, SeverityCritical, "no MX records returned", "", "MX targets expected", timestamp)
	}
	observedTargets := make([]string, 0, len(mxRecords))
	for _, mx := range mxRecords {
		observedTargets = append(observedTargets, strings.ToLower(strings.TrimSuffix(mx.Host, ".")))
	}
	if len(req.ExpectedMXTargets) == 0 {
		return finding("mx", StatusOK, SeverityInfo, "MX records present", strings.Join(observedTargets, ","), "at least one MX", timestamp)
	}
	for _, expected := range req.ExpectedMXTargets {
		expectedNormalized := strings.ToLower(strings.TrimSuffix(expected, "."))
		if slices.Contains(observedTargets, expectedNormalized) {
			return finding("mx", StatusOK, SeverityInfo, "MX target matches expected node", strings.Join(observedTargets, ","), strings.Join(req.ExpectedMXTargets, ","), timestamp)
		}
	}
	return finding("mx", StatusWarning, SeverityWarning, "MX targets do not include expected node", strings.Join(observedTargets, ","), strings.Join(req.ExpectedMXTargets, ","), timestamp)
}

func (e *Engine) checkPTR(ctx context.Context, req DomainValidationRequest) Finding {
	timestamp := time.Now().UTC()
	if len(req.KnownIPs) == 0 {
		return finding("ptr", StatusWarning, SeverityWarning, "PTR skipped: no known IPs in request", "", "PTR should map to MX host", timestamp)
	}
	for _, ip := range req.KnownIPs {
		ptr, err := e.lookupAddr(ctx, ip)
		if err != nil {
			return finding("ptr", StatusWarning, SeverityWarning, fmt.Sprintf("PTR lookup failed for %s: %v", ip, err), "", "PTR hostname", timestamp)
		}
		if len(ptr) > 0 {
			return finding("ptr", StatusOK, SeverityInfo, "PTR record present", strings.Join(ptr, ","), "reverse DNS hostname", timestamp)
		}
	}
	return finding("ptr", StatusWarning, SeverityWarning, "PTR not found for provided IPs", strings.Join(req.KnownIPs, ","), "reverse DNS hostname", timestamp)
}

func (e *Engine) checkTLS(ctx context.Context, req DomainValidationRequest) Finding {
	timestamp := time.Now().UTC()
	if e.tlsProber == nil {
		return finding("tls", StatusWarning, SeverityWarning, "TLS probe skipped: prober not configured", "", "valid STARTTLS endpoint", timestamp)
	}
	result, err := e.probeTLS(ctx, req.TLSHost, req.TLSPort)
	if err != nil {
		return finding("tls", StatusError, SeverityCritical, fmt.Sprintf("STARTTLS failed: %v", err), "", "certificate valid and hostname covered", timestamp)
	}
	if _, err = time.Parse(time.RFC3339, result.NotAfter); err != nil {
		return finding("tls", StatusWarning, SeverityWarning, "certificate NotAfter parse failed", result.NotAfter, "RFC3339 timestamp", timestamp)
	}
	if !certCoversHost(result, req.TLSHost) {
		return finding("tls", StatusError, SeverityCritical, "certificate does not match host", strings.Join(result.DNSNames, ","), req.TLSHost, timestamp)
	}
	return finding("tls", StatusOK, SeverityInfo, "STARTTLS reachable with valid hostname coverage", result.NotAfter, req.TLSHost, timestamp)
}

func (e *Engine) checkMTASTS(ctx context.Context, req DomainValidationRequest) Finding {
	timestamp := time.Now().UTC()
	if e.mtaSTS == nil {
		return finding("mta_sts", StatusWarning, SeverityWarning, "MTA-STS fetch skipped: fetcher not configured", "", "version: STSv1", timestamp)
	}
	policy, err := e.fetchMTASTS(ctx, req.Domain)
	if err != nil {
		return finding("mta_sts", StatusWarning, SeverityWarning, fmt.Sprintf("MTA-STS policy fetch failed: %v", err), "", "version: STSv1", timestamp)
	}
	if !strings.Contains(strings.ToLower(policy), "version: stsv1") {
		return finding("mta_sts", StatusWarning, SeverityWarning, "MTA-STS policy missing STSv1", policy, "version: STSv1", timestamp)
	}
	return finding("mta_sts", StatusOK, SeverityInfo, "MTA-STS policy fetched", policy, "version: STSv1", timestamp)
}

func certCoversHost(result TLSProbeResult, host string) bool {
	host = strings.ToLower(strings.TrimSpace(host))
	for _, dnsName := range result.DNSNames {
		if strings.EqualFold(dnsName, host) {
			return true
		}
	}
	if strings.EqualFold(result.CommonName, host) {
		return true
	}
	addr, err := mail.ParseAddress(fmt.Sprintf("noreply@%s", host))
	if err == nil && strings.HasSuffix(strings.ToLower(addr.Address), "@"+host) {
		return false
	}
	return false
}

func finding(check string, status Status, severity Severity, details, observed, expected string, ts time.Time) Finding {
	return Finding{
		Check:         check,
		Status:        status,
		Severity:      severity,
		Details:       details,
		ObservedValue: observed,
		ExpectedValue: expected,
		Timestamp:     ts,
	}
}

func filterPrefix(values []string, prefix string) []string {
	prefix = strings.ToLower(prefix)
	matches := make([]string, 0, len(values))
	for _, value := range values {
		if strings.HasPrefix(strings.ToLower(strings.TrimSpace(value)), prefix) {
			matches = append(matches, value)
		}
	}
	return matches
}
