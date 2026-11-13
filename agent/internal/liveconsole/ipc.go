package liveconsole

import (
	"context"
	"encoding/json"
	"io"
	"net"
	"sync"
	"time"
)

// ServeOutput shares one immutable, bounded history across all local viewers.
func ServeOutput(ctx context.Context, l net.Listener, h *Hub) error {
	ctx, cancel := context.WithCancel(ctx)
	defer cancel()
	stop := context.AfterFunc(ctx, func() { _ = l.Close() })
	defer stop()
	slots := make(chan struct{}, MaxViewers)
	var wg sync.WaitGroup
	defer func() { cancel(); wg.Wait() }()
	for {
		c, err := l.Accept()
		if err != nil {
			return err
		}
		select {
		case slots <- struct{}{}:
		default:
			_ = c.Close()
			continue
		}
		wg.Add(1)
		go func() {
			defer wg.Done()
			defer func() { <-slots }()
			defer func() { _ = c.Close() }()
			stopConn := context.AfterFunc(ctx, func() { _ = c.Close() })
			defer stopConn()
			_ = c.SetReadDeadline(time.Now().Add(3 * time.Second))
			var r Resume
			if json.NewDecoder(io.LimitReader(c, 512)).Decode(&r) != nil {
				return
			}
			history, ch, unsubscribe, err := h.Subscribe(r)
			if err != nil {
				return
			}
			defer unsubscribe()
			enc := json.NewEncoder(c)
			write := func(f Frame) error { _ = c.SetWriteDeadline(time.Now().Add(3 * time.Second)); return enc.Encode(f) }
			for _, f := range history {
				if write(f) != nil {
					return
				}
			}
			// Drain the peer side so a disconnected browser frees its local subscription
			// even when the game produces no output.
			closed := make(chan struct{})
			go func() {
				defer close(closed)
				_ = c.SetReadDeadline(time.Time{})
				_, _ = io.Copy(io.Discard, io.LimitReader(c, 1))
			}()
			ticker := time.NewTicker(pingInterval)
			defer ticker.Stop()
			for {
				select {
				case <-ctx.Done():
					return
				case <-closed:
					return
				case f, ok := <-ch:
					if !ok {
						_ = write(Frame{Type: "status", Status: "reconnect"})
						return
					}
					if write(f) != nil {
						return
					}
				case <-ticker.C:
					if write(Frame{Type: "ping"}) != nil {
						return
					}
				}
			}
		}()
	}
}
