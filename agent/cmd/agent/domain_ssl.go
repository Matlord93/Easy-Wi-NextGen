package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

var validEmailRegex = regexp.MustCompile(`^[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}$`)

const certRenewThreshold = 14 * 24 * time.Hour

func handleDomainSSLIssue(job jobs.Job) (jobs.Result, func() error) {
	domainName := payloadValue(job.Payload, "domain", "hostname", "name")
	payloadWebRoot := payloadValue(job.Payload, "web_root", "webroot", "docroot", "document_root", "path")
	serverAliases := payloadValue(job.Payload, "server_aliases", "aliases")
	email := payloadValue(job.Payload, "email", "admin_email", "cert_email")

	if strings.TrimSpace(domainName) == "" {
		return failureStepResult(job.ID, "validation", "missing required values: domain", nil)
	}
	domains := sslDomains(domainName, serverAliases)
	if len(domains) == 0 {
		return failureStepResult(job.ID, "validation", "no valid domains to issue certificate", nil)
	}
	if email != "" && !validEmailRegex.MatchString(email) {
		return failureStepResult(job.ID, "validation", fmt.Sprintf("invalid email address: %q", email), nil)
	}
	result, err := issueOrReuseCertificate(domainName, serverAliases, email, payloadWebRoot)
	if err != nil {
		return failureStepResult(job.ID, err.Step, err.Message, err.Details)
	}
	return successSSLResult(job.ID, result.Domain, result.Domains, result.Webroot, result.CertDir, result.CertPath, result.FullchainPath, result.PrivkeyPath, result.ExpiresAt, true, result.NginxReloaded)
}

func handleDomainSSLRenew(job jobs.Job) (jobs.Result, func() error) {
	if err := ensureCertbotAvailable(); err != nil {
		return failureResult(job.ID, err)
	}
	domainName := payloadValue(job.Payload, "domain", "hostname", "name")
	if strings.TrimSpace(domainName) == "" {
		if err := runCommand("certbot", "renew", "--non-interactive"); err != nil {
			return failureResult(job.ID, err)
		}
		_ = reloadNginx()
		return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"action": "renew", "scope": "all"}, Completed: time.Now().UTC()}, nil
	}
	payloadWebRoot := payloadValue(job.Payload, "web_root", "webroot", "docroot", "document_root", "path")
	email := payloadValue(job.Payload, "email", "admin_email", "cert_email")
	result, certErr := issueOrReuseCertificate(domainName, "", email, payloadWebRoot)
	if certErr != nil {
		return failureStepResult(job.ID, certErr.Step, certErr.Message, certErr.Details)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"action": "renew", "scope": "single", "domain": domainName, "webroot": result.Webroot}, Completed: time.Now().UTC()}, nil
}

type certificateResult struct {
	Domain        string
	Domains       []string
	Webroot       string
	CertDir       string
	CertPath      string
	FullchainPath string
	PrivkeyPath   string
	ExpiresAt     time.Time
	NginxReloaded bool
}

type stepFailure struct {
	Step    string
	Message string
	Details map[string]string
}

func issueOrReuseCertificate(domain, aliases, email, payloadWebRoot string) (certificateResult, *stepFailure) {
	if err := ensureCertbotAvailable(); err != nil {
		return certificateResult{}, &stepFailure{Step: "certbot_check", Message: err.Error(), Details: map[string]string{"hint": "install certbot (apt-get install -y certbot)"}}
	}
	domains := sslDomains(domain, aliases)
	primaryDomain := domains[0]
	certDir := filepath.Join("/etc/letsencrypt/live", primaryDomain)
	certPath := filepath.Join(certDir, "cert.pem")
	fullchainPath := filepath.Join(certDir, "fullchain.pem")
	privkeyPath := filepath.Join(certDir, "privkey.pem")

	webRoot, nginxConfig, err := bestWebrootForDomain(primaryDomain, payloadWebRoot)
	if err != nil {
		return certificateResult{}, &stepFailure{Step: "webroot_detect", Message: err.Error(), Details: map[string]string{"webroot": payloadWebRoot}}
	}
	if existingExpiry, ok := existingCertificateExpiry(certPath, fullchainPath, privkeyPath); ok && time.Until(existingExpiry) > certRenewThreshold {
		reloaded, reloadErr := ensureNginxSSLForDomain(primaryDomain, webRoot, fullchainPath, privkeyPath)
		if reloadErr != nil {
			return certificateResult{}, &stepFailure{Step: "nginx_ssl_config", Message: reloadErr.Error()}
		}
		return certificateResult{Domain: primaryDomain, Domains: domains, Webroot: webRoot, CertDir: certDir, CertPath: certPath, FullchainPath: fullchainPath, PrivkeyPath: privkeyPath, ExpiresAt: existingExpiry, NginxReloaded: reloaded}, nil
	}
	testedURL, err := testACMEPath(primaryDomain, webRoot)
	if err != nil {
		return certificateResult{}, &stepFailure{Step: "acme_challenge_check", Message: err.Error(), Details: map[string]string{"webroot": webRoot, "tested_url": testedURL, "nginx_config": nginxConfig}}
	}
	args := []string{"certonly", "--webroot", "--non-interactive", "--agree-tos", "--preferred-challenges", "http", "--keep-until-expiring", "--cert-name", primaryDomain, "-w", webRoot}
	if email != "" {
		args = append(args, "--email", email)
	} else {
		args = append(args, "--register-unsafely-without-email")
	}
	for _, name := range domains {
		args = append(args, "-d", name)
	}
	if err := runCommand("certbot", args...); err != nil {
		return certificateResult{}, &stepFailure{Step: "certbot_issue", Message: err.Error(), Details: map[string]string{"webroot": webRoot}}
	}
	expiresAt, err := readCertificateExpiry(certPath)
	if err != nil {
		return certificateResult{}, &stepFailure{Step: "certificate_read", Message: err.Error()}
	}
	reloaded, reloadErr := ensureNginxSSLForDomain(primaryDomain, webRoot, fullchainPath, privkeyPath)
	if reloadErr != nil {
		return certificateResult{}, &stepFailure{Step: "nginx_ssl_config", Message: reloadErr.Error()}
	}
	return certificateResult{Domain: primaryDomain, Domains: domains, Webroot: webRoot, CertDir: certDir, CertPath: certPath, FullchainPath: fullchainPath, PrivkeyPath: privkeyPath, ExpiresAt: expiresAt, NginxReloaded: reloaded}, nil
}

