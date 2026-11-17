package fileapi

import (
	"bytes"
	"crypto/sha256"
	"encoding/hex"
	"io"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

type signatureCountingBody struct {
	io.Reader
	reads int
}

func (b *signatureCountingBody) Read(p []byte) (int, error) { b.reads++; return b.Reader.Read(p) }
func (b *signatureCountingBody) Close() error               { return nil }
func TestVerifyRequestSignatureHeadersDoesNotReadBody(t *testing.T) {
	body := []byte("large-archive-payload")
	sum := sha256.Sum256(body)
	digest := hex.EncodeToString(sum[:])
	stamp := time.Now().UTC().Format(time.RFC3339)
	request := httptest.NewRequest(http.MethodPost, "/archive?restore=1", bytes.NewReader(body))
	cfg := Config{AgentID: "a", Secret: "secret", MaxSkew: time.Minute}
	request.Header.Set(headerAgentID, "a")
	request.Header.Set(headerCustomerID, "c")
	request.Header.Set(headerTimestamp, stamp)
	request.Header.Set(headerBodySHA256, digest)
	request.Header.Set(headerSignature, signPayload(buildSignaturePayload("a", "c", "POST", "/archive?restore=1", stamp, digest), cfg.Secret))
	tracked := &signatureCountingBody{Reader: bytes.NewReader(body)}
	request.Body = tracked
	customer, got, err := VerifyRequestSignatureHeaders(request, cfg)
	if err != nil || customer != "c" || got != digest || tracked.reads != 0 {
		t.Fatalf("customer=%s digest=%s reads=%d err=%v", customer, got, tracked.reads, err)
	}
	if _, err = VerifyRequestSignature(request, cfg); err != nil {
		t.Fatal(err)
	}
	remaining, _ := io.ReadAll(request.Body)
	if !bytes.Equal(body, remaining) {
		t.Fatal("existing API did not preserve request body")
	}
	request.Body = io.NopCloser(bytes.NewBufferString("tampered"))
	if _, err = VerifyRequestSignature(request, cfg); err == nil {
		t.Fatal("legacy API accepted changed body")
	}
}
