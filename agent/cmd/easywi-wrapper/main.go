// Compatibility executable for older units. New units use easywi-agent --wrapper.
package main

import (
	"easywi/agent/internal/liveconsole"
	"flag"
	"log"
	"os"
	"os/exec"
)

func main() {
	id := flag.String("instance-id", "", "instance identifier")
	socket := flag.String("command-socket", "", "local console endpoint path")
	flag.Parse()
	if err := liveconsole.RunWrapper(*id, *socket, flag.Args()); err != nil {
		if e, ok := err.(*exec.ExitError); ok {
			os.Exit(e.ExitCode())
		}
		log.Print(err)
		os.Exit(1)
	}
}
