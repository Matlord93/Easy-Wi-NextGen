package main

import (
	"bufio"
	"context"
	"errors"
	"fmt"
	"hash/fnv"
	"io"
	"log"
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
// are written with mode 0600.
//
// Plugin subdirectory files (plugins/clientquery.ini) are always overwritten with a
// minimal [ClientQuery] section containing only Port= and Host=.
//
// Primary INI files (.ts3client/clientquery.ini, clientquery.ini) are merged via
// mergeClientQueryPortHost so that [General] — where the TS3 ClientQuery plugin
// stores api_key — is preserved verbatim. Erasing [General] causes the plugin to
// regenerate a new api_key, breaking auth on every restart.
func writeClientQueryPluginConfig(ts3Home, host string, port int) error {
	if host == "" {
		host = "127.0.0.1"
	}

	// Plugin subdirectory locations: fresh overwrite (no api_key stored here).
	pluginContent := fmt.Sprintf("[ClientQuery]\nPort=%d\nHost=%s\n", port, host)
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
		if err := os.WriteFile(iniPath, []byte(pluginContent), 0o600); err != nil {
			return fmt.Errorf("write clientquery.ini to %s: %w", iniPath, err)
		}
	}

	// Primary INI locations: merge Port/Host so [General] (with api_key) survives.
	rootDirs := []string{
		filepath.Join(ts3Home, ".ts3client"),
		ts3Home,
	}
	for _, dir := range rootDirs {
		if err := os.MkdirAll(dir, 0o700); err != nil {
			return fmt.Errorf("clientquery config dir %s: %w", dir, err)
		}
		iniPath := filepath.Join(dir, "clientquery.ini")
		if err := mergeClientQueryPortHost(iniPath, host, port); err != nil {
			return fmt.Errorf("write clientquery.ini to %s: %w", iniPath, err)
		}
	}

	return nil
}

// mergeClientQueryPortHost reads iniPath, updates Port= and Host= inside the
// [ClientQuery] section, and writes the result back. Every other section
// (including [General] where the TS3 plugin stores api_key) and every other
// key=value pair is preserved verbatim. If iniPath does not yet exist, a minimal
// [ClientQuery] section is created. File mode is always 0600.
func mergeClientQueryPortHost(iniPath, host string, port int) error {
	existing, readErr := os.ReadFile(iniPath)
	if readErr != nil {
		content := fmt.Sprintf("[ClientQuery]\nPort=%d\nHost=%s\n", port, host)
		return os.WriteFile(iniPath, []byte(content), 0o600)
	}

	lines := strings.Split(string(existing), "\n")
	out := make([]string, 0, len(lines)+2)
	inClientQuery := false
	portSet := false
	hostSet := false

	for _, rawLine := range lines {
		trimmed := strings.TrimSpace(rawLine)

		// Section header.
		if len(trimmed) >= 2 && trimmed[0] == '[' && trimmed[len(trimmed)-1] == ']' {
			// Leaving [ClientQuery]: flush any missing Port/Host before next section.
			if inClientQuery {
				if !portSet {
					out = append(out, fmt.Sprintf("Port=%d", port))
					portSet = true
				}
				if !hostSet {
					out = append(out, fmt.Sprintf("Host=%s", host))
					hostSet = true
				}
			}
			inClientQuery = strings.EqualFold(trimmed, "[ClientQuery]")
			out = append(out, rawLine)
			continue
		}

		// Key=value inside [ClientQuery]: replace Port= and Host=, keep everything else.
		if inClientQuery && trimmed != "" {
			eqIdx := strings.IndexByte(trimmed, '=')
			if eqIdx > 0 {
				key := strings.ToLower(strings.TrimSpace(trimmed[:eqIdx]))
				switch key {
				case "port":
					out = append(out, fmt.Sprintf("Port=%d", port))
					portSet = true
					continue
				case "host":
					out = append(out, fmt.Sprintf("Host=%s", host))
					hostSet = true
					continue
				}
			}
		}

		out = append(out, rawLine)
	}

	// [ClientQuery] was the last section — flush missing keys now.
	if inClientQuery {
		if !portSet {
			out = append(out, fmt.Sprintf("Port=%d", port))
		}
		if !hostSet {
			out = append(out, fmt.Sprintf("Host=%s", host))
		}
	} else if !portSet || !hostSet {
		// No [ClientQuery] section found; append one.
		if len(out) > 0 && strings.TrimSpace(out[len(out)-1]) != "" {
			out = append(out, "")
		}
		out = append(out, "[ClientQuery]")
		if !portSet {
			out = append(out, fmt.Sprintf("Port=%d", port))
		}
		if !hostSet {
			out = append(out, fmt.Sprintf("Host=%s", host))
		}
	}

	result := strings.Join(out, "\n")
	if !strings.HasSuffix(result, "\n") {
		result += "\n"
	}
	return os.WriteFile(iniPath, []byte(result), 0o600)
}

