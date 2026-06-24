package main

import (
	"bufio"
	"context"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

// TestAllocateClientQueryPortBusyExplicitPortReturnsError verifies that requesting
// an explicit port that is already bound (e.g. occupied by SinusBot) results in a
// clientquery_port_in_use error, not a silent failure.
func TestAllocateClientQueryPortBusyExplicitPortReturnsError(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("Listen: %v", err)
	}
	defer func() { _ = ln.Close() }()
	busyPort := ln.Addr().(*net.TCPAddr).Port

	_, allocErr := allocateClientQueryPort("127.0.0.1", "", busyPort)
	if allocErr == nil {
		t.Fatalf("expected clientquery_port_in_use error for busy port %d", busyPort)
	}
	if !strings.Contains(allocErr.Error(), "clientquery_port_in_use") {
		t.Errorf("error %q does not contain 'clientquery_port_in_use'", allocErr.Error())
	}
}

// TestAllocateClientQueryPortBusyExplicitPortSuggestsFreePort verifies that the
// clientquery_port_in_use error message includes a suggested free port so the
// administrator knows what to use instead.
func TestAllocateClientQueryPortBusyExplicitPortSuggestsFreePort(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("Listen: %v", err)
	}
	defer func() { _ = ln.Close() }()
	busyPort := ln.Addr().(*net.TCPAddr).Port

	_, allocErr := allocateClientQueryPort("127.0.0.1", "", busyPort)
	if allocErr == nil {
		t.Fatalf("expected error for busy port %d", busyPort)
	}
	if !strings.Contains(allocErr.Error(), "suggested free port") {
		t.Errorf("error %q does not mention 'suggested free port'", allocErr.Error())
	}
}

// TestDeterministicClientQueryPortDifferentPaths verifies that two different instance
// paths produce different ClientQuery ports, ensuring distinct instances do not collide
// on the same default port.
func TestDeterministicClientQueryPortDifferentPaths(t *testing.T) {
	paths := []string{
		"/srv/easywi/instances/musicbot/1",
		"/srv/easywi/instances/musicbot/2",
		"/srv/easywi/instances/musicbot/3",
		"/var/lib/easywi/node/alpha",
		"/var/lib/easywi/node/beta",
	}

	seen := make(map[int]string)
	allSame := true
	first := deterministicClientQueryPort(paths[0])
	for _, p := range paths {
		port := deterministicClientQueryPort(p)
		if port < clientQueryPortRangeStart || port >= clientQueryPortRangeStart+clientQueryPortRangeSize {
			t.Errorf("path %q: port %d out of range [%d, %d)", p, port,
				clientQueryPortRangeStart, clientQueryPortRangeStart+clientQueryPortRangeSize)
		}
		if port != first {
			allSame = false
		}
		// Determinism: same path must always yield the same port.
		if port2 := deterministicClientQueryPort(p); port2 != port {
			t.Errorf("path %q: non-deterministic ports %d vs %d", p, port, port2)
		}
		seen[port] = p
	}
	if allSame {
		t.Errorf("all %d paths mapped to the same port %d; hash distribution is broken", len(paths), first)
	}
}

// TestAllocateClientQueryPortAutoFallsBackOnBusyDeterministicPort simulates the
// SinusBot scenario: the deterministic port for the instance is occupied, so the
// allocator must return a different free port without error.
func TestAllocateClientQueryPortAutoFallsBackOnBusyDeterministicPort(t *testing.T) {
	instancePath := "/srv/easywi/instances/ts3bridge/99"
	deterPort := deterministicClientQueryPort(instancePath)

	ln, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", deterPort))
	if err != nil {
		t.Skipf("cannot bind 127.0.0.1:%d to simulate busy port: %v", deterPort, err)
	}
	defer func() { _ = ln.Close() }()

	port, allocErr := allocateClientQueryPort("127.0.0.1", instancePath, 0)
	if allocErr != nil {
		t.Fatalf("unexpected error when deterministic port is busy: %v", allocErr)
	}
	if port == deterPort {
		t.Errorf("allocator returned the occupied deterministic port %d instead of a free alternative", deterPort)
	}
	if port <= 0 {
		t.Errorf("invalid port %d returned", port)
	}
}

