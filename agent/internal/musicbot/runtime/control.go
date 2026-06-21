package musicbotruntime

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

type ControlConfig struct {
	UnixSocket string `json:"unix_socket,omitempty"`
	TCPAddr    string `json:"tcp_addr,omitempty"`
	AuthToken  string `json:"auth_token,omitempty"`
}

type controlEnvelope struct {
	Token   string         `json:"token,omitempty"`
	Command string         `json:"command"`
	Action  string         `json:"action,omitempty"`
	Args    map[string]any `json:"args,omitempty"`
}

func (r *Runtime) StartControlServer(ctx context.Context) error {
	addr := r.controlUnixSocketPath()
	if addr == "" {
		return nil
	}
	_ = os.Remove(addr)
	if err := os.MkdirAll(filepath.Dir(addr), 0o750); err != nil {
		return err
	}
	listener, err := net.Listen("unix", addr)
	if err != nil {
		return err
	}
	_ = os.Chmod(addr, 0o660)
	go func() {
		<-ctx.Done()
		_ = listener.Close()
		_ = os.Remove(addr)
	}()
	go r.serveControl(listener)
	return nil
}

func (r *Runtime) serveControl(listener net.Listener) {
	for {
		conn, err := listener.Accept()
		if err != nil {
			return
		}
		go r.handleControlConn(conn)
	}
}

func (r *Runtime) handleControlConn(conn net.Conn) {
	defer func() { _ = conn.Close() }()
	scanner := bufio.NewScanner(conn)
	encoder := json.NewEncoder(conn)
	for scanner.Scan() {
		response := r.handleControlLine(scanner.Text())
		_ = encoder.Encode(response)
	}
}

func (r *Runtime) handleControlLine(line string) commandResponse {
	var envelope controlEnvelope
	if err := json.Unmarshal([]byte(strings.TrimSpace(line)), &envelope); err != nil {
		return commandResponse{OK: false, Command: "control", Error: fmt.Sprintf("invalid control json: %v", err)}
	}
	if r.config.Control.AuthToken != "" && envelope.Token != r.config.Control.AuthToken {
		return commandResponse{OK: false, Command: envelope.Command, Error: "control auth failed"}
	}
	command := envelope.Command
	if command == "" {
		command = envelope.Action
	}
	payload, _ := json.Marshal(commandRequest{Command: command, Action: envelope.Action, Args: envelope.Args})
	return r.HandleCommand(string(payload))
}

func (r *Runtime) controlUnixSocketPath() string {
	if runtime.GOOS == "windows" {
		return ""
	}
	if r.config.Control.UnixSocket != "" {
		return r.config.Control.UnixSocket
	}
	base := r.config.InstallPath
	if base == "" {
		base = r.config.DataDir
	}
	if base == "" {
		return ""
	}
	return filepath.Join(base, "control.sock")
}

func ValidateLocalControlAddress(addr string) error {
	if addr == "" {
		return nil
	}
	host, _, err := net.SplitHostPort(addr)
	if err != nil {
		return err
	}
	if host != "127.0.0.1" && host != "localhost" && host != "::1" {
		return errors.New("control TCP address must be local")
	}
	return nil
}
