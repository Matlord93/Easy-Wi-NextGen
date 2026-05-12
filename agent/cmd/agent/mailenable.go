package main

import (
	"fmt"
	"runtime"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const mailEnableBackendName = "mailenable"

func isWindowsMailEnableBackend(job jobs.Job) bool {
	if runtime.GOOS != "windows" {
		return false
	}
	backend := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "mail_backend")))
	return backend == "" || backend == "local" || backend == mailEnableBackendName
}

func mailEnablePowerShellAvailable() bool {
	if runtime.GOOS != "windows" || !commandExists("powershell") {
		return false
	}
	cmd := `(Get-PSSnapin -Registered -Name MailEnable.Provision.Command -ErrorAction SilentlyContinue) -ne $null`
	out, err := runCommandOutput("powershell", "-NoProfile", "-NonInteractive", "-Command", cmd)
	return err == nil && strings.EqualFold(strings.TrimSpace(out), "true")
}

func runMailEnablePowerShell(script string) error {
	if !commandExists("powershell") {
		return fmt.Errorf("powershell is required for MailEnable provisioning")
	}
	wrapped := "$ErrorActionPreference='Stop'; Add-PSSnapin MailEnable.Provision.Command -ErrorAction Stop; " + script
	out, err := runCommandOutput("powershell", "-NoProfile", "-NonInteractive", "-Command", wrapped)
	if err != nil {
		return fmt.Errorf("mailenable PowerShell failed: %s", sanitizeOutput(out))
	}
	return nil
}

func splitMailAddress(address string) (mailbox, domain string, err error) {
	parts := strings.SplitN(strings.TrimSpace(address), "@", 2)
	if len(parts) != 2 || parts[0] == "" || parts[1] == "" {
		return "", "", fmt.Errorf("invalid mail address: %s", address)
	}
	return parts[0], strings.ToLower(parts[1]), nil
}

func mailEnablePostofficeForAddress(payload map[string]any, address string) (postoffice, mailbox, domain string, err error) {
	mailbox, domain, err = splitMailAddress(address)
	if err != nil {
		return "", "", "", err
	}
	postoffice = strings.TrimSpace(payloadValue(payload, "postoffice", "mailenable_postoffice"))
	if postoffice == "" {
		postoffice = domain
	}
	return postoffice, mailbox, domain, nil
}

func handleMailEnableDomainCreate(job jobs.Job) (jobs.Result, func() error) {
	domainName := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "domain", "name", "hostname")))
	if domainName == "" {
		return failureResult(job.ID, fmt.Errorf("missing required values: domain"))
	}
	if err := validateDomainName(domainName); err != nil {
		return failureResult(job.ID, err)
	}
	postoffice := strings.TrimSpace(payloadValue(job.Payload, "postoffice", "mailenable_postoffice"))
	if postoffice == "" {
		postoffice = domainName
	}
	adminUser := strings.TrimSpace(payloadValue(job.Payload, "admin_user", "mail_admin_user"))
	if adminUser == "" {
		adminUser = "admin"
	}
	adminPassword := strings.TrimSpace(payloadValue(job.Payload, "admin_password", "mail_admin_password", "password"))
	if adminPassword == "" {
		adminPassword = generateSftpPassword()
	}

	script := fmt.Sprintf(`if (-not (Get-MailEnablePostoffice -Postoffice %s -ErrorAction SilentlyContinue)) { New-MailEnablePostoffice -Domain %s -Postoffice %s -AdminUserName %s -AdminPassword %s | Out-Null } elseif (-not (Get-MailEnableDomain -Domain %s -ErrorAction SilentlyContinue)) { Add-MailEnableDomain -Domain %s -Postoffice %s | Out-Null }`, powershellStringLiteral(postoffice), powershellStringLiteral(domainName), powershellStringLiteral(postoffice), powershellStringLiteral(adminUser), powershellStringLiteral(adminPassword), powershellStringLiteral(domainName), powershellStringLiteral(domainName), powershellStringLiteral(postoffice))
	if err := runMailEnablePowerShell(script); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{
		"domain":                    domainName,
		"postoffice":                postoffice,
		"backend":                   mailEnableBackendName,
		"dkim_automation_supported": "false",
		"dkim_note":                 "MailEnable provides DKIM, but current public PowerShell provisioning does not expose DKIM setup; configure DKIM in MailEnable or use external DNS/mail backend automation.",
	}, Completed: time.Now().UTC()}, nil
}