// rest unchanged helpers + new helper stubs
func handleDomainSSLRevoke(job jobs.Job) (jobs.Result, func() error) { /* unchanged */
	if err := ensureCertbotAvailable(); err != nil {
		return failureResult(job.ID, err)
	}
	domainName := strings.TrimSpace(payloadValue(job.Payload, "domain", "hostname", "name"))
	certPath := payloadValue(job.Payload, "cert_path")
	if certPath == "" && domainName != "" {
		certPath = filepath.Join("/etc/letsencrypt/live", domainName, "cert.pem")
	}
	if certPath == "" {
		return failureResult(job.ID, fmt.Errorf("cert_path or domain is required"))
	}
	if err := runCommand("certbot", "revoke", "--non-interactive", "--cert-path", certPath); err != nil {
		return failureResult(job.ID, err)
	}
	_ = reloadNginx()
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"action": "revoke", "domain": domainName, "cert_path": certPath}, Completed: time.Now().UTC()}, nil
}

func existingCertificateExpiry(certPath, fullchainPath, privkeyPath string) (time.Time, bool) {
	if _, e := os.Stat(fullchainPath); e != nil {
		return time.Time{}, false
	}
	if _, e := os.Stat(privkeyPath); e != nil {
		return time.Time{}, false
	}
	t, e := readCertificateExpiry(certPath)
	if e != nil {
		return time.Time{}, false
	}
	return t, true
}
func failureStepResult(jobID, step, message string, details map[string]string) (jobs.Result, func() error) {
	out := map[string]string{"success": "false", "step": step, "message": message}
	for k, v := range details {
		out[k] = v
	}
	return jobs.Result{JobID: jobID, Status: "failed", Output: out, Completed: time.Now().UTC()}, nil
}
func successSSLResult(jobID, domain string, domains []string, webroot, certDir, certPath, fullchainPath, privkeyPath string, expiresAt time.Time, sslEnabled, reloaded bool) (jobs.Result, func() error) {
	return jobs.Result{JobID: jobID, Status: "success", Output: map[string]string{"success": "true", "domain": domain, "domains": strings.Join(domains, ","), "webroot": webroot, "cert_dir": certDir, "cert_path": certPath, "fullchain_path": fullchainPath, "privkey_path": privkeyPath, "expires_at": expiresAt.UTC().Format(time.RFC3339), "nginx_ssl_enabled": fmt.Sprintf("%t", sslEnabled), "nginx_reloaded": fmt.Sprintf("%t", reloaded)}, Completed: time.Now().UTC()}, nil
}

func sslDomains(domainName, serverAliases string) []string {
	var domains []string
	if domainName != "" {
		domains = append(domains, domainName)
	}
	if serverAliases != "" {
		for _, alias := range strings.FieldsFunc(serverAliases, func(r rune) bool { return r == ',' || r == ' ' || r == ';' }) {
			trimmed := strings.TrimSpace(alias)
			if trimmed != "" {
				domains = append(domains, trimmed)
			}
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
func ensureCertbotAvailable() error {
	if _, err := exec.LookPath("certbot"); err != nil {
		_ = runCommand("apt-get", "update")
		if installErr := runCommand("apt-get", "install", "-y", "certbot"); installErr != nil {
			return fmt.Errorf("certbot is not installed and automatic installation failed: %w", installErr)
		}
		if _, retryErr := exec.LookPath("certbot"); retryErr != nil {
			return fmt.Errorf("certbot is not installed or not in PATH after installation attempt")
		}
	}
	return nil
}
