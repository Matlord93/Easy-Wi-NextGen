package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	// Bootstrap mode: accept-ts3-client-license must be the first argument so it
	// cannot be confused with a regular bridge flag.
	if len(os.Args) >= 2 && os.Args[1] == "--accept-ts3-client-license" {
		// RunLicenseBootstrap calls os.Exit; it never returns.
		RunLicenseBootstrap(os.Args[2:])
	}

	logger := log.New(os.Stderr, "easywi-teamspeak-bridge: ", 0)
	logger.Printf("bridge_main_started=true")

	if os.Getenv("EASYWI_TS_BRIDGE") != "1" {
		logger.Println("warning: EASYWI_TS_BRIDGE=1 not set; expected to be launched by the Musicbot runtime")
	}

	adapter := NewSelectingAdapter()
	b := newBridge(adapter, os.Stdout, logger)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	done := make(chan error, 1)
	go func() {
		done <- b.run(ctx, os.Stdin)
	}()

	select {
	case err := <-done:
		_ = adapter.Shutdown(context.Background())
		if err != nil {
			logger.Printf("protocol error: %v", err)
			os.Exit(1)
		}
	case <-ctx.Done():
		_ = adapter.Shutdown(context.Background())
	}
}
