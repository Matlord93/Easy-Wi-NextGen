package main

import (
	"easywi/agent/internal/liveconsole"
	"log"
	"os"
	"os/exec"
)

func runWrapper(instanceID, socketPath string, childArgs []string) {
	if err := liveconsole.RunWrapper(instanceID, socketPath, childArgs); err != nil {
		if e, ok := err.(*exec.ExitError); ok {
			os.Exit(e.ExitCode())
		}
		log.Print(err)
		os.Exit(1)
	}
}
