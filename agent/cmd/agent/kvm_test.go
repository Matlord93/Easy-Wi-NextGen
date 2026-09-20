package main

import (
	"context"
	"errors"
	"reflect"
	"testing"

	"easywi/agent/internal/jobs"
)

func withKVMRunner(t *testing.T, runner func(context.Context, string, ...string) ([]byte, error)) {
	t.Helper()
	original := kvmRunCommand
	kvmRunCommand = runner
	t.Cleanup(func() { kvmRunCommand = original })
}

func TestHandleKVMListReturnsGuestsAndState(t *testing.T) {
	withKVMRunner(t, func(_ context.Context, binary string, args ...string) ([]byte, error) {
		if binary != "virsh" {
			t.Fatalf("unexpected binary %q", binary)
		}
		if reflect.DeepEqual(args, []string{"list", "--all", "--name"}) {
			return []byte("web-01\ncache_01\n"), nil
		}
		if len(args) == 2 && args[0] == "domstate" {
			return []byte(map[string]string{"web-01": "running\n", "cache_01": "shut off\n"}[args[1]]), nil
		}
		t.Fatalf("unexpected arguments: %#v", args)
		return nil, nil
	})

	result := handleKVMJob(jobs.Job{Type: "kvm.vm.list"})
	if result.status != "success" {
		t.Fatalf("expected success, got %#v", result)
	}
	guests, ok := result.resultPayload["guests"].([]map[string]any)
	if !ok || len(guests) != 2 || guests[0]["state"] != "running" || guests[1]["state"] != "shut off" {
		t.Fatalf("unexpected guests: %#v", result.resultPayload["guests"])
	}
}

func TestHandleKVMActionUsesArgumentVector(t *testing.T) {
	withKVMRunner(t, func(_ context.Context, binary string, args ...string) ([]byte, error) {
		expected := []string{"reboot", "customer-vm"}
		if binary != "virsh" || !reflect.DeepEqual(args, expected) {
			t.Fatalf("expected virsh %#v, got %s %#v", expected, binary, args)
		}
		return nil, nil
	})

	result := handleKVMJob(jobs.Job{Type: "kvm.vm.action", Payload: map[string]any{"name": "customer-vm", "action": "reboot"}})
	if result.status != "success" || result.resultPayload["action"] != "reboot" {
		t.Fatalf("unexpected result: %#v", result)
	}
}

func TestHandleKVMActionRejectsInjection(t *testing.T) {
	withKVMRunner(t, func(context.Context, string, ...string) ([]byte, error) {
		t.Fatal("runner must not be invoked for invalid identifiers")
		return nil, nil
	})

	result := handleKVMJob(jobs.Job{Type: "kvm.vm.action", Payload: map[string]any{"name": "vm;shutdown", "action": "stop"}})
	if result.status != "failed" || result.errorText != "invalid or missing KVM name" {
		t.Fatalf("unexpected result: %#v", result)
	}
}

func TestHandleKVMLimitsValidatesBeforeExecution(t *testing.T) {
	withKVMRunner(t, func(context.Context, string, ...string) ([]byte, error) {
		t.Fatal("runner must not be invoked for invalid limits")
		return nil, nil
	})

	result := handleKVMJob(jobs.Job{Type: "kvm.vm.limits.apply", Payload: map[string]any{"name": "vm1", "vcpus": 0, "memory_mib": 512}})
	if result.status != "failed" || result.errorText != "vcpus must be between 1 and 1024" {
		t.Fatalf("unexpected result: %#v", result)
	}
}

func TestRunVirshReturnsSanitizedFailure(t *testing.T) {
	withKVMRunner(t, func(context.Context, string, ...string) ([]byte, error) {
		return []byte("domain is not running\n"), errors.New("exit status 1")
	})

	result := handleKVMJob(jobs.Job{Type: "kvm.vm.status", Payload: map[string]any{"name": "vm1"}})
	if result.status != "failed" || result.errorText != "virsh command failed: domain is not running" {
		t.Fatalf("unexpected result: %#v", result)
	}
}
