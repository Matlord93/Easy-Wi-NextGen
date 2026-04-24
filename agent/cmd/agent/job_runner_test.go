package main

import (
	"sync/atomic"
	"testing"
	"time"

	"easywi/agent/internal/jobs"
)

func TestJobRunnerParallelDifferentInstances(t *testing.T) {
	runner := newJobRunner(2, 2)
	started := make(chan string, 2)
	release := make(chan struct{})

	handler := func(id string) func(j jobs.Job) {
		return func(j jobs.Job) {
			started <- id
			<-release
		}
	}

	runner.Submit(jobTask{
		job:          jobs.Job{ID: "job-1", Payload: map[string]any{"instance_id": "1"}},
		instanceLock: "instance:1",
		lockMode:     jobLockRead,
		handler:      handler("job-1"),
	})
	runner.Submit(jobTask{
		job:          jobs.Job{ID: "job-2", Payload: map[string]any{"instance_id": "2"}},
		instanceLock: "instance:2",
		lockMode:     jobLockRead,
		handler:      handler("job-2"),
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

func TestJobRunnerReadLockAllowsConcurrentSameInstance(t *testing.T) {
	runner := newJobRunner(2, 2)
	started := make(chan string, 2)
	release := make(chan struct{})

	handler := func(id string) func(j jobs.Job) {
		return func(j jobs.Job) {
			started <- id
			<-release
		}
	}

	runner.Submit(jobTask{job: jobs.Job{ID: "job-1"}, instanceLock: "instance:1", lockMode: jobLockRead, handler: handler("job-1")})
	runner.Submit(jobTask{job: jobs.Job{ID: "job-2"}, instanceLock: "instance:1", lockMode: jobLockRead, handler: handler("job-2")})

	seen := map[string]bool{}
	timeout := time.After(2 * time.Second)
	for len(seen) < 2 {
		select {
		case id := <-started:
			seen[id] = true
		case <-timeout:
			t.Fatalf("expected both read jobs to run concurrently, saw %v", seen)
		}
	}

	close(release)
}

func TestJobRunnerWriteWaitsForReadSameInstance(t *testing.T) {
	runner := newJobRunner(2, 2)
	started := make(chan string, 2)
	readStarted := make(chan struct{})
	releaseRead := make(chan struct{})
	done := make(chan struct{})

	runner.Submit(jobTask{
		job:          jobs.Job{ID: "stream"},
		instanceLock: "instance:1",
		lockMode:     jobLockRead,
		handler: func(j jobs.Job) {
			started <- "stream"
			close(readStarted)
			<-releaseRead
		},
	})

	select {
	case <-readStarted:
	case <-time.After(2 * time.Second):
		t.Fatal("expected reader to acquire lock")
	}

	runner.Submit(jobTask{
		job:          jobs.Job{ID: "start"},
		instanceLock: "instance:1",
		lockMode:     jobLockWrite,
		handler: func(j jobs.Job) {
			started <- "start"
			close(done)
		},
	})

	select {
	case first := <-started:
		if first != "stream" {
			t.Fatalf("expected reader start signal, got %s", first)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("expected reader start signal")
	}

	select {
	case second := <-started:
		t.Fatalf("expected writer to wait for reader completion, got %s", second)
	case <-time.After(200 * time.Millisecond):
	}

	close(releaseRead)
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("expected writer to run after read lock release")
	}
}

func TestJobRunnerStreamLimiterCapsConcurrentStreams(t *testing.T) {
	runner := newJobRunner(3, 1)
	var active int32
	peak := int32(0)
	release := make(chan struct{})
	done := make(chan struct{}, 2)

	streamTask := func(id string) jobTask {
		return jobTask{
			job:          jobs.Job{ID: id},
			instanceLock: "instance:" + id,
			lockMode:     jobLockRead,
			isStream:     true,
			handler: func(j jobs.Job) {
				current := atomic.AddInt32(&active, 1)
				for {
					maxSeen := atomic.LoadInt32(&peak)
					if current <= maxSeen || atomic.CompareAndSwapInt32(&peak, maxSeen, current) {
						break
					}
				}
				<-release
				atomic.AddInt32(&active, -1)
				done <- struct{}{}
			},
		}
	}

	runner.Submit(streamTask("1"))
	runner.Submit(streamTask("2"))

	time.Sleep(250 * time.Millisecond)
	if got := atomic.LoadInt32(&peak); got != 1 {
		t.Fatalf("expected at most 1 concurrent stream, got %d", got)
	}

	close(release)
	<-done
	<-done
}
