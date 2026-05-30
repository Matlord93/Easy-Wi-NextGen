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

func TestWriteStartScriptWritesCommandDirectly(t *testing.T) {
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

	// Command must be written directly to the script — not wrapped in
	// "bash -lc <double-quoted-string>" which would cause the outer shell
	// to expand $1/$2/$3 in any embedded shell function bodies.
	if strings.Contains(content, "bash -lc") {
		t.Fatalf("start script must not use bash -lc wrapper (expands $1/$2/$3 prematurely): %s", content)
	}
	if !strings.Contains(content, startCommand) {
		t.Fatalf("start script must contain the command directly, got: %s", content)
	}
	if !strings.Contains(scriptInvocation, "/bin/bash ") {
		t.Fatalf("expected bash invocation for start script, got: %s", scriptInvocation)
	}
}

func TestWriteStartScriptPreservesShellVariables(t *testing.T) {
	instanceDir := t.TempDir()

	// A command with a shell function that uses $1/$2/$3 — these must be
	// written literally so they resolve to the function's own arguments,
	// not to the outer shell's (empty) positional parameters.
	startCommand := `set_property() { local f="$1" k="$2" v="$3"; touch "$f"; }; set_property server.properties motd "Test"`
	_, err := writeStartScript(instanceDir, startCommand)
	if err != nil {
		t.Fatalf("writeStartScript failed: %v", err)
	}

	scriptPath := filepath.Join(instanceDir, "_easywi", "start.sh")
	contentBytes, err := os.ReadFile(scriptPath)
	if err != nil {
		t.Fatalf("failed to read script: %v", err)
	}
	content := string(contentBytes)

	if !strings.Contains(content, `local f="$1"`) {
		t.Fatalf("script must preserve $1 literally, not expand it; got: %s", content)
	}
	if !strings.Contains(content, `set_property server.properties motd "Test"`) {
		t.Fatalf("set_property call missing from script; got: %s", content)
	}
}
