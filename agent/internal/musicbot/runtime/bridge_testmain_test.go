package musicbotruntime

import (
	"bufio"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

// TestMain detects when the test binary is exec'd as the mock TeamSpeak bridge
// (via a symlink whose base name ends with "mock-ts-bridge") and runs the
// lightweight NDJSON protocol handler instead of the real test suite.
// This avoids writing an executable shell script in writeMockTeamspeakBridge,
// which causes ETXTBSY on Linux when parallel goroutines fork while another
// goroutine's OS thread holds an O_WRONLY fd to the same inode.
func TestMain(m *testing.M) {
	if strings.HasSuffix(filepath.Base(os.Args[0]), "mock-ts-bridge") {
		dir := filepath.Dir(os.Args[0])
		_, err := os.Stat(filepath.Join(dir, "mock-ts-bridge.fail"))
		runMockBridgeProtocol(err == nil)
		return
	}
	os.Exit(m.Run())
}

// runMockBridgeProtocol implements the bridge NDJSON protocol used by
// ExternalBridgeTeamspeakVoiceClient. It reads action lines from stdin and
// writes JSON responses to stdout until a "disconnect" action is received.
func runMockBridgeProtocol(fail bool) {
	joinResp := `{"ok":true,"channel_id":"123"}`
	sendResp := `{"ok":true}`
	if fail {
		joinResp = `{"ok":false,"error":"join failed with super-secret"}`
		sendResp = `{"ok":false,"error":"send failed"}`
	}

	w := bufio.NewWriter(os.Stdout)
	scanner := bufio.NewScanner(os.Stdin)
	for scanner.Scan() {
		line := scanner.Text()
		var resp string
		switch {
		case strings.Contains(line, "disconnect"):
			fmt.Fprintln(w, `{"ok":true}`)
			w.Flush()
			return
		case strings.Contains(line, "reconnect"):
			resp = `{"ok":true,"state":"connected","client_id":"mock-client"}`
		case strings.Contains(line, "connect"):
			resp = `{"ok":true,"state":"connected","client_id":"mock-client"}`
		case strings.Contains(line, "join_channel"):
			resp = joinResp
		case strings.Contains(line, "send_opus_frame"):
			resp = sendResp
		case strings.Contains(line, "status"):
			resp = `{"ok":true,"state":"connected","client_id":"mock-client"}`
		default:
			resp = `{"ok":true}`
		}
		fmt.Fprintln(w, resp)
		w.Flush()
	}
}
