package main

import (
	"context"
	"log"
	"strings"
	"time"

	"easywi/agent/internal/api"
	"easywi/agent/internal/jobs"
)

type JobLogSender interface {
	Send(jobID string, lines []string, progress *int)
}

type apiJobLogSender struct {
	client *api.Client
}

func newApiJobLogSender(client *api.Client) JobLogSender {
	if client == nil {
		return nil
	}
	return &apiJobLogSender{client: client}
}

func (sender *apiJobLogSender) Send(jobID string, lines []string, progress *int) {
	if sender == nil || sender.client == nil || jobID == "" || len(lines) == 0 {
		return
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	if err := sender.client.SubmitJobLogs(ctx, jobID, lines, progress); err != nil {
		log.Printf("submit job logs failed job_id=%s lines=%d err=%v", jobID, len(lines), err)
	}
}

type consoleMirroringJobLogSender struct {
	delegate   JobLogSender
	instanceID string
}

func withConsoleLogMirroring(job jobs.Job, sender JobLogSender) JobLogSender {
	instanceID := strings.TrimSpace(payloadValue(job.Payload, "instance_id", "instance", "server_id"))
	if instanceID == "" || !jobTypeMirrorsToConsole(job.Type) {
		return sender
	}
	return &consoleMirroringJobLogSender{delegate: sender, instanceID: instanceID}
}

func (sender *consoleMirroringJobLogSender) Send(jobID string, lines []string, progress *int) {
	if sender == nil {
		return
	}
	if sender.delegate != nil {
		sender.delegate.Send(jobID, lines, progress)
	}
	if sender.instanceID == "" || len(lines) == 0 {
		return
	}
	if err := appendConsoleLogLines(sender.instanceID, lines); err != nil {
		log.Printf("mirror job logs to console file failed instance_id=%s job_id=%s lines=%d err=%v", sender.instanceID, jobID, len(lines), err)
	}
	session := globalConsoleSessions.getOrCreate(sender.instanceID, resolveInstanceUnitName(sender.instanceID))
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line == "" {
			continue
		}
		session.appendLine("install", line, "info")
	}
}

func jobTypeMirrorsToConsole(jobType string) bool {
	switch strings.TrimSpace(jobType) {
	case "instance.create", "instance.reinstall", "sniper.install", "sniper.update", "sniper.shared.update":
		return true
	default:
		return false
	}
}