// readClientQueryApiKey reads the ApiKey value from the TS3 client's ClientQuery
// configuration at <persistentTs3Home>/.ts3client/clientquery.ini. Returns "" when
// the file is absent or contains no ApiKey entry; never returns an error so the
// caller can proceed without auth when the key is not yet available.
//
// The TS3 ClientQuery plugin writes the key as "api_key=..." (with underscore).
// Older or alternative builds may write "ApiKey=..." or "apikey=...". All forms
// are matched case-insensitively, with and without the underscore.
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
		// Normalise: lower-case and strip underscores → "apikey" matches both
		// "api_key" and "ApiKey" and "apikey".
		normKey := strings.ReplaceAll(strings.ToLower(strings.TrimSpace(line[:eqIdx])), "_", "")
		if normKey == "apikey" {
			val := strings.TrimSpace(line[eqIdx+1:])
			if val != "" {
				return val
			}
		}
	}
	return ""
}

// maskApiKey returns a display-safe version of apiKey that hides the middle
// section. For keys longer than 8 characters the format is XXXX-...-XXXX,
// showing the first 4 and last 4 characters. Shorter keys are fully redacted.
func maskApiKey(apiKey string) string {
	if len(apiKey) <= 8 {
		return "[redacted]"
	}
	return apiKey[:4] + "-...-" + apiKey[len(apiKey)-4:]
}

// clientQueryApiKeyIniPath returns the path to the clientquery.ini file inside
// persistentTs3Home, for logging purposes.
func clientQueryApiKeyIniPath(persistentTs3Home string) string {
	return filepath.Join(persistentTs3Home, ".ts3client", "clientquery.ini")
}

