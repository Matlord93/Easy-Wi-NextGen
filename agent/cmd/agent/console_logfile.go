package main

import (
	"bytes"
	"context"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

const (
	consoleLogTailBytes = 50 * 1024 // read from last 50 KB on reconnect
)

// ansiEscapeRe matches ANSI/VT100 escape sequences produced by a PTY.
var ansiEscapeRe = regexp.MustCompile(`\x1b(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])`)

// consoleLogFilePath returns the path of the per-instance console log file.
// The directory is shared with the command socket so it is always created
// by the wrapper before the game server starts.
func consoleLogFilePath(instanceID string) string {
	instanceID = strings.TrimSpace(instanceID)
	if instanceID == "" {
		return ""
	}
	return filepath.Join(instanceRuntimeDir, instanceID, "console.log")
}

// stripANSI removes ANSI/VT100 escape sequences from raw PTY output so that
// the console log file contains plain text readable by the agent's reader.
func stripANSI(data []byte) []byte {
	return ansiEscapeRe.ReplaceAll(data, nil)
}

// openConsoleLogForWriting creates (or truncates) the console log file for the
// given instance. The directory is created if it does not exist. Each new game
// server start truncates the file so the reader always sees fresh output.
func openConsoleLogForWriting(instanceID string) (*os.File, error) {
	logPath := consoleLogFilePath(instanceID)
	if logPath == "" {
		return nil, fmt.Errorf("invalid instance ID for console log")
	}
	if err := os.MkdirAll(filepath.Dir(logPath), 0o755); err != nil {
		return nil, fmt.Errorf("create console log dir: %w", err)
	}
	f, err := os.OpenFile(logPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
	if err != nil {
		return nil, fmt.Errorf("open console log for writing: %w", err)
	}
	return f, nil
}

// startLogFileStream opens the console log file and follows it like "tail -f".
// It returns a *startedJournalStream so it can be used as a drop-in
// replacement for startJournalStream in consoleSession.run().
//
// The goroutine exits (and closes the pipe write-end) when ctx is cancelled
// or when an unrecoverable read error occurs. The wait() function blocks until
// the goroutine has exited, which lets consoleSession.run() behave identically
// regardless of whether it is watching a log file or a journalctl process.
func startLogFileStream(ctx context.Context, logFilePath string) (*startedJournalStream, error) {
	f, err := os.Open(logFilePath)
	if err != nil {
		return nil, fmt.Errorf("open console log for reading: %w", err)
	}

	pr, pw := io.Pipe()
	doneCh := make(chan struct{})

	go func() {
		defer close(doneCh)
		defer func() { _ = pw.Close() }()

		// currentF tracks the open file; reassigned when a truncation is detected.
		currentF := f
		defer func() { _ = currentF.Close() }()

		// Seek near the end so a reconnecting browser sees recent history without
		// replaying the entire file.
		if stat, statErr := currentF.Stat(); statErr == nil && stat.Size() > consoleLogTailBytes {
			_, _ = currentF.Seek(-consoleLogTailBytes, io.SeekEnd)
			// Skip the partial first line that the seek likely landed in the middle of.
			skipBuf := make([]byte, 1)
			for {
				n, e := currentF.Read(skipBuf)
				if n > 0 && skipBuf[0] == '\n' {
					break
				}
				if e != nil {
					break
				}
			}
		}

		readBuf := make([]byte, 16*1024)
		pending := make([]byte, 0, 4096)

		for {
			select {
			case <-ctx.Done():
				return
			default:
			}

			n, readErr := currentF.Read(readBuf)
			if n > 0 {
				pending = append(pending, readBuf[:n]...)
				// Emit one complete line at a time so the scanner in
				// scanConsoleReader gets a properly terminated token.
				for {
					idx := bytes.IndexByte(pending, '\n')
					if idx < 0 {
						break
					}
					line := pending[:idx+1]
					pending = pending[idx+1:]
					if _, writeErr := pw.Write(line); writeErr != nil {
						return
					}
				}
			}

			if readErr == io.EOF {
				// Check if the file was truncated (new game server run started).
				if stat, statErr := os.Stat(logFilePath); statErr == nil {
					curPos, seekErr := currentF.Seek(0, io.SeekCurrent)
					if seekErr == nil && stat.Size() < curPos {
						_ = currentF.Close()
						newF, openErr := os.Open(logFilePath)
						if openErr != nil {
							return
						}
						currentF = newF
						pending = pending[:0]
					}
				}
				select {
				case <-ctx.Done():
					return
				case <-time.After(100 * time.Millisecond):
				}
				continue
			}

			if readErr != nil {
				return
			}
		}
	}()

	return &startedJournalStream{
		stdout: pr,
		stderr: io.NopCloser(strings.NewReader("")),
		// wait blocks until the goroutine exits so consoleSession.run() can
		// correctly detect stream termination and restart with back-off.
		wait: func() error { <-doneCh; return nil },
	}, nil
}
