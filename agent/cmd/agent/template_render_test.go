package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestWriteStartScriptWrapsCommandInShell(t *testing.T) {
	instanceDir := t.TempDir()

	startCommand := `cd ./game && ./server -port 27015`
	scriptInvocation, err := writeStartScript(instanceDir, startCommand)
	if err != nil {
		t.Fatalf("writeStartScript failed: %v", err)
	}

	scriptPath := filepath.Join(instanceDir, "_easywi", "start.sh")
	contentBytes, err := os.ReadFile(scriptPath)
	if err != nil {
		t.Fatalf("failed to read script: %v", err)
	}
	content := string(contentBytes)

	if strings.Contains(content, "exec cd") {
		t.Fatalf("start script must not exec shell builtins directly: %s", content)
	}

	expected := "exec /bin/bash -lc "
	if !strings.Contains(content, expected) {
		t.Fatalf("expected start script to execute command via shell wrapper, got: %s", content)
	}

	if !strings.Contains(scriptInvocation, "/bin/bash ") {
		t.Fatalf("expected bash invocation for start script, got: %s", scriptInvocation)
	}
}
