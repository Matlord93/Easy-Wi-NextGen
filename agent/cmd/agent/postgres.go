package main

import (
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	pgHBAFileMode = 0o640
)

func handlePostgresDatabaseCreate(job jobs.Job) (jobs.Result, func() error) {
	database := payloadValue(job.Payload, "database", "name")
	owner := payloadValue(job.Payload, "owner", "role")
	encoding := payloadValue(job.Payload, "encoding")
	collation := payloadValue(job.Payload, "collation", "lc_collate")
	ctype := payloadValue(job.Payload, "ctype", "lc_ctype")

	missing := missingValues([]requiredValue{{key: "database", value: database}})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	output := map[string]string{"database": database}
	if owner != "" {
		output["owner"] = owner
	}
	if encoding != "" {
		output["encoding"] = encoding
	}
	if collation != "" {
		output["collation"] = collation
	}
	if ctype != "" {
		output["ctype"] = ctype
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handlePostgresRoleCreate(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "username", "role", "user")
	encryptedPassword := payloadValue(job.Payload, "encrypted_password", "password_encrypted")

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "encrypted_password", value: encryptedPassword},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":             username,
			"encrypted_password":   encryptedPassword,
			"credential_storage":   "encrypted",
			"password_provisioned": "encrypted",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handlePostgresGrantApply(job jobs.Job) (jobs.Result, func() error) {
	database := payloadValue(job.Payload, "database", "name")
	username := payloadValue(job.Payload, "username", "role", "user")
	subnet := payloadValue(job.Payload, "allowed_subnet", "subnet", "web_subnet")
	authMethod := payloadValue(job.Payload, "auth_method", "pg_hba_auth_method")
	pgHBAPath := payloadValue(job.Payload, "pg_hba_path", "pg_hba_file")

	missing := missingValues([]requiredValue{
		{key: "database", value: database},
		{key: "username", value: username},
		{key: "allowed_subnet", value: subnet},
		{key: "pg_hba_path", value: pgHBAPath},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	normalizedSubnet, err := normalizeSubnet(subnet)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if authMethod == "" {
		authMethod = "scram-sha-256"
	}

	pgHBAEntry := fmt.Sprintf("host %s %s %s %s", database, username, normalizedSubnet, authMethod)
	if err := ensurePgHBAEntry(pgHBAPath, pgHBAEntry); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"database":       database,
			"username":       username,
			"allowed_subnet": normalizedSubnet,
			"auth_method":    authMethod,
			"pg_hba_path":    pgHBAPath,
			"pg_hba_entry":   pgHBAEntry,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func normalizeSubnet(subnet string) (string, error) {
	if subnet == "" {
		return "", fmt.Errorf("allowed_subnet cannot be empty")
	}

	if strings.Contains(subnet, "/") {
		ip, cidr, err := net.ParseCIDR(subnet)
		if err != nil {
			return "", fmt.Errorf("invalid subnet %s: %w", subnet, err)
		}
		ones, bits := cidr.Mask.Size()
		if ones == 0 {
			return "", fmt.Errorf("allowed_subnet %s is too broad", subnet)
		}
		if ip.To4() == nil && bits == 0 {
			return "", fmt.Errorf("invalid subnet %s", subnet)
		}
		return cidr.String(), nil
	}

	ip := net.ParseIP(subnet)
	if ip == nil {
		return "", fmt.Errorf("allowed_subnet must be a CIDR or IP address: %s", subnet)
	}
	if ip.To4() != nil {
		return fmt.Sprintf("%s/32", ip.String()), nil
	}
	return fmt.Sprintf("%s/128", ip.String()), nil
}

func ensurePgHBAEntry(path, entry string) error {
	if entry == "" {
		return fmt.Errorf("pg_hba entry cannot be empty")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return fmt.Errorf("create pg_hba dir: %w", err)
	}

	content, err := os.ReadFile(path)
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("read pg_hba file: %w", err)
	}

	lines := strings.Split(string(content), "\n")
	for _, line := range lines {
		if strings.TrimSpace(line) == entry {
			return nil
		}
	}

	builder := strings.Builder{}
	builder.WriteString(strings.TrimRight(string(content), "\n"))
	if builder.Len() > 0 {
		builder.WriteString("\n")
	}
	builder.WriteString("# Managed by Easy-Wi agent\n")
	builder.WriteString(entry)
	builder.WriteString("\n")

	if err := os.WriteFile(path, []byte(builder.String()), pgHBAFileMode); err != nil {
		return fmt.Errorf("write pg_hba file: %w", err)
	}
	return nil
}