// TestWaitForClientQueryReadyTimeout verifies that waitForClientQueryReady returns a
// clientquery_not_ready error when neither the configured port nor the default port
// (25639) has a listener within the deadline.
//
// If port 25639 is already in use, waitForClientQueryReady returns
// clientquery_port_mismatch instead; that path is covered by
// TestWaitForClientQueryReadyPortMismatch.
func TestWaitForClientQueryReadyTimeout(t *testing.T) {
	if !checkPortAvailable("127.0.0.1", clientQueryDefaultPort) {
		t.Skipf("default ClientQuery port %d is already in use; waitForClientQueryReady "+
			"would return clientquery_port_mismatch, not clientquery_not_ready — "+
			"see TestWaitForClientQueryReadyPortMismatch", clientQueryDefaultPort)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	err := waitForClientQueryReady(ctx, "127.0.0.1", 19997, "")
	if err == nil {
		t.Skip("port 19997 unexpectedly has a listener in the test environment")
	}
	if !strings.Contains(err.Error(), "clientquery_not_ready") {
		t.Errorf("error %q does not contain 'clientquery_not_ready'", err.Error())
	}
}

// TestWriteClientQueryPluginConfigCreatesFiles verifies that the INI file with the
// correct port and host is written to all three subdirectory locations the TS3 client may read.
func TestWriteClientQueryPluginConfigCreatesFiles(t *testing.T) {
	ts3Home := t.TempDir()
	if err := writeClientQueryPluginConfig(ts3Home, "127.0.0.1", 25641); err != nil {
		t.Fatalf("writeClientQueryPluginConfig: %v", err)
	}
	locations := []string{
		filepath.Join(ts3Home, ".ts3client", "plugins", "clientquery.ini"),
		filepath.Join(ts3Home, ".config", "plugins", "clientquery.ini"),
		filepath.Join(ts3Home, "config", "plugins", "clientquery.ini"),
	}
	for _, loc := range locations {
		data, readErr := os.ReadFile(loc)
		if readErr != nil {
			t.Errorf("config missing at %s: %v", loc, readErr)
			continue
		}
		content := string(data)
		if !strings.Contains(content, "Port=25641") {
			t.Errorf("%s: missing Port=25641, got:\n%s", loc, content)
		}
		if !strings.Contains(content, "Host=127.0.0.1") {
			t.Errorf("%s: missing Host=127.0.0.1", loc)
		}
		info, statErr := os.Stat(loc)
		if statErr != nil {
			t.Errorf("stat %s: %v", loc, statErr)
			continue
		}
		if info.Mode().Perm() != 0o600 {
			t.Errorf("%s: permissions %04o, want 0600", loc, info.Mode().Perm())
		}
	}
}

// TestWriteClientQueryPluginConfigCreatesAllPaths verifies that the INI file is also
// written to ts3Home/.ts3client/clientquery.ini and ts3Home/clientquery.ini.
func TestWriteClientQueryPluginConfigCreatesAllPaths(t *testing.T) {
	ts3Home := t.TempDir()
	if err := writeClientQueryPluginConfig(ts3Home, "127.0.0.1", 25642); err != nil {
		t.Fatalf("writeClientQueryPluginConfig: %v", err)
	}
	extraLocations := []string{
		filepath.Join(ts3Home, ".ts3client", "clientquery.ini"),
		filepath.Join(ts3Home, "clientquery.ini"),
	}
	for _, loc := range extraLocations {
		data, readErr := os.ReadFile(loc)
		if readErr != nil {
			t.Errorf("config missing at %s: %v", loc, readErr)
			continue
		}
		if !strings.Contains(string(data), "Port=25642") {
			t.Errorf("%s: missing Port=25642", loc)
		}
		info, statErr := os.Stat(loc)
		if statErr != nil {
			t.Errorf("stat %s: %v", loc, statErr)
			continue
		}
		if info.Mode().Perm() != 0o600 {
			t.Errorf("%s: permissions %04o, want 0600", loc, info.Mode().Perm())
		}
	}
}

// TestParseActualClientQueryPortFromLog verifies that port number is parsed from
// "Query | listening on 127.0.0.1:25639" style log lines.
func TestParseActualClientQueryPortFromLog(t *testing.T) {
	logText := "2026-06-23 12:00:00 Query   | listening on 127.0.0.1:25639\n"
	got := parseActualClientQueryPortFromLog(logText)
	if got != 25639 {
		t.Errorf("parseActualClientQueryPortFromLog = %d, want 25639", got)
	}
}

// TestParseActualClientQueryPortFromLogNotFound verifies that 0 is returned when
// no matching line is present.
func TestParseActualClientQueryPortFromLogNotFound(t *testing.T) {
	got := parseActualClientQueryPortFromLog("no port info here\n")
	if got != 0 {
		t.Errorf("parseActualClientQueryPortFromLog = %d, want 0", got)
	}
}

// TestAllocateClientQueryPortDefaultPrefers25639 verifies that when requestedPort==0
// and the default port 25639 is available, it is returned immediately.
func TestAllocateClientQueryPortDefaultPrefers25639(t *testing.T) {
	// Only run this test if 25639 is actually available on the test machine.
	if !checkPortAvailable("127.0.0.1", clientQueryDefaultPort) {
		t.Skipf("port %d is not available on this test machine; skipping", clientQueryDefaultPort)
	}
	port, err := allocateClientQueryPort("127.0.0.1", "/some/instance", 0)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if port != clientQueryDefaultPort {
		t.Errorf("allocateClientQueryPort = %d, want %d (default)", port, clientQueryDefaultPort)
	}
}

// TestWaitForClientQueryReadyPortMismatch verifies that when the expected port is
// not listening but 25639 is, a clientquery_port_mismatch error is returned.
func TestWaitForClientQueryReadyPortMismatch(t *testing.T) {
	// Start a fake ClientQuery server on the default port.
	ln, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", clientQueryDefaultPort))
	if err != nil {
		t.Skipf("cannot bind %d (port in use): %v", clientQueryDefaultPort, err)
	}
	defer func() { _ = ln.Close() }()

	// Serve the TS3 Client banner.
	go func() {
		conn, acceptErr := ln.Accept()
		if acceptErr != nil {
			return
		}
		_, _ = conn.Write([]byte("TS3 Client\n"))
		_ = conn.Close()
	}()

	// Use a port that nobody is listening on (deliberately wrong expected port).
	unusedPort := 19993
	ctx, cancel := context.WithTimeout(context.Background(), 1500*time.Millisecond)
	defer cancel()

	err = waitForClientQueryReady(ctx, "127.0.0.1", unusedPort, "")
	if err == nil {
		t.Fatal("expected error for port mismatch")
	}
	if !strings.Contains(err.Error(), "clientquery_port_mismatch") {
		t.Errorf("error %q does not contain 'clientquery_port_mismatch'", err.Error())
	}
}

// ── API-key reading ───────────────────────────────────────────────────────────

func TestReadClientQueryApiKeyReadsKey(t *testing.T) {
	dir := t.TempDir()
	ts3dir := filepath.Join(dir, ".ts3client")
	if err := os.MkdirAll(ts3dir, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}
	iniPath := filepath.Join(ts3dir, "clientquery.ini")
	if err := os.WriteFile(iniPath, []byte("[General]\nApiKey=abc123\nPort=25639\n"), 0o600); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}
	got := readClientQueryApiKey(dir)
	if got != "abc123" {
		t.Errorf("readClientQueryApiKey = %q, want %q", got, "abc123")
	}
}

