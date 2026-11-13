//go:build windows

package liveconsole

import (
	"context"
	"fmt"
	"github.com/UserExistsError/conpty"
	"os"
	"strings"
	"syscall"
)

func startChild(args []string) (*childProcess, error) {
	if !conpty.IsConPtyAvailable() {
		return startPipes(args)
	}
	quoted := make([]string, len(args))
	for i, s := range args {
		quoted[i] = syscall.EscapeArg(s)
	}
	c, err := conpty.Start(strings.Join(quoted, " "), conpty.ConPtyDimensions(120, 40))
	if err != nil {
		return nil, err
	}
	return &childProcess{ReadWriteCloser: c, wait: func() error {
		code, err := c.Wait(context.Background())
		if err != nil {
			return err
		}
		if code != 0 {
			return fmt.Errorf("game process exited with code %d", code)
		}
		return nil
	}, signal: func(sig os.Signal) error {
		if sig == os.Interrupt {
			_, err := c.Write([]byte{3})
			return err
		}
		p, err := os.FindProcess(c.Pid())
		if err != nil {
			return err
		}
		defer p.Release()
		return p.Kill()
	}}, nil
}
