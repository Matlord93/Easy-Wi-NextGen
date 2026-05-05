package main

import "testing"

func TestHandleMailCLIDryRun(t *testing.T) {
	if ok := handleMailCLI([]string{"mail", "install", "--dry-run"}); !ok {
		t.Fatal("expected mail CLI handler to process dry-run")
	}
}

func TestHandleMailCLINotHandled(t *testing.T) {
	if ok := handleMailCLI([]string{"status"}); ok {
		t.Fatal("expected non-mail command to be ignored")
	}
}
