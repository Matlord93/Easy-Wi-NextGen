package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"
)

const (
	headerAgentID    = "X-Agent-ID"
	headerCustomerID = "X-Customer-ID"
	headerTimestamp  = "X-Timestamp"
	headerSignature  = "X-Signature"
)

func verifyRequestSignature(r *http.Request, cfg filesvcConfig) (string, error) {
	agentID := strings.TrimSpace(r.Header.Get(headerAgentID))
	if agentID == "" || agentID != cfg.AgentID {
		return "", errors.New("missing or mismatched agent id")
	}
	customerID := strings.TrimSpace(r.Header.Get(headerCustomerID))
	if customerID == "" {
		return "", errors.New("missing customer id")
	}
	timestampRaw := strings.TrimSpace(r.Header.Get(headerTimestamp))
	signature := strings.TrimSpace(r.Header.Get(headerSignature))
	if timestampRaw == "" || signature == "" {
		return "", errors.New("missing signature headers")
	}
	parsed, err := time.Parse(time.RFC3339, timestampRaw)
	if err != nil {
		return "", errors.New("invalid timestamp")
	}
	if skew := time.Since(parsed); skew > cfg.MaxSkew || skew < -cfg.MaxSkew {
		return "", errors.New("signature expired")
	}

	payload := buildSignaturePayload(agentID, customerID, r.Method, r.URL.RequestURI(), timestampRaw)
	expected := signPayload(payload, cfg.Secret)
	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return "", errors.New("invalid signature")
	}
	return customerID, nil
}

func buildSignaturePayload(agentID, customerID, method, requestURI, timestamp string) string {
	return fmt.Sprintf("%s\n%s\n%s\n%s\n%s", agentID, customerID, strings.ToUpper(method), requestURI, timestamp)
}

func signPayload(payload, secret string) string {
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(payload))
	return hex.EncodeToString(mac.Sum(nil))
}