// readClientQueryApiKeyWithRetry polls the clientquery.ini until an api_key
// appears or the deadline passes (or ctx is cancelled). This is needed because
// the TS3 ClientQuery plugin writes the api_key asynchronously during plugin
// initialisation, which happens shortly after the "Query listening" log line.
//
// Each poll attempt is logged:
//
//	external_client_bridge clientquery_api_key_wait_attempt=<n> clientquery_ini_exists=<bool> clientquery_api_key_present=<bool>
//
// Returns ("", false) when the timeout expires without finding a key.
func readClientQueryApiKeyWithRetry(ctx context.Context, persistentTs3Home string, timeout time.Duration) (string, bool) {
	iniPath := clientQueryApiKeyIniPath(persistentTs3Home)
	deadline := time.Now().Add(timeout)
	for attempt := 1; ; attempt++ {
		_, statErr := os.Stat(iniPath)
		iniExists := statErr == nil
		key := readClientQueryApiKey(persistentTs3Home)
		keyPresent := key != ""
		log.Printf("external_client_bridge clientquery_api_key_wait_attempt=%d clientquery_ini_exists=%v clientquery_api_key_present=%v",
			attempt, iniExists, keyPresent)
		if keyPresent {
			return key, true
		}
		if time.Now().After(deadline) || ctx.Err() != nil {
			return "", false
		}
		time.Sleep(500 * time.Millisecond)
	}
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

// connectViaClientQuery authenticates to the ClientQuery plugin and sends the
// "connect" command to the TS3 client. The nickname is TS3-escaped before
// transmission. Returns nil when ClientQuery responds with error id=0 (command accepted).
//
// Logging (structured key=value lines, no secrets):
//   - clientquery_auth_attempt=true
//   - clientquery_auth_success=true|false
//   - clientquery_connect_command=connect
//   - clientquery_connect_host=<host>
//   - clientquery_connect_port=<port>
//   - clientquery_connect_response_error_id=<id>
//   - clientquery_connect_success=true|false
func connectViaClientQuery(host string, cqPort int, apiKey, tsHost string, tsPort int, nickname string) error {
	log.Printf("external_client_bridge clientquery_auth_attempt=true")
	conn, scanner, err := clientQueryConnect(host, cqPort, apiKey, 5*time.Second)
	if err != nil {
		log.Printf("external_client_bridge clientquery_auth_success=false clientquery_auth_error=%s", sanitizeErrorForLog(err.Error()))
		return fmt.Errorf("clientquery_connect_cmd: dial: %w", err)
	}
	log.Printf("external_client_bridge clientquery_auth_success=true")
	defer func() { _ = conn.Close() }()

	cmd := fmt.Sprintf("connect address=%s port=%d", tsHost, tsPort)
	if nickname != "" {
		cmd += " nickname=" + ts3Escape(nickname)
	}
	log.Printf("external_client_bridge clientquery_connect_command=connect clientquery_connect_host=%s clientquery_connect_port=%d", tsHost, tsPort)
	resp, err := clientQueryExecCommand(conn, scanner, cmd, 10*time.Second)
	if err != nil {
		log.Printf("external_client_bridge clientquery_connect_success=false clientquery_connect_error=%s", sanitizeErrorForLog(err.Error()))
		return fmt.Errorf("clientquery_connect_cmd: %w", err)
	}
	for _, line := range strings.Split(resp, "\n") {
		line = strings.TrimSpace(line)
		if strings.HasPrefix(line, "error id=0") {
			log.Printf("external_client_bridge clientquery_connect_response_error_id=0 clientquery_connect_success=true")
			return nil
		}
		if strings.HasPrefix(line, "error id=") {
			errID := strings.TrimPrefix(line, "error id=")
			if spaceIdx := strings.IndexByte(errID, ' '); spaceIdx > 0 {
				errID = errID[:spaceIdx]
			}
			log.Printf("external_client_bridge clientquery_connect_response_error_id=%s clientquery_connect_success=false", errID)
			return fmt.Errorf("clientquery_connect_cmd failed: %s", line)
		}
	}
	log.Printf("external_client_bridge clientquery_connect_success=false clientquery_connect_error=no_error_line")
	return fmt.Errorf("clientquery_connect_cmd: no error line in response: %s", resp)
}

// sanitizeErrorForLog strips secrets and limits the length of an error string
// before it appears in log lines.
func sanitizeErrorForLog(errMsg string) string {
	if len(errMsg) > 200 {
		errMsg = errMsg[:200]
	}
	return strings.ReplaceAll(errMsg, "\n", " ")
}

// whoamiResult is the parsed outcome of a single ClientQuery "whoami" call.
type whoamiResult struct {
	clid    string // non-empty when client is assigned an ID by the TS server
	cid     string // non-empty when client is in a channel
	errorID string // numeric error id from the response (e.g. "0", "1794", "1796")
	state   string // human-readable state: "not_connected", "busy", "connected", "error", "dial_failed"
	rawResp string // full raw response for diagnostic messages
}

// parseWhoamiResponse parses the multi-line ClientQuery "whoami" response into a
// whoamiResult. A complete success looks like:
//
//	clid=29 cid=1
//	error id=0 msg=ok
//
// error id=1794 msg=not\sconnected → not yet connected, retry.
// error id=1796 msg=currently\snot\spossible → busy (license check, etc.), retry.
func parseWhoamiResponse(resp string) whoamiResult {
	r := whoamiResult{rawResp: resp}
	for _, rawLine := range strings.Split(resp, "\n") {
		line := strings.TrimSpace(rawLine)
		// Extract clid and cid from "clid=29 cid=1 ..." style lines.
		for _, token := range strings.Fields(line) {
			if strings.HasPrefix(token, "clid=") {
				r.clid = strings.TrimPrefix(token, "clid=")
			} else if strings.HasPrefix(token, "cid=") {
				r.cid = strings.TrimPrefix(token, "cid=")
			}
		}
		if strings.HasPrefix(line, "error id=") {
			rest := strings.TrimPrefix(line, "error id=")
			if spaceIdx := strings.IndexByte(rest, ' '); spaceIdx > 0 {
				r.errorID = rest[:spaceIdx]
			} else {
				r.errorID = rest
			}
		}
	}
	switch r.errorID {
	case "0":
		if r.clid != "" && r.cid != "" {
			r.state = "connected"
		} else {
			// error id=0 without clid/cid: treat as still connecting.
			r.state = "not_connected"
		}
	case "1794":
		r.state = "not_connected"
	case "1796":
		r.state = "busy"
	case "":
		r.state = "dial_failed"
	default:
		r.state = "error"
	}
	return r
}

// waitForTSServerConnected polls "whoami" via ClientQuery every second until the
// TS3 client reports a real server connection (clid + cid + error id=0). Returns
// an error when ctx is cancelled before a successful connection is observed.
//
// Retry policy:
//   - error id=1794 (not\sconnected): normal pre-connection state, retry silently.
//   - error id=1796 (currently\snot\spossible): busy (license dialog, startup),
//     retry up to context deadline.
//   - Other non-zero error IDs: log and retry until timeout.
//   - dial failure: log and retry.
//
// Success requires all three: clid present, cid present, error id=0.
// Returns (clid, cid, nil) on success.
func waitForTSServerConnected(ctx context.Context, host string, port int, apiKey string) (clid, cid string, err error) {
	if host == "" {
		host = "127.0.0.1"
	}
	ticker := time.NewTicker(1 * time.Second)
	defer ticker.Stop()

	var lastResult whoamiResult
	attempt := 0

	for {
		select {
		case <-ctx.Done():
			msg := fmt.Sprintf(
				"ts3_connect_timeout: TS3 client did not connect to TeamSpeak server within deadline; "+
					"host=%s port=%d attempts=%d last_state=%s last_error_id=%s",
				host, port, attempt, lastResult.state, lastResult.errorID)
			if lastResult.rawResp != "" {
				msg += " last_whoami_response=" + sanitizeWhoamiForLog(lastResult.rawResp)
			}
			return "", "", fmt.Errorf("%s", msg)
		case <-ticker.C:
			attempt++
			conn, scannerInner, dialErr := clientQueryConnect(host, port, apiKey, clientQueryDialTimeout)
			if dialErr != nil {
				lastResult = whoamiResult{state: "dial_failed", rawResp: dialErr.Error()}
				log.Printf("external_client_bridge wait_server_connected_attempt=%d wait_server_connected_whoami_state=dial_failed", attempt)
				continue
			}
			resp, cmdErr := clientQueryExecCommand(conn, scannerInner, "whoami", clientQueryDialTimeout)
			_ = conn.Close()
			if cmdErr != nil {
				lastResult = whoamiResult{state: "dial_failed", rawResp: cmdErr.Error()}
				log.Printf("external_client_bridge wait_server_connected_attempt=%d wait_server_connected_whoami_state=dial_failed", attempt)
				continue
			}
			result := parseWhoamiResponse(resp)
			lastResult = result

			log.Printf("external_client_bridge wait_server_connected_attempt=%d wait_server_connected_whoami_state=%s wait_server_connected_last_error_id=%s",
				attempt, result.state, result.errorID)

			if result.state == "connected" {
				log.Printf("external_client_bridge connected_clid=%s connected_cid=%s", result.clid, result.cid)
				return result.clid, result.cid, nil
			}
			// All other states (not_connected, busy, error, dial_failed) → retry.
		}
	}
}

// sanitizeWhoamiForLog strips newlines from a whoami response for safe inline logging.
func sanitizeWhoamiForLog(s string) string {
	s = strings.ReplaceAll(s, "\n", " | ")
	s = strings.ReplaceAll(s, "\r", "")
	if len(s) > 200 {
		s = s[:200]
	}
	return s
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
	attempt := 0
	// earlyMismatchChecked tracks whether we've already done the early port mismatch
	// probe (after ~10 seconds). This avoids waiting the full 45s timeout in the
	// common case where the plugin ignored the INI and bound to the default port.
	earlyMismatchChecked := false
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
								"Tip: set client_query_port=0 or client_query_port=%d to use the default",
							clientQueryDefaultPort, port, clientQueryDefaultPort)
					}
				}
			}
			return fmt.Errorf("clientquery_not_ready: ClientQuery port %s not ready after timeout", addr)
		case <-ticker.C:
			attempt++
			if probeClientQueryControlReady(host, port, apiKey) {
				return nil
			}
			// After ~10 seconds (20 polls × 500ms), check whether the plugin has
			// bound to the default port instead of the configured port. This detects
			// the "plugin ignored INI" scenario early, avoiding a full 45s wait.
			if !earlyMismatchChecked && attempt >= 20 && port != clientQueryDefaultPort {
				earlyMismatchChecked = true
				defAddr := net.JoinHostPort(host, fmt.Sprintf("%d", clientQueryDefaultPort))
				conn, dialErr := net.DialTimeout("tcp", defAddr, clientQueryDialTimeout)
				if dialErr == nil && probeClientQueryBanner(conn) {
					log.Printf("external_client_bridge clientquery_port_mismatch_detected_early=true configured_port=%d default_port=%d", port, clientQueryDefaultPort)
					return fmt.Errorf(
						"clientquery_port_mismatch: ClientQuery plugin is listening on default port %d, not configured port %d; "+
							"the plugin may have ignored its INI configuration. "+
							"Tip: set client_query_port=0 or client_query_port=%d to use the default",
						clientQueryDefaultPort, port, clientQueryDefaultPort)
				}
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