func TestReadClientQueryApiKeyMissingFile(t *testing.T) {
	dir := t.TempDir()
	got := readClientQueryApiKey(dir)
	if got != "" {
		t.Errorf("readClientQueryApiKey = %q, want empty string for missing file", got)
	}
}

func TestReadClientQueryApiKeyNoEntry(t *testing.T) {
	dir := t.TempDir()
	ts3dir := filepath.Join(dir, ".ts3client")
	if err := os.MkdirAll(ts3dir, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}
	iniPath := filepath.Join(ts3dir, "clientquery.ini")
	if err := os.WriteFile(iniPath, []byte("[General]\nPort=25639\n"), 0o600); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}
	got := readClientQueryApiKey(dir)
	if got != "" {
		t.Errorf("readClientQueryApiKey = %q, want empty string when no ApiKey entry", got)
	}
}

// ── TS3 escape ────────────────────────────────────────────────────────────────

func TestTs3EscapeSpaces(t *testing.T) {
	got := ts3Escape("hello world")
	if got != `hello\sworld` {
		t.Errorf("ts3Escape = %q, want %q", got, `hello\sworld`)
	}
}

func TestTs3EscapeBackslash(t *testing.T) {
	got := ts3Escape(`back\slash`)
	if got != `back\\slash` {
		t.Errorf("ts3Escape = %q, want %q", got, `back\\slash`)
	}
}

