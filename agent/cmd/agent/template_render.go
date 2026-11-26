package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"

	"github.com/anmitsu/go-shlex"
)

var templateVariableRegex = regexp.MustCompile(`\{\{\s*([A-Za-z0-9_:-]+)\s*}}`)

func renderTemplateStrict(template string, values map[string]string) (string, error) {
	matches := templateVariableRegex.FindAllStringSubmatch(template, -1)
	for _, match := range matches {
		if _, ok := values[match[1]]; !ok {
			return "", fmt.Errorf("missing template variable %s", match[1])
		}
	}
	for _, match := range matches {
		template = strings.ReplaceAll(template, match[0], values[match[1]])
	}
	return template, nil
}

func renderGameserverCommand(template string, values map[string]string) (string, error) {
	if runtime.GOOS == "windows" {
		return renderTemplateStrict(template, values)
	}
	var rendered strings.Builder
	var quote byte
	last := 0
	for _, match := range templateVariableRegex.FindAllStringSubmatchIndex(template, -1) {
		literal := template[last:match[0]]
		rendered.WriteString(literal)
		quote = shellQuoteAfter(literal, quote)
		key := template[match[2]:match[3]]
		value, ok := values[key]
		if !ok {
			return "", fmt.Errorf("missing template variable %s", key)
		}
		if filepath.IsAbs(value) {
			switch key {
			case "INSTANCE_DIR", "INSTALL_DIR", "GAME_DIR", "instance_dir", "install_path", "game_dir", "SERVER_DIR", "USER_HOME_DIR", "HOME_DIR":
				value = shellPathInQuote(value, quote)
			}
		}
		rendered.WriteString(value)
		last = match[1]
	}
	rendered.WriteString(template[last:])
	return rendered.String(), nil
}

func shellQuoteAfter(text string, quote byte) byte {
	for i := 0; i < len(text); i++ {
		char := text[i]
		if char == '\\' && quote != '\'' {
			i++
		} else if quote != 0 && char == quote {
			quote = 0
		} else if quote == 0 && (char == '\'' || char == '"') {
			quote = char
		}
	}
	return quote
}

func shellPathInQuote(path string, quote byte) string {
	switch quote {
	case '\'':
		return strings.ReplaceAll(path, "'", "'\"'\"'")
	case '"':
		escaped := shellPathArgument(path)
		if escaped == path {
			return path
		}
		return `"` + escaped + `"`
	default:
		return shellPathArgument(path)
	}
}

func shellPathArgument(path string) string {
	for _, r := range path {
		switch {
		case r >= 'a' && r <= 'z', r >= 'A' && r <= 'Z', r >= '0' && r <= '9', strings.ContainsRune("/._-+@:", r):
			continue
		default:
			return shellEscape(path)
		}
	}
	return path
}

func writeStartScript(instanceDir, startCommand string) (string, error) {
	return writeStartScriptInGameDir(instanceDir, instanceDir, startCommand)
}

func writeStartScriptInGameDir(scriptHome, gameDir, startCommand string) (string, error) {
	if scriptHome == "" || gameDir == "" {
		return "", fmt.Errorf("missing instance directory")
	}
	if startCommand == "" {
		return "", fmt.Errorf("missing start command")
	}

	if runtime.GOOS == "windows" {
		scriptDir := filepath.Join(scriptHome, "_easywi")
		if err := ensureInstanceDir(scriptDir); err != nil {
			return "", err
		}
		scriptPath := filepath.Join(scriptDir, "start.bat")
		content := fmt.Sprintf("@echo off\r\ncd /d \"%s\"\r\n%s\r\n", gameDir, startCommand)
		if err := os.WriteFile(scriptPath, []byte(content), instanceFileMode); err != nil {
			return "", fmt.Errorf("write start script: %w", err)
		}
		return scriptPath, nil
	}

	scriptName := filepath.Join("_easywi", "start.sh")
	// Write the command directly — do NOT use "bash -lc <double-quoted-string>"
	// because the outer bash would expand $1/$2/$3 etc. from any shell function
	// bodies in the command before the inner shell ever sees them.
	content := fmt.Sprintf("#!/bin/bash\ncd -- %s || exit 1\n%s\n", shellEscape(gameDir), startCommand)
	if err := publishInstanceFile(scriptHome, scriptName, strings.NewReader(content), 0750); err != nil {
		return "", fmt.Errorf("write start script: %w", err)
	}

	return "/bin/bash " + systemdExecArgument(filepath.Join(scriptHome, scriptName)), nil
}

