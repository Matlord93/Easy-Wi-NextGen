package main

import (
	"context"
	"flag"
	"fmt"
	"os"
	"os/signal"
	"syscall"

	musicbotruntime "easywi/agent/internal/musicbot/runtime"
)

func main() {
	configPath := flag.String("config", "", "path to the Musicbot runtime JSON configuration")
	flag.Parse()
	if *configPath == "" {
		fmt.Fprintln(os.Stderr, "missing --config")
		os.Exit(2)
	}

	config, err := musicbotruntime.LoadConfig(*configPath)
	if err != nil {
		fmt.Fprintf(os.Stderr, "load config: %v\n", err)
		os.Exit(1)
	}

	runtime, err := musicbotruntime.New(config, os.Stderr)
	if err != nil {
		fmt.Fprintf(os.Stderr, "create runtime: %v\n", err)
		os.Exit(1)
	}
	defer func() { _ = runtime.Close() }()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if err := runtime.StartControlServer(ctx); err != nil {
		fmt.Fprintf(os.Stderr, "start control server: %v\n", err)
		os.Exit(1)
	}
	if err := runtime.StartStreamServer(ctx); err != nil {
		fmt.Fprintf(os.Stderr, "start stream server: %v\n", err)
		os.Exit(1)
	}
	if err := runtime.Run(ctx, os.Stdin, os.Stdout); err != nil {
		fmt.Fprintf(os.Stderr, "run runtime: %v\n", err)
		os.Exit(1)
	}
}
