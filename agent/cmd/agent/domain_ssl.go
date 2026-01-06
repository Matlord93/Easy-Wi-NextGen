package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleDomainSSLIssue(job jobs.Job) (jobs.Result, func() error) {
	domainName := payloadValue(job.Payload, "domain", "hostname", "name")
	webRoot := payloadValue(job.Payload, "web_root", "webroot", "docroot", "document_root", "path")
	serverAliases := payloadValue(job.Payload, "server_aliases", "aliases")
	email := payloadValue(job.Payload, "email", "admin_email", "cert_email")
	certDir := payloadValue(job.Payload, "cert_dir", "cert_path", "certificate_dir")

	missing := missingValues([]requiredValue{
		{key: "domain", value: domainName},
		{key: "web_root", value: webRoot},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	domains := sslDomains(domainName, serverAliases)
	if len(domains) == 0 {
		return failureResult(job.ID, fmt.Errorf("no valid domains to issue certificate"))
	}

	args := []string{"certonly", "--webroot", "--non-interactive", "--agree-tos", "--preferred-challenges", "http", "-w", webRoot}
	if email != "" {
		args = append(args, "--email", email)
	} else {
		args = append(args, "--register-unsafely-without-email")
	}
	for _, name := range domains {
		args = append(args, "-d", name)
	}

	if err := runCommand("certbot", args...); err != nil {
		return failureResult(job.ID, err)
	}

	primaryDomain := domains[0]
	if certDir == "" {
		certDir = filepath.Join("/etc/letsencrypt/live", primaryDomain)
	}

	certPath := filepath.Join(certDir, "cert.pem")
	fullchainPath := filepath.Join(certDir, "fullchain.pem")
	privkeyPath := filepath.Join(certDir, "privkey.pem")

	expiresAt, err := readCertificateExpiry(certPath)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if err := reloadNginx(); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"domain":         primaryDomain,
			"domains":        strings.Join(domains, ","),
			"cert_dir":       certDir,
			"cert_path":      certPath,
			"fullchain_path": fullchainPath,
			"privkey_path":   privkeyPath,
			"expires_at":     expiresAt.UTC().Format(time.RFC3339),
		},
		Completed: time.Now().UTC(),
	}, nil
}

func sslDomains(domainName, serverAliases string) []string {
	var domains []string
	if domainName != "" {
		domains = append(domains, domainName)
	}
	if serverAliases != "" {
		for _, alias := range strings.FieldsFunc(serverAliases, func(r rune) bool {
			return r == ',' || r == ' ' || r == ';'
		}) {
			trimmed := strings.TrimSpace(alias)
			if trimmed == "" {
				continue
			}
			domains = append(domains, trimmed)
		}
	}
	return uniqueStrings(domains)
}

func uniqueStrings(values []string) []string {
	seen := make(map[string]struct{}, len(values))
	unique := make([]string, 0, len(values))
	for _, value := range values {
		if value == "" {
			continue
		}
		if _, ok := seen[value]; ok {
			continue
		}
		seen[value] = struct{}{}
		unique = append(unique, value)
	}
	return unique
}

func readCertificateExpiry(certPath string) (time.Time, error) {
	if _, err := os.Stat(certPath); err != nil {
		return time.Time{}, fmt.Errorf("cert path %s: %w", certPath, err)
	}

	output, err := exec.Command("openssl", "x509", "-enddate", "-noout", "-in", certPath).Output()
	if err != nil {
		return time.Time{}, fmt.Errorf("read certificate expiry: %w", err)
	}

	value := strings.TrimSpace(string(output))
	value = strings.TrimPrefix(value, "notAfter=")
	if value == "" {
		return time.Time{}, fmt.Errorf("certificate expiry not found")
	}

	expiresAt, err := time.Parse("Jan 2 15:04:05 2006 MST", value)
	if err != nil {
		return time.Time{}, fmt.Errorf("parse certificate expiry %q: %w", value, err)
	}

	return expiresAt, nil
}
