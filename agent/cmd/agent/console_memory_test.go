package main

import (
	"context"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestConsoleOutputDoesNotRenewViewerLifetime(t *testing.T) {
	old := time.Now().Add(-time.Hour)
	s := &consoleSession{lastAccess: old, windowAt: time.Now()}
	s.appendLine("journal", "no viewer", "")
	if !s.lastAccess.Equal(old) {
		t.Fatal("output retained idle session")
	}
}
func TestConsoleLegacyBufferIsByteBounded(t *testing.T) {
	s := &consoleSession{windowAt: time.Now()}
	for i := 0; i < 2000; i++ {
		s.appendLine("journal", strings.Repeat("x", 64*1024), "")
	}
	bytes := 0
	for _, line := range s.buffer {
		bytes += len(line.Text)
		if len(line.Text) > 16384 {
			t.Fatal("long line not bounded")
		}
	}
	if bytes > 256*1024 || s.bufferBytes != bytes {
		t.Fatal(bytes, s.bufferBytes)
	}
	if s.droppedLines == 0 {
		t.Fatal("missing drop metric")
	}
}
func TestLogReaderCancellationUnblocksPendingWrite(t *testing.T) {
	path := filepath.Join(t.TempDir(), "console.log")
	if err := os.WriteFile(path, nil, 0600); err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	stream, err := startLogFileStream(ctx, path)
	if err != nil {
		t.Fatal(err)
	}
	if err = os.WriteFile(path, []byte(strings.Repeat("x", 32768)+"\n"), 0600); err != nil {
		t.Fatal(err)
	}
	time.Sleep(20 * time.Millisecond)
	cancel()
	done := make(chan error, 1)
	go func() { done <- stream.wait() }()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("blocked log reader leaked")
	}
	stream.stdout.Close()
}
func TestLegacyLogReaderBoundsUnterminatedLine(t *testing.T) {
	path := filepath.Join(t.TempDir(), "console.log")
	_ = os.WriteFile(path, nil, 0600)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	stream, err := startLogFileStream(ctx, path)
	if err != nil {
		t.Fatal(err)
	}
	defer stream.stdout.Close()
	_ = os.WriteFile(path, []byte(strings.Repeat("x", 512*1024)), 0600)
	read := make(chan error, 1)
	go func() {
		data := make([]byte, 16385)
		_, err := io.ReadFull(stream.stdout, data)
		if err == nil && data[16384] != '\n' {
			t.Error("missing bounded line terminator")
		}
		read <- err
	}()
	select {
	case err := <-read:
		if err != nil {
			t.Fatal(err)
		}
	case <-time.After(time.Second):
		t.Fatal("unterminated line was buffered indefinitely")
	}
	cancel()
	_ = stream.wait()
}
