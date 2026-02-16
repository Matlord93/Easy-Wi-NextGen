package main

import (
	"bufio"
	"errors"
	"flag"
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
	maxCommandBytes = 512
	socketFileMode  = 0o660
)

func main() {
	var instanceID string
	var socketPath string

	flag.StringVar(&instanceID, "instance-id", "", "instance identifier")
	flag.StringVar(&socketPath, "command-socket", "", "unix socket path for console command input")
	flag.Parse()

	if instanceID == "" {
		log.Fatal("missing --instance-id")
	}
	if socketPath == "" {
		log.Fatal("missing --command-socket")
	}
	childArgs := flag.Args()
	if len(childArgs) == 0 {
		log.Fatal("missing child command after --")
	}

	if err := os.MkdirAll(filepath.Dir(socketPath), 0o750); err != nil {
		log.Fatalf("create socket directory: %v", err)
	}
	_ = os.Remove(socketPath)

	listener, err := net.Listen("unix", socketPath)
	if err != nil {
		log.Fatalf("listen on command socket: %v", err)
	}
	defer func() {
		_ = listener.Close()
		_ = os.Remove(socketPath)
	}()
	if err := os.Chmod(socketPath, socketFileMode); err != nil {
		log.Fatalf("chmod command socket: %v", err)
	}

	cmd := exec.Command(childArgs[0], childArgs[1:]...)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		log.Fatalf("open child stdin: %v", err)
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		log.Fatalf("open child stdout: %v", err)
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		log.Fatalf("open child stderr: %v", err)
	}

	if err := cmd.Start(); err != nil {
		log.Fatalf("start child: %v", err)
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
				log.Printf("command socket accept error: %v", acceptErr)
				continue
			}
			connWG.Add(1)
			go func(c net.Conn) {
				defer connWG.Done()
				defer func() {
					if err := c.Close(); err != nil {
						log.Printf("command socket close error: %v", err)
					}
				}()
				scanner := bufio.NewScanner(c)
				scanner.Buffer(make([]byte, 0, 256), maxCommandBytes+2)
				for scanner.Scan() {
					line := sanitize(scanner.Text())
					if line == "" {
						continue
					}
					select {
					case commands <- line:
					default:
						log.Printf("command queue full for instance=%s", instanceID)
					}
				}
			}(conn)
		}
	}()

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGTERM, syscall.SIGINT)

	childDone := make(chan error, 1)
	go func() {
		childDone <- cmd.Wait()
	}()

	for {
		select {
		case line := <-commands:
			if line == "" {
				continue
			}
			if _, err := io.WriteString(stdin, line+"\n"); err != nil {
				log.Printf("write child stdin error: %v", err)
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
				log.Printf("child wait error: %v", err)
				os.Exit(1)
			}
			return
		}
	}
}

func sanitize(line string) string {
	trimmed := strings.TrimSpace(strings.ReplaceAll(strings.ReplaceAll(line, "\r", ""), "\n", ""))
	if trimmed == "" {
		return ""
	}
	if len(trimmed) > maxCommandBytes {
		trimmed = trimmed[:maxCommandBytes]
	}
	return trimmed
}
