package main

import (
	"bufio"
	"errors"
	"io"
	"log"
	"net"
	"os"
	"os/exec"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
)

const (
	wrapperMaxCommandBytes = 512
	wrapperSocketFileMode  = 0o666
)

// runWrapper is the entry point when the agent is invoked with --wrapper.
// It starts the child process identified by childArgs, listens on socketPath
// for console commands and forwards them to the child's stdin.
func runWrapper(instanceID, socketPath string, childArgs []string) {
	if instanceID == "" {
		log.Fatal("wrapper: missing --instance-id")
	}
	if socketPath == "" {
		log.Fatal("wrapper: missing --command-socket")
	}
	if len(childArgs) == 0 {
		log.Fatal("wrapper: missing child command after --")
	}

	if err := os.MkdirAll(filepath.Dir(socketPath), 0o755); err != nil {
		log.Fatalf("wrapper: create socket directory: %v", err)
	}
	_ = os.Remove(socketPath)
	if err := os.Chmod(filepath.Dir(socketPath), 0o755); err != nil {
		log.Fatalf("wrapper: chmod socket directory: %v", err)
	}

	listener, err := net.Listen("unix", socketPath)
	if err != nil {
		log.Fatalf("wrapper: listen on command socket: %v", err)
	}
	defer func() {
		_ = listener.Close()
		_ = os.Remove(socketPath)
	}()
	if err := os.Chmod(socketPath, wrapperSocketFileMode); err != nil {
		log.Fatalf("wrapper: chmod command socket: %v", err)
	}

	cmd := exec.Command(childArgs[0], childArgs[1:]...)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		log.Fatalf("wrapper: open child stdin: %v", err)
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		log.Fatalf("wrapper: open child stdout: %v", err)
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		log.Fatalf("wrapper: open child stderr: %v", err)
	}

	if err := cmd.Start(); err != nil {
		log.Fatalf("wrapper: start child: %v", err)
	}

	var copyWG sync.WaitGroup
	copyWG.Add(2)
	go func() {
		defer copyWG.Done()
		_, _ = io.Copy(os.Stdout, stdout)
	}()
	go func() {
		defer copyWG.Done()
		_, _ = io.Copy(os.Stderr, stderr)
	}()

	commands := make(chan string, 64)
	var connWG sync.WaitGroup
	acceptDone := make(chan struct{})
	go func() {
		defer close(acceptDone)
		for {
			conn, acceptErr := listener.Accept()
			if acceptErr != nil {
				if errors.Is(acceptErr, net.ErrClosed) {
					return
				}
				if strings.Contains(strings.ToLower(acceptErr.Error()), "closed") {
					return
				}
				log.Printf("wrapper: command socket accept error: %v", acceptErr)
				continue
			}
			connWG.Add(1)
			go func(c net.Conn) {
				defer connWG.Done()
				defer func() { _ = c.Close() }()
				scanner := bufio.NewScanner(c)
				scanner.Buffer(make([]byte, 0, 256), wrapperMaxCommandBytes+2)
				for scanner.Scan() {
					line := wrapperSanitize(scanner.Text())
					if line == "" {
						continue
					}
					select {
					case commands <- line:
					default:
						log.Printf("wrapper: command queue full for instance=%s", instanceID)
					}
				}
			}(conn)
		}
	}()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT)

	childDone := make(chan error, 1)
	go func() { childDone <- cmd.Wait() }()

	for {
		select {
		case line := <-commands:
			if line == "" {
				continue
			}
			if _, err := io.WriteString(stdin, line+"\n"); err != nil {
				log.Printf("wrapper: write child stdin: %v", err)
			}
		case sig := <-sigCh:
			if cmd.Process != nil {
				_ = cmd.Process.Signal(sig)
			}
		case err := <-childDone:
			_ = listener.Close()
			connWG.Wait()
			_ = stdin.Close()
			copyWG.Wait()
			<-acceptDone
			if err != nil {
				if exitErr, ok := err.(*exec.ExitError); ok {
					os.Exit(exitErr.ExitCode())
				}
				log.Printf("wrapper: child wait error: %v", err)
				os.Exit(1)
			}
			return
		}
	}
}

func wrapperSanitize(line string) string {
	trimmed := strings.TrimSpace(strings.ReplaceAll(strings.ReplaceAll(line, "\r", ""), "\n", ""))
	if trimmed == "" {
		return ""
	}
	if len(trimmed) > wrapperMaxCommandBytes {
		trimmed = trimmed[:wrapperMaxCommandBytes]
	}
	return trimmed
}
