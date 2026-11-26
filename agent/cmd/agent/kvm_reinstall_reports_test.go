package main

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/api"
	"easywi/agent/internal/jobs"
)

func TestKVMReinstallationResultSurvivesLostDeliveryAndAgentRestart(t *testing.T) {
	f := kvmNewFixture(t)
	kvmAssertSuccess(t, f.create())
	var guest kvmGuest
	if err := kvmReadJSON(f.e.guestFile(kvmTestVM, "manifest.json"), &guest); err != nil {
		t.Fatal(err)
	}
	passwordChanges := 0
	kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
		request := args[6]
		switch {
		case strings.Contains(request, "guest-file-open"):
			return []byte(`{"return":7}`), nil
		case strings.Contains(request, "guest-file-read"):
			return kvmTestCloudInitMarker(time.Now())
		case strings.Contains(request, "guest-set-user-password"):
			passwordChanges++
		}
		return []byte(`{"return":{}}`), nil
	})
	job := jobs.Job{ID: kvmTestOp2, Type: "kvm.managed", Payload: map[string]any{
		"action": "vm.reinstall", "operation_id": kvmTestOp, "vm_id": kvmTestVM, "template": guest.Template,
		"confirm": "1", "install_password_hash": "$6$0123456789abcdef$" + strings.Repeat("a", 86),
	}}
	const panel = "https://panel.example.test"
	const agentID = "example-kvm-agent"
	ctx := context.Background()
	transportFailure := errors.New("simulated lost finish response")
	tracking := newKVMReinstallReports(panel, agentID, func(context.Context, string, orchestratorResult) error { return transportFailure })
	path, err := tracking.track(job)
	if err != nil || path == "" {
		t.Fatalf("tracking unavailable: %v", err)
	}
	if err := tracking.retry(ctx); err != nil {
		t.Fatalf("a job without a journal result was sent: %v", err)
	}
	result := handleKVMManaged(job)
	kvmAssertSuccess(t, result)
	if err := tracking.finish(ctx, path, job.ID, result); !errors.Is(err, transportFailure) {
		t.Fatalf("lost result delivery: %v", err)
	}

	var reports int
	finish := func(_ context.Context, id string, result orchestratorResult) error {
		reports++
		if id != job.ID || result.status != "success" || result.resultPayload["root_access_configured"] != true || result.resultPayload["disk_replaced"] != true {
			t.Fatalf("incorrect result for replay: %s %#v", id, result)
		}
		return nil
	}
	if err := newKVMReinstallReports(panel, "another-agent", finish).retry(ctx); err != nil || reports != 0 {
		t.Fatalf("result left its original agent scope: %v", err)
	}
	if err := newKVMReinstallReports("https://other-panel.example.test", agentID, finish).retry(ctx); err != nil || reports != 0 {
		t.Fatalf("result left its original panel scope: %v", err)
	}
	for attempt := 0; attempt < 2; attempt++ {
		if err := newKVMReinstallReports(panel, agentID, finish).retry(ctx); err != nil {
			t.Fatal(err)
		}
	}
	if reports != 1 || passwordChanges != 1 {
		t.Fatalf("expected only replayed evidence, got %d reports and %d password changes", reports, passwordChanges)
	}
	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatalf("acknowledged report is still queued: %v", err)
	}
}

func TestKVMReinstallationReportForRemovedJobIsRetired(t *testing.T) {
	kvmNewFixture(t)
	reports := newKVMReinstallReports("https://panel.example.test", "kvm", func(context.Context, string, orchestratorResult) error { return api.ErrAgentJobNotFound })
	job := jobs.Job{ID: kvmTestOp2, Type: "kvm.managed", Payload: map[string]any{"action": "vm.reinstall", "operation_id": kvmTestOp, "vm_id": kvmTestVM}}
	path, err := reports.track(job)
	if err != nil {
		t.Fatal(err)
	}
	if err := reports.finish(context.Background(), path, job.ID, orchestratorResult{status: "failed"}); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatalf("report for a removed job is still queued: %v", err)
	}
}