// ── mock helpers ──────────────────────────────────────────────────────────────

// mockCQServer starts a TCP listener and calls handler for each accepted connection.
// Returns the listener (caller must Close) and the bound port.
func mockCQServer(t *testing.T, handler func(net.Conn)) (net.Listener, int) {
	t.Helper()
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("mockCQServer Listen: %v", err)
	}
	go func() {
		for {
			conn, err := ln.Accept()
			if err != nil {
				return
			}
			go handler(conn)
		}
	}()
	return ln, ln.Addr().(*net.TCPAddr).Port
}

// ── clientQueryConnect ────────────────────────────────────────────────────────

// lineDispatcher is a sequential mock server helper: it sends the banner, then
// reads client commands line-by-line via a bufio.Scanner and dispatches responses
// via the provided map (prefix → response line(s)). This avoids pre-buffering
// responses ahead of the client's requests.
func lineDispatcher(conn net.Conn, responses map[string]string) {
	defer conn.Close()
	_, _ = conn.Write([]byte("TS3 Client\n"))
	sc := bufio.NewScanner(conn)
	for sc.Scan() {
		line := strings.TrimSpace(sc.Text())
		for prefix, resp := range responses {
			if strings.HasPrefix(line, prefix) {
				_, _ = conn.Write([]byte(resp))
				break
			}
		}
	}
}

func TestClientQueryConnectAuthSuccess(t *testing.T) {
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"auth": "error id=0 msg=ok\n",
		})
	})
	defer func() { _ = ln.Close() }()

	conn, scanner, err := clientQueryConnect("127.0.0.1", port, "mykey", 2*time.Second)
	if err != nil {
		t.Fatalf("clientQueryConnect: %v", err)
	}
	_ = scanner
	_ = conn.Close()
}

func TestClientQueryConnectNoBanner(t *testing.T) {
	ln, port := mockCQServer(t, func(conn net.Conn) {
		_ = conn.Close() // close immediately without banner
	})
	defer func() { _ = ln.Close() }()

	_, _, err := clientQueryConnect("127.0.0.1", port, "", 500*time.Millisecond)
	if err == nil {
		t.Fatal("expected error when server closes without banner")
	}
}

func TestClientQueryConnectAuthFailed(t *testing.T) {
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"auth": "error id=1796 msg=invalid_api_key\n",
		})
	})
	defer func() { _ = ln.Close() }()

	_, _, err := clientQueryConnect("127.0.0.1", port, "wrongkey", 2*time.Second)
	if err == nil {
		t.Fatal("expected error for invalid auth")
	}
}

// ── connectViaClientQuery ─────────────────────────────────────────────────────

func TestConnectViaClientQuerySuccess(t *testing.T) {
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"connect": "error id=0 msg=ok\n",
		})
	})
	defer func() { _ = ln.Close() }()

	err := connectViaClientQuery("127.0.0.1", port, "", "ts3.example.com", 9987, "MusicBot")
	if err != nil {
		t.Fatalf("connectViaClientQuery: %v", err)
	}
}