func handleMailEnableDkimRotate(job jobs.Job) (jobs.Result, func() error) {
	return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{
		"error_code": "MAILENABLE_DKIM_MANUAL",
		"message":    "MailEnable DKIM cannot be rotated safely through the current public PowerShell provisioning API; configure/rotate DKIM in MailEnable or use an external mail backend with API support.",
		"backend":    mailEnableBackendName,
	}, Completed: time.Now().UTC()}, nil
}

func handleMailEnableMailboxCreate(job jobs.Job) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "email")))
	password := strings.TrimSpace(payloadValue(job.Payload, "password", "plain_password", "one_time_password", "password_hash"))
	quotaMB := normalizeMailboxQuota(payloadValue(job.Payload, "quota_mb", "quota"))
	enabled := normalizeMailboxEnabled(payloadValue(job.Payload, "enabled", "active"), true)
	if address == "" || password == "" {
		return failureResult(job.ID, fmt.Errorf("missing address or password"))
	}
	postoffice, mailbox, domain, err := mailEnablePostofficeForAddress(job.Payload, address)
	if err != nil {
		return failureResult(job.ID, err)
	}
	quotaKB := mailEnableQuotaKB(quotaMB)
	status := "1"
	if !enabled {
		status = "0"
	}
	script := fmt.Sprintf(`if (-not (Get-MailEnableMailbox -Postoffice %s -Mailbox %s -ErrorAction SilentlyContinue)) { New-MailEnableMailbox -Mailbox %s -Domain %s -Password %s -Right "USER" | Out-Null } else { Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxPassword" -Value %s }; Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxLimit" -Value %s; Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxStatus" -Value %s; Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxLoginStatus" -Value %s`, powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(mailbox), powershellStringLiteral(domain), powershellStringLiteral(password), powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(password), powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(strconv.Itoa(quotaKB)), powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(status), powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(status))
	if err := runMailEnablePowerShell(script); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "postoffice": postoffice, "quota_mb": strconv.Itoa(quotaMB), "enabled": strconv.FormatBool(enabled), "password_set": "secret", "backend": mailEnableBackendName}, Completed: time.Now().UTC()}, nil
}

func handleMailEnableMailboxPasswordReset(job jobs.Job) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "email")))
	password := strings.TrimSpace(payloadValue(job.Payload, "password", "plain_password", "one_time_password", "password_hash"))
	if address == "" || password == "" {
		return failureResult(job.ID, fmt.Errorf("missing address or password"))
	}
	postoffice, mailbox, _, err := mailEnablePostofficeForAddress(job.Payload, address)
	if err != nil {
		return failureResult(job.ID, err)
	}
	script := fmt.Sprintf(`Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxPassword" -Value %s`, powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(password))
	if err := runMailEnablePowerShell(script); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "password_set": "secret", "backend": mailEnableBackendName}, Completed: time.Now().UTC()}, nil
}

func handleMailEnableMailboxQuotaUpdate(job jobs.Job) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "email")))
	quotaValue := payloadValue(job.Payload, "quota_mb", "quota")
	if address == "" || quotaValue == "" {
		return failureResult(job.ID, fmt.Errorf("missing address or quota"))
	}
	quotaMB := normalizeMailboxQuota(quotaValue)
	postoffice, mailbox, _, err := mailEnablePostofficeForAddress(job.Payload, address)
	if err != nil {
		return failureResult(job.ID, err)
	}
	script := fmt.Sprintf(`Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxLimit" -Value %s`, powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(strconv.Itoa(mailEnableQuotaKB(quotaMB))))
	if err := runMailEnablePowerShell(script); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "quota_mb": strconv.Itoa(quotaMB), "backend": mailEnableBackendName}, Completed: time.Now().UTC()}, nil
}

func handleMailEnableMailboxStatus(job jobs.Job, enabled bool) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "email")))
	if address == "" {
		return failureResult(job.ID, fmt.Errorf("missing address"))
	}
	postoffice, mailbox, _, err := mailEnablePostofficeForAddress(job.Payload, address)
	if err != nil {
		return failureResult(job.ID, err)
	}
	status := "1"
	if !enabled {
		status = "0"
	}
	script := fmt.Sprintf(`Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxStatus" -Value %s; Set-MailEnableMailbox -Postoffice %s -Mailbox %s -Setting "mailboxLoginStatus" -Value %s`, powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(status), powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(status))
	if err := runMailEnablePowerShell(script); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "enabled": strconv.FormatBool(enabled), "backend": mailEnableBackendName}, Completed: time.Now().UTC()}, nil
}

