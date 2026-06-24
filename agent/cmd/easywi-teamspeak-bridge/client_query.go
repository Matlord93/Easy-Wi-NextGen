package main

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"hash/fnv"
	"io"
	"net"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

const (
	clientQueryDefaultPort    = 25639
	clientQueryPortRangeStart = 25600
	clientQueryPortRangeSize  = 100
	clientQueryDialTimeout    = 2 * time.Second
)

// allocateClientQueryPort returns the port the ClientQuery plugin should listen on.
//
// If requestedPort > 0 it is probed first; if the port is already bound (e.g. by
// SinusBot) a clientquery_port_in_use error is returned together with a suggested
// free alternative port.
//
// When requestedPort == 0 a deterministic port derived from instancePath (FNV-32a)
// is tried first so that the same instance always prefers the same port across
// restarts. If that port is occupied the first free port in the scan range is used.
func allocateClientQueryPort(host, instancePath string, requestedPort int) (int, error) {
	if host == "" {
		host = "127.0.0.1"
	}

	if requestedPort > 0 {
		if checkPortAvailable(host, requestedPort) {
			return requestedPort, nil
		}
		free, _ := findFreeClientQueryPort(host)
		suggestion := ""
		if free > 0 {
			suggestion = fmt.Sprintf("; suggested free port: %d", free)
		}
		return 0, fmt.Errorf("clientquery_port_in_use: port %d on %s is already in use%s",
			requestedPort, host, suggestion)
	}

	// When no port is requested, prefer the plugin default (25639) for
	// maximum compatibility – the plugin ignores custom INI configs on many
	// installations and always binds to the default port.
	if checkPortAvailable(host, clientQueryDefaultPort) {
		return clientQueryDefaultPort, nil
	}

	if strings.TrimSpace(instancePath) != "" {
		port := deterministicClientQueryPort(instancePath)
		if checkPortAvailable(host, port) {
			return port, nil
		}
	}

	port, err := findFreeClientQueryPort(host)
	if err != nil {
		return 0, fmt.Errorf("clientquery_port_in_use: no free ClientQuery port found in range %d-%d on %s",
			clientQueryPortRangeStart, clientQueryPortRangeStart+clientQueryPortRangeSize-1, host)
	}
	return port, nil
}

// deterministicClientQueryPort maps instancePath to a port in the scan range via
// FNV-32a so each instance prefers a stable, collision-resistant port.
func deterministicClientQueryPort(instancePath string) int {
	h := fnv.New32a()
	_, _ = io.WriteString(h, filepath.Clean(instancePath))
	offset := int(h.Sum32() % uint32(clientQueryPortRangeSize))
	return clientQueryPortRangeStart + offset
}

// checkPortAvailable returns true when host:port can be bound right now (i.e. no
// process is currently listening there). The listener is closed immediately.
func checkPortAvailable(host string, port int) bool {
	ln, err := net.Listen("tcp", net.JoinHostPort(host, fmt.Sprintf("%d", port)))
	if err != nil {
		return false
	}
	_ = ln.Close()
	return true
}

// findFreeClientQueryPort scans the ClientQuery port range and returns the first
// available port on host.
func findFreeClientQueryPort(host string) (int, error) {
	for i := 0; i < clientQueryPortRangeSize; i++ {
		port := clientQueryPortRangeStart + i
		if checkPortAvailable(host, port) {
			return port, nil
		}
	}
	return 0, fmt.Errorf("no free port in range %d-%d",
		clientQueryPortRangeStart, clientQueryPortRangeStart+clientQueryPortRangeSize-1)
}

