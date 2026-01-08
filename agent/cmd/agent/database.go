package main

import (
	"fmt"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

func handleDatabaseCreate(job jobs.Job) (jobs.Result, func() error) {
	engine := strings.ToLower(payloadValue(job.Payload, "engine", "driver"))
	database := payloadValue(job.Payload, "database", "name")
	username := payloadValue(job.Payload, "username", "user")
	host := payloadValue(job.Payload, "host")
	port := payloadValue(job.Payload, "port")
	encryptedPassword := payloadValue(job.Payload, "encrypted_password", "password_encrypted")

	missing := missingValues([]requiredValue{{key: "engine", value: engine}, {key: "database", value: database}, {key: "username", value: username}})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	output := map[string]string{
		"engine":   engine,
		"database": database,
		"username": username,
	}
	if host != "" {
		output["host"] = host
	}
	if port != "" {
		output["port"] = port
	}
	if encryptedPassword != "" {
		output["encrypted_password"] = encryptedPassword
		output["credential_storage"] = "encrypted"
	}

	return jobs.Result{
		JobID:     job.ID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}, nil
}

func handleDatabasePasswordReset(job jobs.Job) (jobs.Result, func() error) {
	username := payloadValue(job.Payload, "username", "user")
	encryptedPassword := payloadValue(job.Payload, "encrypted_password", "password_encrypted")

	missing := missingValues([]requiredValue{{key: "username", value: username}, {key: "encrypted_password", value: encryptedPassword}})
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
			"password_provisioned": fmt.Sprintf("reset for %s", username),
		},
		Completed: time.Now().UTC(),
	}, nil
}
