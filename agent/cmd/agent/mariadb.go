package main

import (
	"fmt"
	"net"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleMariaDBDatabaseCreate(job jobs.Job) (jobs.Result, func() error) {
	database := payloadValue(job.Payload, "database", "name")
	charset := payloadValue(job.Payload, "charset")
	collation := payloadValue(job.Payload, "collation")

	missing := missingValues([]requiredValue{{key: "database", value: database}})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	output := map[string]string{
		"database": database,
	}
	if charset != "" {
		output["charset"] = charset
	}
	if collation != "" {
		output["collation"] = collation
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleMariaDBUserCreate(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "username", "user")
	host := payloadValue(job.Payload, "host", "allowed_host")
	allowedSubnet := payloadValue(job.Payload, "allowed_subnet", "web_subnet", "subnet")
	encryptedPassword := payloadValue(job.Payload, "encrypted_password", "password_encrypted")

	missing := missingValues([]requiredValue{
		{key: "username", value: username},
		{key: "host", value: host},
		{key: "allowed_subnet", value: allowedSubnet},
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

	if err := ensureHostInSubnet(host, allowedSubnet); err != nil {
		return failureResult(job.ID, err)
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"username":             username,
			"host":                 host,
			"encrypted_password":   encryptedPassword,
			"credential_storage":   "encrypted",
			"allowed_subnet":       allowedSubnet,
			"password_provisioned": "encrypted",
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleMariaDBGrantApply(job jobs.Job) (jobs.Result, func() error) {
	database := payloadValue(job.Payload, "database", "name")
	username := payloadValue(job.Payload, "username", "user")
	host := payloadValue(job.Payload, "host", "allowed_host")
	allowedSubnet := payloadValue(job.Payload, "allowed_subnet", "web_subnet", "subnet")
	privileges := payloadValue(job.Payload, "privileges", "grants")

	missing := missingValues([]requiredValue{
		{key: "database", value: database},
		{key: "username", value: username},
		{key: "host", value: host},
		{key: "allowed_subnet", value: allowedSubnet},
	})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if err := ensureHostInSubnet(host, allowedSubnet); err != nil {
		return failureResult(job.ID, err)
	}

	if privileges == "" {
		privileges = "ALL"
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"database":       database,
			"username":       username,
			"host":           host,
			"privileges":     privileges,
			"allowed_subnet": allowedSubnet,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func ensureHostInSubnet(host, subnet string) error {
	ip := net.ParseIP(host)
	if ip == nil {
		return fmt.Errorf("host must be an IP address: %s", host)
	}

	_, cidr, err := net.ParseCIDR(subnet)
	if err != nil {
		return fmt.Errorf("invalid subnet %s: %w", subnet, err)
	}

	if !cidr.Contains(ip) {
		return fmt.Errorf("host %s is outside allowed subnet %s", host, subnet)
	}

	return nil
}
