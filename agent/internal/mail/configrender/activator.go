package configrender

import (
	"context"
	"fmt"
	"os/exec"
)

type Activator struct{}

func NewActivator() *Activator { return &Activator{} }

func (a *Activator) ReloadAndHealthcheck(ctx context.Context, snapshot Snapshot) (map[string]string, error) {
	health := map[string]string{}
	if err := runSystemctl(ctx, "reload", "postfix"); err != nil {
		return nil, &ApplyError{Class: ErrClassActivate, Service: "postfix", Code: "reload_failed", Message: err.Error()}
	}
	health["postfix_reload"] = "ok"

	if err := runSystemctl(ctx, "reload", "dovecot"); err != nil {
		return nil, &ApplyError{Class: ErrClassActivate, Service: "dovecot", Code: "reload_failed", Message: err.Error()}
	}
	health["dovecot_reload"] = "ok"

	if len(snapshot.DKIMKeys) > 0 {
		if err := runSystemctl(ctx, "reload", "opendkim"); err != nil {
			return nil, &ApplyError{Class: ErrClassActivate, Service: "opendkim", Code: "reload_failed", Message: err.Error()}
		}
		health["opendkim_reload"] = "ok"
	}

	for _, svc := range []string{"postfix", "dovecot", "opendkim"} {
		if svc == "opendkim" && len(snapshot.DKIMKeys) == 0 {
			continue
		}
		if err := runSystemctl(ctx, "is-active", svc); err != nil {
			return health, &ApplyError{Class: ErrClassHealth, Service: svc, Code: "healthcheck_failed", Message: err.Error()}
		}
		health[svc+"_active"] = "ok"
	}

	return health, nil
}

func runSystemctl(ctx context.Context, action, service string) error {
	cmd := exec.CommandContext(ctx, "systemctl", action, service)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("systemctl %s %s: %w: %s", action, service, err, string(output))
	}
	return nil
}
