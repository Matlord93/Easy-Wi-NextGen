package configrender

import (
	"context"
	"fmt"
	"os/exec"
)

type Validator struct{}

func NewValidator() *Validator { return &Validator{} }

func (v *Validator) Validate(ctx context.Context, snapshot Snapshot) error {
	if err := runCheckedCommand(ctx, "postfix", "check"); err != nil {
		return &ApplyError{Class: ErrClassValidate, Service: "postfix", Code: "postfix_check_failed", Message: err.Error()}
	}
	if err := runCheckedCommand(ctx, "doveconf", "-n"); err != nil {
		return &ApplyError{Class: ErrClassValidate, Service: "dovecot", Code: "dovecot_config_test_failed", Message: err.Error()}
	}
	for _, key := range snapshot.DKIMKeys {
		if !key.Enabled {
			continue
		}
		if err := runCheckedCommand(ctx, "opendkim-testkey", "-x", "/etc/opendkim.conf", "-d", key.Domain, "-s", key.Selector, "-k", key.PrivateKeyPath); err != nil {
			return &ApplyError{Class: ErrClassValidate, Service: "opendkim", Code: "opendkim_config_test_failed", Message: err.Error()}
		}
		break
	}
	return nil
}

func runCheckedCommand(ctx context.Context, binary string, args ...string) error {
	cmd := exec.CommandContext(ctx, binary, args...)
	output, err := cmd.CombinedOutput()
	if err != nil {
		return fmt.Errorf("%s %v: %w: %s", binary, args, err, string(output))
	}
	return nil
}
