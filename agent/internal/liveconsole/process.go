package liveconsole

import (
	"io"
	"os"
	"os/exec"
)

type childProcess struct {
	io.ReadWriteCloser
	wait   func() error
	signal func(os.Signal) error
}
type pipeTerminal struct {
	io.ReadCloser
	io.WriteCloser
}

func (p *pipeTerminal) Close() error { _ = p.WriteCloser.Close(); return p.ReadCloser.Close() }
func startPipes(args []string) (*childProcess, error) {
	cmd := exec.Command(args[0], args[1:]...)
	r, w, err := os.Pipe()
	if err != nil {
		return nil, err
	}
	stdin, err := cmd.StdinPipe()
	if err != nil {
		_ = r.Close()
		_ = w.Close()
		return nil, err
	}
	cmd.Stdout = w
	cmd.Stderr = w
	if err = cmd.Start(); err != nil {
		_ = stdin.Close()
		_ = r.Close()
		_ = w.Close()
		return nil, err
	}
	_ = w.Close()
	return &childProcess{ReadWriteCloser: &pipeTerminal{r, stdin}, wait: cmd.Wait, signal: cmd.Process.Signal}, nil
}
