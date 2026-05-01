package main

import (
	"net"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"time"
)

const tsQueryMinInterval = 200 * time.Millisecond

// tsPoolKey identifies a unique TS query endpoint.
type tsPoolKey struct {
	address  string
	username string
}

// tsPoolEntry holds a single persistent connection to one TS server.
// The mutex serialises all commands so only one goroutine uses the
// connection at a time, preventing the ban-on-multiple-logins problem.
type tsPoolEntry struct {
	mu      sync.Mutex
	client  *ts3QueryClient
	lastCmd time.Time
	factory func() (*ts3QueryClient, error)
}

// use locks the entry, enforces a minimum command interval to avoid
// flood-ban thresholds, and retries once on connection failure.
func (e *tsPoolEntry) use(fn func(*ts3QueryClient) error) error {
	e.mu.Lock()
	defer e.mu.Unlock()

	if !e.lastCmd.IsZero() {
		if wait := tsQueryMinInterval - time.Since(e.lastCmd); wait > 0 {
			time.Sleep(wait)
		}
	}

	for attempt := 0; attempt < 2; attempt++ {
		if e.client == nil {
			c, err := e.factory()
			if err != nil {
				return err
			}
			e.client = c
		}

		err := fn(e.client)
		e.lastCmd = time.Now()
		if err != nil {
			if attempt == 0 {
				e.client.close()
				e.client = nil
				continue
			}
			return err
		}
		return nil
	}
	return nil
}

type tsConnectionPool struct {
	mu      sync.Mutex
	entries map[tsPoolKey]*tsPoolEntry
}

func (p *tsConnectionPool) entry(key tsPoolKey, factory func() (*ts3QueryClient, error)) *tsPoolEntry {
	p.mu.Lock()
	defer p.mu.Unlock()
	if e, ok := p.entries[key]; ok {
		return e
	}
	e := &tsPoolEntry{factory: factory}
	p.entries[key] = e
	return e
}

var (
	globalTs3Pool = &tsConnectionPool{entries: make(map[tsPoolKey]*tsPoolEntry)}
	globalTs6Pool = &tsConnectionPool{entries: make(map[tsPoolKey]*tsPoolEntry)}
)

// withTs3Client acquires the pooled connection for the given TS3 server
// and runs fn. The connection is kept alive for subsequent calls.
func withTs3Client(payload map[string]any, fn func(*ts3QueryClient) error) error {
	key := tsPoolKeyFromTs3Payload(payload)
	installDir := strings.TrimSpace(payloadValue(payload, "install_dir"))
	entry := globalTs3Pool.entry(key, func() (*ts3QueryClient, error) {
		if installDir != "" {
			ensureQueryAllowlisted(installDir, "query_ip_whitelist.txt", key.address)
		}
		return newTs3QueryClient(payload)
	})
	return entry.use(fn)
}

// withTs6Client is the TS6 equivalent of withTs3Client.
func withTs6Client(payload map[string]any, fn func(*ts3QueryClient) error) error {
	key := tsPoolKeyFromTs6Payload(payload)
	installDir := strings.TrimSpace(payloadValue(payload, "install_dir"))
	entry := globalTs6Pool.entry(key, func() (*ts3QueryClient, error) {
		if installDir != "" {
			ensureQueryAllowlisted(installDir, "query_ip_allowlist.txt", key.address)
		}
		return newTs6QueryClient(payload)
	})
	return entry.use(fn)
}

// ensureQueryAllowlisted adds the host part of addr to the TS allowlist
// file so the query client is exempt from flood-ban protection.
func ensureQueryAllowlisted(installDir, filename, addr string) {
	host, _, err := net.SplitHostPort(addr)
	if err != nil {
		host = addr
	}
	if host == "" {
		return
	}

	path := filepath.Join(installDir, filename)
	data, _ := os.ReadFile(path)
	for _, line := range strings.Split(string(data), "\n") {
		if strings.TrimSpace(line) == host {
			return
		}
	}

	f, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0644)
	if err != nil {
		return
	}
	defer func() { _ = f.Close() }()
	_, _ = f.WriteString(host + "\n")
}

func tsPoolKeyFromTs3Payload(payload map[string]any) tsPoolKey {
	queryIP := payloadValue(payload, "query_bind_ip", "query_ip")
	if queryIP == "" {
		queryIP = "127.0.0.1"
	}
	queryIP = normalizeQueryConnectIP(queryIP)
	queryPort := payloadValue(payload, "query_port")
	if queryPort == "" {
		queryPort = "10011"
	}
	user := payloadValue(payload, "admin_username")
	if user == "" {
		user = "serveradmin"
	}
	return tsPoolKey{address: net.JoinHostPort(queryIP, queryPort), username: user}
}

func tsPoolKeyFromTs6Payload(payload map[string]any) tsPoolKey {
	protocol := strings.ToLower(strings.TrimSpace(payloadValue(payload, "query_protocol", "query_transport")))
	queryIP := payloadValue(payload, "query_bind_ip", "query_ip")
	if queryIP == "" {
		queryIP = "127.0.0.1"
	}
	queryIP = normalizeQueryConnectIP(queryIP)
	var queryPort string
	if protocol == "ssh" {
		queryPort = payloadValue(payload, "query_port")
		if queryPort == "" {
			queryPort = "10022"
		}
	} else {
		queryPort = payloadValue(payload, "query_port", "query_https_port")
		if queryPort == "" {
			queryPort = "10443"
		}
	}
	user := payloadValue(payload, "admin_username")
	if user == "" {
		user = "serveradmin"
	}
	return tsPoolKey{address: net.JoinHostPort(queryIP, queryPort), username: user}
}
