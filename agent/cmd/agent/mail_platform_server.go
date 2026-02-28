package main

import (
	"log"
	"net/http"

	platformexec "easywi/agent/internal/platform/exec"
	platformhttp "easywi/agent/internal/platform/http"
)

// startMailPlatformServer is a skeleton entrypoint for dedicated mail-agent API serving.
func startMailPlatformServer(configPath string) error {
	cfg, err := platformhttp.LoadConfig(configPath)
	if err != nil {
		return err
	}

	srv := platformhttp.NewServer(platformhttp.ServiceReadiness{Runner: platformexec.NewRunner(0)})
	log.Printf("mail platform server listening on %s", cfg.Listen)
	return http.ListenAndServe(cfg.Listen, srv.Handler())
}
