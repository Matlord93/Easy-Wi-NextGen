package main

import (
	"context"
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
	"easywi/agent/internal/mail/validator"
)

func handleMailDNSValidate(job jobs.Job) (jobs.Result, func() error) {
	domain := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "domain")))
	selector := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "selector", "dkim_selector")))
	if domain == "" {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing domain"},
			Completed: time.Now().UTC(),
		}, nil
	}

	tlsHost := payloadValue(job.Payload, "tls_host", "smtp_host")
	if tlsHost == "" {
		tlsHost = domain
	}
	tlsPort := parseIntDefault(payloadValue(job.Payload, "tls_port", "smtp_port"), 587)

	engine := validator.NewEngine(
		validator.NewNetResolver(),
		validator.NewSTARTTLSProber(),
		validator.NewMTASTSFetcher(),
		validator.NewGlobalLimiter(20),
		validator.NewExponentialBackoff(250*time.Millisecond),
	)

	req := validator.DomainValidationRequest{
		Domain:            domain,
		Selector:          selector,
		ExpectedMXTargets: splitCSV(payloadValue(job.Payload, "expected_mx_targets")),
		KnownIPs:          splitCSV(payloadValue(job.Payload, "known_ips")),
		TLSHost:           tlsHost,
		TLSPort:           tlsPort,
		MTASTSEnabled:     parseBoolDefault(payloadValue(job.Payload, "mta_sts_enabled"), false),
		Timeout:           15 * time.Second,
	}

	ctx, cancel := context.WithTimeout(context.Background(), 25*time.Second)
	defer cancel()

	result, err := engine.ValidateDomain(ctx, req)
	if err != nil {
		return failureResult(job.ID, err)
	}

	findingsJSON, err := json.Marshal(result.Findings)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("marshal findings: %w", err))
	}

	resultJSON, err := json.Marshal(result)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("marshal result: %w", err))
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"domain":              result.Domain,
			"dkim_status":         string(result.DkimStatus),
			"spf_status":          string(result.SpfStatus),
			"dmarc_status":        string(result.DmarcStatus),
			"mx_status":           string(result.MxStatus),
			"tls_status":          string(result.TLSStatus),
			"dns_last_checked_at": result.DnsLastCheckedAt.UTC().Format(time.RFC3339),
			"findings_json":       string(findingsJSON),
			"validation_json":     string(resultJSON),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func splitCSV(raw string) []string {
	if strings.TrimSpace(raw) == "" {
		return nil
	}
	parts := strings.Split(raw, ",")
	out := make([]string, 0, len(parts))
	for _, part := range parts {
		item := strings.TrimSpace(part)
		if item != "" {
			out = append(out, item)
		}
	}
	return out
}

func parseIntDefault(raw string, fallback int) int {
	if strings.TrimSpace(raw) == "" {
		return fallback
	}
	value, err := strconv.Atoi(strings.TrimSpace(raw))
	if err != nil || value <= 0 {
		return fallback
	}
	return value
}

func parseBoolDefault(raw string, fallback bool) bool {
	switch strings.ToLower(strings.TrimSpace(raw)) {
	case "1", "true", "yes", "on":
		return true
	case "0", "false", "no", "off":
		return false
	default:
		return fallback
	}
}
