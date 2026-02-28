package http

import (
	"context"
	"strings"

	safeexec "easywi/agent/internal/platform/exec"
)

type ServiceReadiness struct {
	Runner safeexec.Runner
}

func (s ServiceReadiness) CheckServices() map[string]bool {
	ctx := context.Background()
	services := []string{"postfix", "dovecot", "opendkim"}
	out := make(map[string]bool, len(services))
	for _, svc := range services {
		b, err := s.Runner.Run(ctx, "systemctl", "is-active", svc)
		out[svc] = err == nil && strings.TrimSpace(string(b)) == "active"
	}
	return out
}
