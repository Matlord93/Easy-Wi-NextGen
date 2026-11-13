package liveconsole

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net"
	"os"
	"os/signal"
	"path/filepath"
	"regexp"
	"strings"
	"sync"
	"syscall"
	"time"
)

var instanceIDPattern = regexp.MustCompile(`^[a-zA-Z0-9_-]{1,64}$`)

// RunWrapper owns the game process, not the viewers. The caller's working
// directory/environment and the original argument vector are preserved.
func RunWrapper(instanceID, socketPath string, args []string) error {
	if !instanceIDPattern.MatchString(instanceID) || socketPath == "" || len(args) == 0 {
		return errors.New("wrapper: instance, socket and child command required")
	}
	if err := os.MkdirAll(filepath.Dir(socketPath), 0750); err != nil {
		return err
	}
	commands, err := ListenLocal(socketPath)
	if err != nil {
		return fmt.Errorf("command listener: %w", err)
	}
	defer func() { _ = commands.Close() }()
	output, err := ListenLocal(socketPath + ".output")
	if err != nil {
		return fmt.Errorf("output listener: %w", err)
	}
	defer func() { _ = output.Close() }()
	child, err := startChild(args)
	if err != nil {
		return err
	}
	defer func() { _ = child.Close() }()
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	hub := NewHub()
	hub.instance = instanceID
	defer hub.Close()
	go func() { _ = ServeOutput(ctx, output, hub) }()
	logFile, err := OpenLog(filepath.Join(filepath.Dir(socketPath), "console.log"), true)
	if err != nil {
		log.Printf("wrapper compatibility log unavailable: %v", err)
	} else {
		defer func() { _ = logFile.Close() }()
	}
	readDone := make(chan struct{})
	go func() {
		defer close(readDone)
		buf := make([]byte, ChunkBytes)
		for {
			n, err := child.Read(buf)
			if n > 0 {
				data := buf[:n]
				hub.Publish(data)
				if logFile != nil {
					_, _ = logFile.Write(data)
				}
				_, _ = os.Stdout.Write(data)
			}
			if err != nil {
				return
			}
		}
	}()
	commandQueue := make(chan string, 16)
	go func() {
		for {
			select {
			case <-ctx.Done():
				return
			case line := <-commandQueue:
				_, _ = io.WriteString(child, line+"\n")
			}
		}
	}()
	go serveCommands(ctx, commands, commandQueue, instanceID)
	signals := make(chan os.Signal, 2)
	signal.Notify(signals, syscall.SIGTERM, os.Interrupt)
	defer signal.Stop(signals)
	exited := make(chan error, 1)
	go func() { exited <- child.wait() }()
	select {
	case err = <-exited:
	case sig := <-signals:
		_ = child.signal(sig)
		select {
		case err = <-exited:
		case <-time.After(10 * time.Second):
			_ = child.Close()
			err = <-exited
		}
	}
	// Drain final output without keeping a wrapper indefinitely after game exit.
	select {
	case <-readDone:
	case <-time.After(time.Second):
	}
	return err
}
func serveCommands(ctx context.Context, l net.Listener, queue chan<- string, instanceID string) {
	stop := context.AfterFunc(ctx, func() { _ = l.Close() })
	defer stop()
	slots := make(chan struct{}, 8)
	var wg sync.WaitGroup
	defer wg.Wait()
	for {
		conn, err := l.Accept()
		if err != nil {
			return
		}
		select {
		case slots <- struct{}{}:
		default:
			_ = conn.Close()
			continue
		}
		wg.Add(1)
		go func(c net.Conn) {
			defer wg.Done()
			defer func() { <-slots }()
			defer func() { _ = c.Close() }()
			stopConn := context.AfterFunc(ctx, func() { _ = c.Close() })
			defer stopConn()
			_ = c.SetReadDeadline(time.Now().Add(2 * time.Second))
			_ = c.SetWriteDeadline(time.Now().Add(time.Second))
			if json.NewEncoder(c).Encode(map[string]any{"v": 1, "instance_id": instanceID}) != nil {
				return
			}
			scanner := bufio.NewScanner(c)
			scanner.Buffer(make([]byte, 512), 4096)
			if scanner.Scan() {
				var request struct {
					Instance string `json:"instance_id"`
					Command  string `json:"command"`
				}
				if json.Unmarshal(scanner.Bytes(), &request) != nil || request.Instance != instanceID {
					return
				}
				line := request.Command
				accepted := false
				if line != "" && len(line) <= 512 && !strings.ContainsAny(line, "\r\n") {
					select {
					case queue <- line:
						accepted = true
					case <-ctx.Done():
					default:
					}
				}
				_ = json.NewEncoder(c).Encode(map[string]bool{"accepted": accepted})
			}
		}(conn)
	}
}