func TestConnectViaClientQueryFailed(t *testing.T) {
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"connect": "error id=770 msg=cannot_connect_to_server\n",
		})
	})
	defer func() { _ = ln.Close() }()

	err := connectViaClientQuery("127.0.0.1", port, "", "bad.host", 9987, "Bot")
	if err == nil {
		t.Fatal("expected error when connect command returns non-zero error id")
	}
}

// ── probeClientQueryControlReady ─────────────────────────────────────────────

func TestProbeClientQueryControlReady1794(t *testing.T) {
	// error id=1794 = not connected to TS server, but control-ready
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"whoami": "error id=1794 msg=not\\sconnected\n",
		})
	})
	defer func() { _ = ln.Close() }()

	if !probeClientQueryControlReady("127.0.0.1", port, "") {
		t.Error("probeClientQueryControlReady should return true for error id=1794")
	}
}

func TestProbeClientQueryControlReadyConnected(t *testing.T) {
	// error id=0 from whoami = fully connected; uses real cid format.
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"whoami": "clid=29 cid=1\nerror id=0 msg=ok\n",
		})
	})
	defer func() { _ = ln.Close() }()

	if !probeClientQueryControlReady("127.0.0.1", port, "") {
		t.Error("probeClientQueryControlReady should return true for error id=0")
	}
}

// ── waitForTSServerConnected ──────────────────────────────────────────────────

func TestWaitForTSServerConnectedImmediate(t *testing.T) {
	// Simulate a fully connected state: clid and cid present, error id=0.
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"whoami": "clid=29 cid=1\nerror id=0 msg=ok\n",
		})
	})
	defer func() { _ = ln.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	gotCLID, gotCID, err := waitForTSServerConnected(ctx, "127.0.0.1", port, "")
	if err != nil {
		t.Fatalf("waitForTSServerConnected: %v", err)
	}
	if gotCLID != "29" {
		t.Errorf("clid = %q, want 29", gotCLID)
	}
	if gotCID != "1" {
		t.Errorf("cid = %q, want 1", gotCID)
	}
}

func TestWaitForTSServerConnectedTimeout(t *testing.T) {
	// whoami always returns 1794 (not connected)
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"whoami": "error id=1794 msg=not\\sconnected\n",
		})
	})
	defer func() { _ = ln.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 400*time.Millisecond)
	defer cancel()
	_, _, err := waitForTSServerConnected(ctx, "127.0.0.1", port, "")
	if err == nil {
		t.Fatal("expected timeout error when TS server never connects")
	}
}

// TestWaitForTSServerConnectedRetry1794ThenSuccess verifies the happy path where
// whoami first returns 1794 (not connected) then returns clid+cid+error id=0.
// This matches the real-world sequence observed after sending the ClientQuery connect command.
func TestWaitForTSServerConnectedRetry1794ThenSuccess(t *testing.T) {
	responses := []string{
		"error id=1794 msg=not\\sconnected\n",
		"error id=1794 msg=not\\sconnected\n",
		"clid=29 cid=1\nerror id=0 msg=ok\n",
	}
	idx := 0
	ln, port := mockCQServer(t, func(conn net.Conn) {
		defer func() { _ = conn.Close() }()
		_, _ = conn.Write([]byte("TS3 Client\n"))
		sc := bufio.NewScanner(conn)
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if strings.HasPrefix(line, "whoami") {
				var resp string
				if idx < len(responses) {
					resp = responses[idx]
					idx++
				} else {
					resp = "clid=29 cid=1\nerror id=0 msg=ok\n"
				}
				_, _ = conn.Write([]byte(resp))
			}
		}
	})
	defer func() { _ = ln.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	gotCLID, gotCID, err := waitForTSServerConnected(ctx, "127.0.0.1", port, "")
	if err != nil {
		t.Fatalf("waitForTSServerConnected: %v", err)
	}
	if gotCLID != "29" {
		t.Errorf("clid = %q, want 29", gotCLID)
	}
	if gotCID != "1" {
		t.Errorf("cid = %q, want 1", gotCID)
	}
}

