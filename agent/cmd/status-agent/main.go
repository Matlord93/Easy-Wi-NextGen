package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"easywi/agent/internal/statusagent"
)

func main() {
	go func() {
		_ = http.ListenAndServe("127.0.0.1:8099", http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte("ok"))
		}))
	}()
	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()
	ticker := time.NewTicker(15 * time.Second)
	defer ticker.Stop()
	for {
		if err := statusagent.Run(ctx); err != nil {
			log.Printf("status cycle failed: %v", err)
		}
		select {
		case <-ctx.Done():
			os.Exit(0)
		case <-ticker.C:
		}
	}
}
