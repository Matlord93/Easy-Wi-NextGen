package main

import (
	"reflect"
	"testing"
)

func TestExtractVersionFromDownloadURL(t *testing.T) {
	cases := []struct {
		url  string
		want string
	}{
		// Old format: version embedded in filename (no archive suffix should leak in)
		{"https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta8/teamspeak-server_linux_amd64-v6.0.0-beta8.tar.bz2", "6.0.0-beta8"},
		// New format: version only in URL path segment, not in filename
		{"https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-amd64.tar.xz", "6.0.0-beta10"},
		{"https://github.com/teamspeak/teamspeak6-server/releases/download/v6.0.0-beta10/teamspeak6-server-linux-arm64.tar.xz", "6.0.0-beta10"},
		// Stable release
		{"https://github.com/teamspeak/teamspeak6-server/releases/download/v6.1.0/teamspeak6-server-linux-amd64.tar.xz", "6.1.0"},
		{"", ""},
	}
	for _, tc := range cases {
		got := extractVersionFromDownloadURL(tc.url)
		if got != tc.want {
			t.Errorf("extractVersionFromDownloadURL(%q) = %q, want %q", tc.url, got, tc.want)
		}
	}
}

func TestExtractArchiveUsesTarXzFlags(t *testing.T) {
	originalRunner := commandOutputRunner
	t.Cleanup(func() { commandOutputRunner = originalRunner })

	var gotName string
	var gotArgs []string
	commandOutputRunner = func(name string, args ...string) (string, error) {
		gotName = name
		gotArgs = append([]string{}, args...)
		return "", nil
	}

	if err := extractArchive("/tmp/teamspeak6-server-linux-amd64.tar.xz", "", "/opt/teamspeak6"); err != nil {
		t.Fatalf("extractArchive returned error: %v", err)
	}

	wantArgs := []string{"-xJf", "/tmp/teamspeak6-server-linux-amd64.tar.xz", "-C", "/opt/teamspeak6", "--strip-components=1"}
	if gotName != "tar" || !reflect.DeepEqual(gotArgs, wantArgs) {
		t.Fatalf("command=%s %v, want tar %v", gotName, gotArgs, wantArgs)
	}
}
