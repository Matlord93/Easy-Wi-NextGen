package fileapi

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

const (
	headerAgentID    = "X-Agent-ID"
	headerCustomerID = "X-Customer-ID"
	headerTimestamp  = "X-Timestamp"
	headerSignature  = "X-Signature"
	headerBodySHA256 = "X-Content-SHA256"
)

// VerifyRequestSignature validates the HMAC-SHA256 request signature used by the agent protocol.
// Returns the customer ID on success, or a non-nil error if the signature is absent, expired, or invalid.
func VerifyRequestSignature(r *http.Request, cfg Config) (string, error) {
	return verifyRequestSignature(r, cfg)
}

func verifyRequestSignature(r *http.Request, cfg Config) (string, error) {
	customerID, bodyHash, err := VerifyRequestSignatureHeaders(r, cfg)
	if err != nil {
		return "", err
	}
	actualBodyHash, err := requestBodySHA256(r)
	if err != nil {
		return "", errors.New("invalid request body")
	}
	if !hmac.Equal([]byte(actualBodyHash), []byte(bodyHash)) {
		return "", errors.New("invalid body hash")
	}
	return customerID, nil
}

// VerifyRequestSignatureHeaders authenticates the signed request metadata without
// reading its body. The caller MUST hash the entire body and compare it against
// the returned digest before interpreting the body or performing any mutation.
// This supports bounded-memory archive uploads while rejecting unauthenticated
// senders before accepting their data.
func VerifyRequestSignatureHeaders(r *http.Request, cfg Config) (string, string, error) {
	agentID := strings.TrimSpace(r.Header.Get(headerAgentID))
	if agentID == "" || agentID != cfg.AgentID {
		return "", "", errors.New("missing or mismatched agent id")
	}
	customerID := strings.TrimSpace(r.Header.Get(headerCustomerID))
	if customerID == "" {
		return "", "", errors.New("missing customer id")
	}
	timestampRaw := strings.TrimSpace(r.Header.Get(headerTimestamp))
	signature := strings.TrimSpace(r.Header.Get(headerSignature))
	bodyHash := strings.ToLower(strings.TrimSpace(r.Header.Get(headerBodySHA256)))
	if timestampRaw == "" || signature == "" || bodyHash == "" {
		return "", "", errors.New("missing signature headers")
	}
	if !isHexSHA256(bodyHash) {
		return "", "", errors.New("invalid body hash")
	}
	parsed, err := time.Parse(time.RFC3339, timestampRaw)
	if err != nil {
		return "", "", errors.New("invalid timestamp")
	}
	if skew := time.Since(parsed); skew > cfg.MaxSkew || skew < -cfg.MaxSkew {
		return "", "", errors.New("signature expired")
	}
	payload := buildSignaturePayload(agentID, customerID, r.Method, r.URL.RequestURI(), timestampRaw, bodyHash)
	expected := signPayload(payload, cfg.Secret)
	if cfg.Secret == "" || !hmac.Equal([]byte(expected), []byte(signature)) {
		return "", "", errors.New("invalid signature")
	}
	return customerID, bodyHash, nil
}

func buildSignaturePayload(agentID, customerID, method, requestURI, timestamp, bodyHash string) string {
	return fmt.Sprintf("%s\n%s\n%s\n%s\n%s\n%s", agentID, customerID, strings.ToUpper(method), requestURI, timestamp, strings.ToLower(bodyHash))
}

func requestBodySHA256(r *http.Request) (string, error) {
	if r.Body == nil {
		sum := sha256.Sum256(nil)
		return hex.EncodeToString(sum[:]), nil
	}
	body, err := io.ReadAll(r.Body)
	if err != nil {
		return "", err
	}
	if err := r.Body.Close(); err != nil {
		return "", err
	}
	r.Body = io.NopCloser(bytes.NewReader(body))
	sum := sha256.Sum256(body)
	return hex.EncodeToString(sum[:]), nil
}

func isHexSHA256(value string) bool {
	if len(value) != sha256.Size*2 {
		return false
	}
	_, err := hex.DecodeString(value)
	return err == nil
}

func signPayload(payload, secret string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(payload))
	return hex.EncodeToString(mac.Sum(nil))
}