// TestWaitForTSServerConnectedRetry1796 verifies that error id=1796
// (currently not possible) is treated as a retry, not a fatal error.
func TestWaitForTSServerConnectedRetry1796(t *testing.T) {
	responses := []string{
		"error id=1796 msg=currently\\snot\\spossible\n",
		"error id=1796 msg=currently\\snot\\spossible\n",
		"clid=5 cid=2\nerror id=0 msg=ok\n",
	}
	idx := 0
	ln, port := mockCQServer(t, func(conn net.Conn) {
		defer func() { _ = conn.Close() }()
		_, _ = conn.Write([]byte("TS3 Client\n"))
		sc := bufio.NewScanner(conn)
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if strings.HasPrefix(line, "whoami") {
				var resp string
				if idx < len(responses) {
					resp = responses[idx]
					idx++
				} else {
					resp = "clid=5 cid=2\nerror id=0 msg=ok\n"
				}
				_, _ = conn.Write([]byte(resp))
			}
		}
	})
	defer func() { _ = ln.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	gotCLID, gotCID, err := waitForTSServerConnected(ctx, "127.0.0.1", port, "")
	if err != nil {
		t.Fatalf("expected success after 1796 retries, got: %v", err)
	}
	if gotCLID != "5" {
		t.Errorf("clid = %q, want 5", gotCLID)
	}
	if gotCID != "2" {
		t.Errorf("cid = %q, want 2", gotCID)
	}
}

// TestWaitForTSServerConnectedRequiresCID verifies that error id=0 without cid
// is NOT treated as success; a cid must be present.
func TestWaitForTSServerConnectedRequiresCID(t *testing.T) {
	// First response has clid but no cid; second response has both.
	responses := []string{
		"clid=29\nerror id=0 msg=ok\n",   // clid only — not enough
		"clid=29 cid=1\nerror id=0 msg=ok\n", // full success
	}
	idx := 0
	ln, port := mockCQServer(t, func(conn net.Conn) {
		defer func() { _ = conn.Close() }()
		_, _ = conn.Write([]byte("TS3 Client\n"))
		sc := bufio.NewScanner(conn)
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if strings.HasPrefix(line, "whoami") {
				var resp string
				if idx < len(responses) {
					resp = responses[idx]
					idx++
				} else {
					resp = "clid=29 cid=1\nerror id=0 msg=ok\n"
				}
				_, _ = conn.Write([]byte(resp))
			}
		}
	})
	defer func() { _ = ln.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	gotCLID, gotCID, err := waitForTSServerConnected(ctx, "127.0.0.1", port, "")
	if err != nil {
		t.Fatalf("waitForTSServerConnected: %v", err)
	}
	if gotCLID != "29" || gotCID != "1" {
		t.Errorf("got clid=%q cid=%q, want clid=29 cid=1", gotCLID, gotCID)
	}
}

// TestWaitForTSServerConnectedTimeoutIncludesContext verifies that the timeout
// error message includes host, port, and attempt count.
func TestWaitForTSServerConnectedTimeoutIncludesContext(t *testing.T) {
	ln, port := mockCQServer(t, func(conn net.Conn) {
		lineDispatcher(conn, map[string]string{
			"whoami": "error id=1794 msg=not\\sconnected\n",
		})
	})
	defer func() { _ = ln.Close() }()

	ctx, cancel := context.WithTimeout(context.Background(), 600*time.Millisecond)
	defer cancel()
	_, _, err := waitForTSServerConnected(ctx, "127.0.0.1", port, "")
	if err == nil {
		t.Fatal("expected timeout error")
	}
	msg := err.Error()
	if !strings.Contains(msg, "ts3_connect_timeout") {
		t.Errorf("error %q does not contain ts3_connect_timeout", msg)
	}
	if !strings.Contains(msg, "127.0.0.1") {
		t.Errorf("error %q does not contain host", msg)
	}
	if !strings.Contains(msg, fmt.Sprintf("%d", port)) {
		t.Errorf("error %q does not contain port", msg)
	}
	if !strings.Contains(msg, "attempts=") {
		t.Errorf("error %q does not contain attempt count", msg)
	}
}