// writeClientQueryPluginConfig writes the ClientQuery INI to all locations
// the TS3 client may read it from. Directories are created with mode 0700; files
// are written with mode 0600. No secrets are included.
func writeClientQueryPluginConfig(ts3Home, host string, port int) error {
	if host == "" {
		host = "127.0.0.1"
	}
	content := fmt.Sprintf("[ClientQuery]\nPort=%d\nHost=%s\n", port, host)

	// Subdirectory locations (write clientquery.ini inside the dir)
	dirs := []string{
		filepath.Join(ts3Home, ".ts3client", "plugins"),
		filepath.Join(ts3Home, ".config", "plugins"),
		filepath.Join(ts3Home, "config", "plugins"),
	}
	for _, dir := range dirs {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return fmt.Errorf("clientquery config dir %s: %w", dir, err)
		}
		iniPath := filepath.Join(dir, "clientquery.ini")
		if err := os.WriteFile(iniPath, []byte(content), 0o600); err != nil {
			return fmt.Errorf("write clientquery.ini to %s: %w", iniPath, err)
		}
	}

	// Also write directly to ts3Home/.ts3client/ and ts3Home/ for installations
	// that look for the file in non-standard locations.
	rootDirs := []string{
		filepath.Join(ts3Home, ".ts3client"),
		ts3Home,
	}
	for _, dir := range rootDirs {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return fmt.Errorf("clientquery config dir %s: %w", dir, err)
		}
		iniPath := filepath.Join(dir, "clientquery.ini")
		if err := os.WriteFile(iniPath, []byte(content), 0o600); err != nil {
			return fmt.Errorf("write clientquery.ini to %s: %w", iniPath, err)
		}
	}

	return nil
}

// readClientQueryApiKey reads the ApiKey value from the TS3 client's ClientQuery
// configuration at <persistentTs3Home>/.ts3client/clientquery.ini. Returns "" when
// the file is absent or contains no ApiKey entry; never returns an error so the
// caller can proceed without auth when the key is not yet available.
func readClientQueryApiKey(persistentTs3Home string) string {
	iniPath := filepath.Join(persistentTs3Home, ".ts3client", "clientquery.ini")
	data, err := os.ReadFile(iniPath)
	if err != nil {
		return ""
	}
	for _, rawLine := range strings.Split(string(data), "\n") {
		line := strings.TrimSpace(rawLine)
		eqIdx := strings.IndexByte(line, '=')
		if eqIdx < 0 {
			continue
		}
		if strings.ToLower(strings.TrimSpace(line[:eqIdx])) == "apikey" {
			val := strings.TrimSpace(line[eqIdx+1:])
			if val != "" {
				return val
			}
		}
	}
	return ""
}

