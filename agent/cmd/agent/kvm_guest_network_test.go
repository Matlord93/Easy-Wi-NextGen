package main

import (
	"bytes"
	"context"
	_ "embed"
	"encoding/json"
	"errors"
	"fmt"
	"testing"
)

// Shared with the Core's VmGuestNetworkReportTest. Keep the fixture in the
// agent module so agent-only checkouts can run the contract test.
//
//go:embed testdata/vm-guest-network.json
var kvmGuestNetworkProtocolCases []byte

func TestKVMGuestNetworkSharedProtocolContract(t *testing.T) {
	var cases []struct {
		Name     string `json:"name"`
		Input    any    `json:"input"`
		Expected any    `json:"expected"`
	}
	if err := json.Unmarshal(kvmGuestNetworkProtocolCases, &cases); err != nil {
		t.Fatal(err)
	}
	for _, c := range cases {
		t.Run(c.Name, func(t *testing.T) {
			actual, _ := json.Marshal(kvmGuestNetwork(c.Input))
			expected, _ := json.Marshal(c.Expected)
			if !bytes.Equal(actual, expected) {
				t.Fatalf("got %s want %s", actual, expected)
			}
		})
	}
}
func TestKVMGuestNetworkListsStayBounded(t *testing.T) {
	rows := []any{}
	for i := 0; i < 17; i++ {
		ips := []any{}
		for j := 0; j < 17; j++ {
			ips = append(ips, map[string]any{"ip-address-type": "ipv4", "ip-address": fmt.Sprintf("192.0.2.%d", j+1), "prefix": float64(24)})
		}
		rows = append(rows, map[string]any{"name": fmt.Sprintf("nic%d", i), "ip-addresses": ips})
	}
	result := kvmGuestNetwork(rows)
	interfaces := result["interfaces"].([]any)
	if result["limited"] != true || len(interfaces) != 16 {
		t.Fatal("unbounded interfaces")
	}
	for _, row := range interfaces {
		if len(row.(map[string]any)["addresses"].([]any)) != 16 {
			t.Fatal("unbounded addresses")
		}
	}
}
func TestKVMGuestNetworkFailureDoesNotInvalidateGuestInfo(t *testing.T) {
	for _, mode := range []string{"error", "object-instead-of-list", "unsupported"} {
		t.Run(mode, func(t *testing.T) {
			f := kvmNewFixture(t)
			kvmAssertSuccess(t, f.create())
			f.states[kvmTestVM] = "running"
			calls := 0
			kvmAgentFixture(t, func(ctx context.Context, args ...string) ([]byte, error) {
				calls++
				if calls == 1 {
					if mode == "unsupported" {
						return []byte(`{"return":{"version":"9.2"}}`), nil
					}
					return []byte(`{"return":{"version":"9.2","supported_commands":[{"name":"guest-network-get-interfaces","enabled":true}]}}`), nil
				}
				if mode == "error" {
					return nil, errors.New("private network failure")
				}
				return []byte(`{"return":{"unexpected":[]}}`), nil
			})
			r := f.job("vm.agent_status", kvmTestOp, map[string]any{"vm_id": kvmTestVM})
			kvmAssertSuccess(t, r)
			report := r.resultPayload["guest_agent"].(map[string]any)
			network := report["network"].(map[string]any)
			want := map[string]string{"error": "unavailable", "object-instead-of-list": "invalid_response", "unsupported": "unsupported"}[mode]
			if report["state"] != "reachable" || network["state"] != want || len(network["interfaces"].([]any)) != 0 {
				t.Fatalf("bad observation: %#v", report)
			}
			if mode == "unsupported" && calls != 1 {
				t.Fatal("unsupported network command dispatched")
			}
		})
	}
}