func handleMailEnableMailboxDelete(job jobs.Job) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "email")))
	if address == "" {
		return failureResult(job.ID, fmt.Errorf("missing address"))
	}
	postoffice, mailbox, _, err := mailEnablePostofficeForAddress(job.Payload, address)
	if err != nil {
		return failureResult(job.ID, err)
	}
	script := fmt.Sprintf(`if (Get-MailEnableMailbox -Postoffice %s -Mailbox %s -ErrorAction SilentlyContinue) { Remove-MailEnableMailbox -Postoffice %s -Mailbox %s }`, powershellStringLiteral(postoffice), powershellStringLiteral(mailbox), powershellStringLiteral(postoffice), powershellStringLiteral(mailbox))
	if err := runMailEnablePowerShell(script); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "deleted": "true", "backend": mailEnableBackendName}, Completed: time.Now().UTC()}, nil
}

func mailEnableQuotaKB(quotaMB int) int {
	if quotaMB <= 0 {
		return -1
	}
	return quotaMB * 1000
}

func handleMailEnableAliasUpsert(job jobs.Job, action string) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "alias")))
	destinations := parseAliasDestinations(payloadValue(job.Payload, "destinations", "forward_to"))
	enabled := normalizeMailboxEnabled(payloadValue(job.Payload, "enabled"), true)
	if address == "" || len(destinations) == 0 {
		return failureResult(job.ID, fmt.Errorf("missing address or destinations"))
	}
	postoffice, groupName, _, err := mailEnablePostofficeForAddress(job.Payload, address)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if !enabled {
		return handleMailEnableAliasDelete(job)
	}
	commands := []string{
		fmt.Sprintf(`if (Get-MailEnableGroup -Postoffice %s -GroupName %s -ErrorAction SilentlyContinue) { Remove-MailEnableGroup -Postoffice %s -GroupName %s }`, powershellStringLiteral(postoffice), powershellStringLiteral(groupName), powershellStringLiteral(postoffice), powershellStringLiteral(groupName)),
		fmt.Sprintf(`New-MailEnableGroup -Postoffice %s -GroupName %s -EmailAddress %s | Out-Null`, powershellStringLiteral(postoffice), powershellStringLiteral(groupName), powershellStringLiteral(address)),
	}
	for _, destination := range destinations {
		commands = append(commands, fmt.Sprintf(`New-MailEnableGroupMember -Postoffice %s -GroupName %s -EmailAddress %s | Out-Null`, powershellStringLiteral(postoffice), powershellStringLiteral(groupName), powershellStringLiteral(destination)))
	}
	if err := runMailEnablePowerShell(strings.Join(commands, "; ")); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "destinations": strings.Join(destinations, ", "), "action": action, "enabled": strconv.FormatBool(enabled), "backend": mailEnableBackendName}, Completed: time.Now().UTC()}, nil
}

func handleMailEnableAliasStatus(job jobs.Job, enabled bool) (jobs.Result, func() error) {
	if enabled {
		return handleMailEnableAliasUpsert(job, "enabled")
	}
	return handleMailEnableAliasDelete(job)
}

func handleMailEnableAliasDelete(job jobs.Job) (jobs.Result, func() error) {
	address := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "address", "alias")))
	if address == "" {
		return failureResult(job.ID, fmt.Errorf("missing address"))
	}
	postoffice, groupName, _, err := mailEnablePostofficeForAddress(job.Payload, address)
	if err != nil {
		return failureResult(job.ID, err)
	}
	script := fmt.Sprintf(`if (Get-MailEnableGroup -Postoffice %s -GroupName %s -ErrorAction SilentlyContinue) { Remove-MailEnableGroup -Postoffice %s -GroupName %s }`, powershellStringLiteral(postoffice), powershellStringLiteral(groupName), powershellStringLiteral(postoffice), powershellStringLiteral(groupName))
	if err := runMailEnablePowerShell(script); err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"address": address, "action": "deleted", "backend": mailEnableBackendName}, Completed: time.Now().UTC()}, nil
}
