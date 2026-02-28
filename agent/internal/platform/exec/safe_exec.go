package exec

import (
	"context"
	"fmt"
	osexec "os/exec"
	"time"
)

type Runner struct {
	Timeout time.Duration
}

func NewRunner(timeout time.Duration) Runner {
	if timeout <= 0 {
		timeout = 10 * time.Second
	}
	return Runner{Timeout: timeout}
}

func (r Runner) Run(ctx context.Context, name string, args ...string) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, r.Timeout)
	defer cancel()

	cmd := osexec.CommandContext(ctx, name, args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return out, fmt.Errorf("run %s: %w", name, err)
	}
	return out, nil
}
