//go:build !windows

package liveconsole

import (
	"github.com/creack/pty"
	"os/exec"
)

func startChild(args []string) (*childProcess, error) {
	cmd := exec.Command(args[0], args[1:]...)
	f, err := pty.Start(cmd)
	if err != nil {
		return startPipes(args)
	}
	return &childProcess{ReadWriteCloser: f, wait: cmd.Wait, signal: cmd.Process.Signal}, nil
}
