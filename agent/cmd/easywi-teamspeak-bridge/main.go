package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	logger := log.New(os.Stderr, "easywi-teamspeak-bridge: ", 0)

	if os.Getenv("EASYWI_TS_BRIDGE") != "1" {
		logger.Println("warning: EASYWI_TS_BRIDGE=1 not set; expected to be launched by the Musicbot runtime")
	}

	adapter := NewPlaceholderAdapter()
	b := newBridge(adapter, os.Stdout, logger)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	done := make(chan error, 1)
	go func() {
		done <- b.run(ctx, os.Stdin)
	}()

	select {
	case err := <-done:
		_ = adapter.Close()
		if err != nil {
			logger.Printf("protocol error: %v", err)
			os.Exit(1)
		}
	case <-ctx.Done():
		_ = adapter.Close()
	}
}
