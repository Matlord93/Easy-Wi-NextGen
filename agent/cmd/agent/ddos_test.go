package main

import (
	"sort"
	"testing"
)

func TestDdosPortsFromPayloadCombinesAllPortSources(t *testing.T) {
	ports, err := ddosPortsFromPayload(map[string]any{
		"ports":            []any{float64(27015), "27016"},
		"port_block_ports": "27017",
		"port_reservations": []any{
			map[string]any{"role": "game", "port": float64(27018)},
		},
	})
	if err != nil {
		t.Fatalf("ddosPortsFromPayload returned error: %v", err)
	}
	sort.Ints(ports)
	want := []int{27015, 27016, 27017, 27018}
	if len(ports) != len(want) {
		t.Fatalf("ports=%v, want %v", ports, want)
	}
	for i, port := range want {
		if ports[i] != port {
			t.Fatalf("ports=%v, want %v", ports, want)
		}
	}
}

func TestDdosProtocolsDefaultToTcpAndUdp(t *testing.T) {
	protocols := defaultDdosProtocols()
	if len(protocols) != 2 || protocols[0] != "tcp" || protocols[1] != "udp" {
		t.Fatalf("protocols=%v, want [tcp udp]", protocols)
	}
}

func TestDdosPortFromAddressParsesIPv4AndIPv6(t *testing.T) {
	cases := map[string]int{
		"192.0.2.10:27015":    27015,
		"[2001:db8::1]:27016": 27016,
		"*:25565":             25565,
		":::27017":            27017,
	}
	for address, want := range cases {
		got, ok := ddosPortFromAddress(address)
		if !ok || got != want {
			t.Fatalf("ddosPortFromAddress(%q)=(%d,%v), want (%d,true)", address, got, ok, want)
		}
	}
}

func TestDdosBuildPortStatsMarksAttackedPorts(t *testing.T) {
	stats := ddosBuildPortStats([]int{27015, 27016}, map[int]int{27015: ddosSynPortThreshold, 27016: ddosSynPortThreshold - 1})
	attacked := ddosAttackedPorts(stats)
	if len(attacked) != 1 || attacked[0] != 27015 {
		t.Fatalf("attacked=%v, want [27015]", attacked)
	}
}
