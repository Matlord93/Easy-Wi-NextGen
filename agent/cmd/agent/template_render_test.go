package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestExtractFirstCommandTokenSkipsShellFunctions(t *testing.T) {
	// start_params that begin with a shell function definition must not be
	// treated as a binary name — the token contains "(" so it is a shell
	// language construct, not an executable path.
	shellPreamble := `set_property() { local f="$1" k="$2" v="$3"; touch "$f"; { grep -v "^${k}=" "$f" 2>/dev/null || true; printf '%s=%s\n' "$k" "$v"; } > "${f}.tmp" && mv "${f}.tmp" "$f"; }; set_property server.properties server-port "25565"; ./server`
	token := extractFirstCommandToken(shellPreamble)
	if token != "" {
		t.Fatalf("expected empty token for shell function preamble, got %q", token)
	}
}

func TestExtractFirstCommandTokenReturnsNormalBinary(t *testing.T) {
	token := extractFirstCommandToken("./server -port 27015")
	if token != "./server" {
		t.Fatalf("expected ./server, got %q", token)
	}
}

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
