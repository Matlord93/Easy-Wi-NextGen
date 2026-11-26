package main

import (
	"math"
	"net"
	"net/netip"
	"strings"
)

func (e *kvmEngine) installNetworkReady(g kvmGuest) bool {
	if len(g.StaticAddresses) == 0 {
		return true
	}
	raw, err := e.readGuestAgent(g.ID, "guest-network-get-interfaces")
	if err != nil {
		return false
	}
	interfaces, _ := kvmGuestNetwork(raw)["interfaces"].([]any)
	primaryMAC := kvmGuestMAC(g.ID)
	observed := make(map[netip.Prefix]bool)
	for _, value := range interfaces {
		iface, _ := value.(map[string]any)
		if iface["mac"] != primaryMAC {
			continue
		}
		addresses, _ := iface["addresses"].([]any)
		for _, value := range addresses {
			address, _ := value.(map[string]any)
			text, _ := address["address"].(string)
			prefix, _ := address["prefix"].(int)
			if ip, err := netip.ParseAddr(text); err == nil {
				observed[netip.PrefixFrom(ip, prefix)] = true
			}
		}
	}
	for _, reservation := range g.StaticAddresses {
		if !observed[reservation.Address] {
			return false
		}
	}
	return true
}

func kvmEmptyGuestNetwork(state string) map[string]any {
	return map[string]any{"state": state, "interfaces": []any{}, "limited": false}
}
func kvmGuestNetwork(input any) map[string]any {
	rows, ok := input.([]any)
	if !ok {
		return kvmEmptyGuestNetwork("invalid_response")
	}
	out := []any{}
	limited := len(rows) > 16
	names := map[string]bool{}
	for i, raw := range rows {
		if i >= 16 {
			break
		}
		row, ok := raw.(map[string]any)
		if !ok {
			limited = true
			continue
		}
		name := kvmAgentText(row["name"], 128)
		if name == "" || names[name] {
			limited = true
			continue
		}
		names[name] = true
		var mac any
		if rawMAC, present := row["hardware-address"]; present && rawMAC != nil {
			text := kvmAgentText(rawMAC, 59)
			parsed, err := net.ParseMAC(text)
			if err == nil && strings.Contains(text, ":") {
				mac = parsed.String()
			} else {
				limited = true
			}
		}
		ips := []any{}
		if value, present := row["ip-addresses"]; present {
			var valid bool
			ips, valid = value.([]any)
			if !valid {
				ips = []any{}
				limited = true
			}
		}
		if len(ips) > 16 {
			limited = true
		}
		addresses := []any{}
		seen := map[string]bool{}
		for j, rawIP := range ips {
			if j >= 16 {
				break
			}
			ip, ok := rawIP.(map[string]any)
			if !ok {
				limited = true
				continue
			}
			family, _ := ip["ip-address-type"].(string)
			text := kvmAgentText(ip["ip-address"], 45)
			addr, err := netip.ParseAddr(text)
			prefix, isNumber := ip["prefix"].(float64)
			max := 128.0
			if family == "ipv4" {
				max = 32
			}
			if err != nil || addr.Zone() != "" || !isNumber || math.IsNaN(prefix) || prefix < 0 || prefix > max || math.Trunc(prefix) != prefix || (family != "ipv4" && family != "ipv6") || (family == "ipv4" && !addr.Is4()) || (family == "ipv6" && !addr.Is6()) {
				limited = true
				continue
			}
			key := netip.PrefixFrom(addr, int(prefix)).String()
			if seen[key] {
				limited = true
				continue
			}
			seen[key] = true
			addresses = append(addresses, map[string]any{"family": family, "address": addr.String(), "prefix": int(prefix)})
		}
		out = append(out, map[string]any{"name": name, "mac": mac, "addresses": addresses})
	}
	return map[string]any{"state": "available", "interfaces": out, "limited": limited}
}
