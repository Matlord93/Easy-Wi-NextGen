package main

import (
	"crypto/tls"
	"crypto/x509"
	"flag"
	"fmt"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
)

func main() {
	configPath := flag.String("config", "", "path to filesvc.conf")
	flag.Parse()

	cfg, err := loadFilesvcConfig(*configPath)
	if err != nil {
		log.Fatalf("load config: %v", err)
	}

	cert, err := tls.LoadX509KeyPair(cfg.TLSCertPath, cfg.TLSKeyPath)
	if err != nil {
		log.Fatalf("load tls cert: %v", err)
	}

	caData, err := os.ReadFile(cfg.TLSCAPath)
	if err != nil {
		log.Fatalf("load tls ca: %v", err)
	}
	caPool := x509.NewCertPool()
	if !caPool.AppendCertsFromPEM(caData) {
		log.Fatalf("append tls ca failed")
	}

	server := &filesvcServer{
		config: cfg,
		cache:  newListingCache(cfg.CacheSize),
	}

	httpServer := &http.Server{
		Addr:         cfg.ListenAddr,
		Handler:      server.routes(),
		ReadTimeout:  cfg.ReadTimeout,
		WriteTimeout: cfg.WriteTimeout,
		IdleTimeout:  cfg.IdleTimeout,
		TLSConfig: &tls.Config{
			Certificates: []tls.Certificate{cert},
			ClientCAs:    caPool,
			ClientAuth:   tls.RequireAndVerifyClientCert,
			MinVersion:   tls.VersionTLS12,
		},
	}

	shutdown := make(chan os.Signal, 1)
	signal.Notify(shutdown, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		<-shutdown
		_ = httpServer.Close()
	}()

	log.Printf("filesvc listening on %s", cfg.ListenAddr)
	if err := httpServer.ListenAndServeTLS("", ""); err != nil && err != http.ErrServerClosed {
		log.Fatalf("filesvc failed: %v", err)
	}

	log.Printf("filesvc shutdown")
}

func init() {
	flag.CommandLine.SetOutput(os.Stdout)
	flag.CommandLine.Usage = func() {
		_, _ = fmt.Fprintf(os.Stdout, "Usage: filesvc --config /etc/easywi/filesvc.conf\n")
		flag.PrintDefaults()
	}
}
