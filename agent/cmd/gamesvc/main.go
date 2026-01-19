package main

import (
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	configPath := flag.String("config", "", "path to gamesvc.json")
	flag.Parse()

	cfg, err := loadGamesvcConfig(*configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	server := newGameServer(cfg)

	httpServer := &http.Server{
		Addr:         cfg.ListenAddr,
		Handler:      server.routes(),
		ReadTimeout:  cfg.ReadTimeout,
		WriteTimeout: cfg.WriteTimeout,
		IdleTimeout:  cfg.IdleTimeout,
	}

	shutdown := make(chan os.Signal, 1)
	signal.Notify(shutdown, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		<-shutdown
		_ = httpServer.Close()
	}()

	log.Printf("gamesvc listening on %s", cfg.ListenAddr)
	if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("gamesvc failed: %v", err)
	}
}

func init() {
	flag.CommandLine.SetOutput(os.Stdout)
	flag.CommandLine.Usage = func() {
		_, _ = fmt.Fprintf(os.Stdout, "Usage: gamesvc --config /etc/easywi/gamesvc.json\n")
		flag.PrintDefaults()
	}
}
