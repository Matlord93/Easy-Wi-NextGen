//go:build windows

package liveconsole

import (
	"context"
	"crypto/sha256"
	"fmt"
	"github.com/Microsoft/go-winio"
	"golang.org/x/sys/windows"
	"net"
	"path/filepath"
	"strings"
)

func pipeName(path string) string {
	sum := sha256.Sum256([]byte(strings.ToLower(filepath.Clean(path))))
	return fmt.Sprintf(`\\.\pipe\easywi-console-%x`, sum[:16])
}
func ListenLocal(path string) (net.Listener, error) {
	user, err := windows.GetCurrentProcessToken().GetTokenUser()
	if err != nil {
		return nil, err
	}
	acl := "D:P(A;;GA;;;SY)(A;;GA;;;BA)(A;;GA;;;" + user.User.Sid.String() + ")"
	return winio.ListenPipe(pipeName(path), &winio.PipeConfig{SecurityDescriptor: acl, InputBufferSize: 4096, OutputBufferSize: 4096})
}
func DialLocal(ctx context.Context, path string) (net.Conn, error) {
	return winio.DialPipeContext(ctx, pipeName(path))
}
