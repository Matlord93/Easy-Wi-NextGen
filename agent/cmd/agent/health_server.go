package main

import (
	"context"
	"log"
	"net/http"
	"strings"
	"time"

	"easywi/agent/internal/config"
	"easywi/agent/internal/fileapi"
	"easywi/agent/internal/gamesvcembed"
	"easywi/agent/internal/sinusbotsvcembed"
)

func startServiceServer(ctx context.Context, cfg config.Config) {
	listen := strings.TrimSpace(cfg.ServiceListen)
	if listen == "" || strings.EqualFold(listen, "off") || strings.EqualFold(listen, "disabled") {
		return
	}

	fileServer, err := fileapi.NewServer(fileapi.Config{
		AgentID:        cfg.AgentID,
		Secret:         cfg.Secret,
		BaseDir:        cfg.FileBaseDir,
		CacheSize:      cfg.FileCacheSize,
		MaxSkew:        cfg.FileMaxSkew,
		ReadTimeout:    cfg.FileReadTimeout,
		WriteTimeout:   cfg.FileWriteTimeout,
		IdleTimeout:    cfg.FileIdleTimeout,
		MaxUploadBytes: cfg.FileMaxUploadMB * 1024 * 1024,
		Version:        cfg.Version,
	})
	if err != nil {
		log.Fatalf("init file api: %v", err)
	}

	gameServer := gamesvcembed.NewServer(gamesvcembed.Config{})
	sinusbotServer := sinusbotsvcembed.NewServer(sinusbotsvcembed.Config{
		AgentID: cfg.AgentID,
		Secret:  cfg.Secret,
	})

	mux := http.NewServeMux()
	mux.Handle("/v1/servers/", fileServer.Handler())
	mux.Handle("/health", fileServer.Handler())
	mux.Handle("/healthz", fileServer.Handler())
	mux.Handle("/ports/check-free", gameServer.Handler())
	mux.Handle("/instance/render-config", gameServer.Handler())
	mux.Handle("/instance/start", gameServer.Handler())
	mux.Handle("/instance/stop", gameServer.Handler())
	mux.Handle("/instance/status", gameServer.Handler())
	mux.Handle("/internal/sinusbot/instances", sinusbotServer.Handler())
	mux.Handle("/internal/sinusbot/instances/", sinusbotServer.Handler())

	httpServer := &http.Server{
		Addr:              listen,
		Handler:           mux,
		ReadTimeout:       cfg.FileReadTimeout,
		WriteTimeout:      cfg.FileWriteTimeout,
		IdleTimeout:       cfg.FileIdleTimeout,
		ReadHeaderTimeout: 5 * time.Second,
	}

	go func() {
		<-ctx.Done()
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		defer cancel()
		_ = httpServer.Shutdown(shutdownCtx)
	}()

	go func() {
		log.Printf("agent service listening on %s", listen)
		if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Printf("agent service failed: %v", err)
		}
	}()
}
