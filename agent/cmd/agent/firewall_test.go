package main

import "testing"

func TestPortsFromPayloadAcceptsArrayPayload(t *testing.T) {
	ports, err := portsFromPayload(map[string]any{
		"ports": []any{float64(27015), "27016"},
	})
	if err != nil {
		t.Fatalf("portsFromPayload returned error: %v", err)
	}
	if len(ports) != 2 || ports[0] != 27015 || ports[1] != 27016 {
		t.Fatalf("ports=%v, want [27015 27016]", ports)
	}
}

func TestPortsFromPayloadAcceptsJsonArrayString(t *testing.T) {
	ports, err := portsFromPayload(map[string]any{
		"ports": "[27015,27016]",
	})
	if err != nil {
		t.Fatalf("portsFromPayload returned error: %v", err)
	}
	if len(ports) != 2 || ports[0] != 27015 || ports[1] != 27016 {
		t.Fatalf("ports=%v, want [27015 27016]", ports)
	}
}

func TestPortsFromPayloadPrefersPortBlockPorts(t *testing.T) {
	ports, err := portsFromPayload(map[string]any{
		"ports":            "[27015]",
		"port_block_ports": []int{27020},
	})
	if err != nil {
		t.Fatalf("portsFromPayload returned error: %v", err)
	}
	if len(ports) != 1 || ports[0] != 27020 {
		t.Fatalf("ports=%v, want [27020]", ports)
	}
}

func TestPortsFromPayloadFallsBackWhenPortBlockPortsEmpty(t *testing.T) {
	ports, err := portsFromPayload(map[string]any{
		"port_block_ports": []any{},
		"ports":            "27015",
	})
	if err != nil {
		t.Fatalf("portsFromPayload returned error: %v", err)
	}
	if len(ports) != 1 || ports[0] != 27015 {
		t.Fatalf("ports=%v, want [27015]", ports)
	}
}
