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
	configPath := flag.String("config", "", "path to sinusbotsvc.json")
	flag.Parse()

	cfg, err := loadSinusbotConfig(*configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	server := &sinusbotsvcServer{cfg: cfg}

	httpServer := &http.Server{
		Addr:    cfg.ListenAddr,
		Handler: server.routes(),
	}

	shutdown := make(chan os.Signal, 1)
	signal.Notify(shutdown, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		<-shutdown
		_ = httpServer.Close()
	}()

	log.Printf("sinusbotsvc listening on %s", cfg.ListenAddr)
	if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("sinusbotsvc failed: %v", err)
	}
}

func init() {
	flag.CommandLine.SetOutput(os.Stdout)
	flag.CommandLine.Usage = func() {
		_, _ = fmt.Fprintf(os.Stdout, "Usage: sinusbotsvc --config /etc/easywi/sinusbotsvc.json\n")
		flag.PrintDefaults()
	}
}
