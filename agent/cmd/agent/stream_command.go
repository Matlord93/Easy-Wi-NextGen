package main

import (
	"fmt"
	"io"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"sync"
	"time"
)

const (
	streamChunkSize   = 4 * 1024
	streamFlushPeriod = 400 * time.Millisecond
	streamFlushSize   = 20
	streamKeepalive   = 5 * time.Second
)

type streamChunk struct {
	data   []byte
	source string
}

func StreamCommand(cmd *exec.Cmd, jobID string, logSender JobLogSender) (string, error) {
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return "", fmt.Errorf("stdout pipe: %w", err)
	}
	stderr, err := cmd.StderrPipe()
	if err != nil {
		return "", fmt.Errorf("stderr pipe: %w", err)
	}

	if err := cmd.Start(); err != nil {
		return "", fmt.Errorf("start command: %w", err)
	}

	prefixEnabled := shouldPrefixOutput()
	chunkCh := make(chan streamChunk, 256)
	errCh := make(chan error, 4)
	var wg sync.WaitGroup

	readStream := func(reader io.Reader, writer io.Writer, source string) {
		defer wg.Done()
		buffer := make([]byte, streamChunkSize)
		for {
			n, readErr := reader.Read(buffer)
			if n > 0 {
				chunk := append([]byte(nil), buffer[:n]...)
				if prefixEnabled {
					if _, err := writer.Write([]byte(source)); err != nil {
						errCh <- fmt.Errorf("write %s prefix: %w", source, err)
					}
				}
				if _, err := writer.Write(chunk); err != nil {
					errCh <- fmt.Errorf("write %s: %w", source, err)
				}
				chunkCh <- streamChunk{data: chunk, source: source}
			}
			if readErr != nil {
				if readErr != io.EOF {
					errCh <- fmt.Errorf("read %s: %w", source, readErr)
				}
				return
			}
		}
	}

	wg.Add(2)
	go readStream(stdout, os.Stdout, "[stdout] ")
	go readStream(stderr, os.Stderr, "[stderr] ")
	go func() {
		wg.Wait()
		close(chunkCh)
		close(errCh)
	}()

	var output strings.Builder
	logBuffer := make([]string, 0, streamFlushSize)
	lastFlush := time.Now()
	lastOutput := time.Now()

	logSendCh := make(chan []string, 32)
	var logWg sync.WaitGroup
	if logSender != nil && jobID != "" {
		logWg.Add(1)
		go func() {
			defer logWg.Done()
			for batch := range logSendCh {
				logSender.Send(jobID, batch, nil)
			}
		}()
	}

	sendBatch := func(batch []string) {
		if logSender == nil || jobID == "" || len(batch) == 0 {
			return
		}
		select {
		case logSendCh <- batch:
		default:
			go logSender.Send(jobID, batch, nil)
		}
	}

	flush := func(force bool) {
		if len(logBuffer) == 0 {
			return
		}
		if !force && time.Since(lastFlush) < streamFlushPeriod && len(logBuffer) < streamFlushSize {
			return
		}
		batch := append([]string(nil), logBuffer...)
		logBuffer = logBuffer[:0]
		lastFlush = time.Now()
		sendBatch(batch)
	}

	if logSender != nil && jobID != "" {
		sendBatch([]string{fmt.Sprintf("command started: %s", commandLabel(cmd))})
	}

	flushTicker := time.NewTicker(streamFlushPeriod)
	keepaliveTicker := time.NewTicker(streamKeepalive)
	defer flushTicker.Stop()
	defer keepaliveTicker.Stop()

	for running := true; running; {
		select {
		case chunk, ok := <-chunkCh:
			if !ok {
				running = false
				break
			}
			lastOutput = time.Now()
			output.Write(chunk.data)
			logBuffer = append(logBuffer, chunkPrefix(prefixEnabled, chunk.source)+string(chunk.data))
			flush(false)
		case <-flushTicker.C:
			flush(false)
		case <-keepaliveTicker.C:
			if logSender == nil || jobID == "" {
				continue
			}
			if time.Since(lastOutput) >= streamKeepalive {
				logBuffer = append(logBuffer, "still running …")
				flush(true)
			}
		}
	}

	flush(true)

	var readErr error
	for err := range errCh {
		if err != nil && readErr == nil {
			readErr = err
		}
	}

	waitErr := cmd.Wait()
	if readErr != nil {
		close(logSendCh)
		logWg.Wait()
		return output.String(), readErr
	}
	if waitErr != nil {
		if logSender != nil && jobID != "" {
			sendBatch([]string{fmt.Sprintf("command failed: %s", commandLabel(cmd))})
		}
		close(logSendCh)
		logWg.Wait()
		return output.String(), waitErr
	}

	if logSender != nil && jobID != "" {
		sendBatch([]string{fmt.Sprintf("command finished: %s", commandLabel(cmd))})
	}
	close(logSendCh)
	logWg.Wait()

	return output.String(), nil
}

func shouldPrefixOutput() bool {
	value := strings.ToLower(strings.TrimSpace(os.Getenv("EASYWI_STREAM_PREFIX")))
	return value == "1" || value == "true" || value == "yes" || value == "on"
}

func chunkPrefix(prefixEnabled bool, source string) string {
	if !prefixEnabled {
		return ""
	}
	return source
}

func commandLabel(cmd *exec.Cmd) string {
	if cmd == nil || len(cmd.Args) == 0 {
		return "unknown"
	}
	if shouldLogCommandDetails() {
		return strings.Join(cmd.Args, " ")
	}
	return cmd.Args[0]
}

func shouldLogCommandDetails() bool {
	value := strings.ToLower(strings.TrimSpace(os.Getenv("EASYWI_LOG_COMMANDS")))
	return value == "1" || value == "true" || value == "yes" || value == "on"
}

func shouldForceTTY(command string) bool {
	if runtime.GOOS != "linux" {
		return false
	}
	value := strings.ToLower(strings.TrimSpace(os.Getenv("EASYWI_FORCE_TTY")))
	if value == "1" || value == "true" || value == "yes" || value == "on" {
		return true
	}
	installerValue := strings.ToLower(strings.TrimSpace(os.Getenv("EASYWI_TTY_INSTALLER")))
	if installerValue == "1" || installerValue == "true" || installerValue == "yes" || installerValue == "on" {
		lower := strings.ToLower(command)
		return strings.Contains(lower, "install")
	}
	return false
}
