package crypto

import (
	"strings"
	"testing"
	"time"
)

func TestBuildAgentJWTFormat(t *testing.T) {
	tok, err := BuildAgentJWT("secret", "agent-1", "panel", "easywi-agent", "nonce-1", time.Unix(100, 0).UTC(), time.Minute)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if parts := strings.Split(tok, "."); len(parts) != 3 {
		t.Fatalf("jwt must have 3 segments")
	}
}