func maskSensitiveValues(input string, values map[string]string) string {
	if input == "" || len(values) == 0 {
		return input
	}

	masked := input
	for key, value := range values {
		if value == "" {
			continue
		}
		if !isSensitiveKey(key) {
			continue
		}
		masked = strings.ReplaceAll(masked, value, "****")
	}
	return masked
}

func isSensitiveKey(key string) bool {
	normalized := strings.ToUpper(key)
	indicators := []string{"PASSWORD", "TOKEN", "SECRET", "KEY", "RCON", "GSLT"}
	for _, indicator := range indicators {
		if strings.Contains(normalized, indicator) {
			return true
		}
	}
	return false
}

func validateBinaryExists(instanceDir, startCommand string) error {
	binaryToken := extractFirstCommandToken(startCommand)
	if binaryToken == "" {
		return nil
	}
	if strings.HasPrefix(binaryToken, "-") || strings.HasPrefix(binaryToken, "+") {
		return nil
	}
	// Shell builtins such as cd are handled by the generated shell script and
	// must never be resolved as instance-relative binaries like /home/gs36/cd.
	if isShellBuiltinToken(binaryToken) {
		return nil
	}
	if !strings.Contains(binaryToken, "/") && !filepath.IsAbs(binaryToken) {
		if _, err := exec.LookPath(binaryToken); err == nil {
			return nil
		}
	}
	binaryPath := resolveBinaryPath(instanceDir, binaryToken)
	if binaryPath == "" {
		return nil
	}
	if !pathExists(binaryPath) {
		return fmt.Errorf("missing binary %q (resolved to %s)", binaryToken, binaryPath)
	}
	return nil
}

func extractFirstCommandToken(command string) string {
	if command == "" {
		return ""
	}
	fields := commandWords(command)
	for i := 0; i < len(fields); i++ {
		token := normalizeCommandToken(fields[i])
		if token == "" || token == ";" || token == "&&" || token == "||" {
			continue
		}
		// Shell function declarations (e.g. "set_property() {") are not binaries.
		if strings.Contains(token, "(") {
			return ""
		}
		if isShellAssignmentToken(token) {
			continue
		}
		if isShellBuiltinToken(token) {
			if commandTokenConsumesNextArgument(token) && i+1 < len(fields) {
				i++
			}
			continue
		}
		return token
	}
	return ""
}

func commandWords(command string) []string {
	if runtime.GOOS != "windows" {
		if words, err := shlex.Split(command, true); err == nil {
			return words
		}
	}
	return strings.Fields(command)
}

func normalizeCommandToken(token string) string {
	token = strings.TrimSpace(token)
	token = strings.Trim(token, "\"'")
	token = strings.TrimRight(token, ";")
	return strings.TrimSpace(token)
}

func isShellAssignmentToken(token string) bool {
	if strings.HasPrefix(token, "=") || !strings.Contains(token, "=") {
		return false
	}
	name, _, _ := strings.Cut(token, "=")
	if name == "" {
		return false
	}
	for i, r := range name {
		if r == '_' || r >= 'A' && r <= 'Z' || r >= 'a' && r <= 'z' || i > 0 && r >= '0' && r <= '9' {
			continue
		}
		return false
	}
	return true
}

func isShellBuiltinToken(token string) bool {
	switch token {
	case "cd", "export", "set", "unset", "ulimit", "umask", "shift", "alias", "unalias", "source", ".", "true", "false", "local", "readonly", "command", "exec":
		return true
	default:
		return false
	}
}

func commandTokenConsumesNextArgument(token string) bool {
	switch token {
	case "cd", "source", ".", "umask", "ulimit":
		return true
	default:
		return false
	}
}

func resolveBinaryPath(instanceDir, token string) string {
	trimmed := strings.Trim(token, "\"'")
	if trimmed == "" {
		return ""
	}
	trimmed = strings.TrimPrefix(trimmed, "./")
	if filepath.IsAbs(trimmed) {
		return trimmed
	}
	return filepath.Join(instanceDir, trimmed)
}
