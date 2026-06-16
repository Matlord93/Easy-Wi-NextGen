package main

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
)

var templateVariableRegex = regexp.MustCompile(`\{\{\s*([A-Za-z0-9_:-]+)\s*}}`)

func renderTemplateStrict(template string, values map[string]string) (string, error) {
	if template == "" {
		return "", nil
	}

	matches := templateVariableRegex.FindAllStringSubmatch(template, -1)
	for _, match := range matches {
		if len(match) < 2 {
			continue
		}
		key := strings.TrimSpace(match[1])
		if key == "" {
			continue
		}
		if _, ok := values[key]; !ok {
			return "", fmt.Errorf("missing template variable %s", key)
		}
	}

	rendered := template
	for _, match := range matches {
		if len(match) < 2 {
			continue
		}
		key := strings.TrimSpace(match[1])
		if key == "" {
			continue
		}
		rendered = strings.ReplaceAll(rendered, match[0], values[key])
	}

	return rendered, nil
}

func writeStartScript(instanceDir, startCommand string) (string, error) {
	if instanceDir == "" {
		return "", fmt.Errorf("missing instance directory")
	}
	if startCommand == "" {
		return "", fmt.Errorf("missing start command")
	}

	scriptDir := filepath.Join(instanceDir, "_easywi")
	if err := ensureInstanceDir(scriptDir); err != nil {
		return "", err
	}

	if runtime.GOOS != "windows" {
		if err := chownToInstanceOwner(instanceDir, scriptDir); err != nil {
			return "", err
		}
	}

	if runtime.GOOS == "windows" {
		scriptPath := filepath.Join(scriptDir, "start.bat")
		content := fmt.Sprintf("@echo off\r\ncd /d \"%s\"\r\n%s\r\n", instanceDir, startCommand)
		if err := os.WriteFile(scriptPath, []byte(content), instanceFileMode); err != nil {
			return "", fmt.Errorf("write start script: %w", err)
		}
		return scriptPath, nil
	}

	scriptPath := filepath.Join(scriptDir, "start.sh")
	// Write the command directly — do NOT use "bash -lc <double-quoted-string>"
	// because the outer bash would expand $1/$2/$3 etc. from any shell function
	// bodies in the command before the inner shell ever sees them.
	content := fmt.Sprintf("#!/bin/bash\ncd %q\n%s\n", instanceDir, startCommand)
	if err := os.WriteFile(scriptPath, []byte(content), instanceFileMode); err != nil {
		return "", fmt.Errorf("write start script: %w", err)
	}
	if err := chownToInstanceOwner(instanceDir, scriptPath); err != nil {
		return "", err
	}
	if err := os.Chmod(scriptPath, 0o750); err != nil {
		return "", fmt.Errorf("chmod start script: %w", err)
	}

	return fmt.Sprintf("/bin/bash %q", scriptPath), nil
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
	indicators := []string{"PASSWORD", "TOKEN", "SECRET", "KEY", "RCON"}
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
	fields := strings.Fields(command)
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
