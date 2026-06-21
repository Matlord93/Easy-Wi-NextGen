package main

import (
	"bufio"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

type RuntimeControlClient struct {
	InstallPath string
	UnixSocket  string
	TCPAddr     string
	AuthToken   string
}

type runtimeControlResponse struct {
	OK      bool           `json:"ok"`
	Command string         `json:"command"`
	Error   string         `json:"error,omitempty"`
	Payload map[string]any `json:"payload,omitempty"`
}

func NewRuntimeControlClient(installPath string) RuntimeControlClient {
	client := RuntimeControlClient{InstallPath: installPath}
	if runtime.GOOS != "windows" {
		client.UnixSocket = filepath.Join(installPath, "control.sock")
	}
	configPath := filepath.Join(installPath, "config.json")
	if content, err := os.ReadFile(configPath); err == nil {
		var config struct {
			Control struct {
				UnixSocket string `json:"unix_socket"`
				TCPAddr    string `json:"tcp_addr"`
				AuthToken  string `json:"auth_token"`
			} `json:"control"`
		}
		if json.Unmarshal(content, &config) == nil {
			if config.Control.UnixSocket != "" {
				client.UnixSocket = config.Control.UnixSocket
			}
			client.TCPAddr = config.Control.TCPAddr
			client.AuthToken = config.Control.AuthToken
		}
	}
	return client
}

func (c RuntimeControlClient) Command(command string, args map[string]any) (runtimeControlResponse, error) {
	request := map[string]any{"command": command, "args": args}
	if c.AuthToken != "" {
		request["token"] = c.AuthToken
	}
	encoded, _ := json.Marshal(request)
	if c.UnixSocket != "" {
		if response, err := c.roundTrip("unix", c.UnixSocket, encoded); err == nil {
			return response, nil
		}
	}
	if c.TCPAddr != "" {
		if !strings.HasPrefix(c.TCPAddr, "127.0.0.1:") && !strings.HasPrefix(c.TCPAddr, "localhost:") && !strings.HasPrefix(c.TCPAddr, "[::1]:") {
			return runtimeControlResponse{}, fmt.Errorf("runtime control tcp address must be local")
		}
		if response, err := c.roundTrip("tcp", c.TCPAddr, encoded); err == nil {
			return response, nil
		}
	}
	return c.writeStateFile(command, args)
}

func (c RuntimeControlClient) roundTrip(network, address string, payload []byte) (runtimeControlResponse, error) {
	conn, err := net.DialTimeout(network, address, 2*time.Second)
	if err != nil {
		return runtimeControlResponse{}, err
	}
	defer func() { _ = conn.Close() }()
	_ = conn.SetDeadline(time.Now().Add(4 * time.Second))
	if _, err := conn.Write(append(payload, '\n')); err != nil {
		return runtimeControlResponse{}, err
	}
	line, err := bufio.NewReader(conn).ReadBytes('\n')
	if err != nil {
		return runtimeControlResponse{}, err
	}
	var response runtimeControlResponse
	if err := json.Unmarshal(line, &response); err != nil {
		return runtimeControlResponse{}, err
	}
	if !response.OK {
		return response, errors.New(response.Error)
	}
	return response, nil
}

func (c RuntimeControlClient) writeStateFile(command string, args map[string]any) (runtimeControlResponse, error) {
	if c.InstallPath == "" {
		return runtimeControlResponse{}, fmt.Errorf("missing install_path for runtime control fallback")
	}
	stateDir := filepath.Join(c.InstallPath, "control-state")
	if err := os.MkdirAll(stateDir, 0o750); err != nil {
		return runtimeControlResponse{}, err
	}
	payload := map[string]any{"command": command, "args": args, "queued_at": time.Now().UTC().Format(time.RFC3339), "transport": "state_file"}
	encoded, _ := json.MarshalIndent(payload, "", "  ")
	path := filepath.Join(stateDir, fmt.Sprintf("%d-%s.json", time.Now().UnixNano(), command))
	if err := os.WriteFile(path, append(encoded, '\n'), 0o640); err != nil {
		return runtimeControlResponse{}, err
	}
	return runtimeControlResponse{OK: true, Command: command, Payload: map[string]any{"accepted": true, "transport": "state_file", "state_file": path, "last_error": "runtime control socket unavailable; command queued as state file"}}, nil
}
