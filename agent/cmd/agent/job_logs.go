package main

import (
	"context"
	"log"
	"time"

	"easywi/agent/internal/api"
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
