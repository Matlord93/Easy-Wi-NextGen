package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func sevenDaysPayload() map[string]any {
	return map[string]any{
		"game_key":                        "seven_days_to_die",
		"seven_days_to_die_managed_ports": "true",
		"port_reservations": []any{
			map[string]any{"role": "game", "port": 27021},
			map[string]any{"role": "webdashboard", "port": 27022},
			map[string]any{"role": "telnet", "port": 27023},
		},
	}
}

func TestSevenDaysConfigBindsReservationsAndPreservesOtherSettings(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, sevenDaysConfig)
	original := "<?xml version=\"1.0\"?>\r\n<ServerSettings>\r\n" +
		"<!-- <property name=\"ServerPort\" value=\"12345\"/> -->\r\n" +
		"<property value='26900' name='ServerPort'/>\r\n" +
		"<property name=\"WebDashboardEnabled\" value=\"false\"/>\r\n" +
		"<property name=\"TelnetPort\" value=\"8081\" />\r\n" +
		"<property name=\"TelnetPassword\" value=\"a&amp;b\"/>\r\n" +
		"<property name=\"GameWorld\" value=\"Navezgane\"/>\r\n</ServerSettings>\r\n"
	if err := os.WriteFile(path, []byte(original), 0640); err != nil {
		t.Fatal(err)
	}
	if err := ensureSevenDaysToDiePorts(dir, sevenDaysPayload()); err != nil {
		t.Fatal(err)
	}
	saved, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	want := []string{
		`<property value="27021" name="ServerPort"/>`,
		`<property name="TelnetPort" value="27023"/>`,
		`<property name="WebDashboardPort" value="27022" />`,
		`<!-- <property name="ServerPort" value="12345"/> -->`,
		`<property name="WebDashboardEnabled" value="false"/>`,
		`<property name="TelnetPassword" value="a&amp;b"/>`,
		`<property name="GameWorld" value="Navezgane"/>`,
	}
	for _, value := range want {
		if !strings.Contains(string(saved), value) {
			t.Errorf("configuration missing %s", value)
		}
	}
	first, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if err := ensureSevenDaysToDiePorts(dir, sevenDaysPayload()); err != nil {
		t.Fatal(err)
	}
	second, err := os.Stat(path)
	if err != nil || !os.SameFile(first, second) {
		t.Fatal("an already synchronized config should not be replaced", err)
	}
}

func TestSevenDaysDoesNotWriteWithoutAllReservationsOrOptIn(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, sevenDaysConfig)
	original := `<ServerSettings><property name="ServerPort" value="26900"/></ServerSettings>`
	if err := os.WriteFile(path, []byte(original), 0640); err != nil {
		t.Fatal(err)
	}
	payload := sevenDaysPayload()
	delete(payload, "port_reservations")
	if err := ensureSevenDaysToDiePorts(dir, payload); err == nil {
		t.Fatal("managed starts must not fall back to unallocated ports")
	}
	delete(payload, "seven_days_to_die_managed_ports")
	if err := ensureSevenDaysToDiePorts(dir, payload); err != nil {
		t.Fatal(err)
	}
	saved, err := os.ReadFile(path)
	if err != nil || string(saved) != original {
		t.Fatalf("configuration changed: %s, %v", saved, err)
	}
}
