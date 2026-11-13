package main

import (
	"easywi/agent/internal/liveconsole"
	"log"
	"os"
	"os/exec"
	"strings"
)

const wrapperMaxCommandBytes = 512

func runWrapper(instanceID, socketPath string, childArgs []string) {
	if err := liveconsole.RunWrapper(instanceID, socketPath, childArgs); err != nil {
		if e, ok := err.(*exec.ExitError); ok {
			os.Exit(e.ExitCode())
		}
		log.Print(err)
		os.Exit(1)
	}
}
func wrapperSanitize(line string) string {
	trimmed := strings.TrimSpace(strings.ReplaceAll(strings.ReplaceAll(line, "\r", ""), "\n", ""))
	if len(trimmed) > wrapperMaxCommandBytes {
		trimmed = trimmed[:wrapperMaxCommandBytes]
	}
	return trimmed
}
