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
				configured := filepath.Clean(config.Control.UnixSocket)
				expected := filepath.Join(installPath, "control.sock")
				if configured == expected {
					client.UnixSocket = configured
				}
			}
			// Per-instance Unix sockets are mandatory on Unix so jobs cannot silently
			// fall back to another runtime listener. TCP control is only a Windows fallback.
			if runtime.GOOS == "windows" {
				client.TCPAddr = config.Control.TCPAddr
			}
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
		response, err := c.roundTrip("unix", c.UnixSocket, encoded)
		if err != nil {
			return runtimeControlResponse{}, fmt.Errorf("runtime control socket unavailable for install_path %s at %s: %w", c.InstallPath, c.UnixSocket, err)
		}
		return response, nil
	}
	if c.TCPAddr != "" {
		if !strings.HasPrefix(c.TCPAddr, "127.0.0.1:") && !strings.HasPrefix(c.TCPAddr, "localhost:") && !strings.HasPrefix(c.TCPAddr, "[::1]:") {
			return runtimeControlResponse{}, fmt.Errorf("runtime control tcp address must be local")
		}
		response, err := c.roundTrip("tcp", c.TCPAddr, encoded)
		if err != nil {
			return runtimeControlResponse{}, fmt.Errorf("runtime control tcp unavailable for install_path %s at %s: %w", c.InstallPath, c.TCPAddr, err)
		}
		return response, nil
	}
	return runtimeControlResponse{}, fmt.Errorf("runtime control socket missing for install_path %s", c.InstallPath)
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
