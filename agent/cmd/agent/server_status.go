package main

import (
	"net"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const serverStatusTimeout = 3 * time.Second

func handleServerStatusCheck(job jobs.Job) (jobs.Result, func() error) {
	ip := payloadValue(job.Payload, "ip", "host")
	port := payloadValue(job.Payload, "query_port", "port")

	missing := missingValues([]requiredValue{{key: "ip", value: ip}, {key: "port", value: port}})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	address := net.JoinHostPort(ip, port)
	status := "offline"

	conn, err := net.DialTimeout("tcp", address, serverStatusTimeout)
	if err == nil {
		status = "online"
		_ = conn.Close()
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"status": status,
			"ip":     ip,
			"port":   port,
		},
		Completed: time.Now().UTC(),
	}, nil
}
