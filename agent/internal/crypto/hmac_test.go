package crypto

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"strings"
	"testing"
	"time"
)

func TestSignBuildsExpectedSignature(t *testing.T) {
	timestamp := time.Date(2025, 1, 15, 12, 0, 0, 0, time.UTC)
	headers, err := Sign("agent-1", "secret", "POST", "/agent/agent-1/jobs", []byte(`{"ok":true}`), timestamp, "nonce-1")
	if err != nil {
		t.Fatalf("Sign returned error: %v", err)
	}

	bodyHash := sha256.Sum256([]byte(`{"ok":true}`))
	payload := strings.Join([]string{
		"agent-1",
		"POST",
		"/agent/agent-1/jobs",
		hex.EncodeToString(bodyHash[:]),
		timestamp.Format(time.RFC3339),
		"nonce-1",
	}, "\n")

	mac := hmac.New(sha256.New, []byte("secret"))
	mac.Write([]byte(payload))
	expected := hex.EncodeToString(mac.Sum(nil))

	if headers.Signature != expected {
		t.Fatalf("signature mismatch: got %s want %s", headers.Signature, expected)
	}
	if headers.AgentID != "agent-1" {
		t.Fatalf("agent id mismatch: %s", headers.AgentID)
	}
}
