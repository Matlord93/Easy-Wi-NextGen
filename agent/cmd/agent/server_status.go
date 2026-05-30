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
	queryType := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "query_type", "protocol")))

	missing := missingValues([]requiredValue{{key: "ip", value: ip}, {key: "port", value: port}})
	if len(missing) > 0 {
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "missing required values: " + strings.Join(missing, ", ")},
			Completed: time.Now().UTC(),
		}, nil
	}

	if queryType == "" {
		queryType = "tcp"
	}

	startedAt := time.Now()
	switch queryType {
	case "a2s", "steam_a2s", "source", "source1", "source2", "valve", "steam":
		data, err := queryA2S(ip, port)
		return serverStatusQueryResult(job.ID, "source", startedAt, data, err), nil
	case "minecraft", "minecraft_java", "java", "minecraft_paper_all", "minecraft_vanilla_all":
		data, err := queryMinecraftJava(ip, port)
		return serverStatusQueryResult(job.ID, "minecraft_java", startedAt, data, err), nil
	case "minecraft_bedrock", "bedrock", "mcpe":
		data, err := queryMinecraftBedrock(ip, port)
		return serverStatusQueryResult(job.ID, "minecraft_bedrock", startedAt, data, err), nil
	case "tcp", "tcp_connect", "connect", "generic":
		return serverStatusTCPResult(job.ID, ip, port, startedAt), nil
	default:
		return jobs.Result{
			JobID:     job.ID,
			Status:    "failed",
			Output:    map[string]string{"message": "unsupported query type", "query_type": queryType},
			Completed: time.Now().UTC(),
		}, nil
	}
}

func serverStatusQueryResult(jobID, engine string, startedAt time.Time, data map[string]string, err error) jobs.Result {
	status := "online"
	message := ""
	if err != nil {
		status = "offline"
		message = err.Error()
	}

	return jobs.Result{
		JobID:     jobID,
		Status:    "success",
		Output:    buildQueryOutput(status, engine, message, startedAt, data),
		Completed: time.Now().UTC(),
	}
}

func serverStatusTCPResult(jobID, ip, port string, startedAt time.Time) jobs.Result {
	address := net.JoinHostPort(ip, port)
	status := "offline"
	message := ""

	conn, err := net.DialTimeout("tcp", address, serverStatusTimeout)
	if err == nil {
		status = "online"
		_ = conn.Close()
	} else {
		message = err.Error()
	}

	output := buildQueryOutput(status, "tcp", message, startedAt, nil)
	output["ip"] = ip
	output["port"] = port

	return jobs.Result{
		JobID:     jobID,
		Status:    "success",
		Output:    output,
		Completed: time.Now().UTC(),
	}
}
