package liveconsole

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestWrapperFixture(t *testing.T) {
	if os.Getenv("EASYWI_TEST_WRAPPER") != "1" {
		return
	}
	err := RunWrapper("7", os.Getenv("EASYWI_TEST_SOCKET"), []string{os.Args[0], "-test.run=^TestGameFixture$"})
	if err != nil {
		fmt.Fprintln(os.Stderr, err)
		os.Exit(1)
	}
	os.Exit(0)
}
func TestGameFixture(t *testing.T) {
	if os.Getenv("EASYWI_TEST_WRAPPER") != "1" {
		return
	}
	fmt.Print("ready-without-newline")
	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		if scanner.Text() == "quit" {
			fmt.Print("bye")
			os.Exit(0)
		}
		fmt.Print("received:" + scanner.Text())
	}
	os.Exit(0)
}
func TestWrapperStreamsImmediatelyAndKeepsGameAfterViewerLeaves(t *testing.T) {
	socket := filepath.Join(t.TempDir(), "console.sock")
	cmd := exec.Command(os.Args[0], "-test.run=^TestWrapperFixture$")
	cmd.Env = append(os.Environ(), "EASYWI_TEST_WRAPPER=1", "EASYWI_TEST_SOCKET="+socket)
	if err := cmd.Start(); err != nil {
		t.Fatal(err)
	}
	defer func() {
		if cmd.ProcessState == nil {
			_ = cmd.Process.Kill()
			_ = cmd.Wait()
		}
	}()
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	for {
		conn, err := DialLocal(ctx, socket+".output")
		if err == nil {
			_ = json.NewEncoder(conn).Encode(Resume{})
			_ = conn.SetReadDeadline(time.Now().Add(time.Second))
			dec := json.NewDecoder(conn)
			found := false
			for !found {
				var f Frame
				if err = dec.Decode(&f); err != nil {
					_ = conn.Close()
					t.Fatal(err)
				}
				if len(f.Data) > 0 {
					found = true
				}
			}
			_ = conn.Close()
			break
		}
		if ctx.Err() != nil {
			t.Fatal(err)
		}
		time.Sleep(10 * time.Millisecond)
	}
	send := func(line string) {
		conn, err := DialLocal(ctx, socket)
		if err != nil {
			t.Fatal(err)
		}
		var hello map[string]any
		_ = json.NewDecoder(conn).Decode(&hello)
		_ = json.NewEncoder(conn).Encode(map[string]string{"instance_id": "7", "command": line})
		_ = conn.Close()
	}
	send("after-disconnect")
	second, err := DialLocal(ctx, socket+".output")
	if err != nil {
		t.Fatal(err)
	}
	defer func() { _ = second.Close() }()
	_ = json.NewEncoder(second).Encode(Resume{})
	_ = second.SetReadDeadline(time.Now().Add(2 * time.Second))
	dec := json.NewDecoder(second)
	found := false
	var received string
	for !found {
		var f Frame
		if err = dec.Decode(&f); err != nil {
			t.Fatal(err)
		}
		received += string(f.Data)
		if strings.Contains(received, "received:after-disconnect") {
			found = true
		}
	}
	send("quit")
	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	select {
	case err := <-done:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("wrapper did not exit")
	}
}