func TestKVMReinstallationRecoversAfterPreviousSystemDiskRemoval(t *testing.T) {
	for _, scenario := range []string{"complete", "complete through inspection", "replacement changed", "previous disk present"} {
		t.Run(scenario, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			stage := f.e.guestFile(kvmTestVM, ".reinstall-"+kvmTestOp)
			previous := filepath.Join(stage, "previous.qcow2")
			preserved := f.e.guestFile(kvmTestVM, "test-previous.qcow2")
			passwordChanges := 0
			kvmAgentFixture(t, func(_ context.Context, args ...string) ([]byte, error) {
				request := args[6]
				switch {
				case strings.Contains(request, "guest-file-open"):
					if scenario == "previous disk present" {
						if err := os.Link(previous, preserved); err != nil {
							t.Fatal(err)
						}
					}
					return []byte(`{"return":7}`), nil
				case strings.Contains(request, "guest-file-read"):
					return kvmTestCloudInitMarker(time.Now())
				case strings.Contains(request, "guest-set-user-password"):
					passwordChanges++
				}
				return []byte(`{"return":{}}`), nil
			})
			ctx := context.Background()
			job := jobs.Job{ID: kvmTestOp2, Type: "kvm.managed", Payload: map[string]any{
				"action": "vm.reinstall", "operation_id": kvmTestOp, "vm_id": kvmTestVM, "template": "ubuntu--24.04-v1",
				"confirm": "1", "install_password_hash": "$6$0123456789abcdef$" + strings.Repeat("a", 86),
			}}
			const panel = "https://panel.example.test"
			const agent = "kvm-test"
			interrupted := errors.New("simulated crash between disk removal and result commit")
			withKVMRunner(t, func(ctx context.Context, binary string, args ...string) ([]byte, error) {
				if binary == "virsh" && len(args) > 2 && args[2] == "list" {
					panic(interrupted)
				}
				return f.run(ctx, binary, args...)
			})
			tracking := newKVMReinstallReports(panel, agent, nil)
			reportPath, err := tracking.track(job)
			if err != nil {
				t.Fatal(err)
			}
			func() {
				defer func() {
					if err := recover(); err != interrupted {
						t.Fatalf("reinstallation did not reach the interruption point: %v", err)
					}
				}()
				handleKVMManaged(job)
			}()
			withKVMRunner(t, f.run)
			if _, err := os.Stat(previous); !os.IsNotExist(err) {
				t.Fatalf("previous disk was not removed: %v", err)
			}
			disk := f.e.guestFile(kvmTestVM, "disk.qcow2")
			switch scenario {
			case "replacement changed":
				other := f.e.guestFile(kvmTestVM, "other.qcow2")
				f.image(other)
				if err := os.Rename(other, disk); err != nil {
					t.Fatal(err)
				}
			case "previous disk present":
				if err := os.Link(preserved, previous); err != nil {
					t.Fatal(err)
				}
				old := time.Now().Add(-2 * time.Hour)
				if err := os.Chtimes(reportPath, old, old); err != nil {
					t.Fatal(err)
				}
			}
			original, err := os.Stat(disk)
			if err != nil {
				t.Fatal(err)
			}
			reports := 0
			sender := func(_ context.Context, id string, result orchestratorResult) error {
				reports++
				if id != job.ID || result.status != "success" || result.resultPayload["root_access_configured"] != true || result.resultPayload["disk_replaced"] != true {
					t.Fatalf("unexpected recovered result %s %#v", id, result)
				}
				return nil
			}
			before := len(f.writes)
			if scenario == "complete through inspection" {
				inspection := f.job("inspect", "", nil)
				kvmAssertSuccess(t, inspection)
				inventory := inspection.resultPayload["inventory"].(map[string]any)
				if pending := inventory["pending_operations"].([]string); len(pending) != 0 {
					t.Fatalf("inspection still reports the completed operation as pending: %v", pending)
				}
			}
			if err := newKVMReinstallReports(panel, agent, sender).retry(ctx); err != nil {
				t.Fatal(err)
			}
			current, err := os.Stat(disk)
			if err != nil || !os.SameFile(original, current) || len(f.writes) != before || passwordChanges != 1 {
				t.Fatal("recovery repeated part of the reinstallation")
			}
			pending, err := f.e.pendingOperations()
			complete := scenario == "complete" || scenario == "complete through inspection"
			if err != nil || complete != (len(pending) == 0 && reports == 1) {
				t.Fatalf("unexpected recovery outcome: %v, %d reports, %v", pending, reports, err)
			}
			if complete {
				if _, err := os.Stat(stage); !os.IsNotExist(err) {
					t.Fatalf("completed stage was not cleaned up: %v", err)
				}
			} else if reports != 0 || len(pending) != 1 {
				t.Fatal("inconsistent state was reported as complete")
			} else if scenario == "previous disk present" {
				if _, err := os.Stat(previous); err != nil {
					t.Fatalf("recovery removed the previous disk: %v", err)
				}
				if _, err := os.Stat(reportPath); err != nil {
					t.Fatalf("the unfinished report was lost: %v", err)
				}
			}
		})
	}
}