// TestConnectViaClientQuerySendsAuthThenConnect verifies the full protocol:
// the mock server checks that auth is received before the connect command.
func TestConnectViaClientQuerySendsAuthThenConnect(t *testing.T) {
	authReceived := false
	connectReceived := false
	ln, port := mockCQServer(t, func(conn net.Conn) {
		defer func() { _ = conn.Close() }()
		_, _ = conn.Write([]byte("TS3 Client\n"))
		sc := bufio.NewScanner(conn)
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if strings.HasPrefix(line, "auth apikey=testkey") {
				authReceived = true
				_, _ = conn.Write([]byte("error id=0 msg=ok\n"))
			} else if strings.HasPrefix(line, "connect address=ts.example.com port=9987") {
				if !authReceived {
					// auth must precede connect
					_, _ = conn.Write([]byte("error id=1796 msg=auth_required\n"))
					return
				}
				connectReceived = true
				_, _ = conn.Write([]byte("error id=0 msg=ok\n"))
			}
		}
	})
	defer func() { _ = ln.Close() }()

	err := connectViaClientQuery("127.0.0.1", port, "testkey", "ts.example.com", 9987, "MusicBot")
	if err != nil {
		t.Fatalf("connectViaClientQuery: %v", err)
	}
	if !authReceived {
		t.Error("auth apikey command was not sent to the mock server")
	}
	if !connectReceived {
		t.Error("connect command was not sent to the mock server")
	}
}

// TestConnectViaClientQueryNicknameEscaped verifies that the nickname is
// TS3-escaped (spaces → \s) in the connect command.
func TestConnectViaClientQueryNicknameEscaped(t *testing.T) {
	var receivedConnect string
	ln, port := mockCQServer(t, func(conn net.Conn) {
		defer func() { _ = conn.Close() }()
		_, _ = conn.Write([]byte("TS3 Client\n"))
		sc := bufio.NewScanner(conn)
		for sc.Scan() {
			line := strings.TrimSpace(sc.Text())
			if strings.HasPrefix(line, "connect") {
				receivedConnect = line
				_, _ = conn.Write([]byte("error id=0 msg=ok\n"))
			}
		}
	})
	defer func() { _ = ln.Close() }()

	err := connectViaClientQuery("127.0.0.1", port, "", "ts.example.com", 9987, "Easy Wi Musicbot")
	if err != nil {
		t.Fatalf("connectViaClientQuery: %v", err)
	}
	if !strings.Contains(receivedConnect, `nickname=Easy\sWi\sMusicbot`) {
		t.Errorf("connect command %q does not contain escaped nickname", receivedConnect)
	}
}

// ── readClientQueryApiKey with api_key (underscore) ─────────────────────────

// TestReadClientQueryApiKeyUnderscore verifies that the key is read when
// the ini file uses the "api_key=..." format (with underscore) as written by
// the official TS3 ClientQuery plugin.
func TestReadClientQueryApiKeyUnderscore(t *testing.T) {
	dir := t.TempDir()
	ts3dir := filepath.Join(dir, ".ts3client")
	if err := os.MkdirAll(ts3dir, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}
	iniPath := filepath.Join(ts3dir, "clientquery.ini")
	// Typical TS3 ClientQuery plugin format with underscore key name.
	if err := os.WriteFile(iniPath, []byte("[ClientQuery]\napi_key=ABCD-1234-EFGH-5678\nPort=25639\n"), 0o600); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}
	got := readClientQueryApiKey(dir)
	if got != "ABCD-1234-EFGH-5678" {
		t.Errorf("readClientQueryApiKey = %q, want ABCD-1234-EFGH-5678 (api_key with underscore not parsed)", got)
	}
}

