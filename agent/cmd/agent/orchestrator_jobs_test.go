package main

import (
	"reflect"
	"testing"
)

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
