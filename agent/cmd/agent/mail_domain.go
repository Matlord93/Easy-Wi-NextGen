package main

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	mailDomainDirMode  = 0o750
	mailDomainFileMode = 0o640
)

func handleMailDomainCreate(job jobs.Job) (jobs.Result, func() error) {
	domainName := payloadValue(job.Payload, "domain", "name", "hostname")
	configPath := payloadValue(job.Payload, "config_path", "domain_config_path", "virtual_domain_path")
	dkimSelector := payloadValue(job.Payload, "dkim_selector", "selector")
	dkimDir := payloadValue(job.Payload, "dkim_dir", "dkim_path", "dkim_directory")

	if dkimSelector == "" {
		dkimSelector = "default"
	}
	if dkimDir == "" && domainName != "" {
		dkimDir = filepath.Join("/etc/opendkim/keys", domainName)
	}

	missing := missingValues([]requiredValue{
		{key: "domain", value: domainName},
		{key: "config_path", value: configPath},
		{key: "dkim_dir", value: dkimDir},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := ensureDirWithMode(filepath.Dir(configPath), mailDomainDirMode); err != nil {
		return failureResult(job.ID, err)
	}
	if err := ensureDirWithMode(dkimDir, mailDomainDirMode); err != nil {
		return failureResult(job.ID, err)
	}

	if err := writeMailDomainConfig(configPath, domainName); err != nil {
		return failureResult(job.ID, err)
	}

	txtValue, err := generateDKIMKeys(dkimDir, domainName, dkimSelector)
	if err != nil {
		return failureResult(job.ID, err)
	}

	recordName := fmt.Sprintf("%s._domainkey.%s", dkimSelector, strings.TrimSuffix(domainName, "."))

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"domain":           domainName,
			"config_path":      configPath,
			"dkim_dir":         dkimDir,
			"dkim_selector":    dkimSelector,
			"dkim_record_name": recordName,
			"dkim_txt":         txtValue,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func writeMailDomainConfig(path, domainName string) error {
	content := fmt.Sprintf("## Managed by Easy-Wi agent\n%s\n", domainName)
	if err := os.WriteFile(path, []byte(content), mailDomainFileMode); err != nil {
		return fmt.Errorf("write mail domain config %s: %w", path, err)
	}
	return nil
}

func generateDKIMKeys(dkimDir, domainName, selector string) (string, error) {
	if err := runCommand("opendkim-genkey", "-b", "2048", "-s", selector, "-d", domainName, "-D", dkimDir); err != nil {
		return "", fmt.Errorf("generate DKIM keys: %w", err)
	}

	txtPath := filepath.Join(dkimDir, fmt.Sprintf("%s.txt", selector))
	txtData, err := os.ReadFile(txtPath)
	if err != nil {
		return "", fmt.Errorf("read DKIM txt %s: %w", txtPath, err)
	}

	value := parseDKIMTXT(string(txtData))
	if value == "" {
		return "", fmt.Errorf("DKIM txt record empty")
	}

	return value, nil
}

func parseDKIMTXT(raw string) string {
	re := regexp.MustCompile(`"([^"]+)"`)
	matches := re.FindAllStringSubmatch(raw, -1)
	if len(matches) == 0 {
		return ""
	}
	var builder strings.Builder
	for _, match := range matches {
		builder.WriteString(match[1])
	}
	return strings.TrimSpace(builder.String())
}
