package crypto

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"time"
)

// SignatureHeaders represents the HMAC headers required by the API.
type SignatureHeaders struct {
	AgentID   string
	Timestamp string
	Nonce     string
	Signature string
}

// Sign builds an HMAC signature for the request.
func Sign(agentID, secret, method, path string, body []byte, timestamp time.Time, nonce string) (SignatureHeaders, error) {
	bodyHash := sha256.Sum256(body)
	signaturePayload := fmt.Sprintf("%s\n%s\n%s\n%s\n%s", agentID, method, path, hex.EncodeToString(bodyHash[:]), timestamp.UTC().Format(time.RFC3339))
	if nonce != "" {
		signaturePayload = signaturePayload + "\n" + nonce
	}

	mac := hmac.New(sha256.New, []byte(secret))
	if _, err := mac.Write([]byte(signaturePayload)); err != nil {
		return SignatureHeaders{}, fmt.Errorf("sign payload: %w", err)
	}

	return SignatureHeaders{
		AgentID:   agentID,
		Timestamp: timestamp.UTC().Format(time.RFC3339),
		Nonce:     nonce,
		Signature: hex.EncodeToString(mac.Sum(nil)),
	}, nil
}

// NewNonce creates a random nonce for signing.
func NewNonce() (string, error) {
	bytes := make([]byte, 16)
	if _, err := io.ReadFull(rand.Reader, bytes); err != nil {
		return "", fmt.Errorf("random nonce: %w", err)
	}
	return hex.EncodeToString(bytes), nil
}