// TestReadClientQueryApiKeyApiKeyMixed verifies mixed-case ApiKey= (no underscore).
func TestReadClientQueryApiKeyMixed(t *testing.T) {
	dir := t.TempDir()
	ts3dir := filepath.Join(dir, ".ts3client")
	if err := os.MkdirAll(ts3dir, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}
	iniPath := filepath.Join(ts3dir, "clientquery.ini")
	if err := os.WriteFile(iniPath, []byte("[ClientQuery]\nApiKey=WXYZ-9876\nPort=25639\n"), 0o600); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}
	got := readClientQueryApiKey(dir)
	if got != "WXYZ-9876" {
		t.Errorf("readClientQueryApiKey = %q, want WXYZ-9876 (ApiKey not parsed)", got)
	}
}

// ── maskApiKey ────────────────────────────────────────────────────────────────

// TestMaskApiKeyLong verifies that a long key is masked with first/last 4 chars.
func TestMaskApiKeyLong(t *testing.T) {
	key := "ABCD-1234-EFGH-5678-WXYZ"
	got := maskApiKey(key)
	if !strings.HasPrefix(got, "ABCD") {
		t.Errorf("maskApiKey = %q, want prefix ABCD", got)
	}
	if !strings.HasSuffix(got, "WXYZ") {
		t.Errorf("maskApiKey = %q, want suffix WXYZ", got)
	}
	if strings.Contains(got, "1234") || strings.Contains(got, "EFGH") {
		t.Errorf("maskApiKey = %q, must not contain middle portion", got)
	}
}

// TestMaskApiKeyShort verifies that short keys are fully redacted.
func TestMaskApiKeyShort(t *testing.T) {
	got := maskApiKey("abc")
	if got != "[redacted]" {
		t.Errorf("maskApiKey(%q) = %q, want [redacted]", "abc", got)
	}
}

// TestMaskApiKeyNotFullKey verifies that the full API key never appears when masked.
func TestMaskApiKeyNotFullKey(t *testing.T) {
	key := "SECRET-APIKEY-THAT-MUST-NOT-LEAK"
	masked := maskApiKey(key)
	if masked == key {
		t.Error("maskApiKey returned the full key unchanged — key would leak into logs")
	}
	// The masked version must not contain the middle section.
	if strings.Contains(masked, "APIKEY-THAT-MUST-NOT") {
		t.Errorf("maskApiKey = %q, middle section should not appear", masked)
	}
}

// ── parseWhoamiResponse ───────────────────────────────────────────────────────

func TestParseWhoamiResponseConnected(t *testing.T) {
	resp := "clid=29 cid=1\nerror id=0 msg=ok\n"
	r := parseWhoamiResponse(resp)
	if r.clid != "29" || r.cid != "1" || r.errorID != "0" || r.state != "connected" {
		t.Errorf("parseWhoamiResponse = clid=%q cid=%q errorID=%q state=%q; want 29/1/0/connected",
			r.clid, r.cid, r.errorID, r.state)
	}
}

func TestParseWhoamiResponseNotConnected(t *testing.T) {
	resp := "error id=1794 msg=not\\sconnected\n"
	r := parseWhoamiResponse(resp)
	if r.state != "not_connected" || r.errorID != "1794" {
		t.Errorf("state=%q errorID=%q; want not_connected/1794", r.state, r.errorID)
	}
}

func TestParseWhoamiResponseBusy(t *testing.T) {
	resp := "error id=1796 msg=currently\\snot\\spossible\n"
	r := parseWhoamiResponse(resp)
	if r.state != "busy" || r.errorID != "1796" {
		t.Errorf("state=%q errorID=%q; want busy/1796", r.state, r.errorID)
	}
}

func TestParseWhoamiResponseClidWithoutCid(t *testing.T) {
	// error id=0 with clid but no cid should not be treated as connected.
	resp := "clid=29\nerror id=0 msg=ok\n"
	r := parseWhoamiResponse(resp)
	if r.state == "connected" {
		t.Errorf("state=%q but cid is empty; should not be connected", r.state)
	}
}
