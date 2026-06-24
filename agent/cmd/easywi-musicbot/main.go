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
	interactive := flag.Bool("interactive", false, "read JSON commands from stdin and write responses to stdout (local testing only; not for systemd)")
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

	rt, err := musicbotruntime.New(config, os.Stderr)
	if err != nil {
		fmt.Fprintf(os.Stderr, "create runtime: %v\n", err)
		os.Exit(1)
	}
	defer func() { _ = rt.Close() }()

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if err := rt.StartControlServer(ctx); err != nil {
		fmt.Fprintf(os.Stderr, "start control server: %v\n", err)
		os.Exit(1)
	}
	if err := rt.StartStreamServer(ctx); err != nil {
		fmt.Fprintf(os.Stderr, "start stream server: %v\n", err)
		os.Exit(1)
	}
	if *interactive {
		if err := rt.Run(ctx, os.Stdin, os.Stdout); err != nil {
			fmt.Fprintf(os.Stderr, "run runtime: %v\n", err)
			os.Exit(1)
		}
	} else {
		if err := rt.RunService(ctx); err != nil {
			fmt.Fprintf(os.Stderr, "run runtime: %v\n", err)
			os.Exit(1)
		}
	}
}
