package main

import (
	"testing"
	"time"

	"easywi/agent/internal/jobs"
)

func TestJobRunnerParallelDifferentInstances(t *testing.T) {
	runner := newJobRunner(2)
	started := make(chan string, 2)
	release := make(chan struct{})

	handler := func(id string) func(j jobs.Job) {
		return func(j jobs.Job) {
			started <- id
			<-release
		}
	}

	runner.Submit(jobTask{
		job:     jobs.Job{ID: "job-1", Payload: map[string]any{"instance_id": "1"}},
		lockKey: "instance:1",
		handler: handler("job-1"),
	})
	runner.Submit(jobTask{
		job:     jobs.Job{ID: "job-2", Payload: map[string]any{"instance_id": "2"}},
		lockKey: "instance:2",
		handler: handler("job-2"),
	})

	seen := map[string]bool{}
	timeout := time.After(2 * time.Second)
	for len(seen) < 2 {
		select {
		case id := <-started:
			seen[id] = true
		case <-timeout:
			t.Fatalf("expected both jobs to start concurrently, saw %v", seen)
		}
	}

	close(release)
}

func TestJobRunnerSerializesSameInstance(t *testing.T) {
	runner := newJobRunner(2)
	started := make(chan string, 2)
	release := make(chan struct{})

	handler := func(id string) func(j jobs.Job) {
		return func(j jobs.Job) {
			started <- id
			<-release
		}
	}

	runner.Submit(jobTask{
		job:     jobs.Job{ID: "job-1", Payload: map[string]any{"instance_id": "1"}},
		lockKey: "instance:1",
		handler: handler("job-1"),
	})
	runner.Submit(jobTask{
		job:     jobs.Job{ID: "job-2", Payload: map[string]any{"instance_id": "1"}},
		lockKey: "instance:1",
		handler: handler("job-2"),
	})

	select {
	case first := <-started:
		if first != "job-1" && first != "job-2" {
			t.Fatalf("unexpected job start %s", first)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("expected first job to start")
	}

	select {
	case second := <-started:
		t.Fatalf("expected second job to wait, but got %s", second)
	case <-time.After(200 * time.Millisecond):
	}

	close(release)
}