// ts3Escape encodes a string value for use as a parameter in the TeamSpeak 3
// ClientQuery text protocol. Spaces become \s, backslashes become \\, etc.
func ts3Escape(s string) string {
	return strings.NewReplacer(
		`\`, `\\`,
		` `, `\s`,
		`/`, `\/`,
		`|`, `\p`,
		"\n", `\n`,
		"\r", `\r`,
		"\t", `\t`,
	).Replace(s)
}

// clientQueryConnect dials host:port, reads the "TS3 Client" banner, and
// authenticates with apiKey (if non-empty). Returns the open connection and a
// Scanner positioned after the banner/auth exchange, ready for command responses.
// The caller must close the connection when done.
func clientQueryConnect(host string, port int, apiKey string, timeout time.Duration) (net.Conn, *bufio.Scanner, error) {
	addr := net.JoinHostPort(host, strconv.Itoa(port))
	conn, err := net.DialTimeout("tcp", addr, timeout)
	if err != nil {
		return nil, nil, err
	}
	_ = conn.SetDeadline(time.Now().Add(timeout))

	scanner := bufio.NewScanner(conn)

	// Scan until the "TS3 Client" banner line.
	bannerSeen := false
	for scanner.Scan() {
		if strings.HasPrefix(strings.TrimSpace(scanner.Text()), "TS3 Client") {
			bannerSeen = true
			break
		}
	}
	if !bannerSeen {
		_ = conn.Close()
		return nil, nil, errors.New("clientquery: banner not received")
	}

	if apiKey != "" {
		_ = conn.SetDeadline(time.Now().Add(timeout))
		if _, writeErr := fmt.Fprintf(conn, "auth apikey=%s\n", apiKey); writeErr != nil {
			_ = conn.Close()
			return nil, nil, fmt.Errorf("clientquery: auth write: %w", writeErr)
		}
		authOK := false
		for scanner.Scan() {
			line := strings.TrimSpace(scanner.Text())
			if strings.HasPrefix(line, "error id=") {
				authOK = strings.HasPrefix(line, "error id=0")
				break
			}
		}
		if !authOK {
			_ = conn.Close()
			return nil, nil, errors.New("clientquery: auth failed")
		}
	}

	_ = conn.SetDeadline(time.Now().Add(timeout))
	return conn, scanner, nil
}

// clientQueryExecCommand writes cmd to conn, reads response lines until an
// "error id=…" line appears, and returns the full response. The connection is
// NOT closed; the caller owns the lifetime.
func clientQueryExecCommand(conn net.Conn, scanner *bufio.Scanner, cmd string, timeout time.Duration) (string, error) {
	_ = conn.SetDeadline(time.Now().Add(timeout))
	if _, err := fmt.Fprintf(conn, "%s\n", cmd); err != nil {
		return "", fmt.Errorf("clientquery: write %q: %w", cmd, err)
	}
	var lines []string
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		lines = append(lines, line)
		if strings.HasPrefix(line, "error id=") {
			break
		}
	}
	if scanErr := scanner.Err(); scanErr != nil {
		return strings.Join(lines, "\n"), fmt.Errorf("clientquery: read: %w", scanErr)
	}
	return strings.Join(lines, "\n"), nil
}

// probeClientQueryControlReady opens a ClientQuery session, authenticates, sends
// "whoami", and returns true when the response indicates the plugin is up and
// accepting commands. Both "error id=0" (connected to a TS server) and
// "error id=1794" (not connected yet, but plugin is ready) are accepted.
func probeClientQueryControlReady(host string, port int, apiKey string) bool {
	conn, scanner, err := clientQueryConnect(host, port, apiKey, clientQueryDialTimeout)
	if err != nil {
		return false
	}
	defer func() { _ = conn.Close() }()

	resp, err := clientQueryExecCommand(conn, scanner, "whoami", clientQueryDialTimeout)
	if err != nil {
		return false
	}
	for _, line := range strings.Split(resp, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "error id=0") || strings.HasPrefix(line, "error id=1794") {
			return true
		}
	}
	return false
}

// connectViaClientQuery sends the ClientQuery "connect" command to the TS3 client.
// The nickname is TS3-escaped before transmission. Returns nil when ClientQuery
// responds with error id=0 (command accepted).
func connectViaClientQuery(host string, cqPort int, apiKey, tsHost string, tsPort int, nickname string) error {
	conn, scanner, err := clientQueryConnect(host, cqPort, apiKey, 5*time.Second)
	if err != nil {
		return fmt.Errorf("clientquery_connect_cmd: dial: %w", err)
	}
	defer func() { _ = conn.Close() }()

	cmd := fmt.Sprintf("connect address=%s port=%d", tsHost, tsPort)
	if nickname != "" {
		cmd += " nickname=" + ts3Escape(nickname)
	}
	resp, err := clientQueryExecCommand(conn, scanner, cmd, 10*time.Second)
	if err != nil {
		return fmt.Errorf("clientquery_connect_cmd: %w", err)
	}
	for _, line := range strings.Split(resp, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "error id=0") {
			return nil
		}
		if strings.HasPrefix(line, "error id=") {
			return fmt.Errorf("clientquery_connect_cmd failed: %s", line)
		}
	}
	return fmt.Errorf("clientquery_connect_cmd: no error line in response: %s", resp)
}

// waitForTSServerConnected polls "whoami" via ClientQuery every second until the
// TS3 client is connected to a TeamSpeak server (error id=0). Returns an error
// when ctx is cancelled before a successful connection is observed.
func waitForTSServerConnected(ctx context.Context, host string, port int, apiKey string) error {
	if host == "" {
		host = "127.0.0.1"
	}
	ticker := time.NewTicker(1 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			return fmt.Errorf("ts3_connect_timeout: TS3 client did not connect to TeamSpeak server within deadline")
		case <-ticker.C:
			conn, scanner, err := clientQueryConnect(host, port, apiKey, clientQueryDialTimeout)
			if err != nil {
				continue
			}
			resp, cmdErr := clientQueryExecCommand(conn, scanner, "whoami", clientQueryDialTimeout)
			_ = conn.Close()
			if cmdErr != nil {
				continue
			}
			for _, line := range strings.Split(resp, "\n") {
				if strings.HasPrefix(strings.TrimSpace(line), "error id=0") {
					return nil
				}
			}
		}
	}
}

// waitForClientQueryReady polls until the ClientQuery plugin is up and accepting
// authenticated commands. Readiness is confirmed when "whoami" returns either
// error id=0 (connected) or error id=1794 (not yet connected to a TS server but
// the plugin is responsive). This is a stronger check than a banner-only probe
// because it verifies that API-key auth works before the connect command is sent.
//
// If the expected port never becomes ready but the plugin default port (25639) is
// listening, a clientquery_port_mismatch error is returned with a remediation tip.
func waitForClientQueryReady(ctx context.Context, host string, port int, apiKey string) error {
	if host == "" {
		host = "127.0.0.1"
	}
	addr := net.JoinHostPort(host, strconv.Itoa(port))
	ticker := time.NewTicker(500 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-ctx.Done():
			// Check if the plugin is listening on the default port instead (it may
			// have ignored the INI configuration and bound to 25639).
			if port != clientQueryDefaultPort {
				defAddr := net.JoinHostPort(host, fmt.Sprintf("%d", clientQueryDefaultPort))
				conn, dialErr := net.DialTimeout("tcp", defAddr, clientQueryDialTimeout)
				if dialErr == nil {
					if probeClientQueryBanner(conn) {
						return fmt.Errorf(
							"clientquery_port_mismatch: ClientQuery plugin is listening on default port %d, not configured port %d; "+
								"the plugin may have ignored its INI configuration. "+
								"Tip: set client_query_port=0 or client_query_port=%d to use the default.",
							clientQueryDefaultPort, port, clientQueryDefaultPort)
					}
				}
			}
			return fmt.Errorf("clientquery_not_ready: ClientQuery port %s not ready after timeout", addr)
		case <-ticker.C:
			if probeClientQueryControlReady(host, port, apiKey) {
				return nil
			}
		}
	}
}

// probeClientQueryBanner reads the first line from conn and checks it starts with
// "TS3 Client". The connection is always closed before returning.
func probeClientQueryBanner(conn net.Conn) bool {
	defer func() { _ = conn.Close() }()
	_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
	scanner := bufio.NewScanner(conn)
	if scanner.Scan() {
		return strings.HasPrefix(strings.TrimSpace(scanner.Text()), "TS3 Client")
	}
	return false
}

// detectActualClientQueryPort probes expectedPort; if not listening, also checks
// clientQueryDefaultPort (25639). Returns (actualPort, mismatch bool, error).
// mismatch is true when expectedPort != actualPort and actualPort == clientQueryDefaultPort.
func detectActualClientQueryPort(host string, expectedPort int) (actualPort int, mismatch bool, err error) {
	if host == "" {
		host = "127.0.0.1"
	}
	addr := net.JoinHostPort(host, fmt.Sprintf("%d", expectedPort))
	conn, dialErr := net.DialTimeout("tcp", addr, clientQueryDialTimeout)
	if dialErr == nil {
		if probeClientQueryBanner(conn) {
			return expectedPort, false, nil
		}
	}
	// Expected port not ready, check default
	if expectedPort != clientQueryDefaultPort {
		defAddr := net.JoinHostPort(host, fmt.Sprintf("%d", clientQueryDefaultPort))
		conn2, dialErr2 := net.DialTimeout("tcp", defAddr, clientQueryDialTimeout)
		if dialErr2 == nil {
			if probeClientQueryBanner(conn2) {
				return clientQueryDefaultPort, true, nil
			}
		}
	}
	return expectedPort, false, fmt.Errorf("clientquery_not_ready: port %s not listening", addr)
}

// parseActualClientQueryPortFromLog scans log text for lines matching
// "Query | listening on <host>:<port>" and returns the port number.
// Returns 0 if not found.
func parseActualClientQueryPortFromLog(logText string) int {
	for _, line := range strings.Split(logText, "\n") {
		line = strings.TrimSpace(line)
		// Match: "Query   | listening on 127.0.0.1:25639"
		idx := strings.Index(strings.ToLower(line), "listening on ")
		if idx < 0 {
			continue
		}
		after := line[idx+len("listening on "):]
		// after should be like "127.0.0.1:25639"
		colonIdx := strings.LastIndex(after, ":")
		if colonIdx < 0 {
			continue
		}
		portStr := strings.TrimSpace(after[colonIdx+1:])
		p, err := strconv.Atoi(portStr)
		if err == nil && p > 0 {
			return p
		}
	}
	return 0
}
