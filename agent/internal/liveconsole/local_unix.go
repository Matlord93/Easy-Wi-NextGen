//go:build !windows

package liveconsole

import (
	"context"
	"net"
	"os"
)

func ListenLocal(path string) (net.Listener, error) {
	// Do not unlink a live socket: a second wrapper must not replace a game.
	if c, err := net.Dial("unix", path); err == nil {
		c.Close()
		return nil, os.ErrExist
	}
	_ = os.Remove(path)
	l, err := net.Listen("unix", path)
	if err != nil {
		return nil, err
	}
	if err = os.Chmod(path, 0600); err != nil {
		l.Close()
		return nil, err
	}
	return l, nil
}
func DialLocal(ctx context.Context, path string) (net.Conn, error) {
	return (&net.Dialer{}).DialContext(ctx, "unix", path)
}
