package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestValidateDomainName(t *testing.T) {
	for _, tc := range []struct {
		name   string
		domain string
		ok     bool
	}{
		{name: "valid apex", domain: "example.com", ok: true},
		{name: "valid subdomain", domain: "app.demo.example.com", ok: true},
		{name: "invalid chars", domain: "exa$mple.com", ok: false},
		{name: "invalid label", domain: "-bad.example.com", ok: false},
		{name: "missing dot", domain: "localhost", ok: false},
	} {
		err := validateDomainName(tc.domain)
		if tc.ok && err != nil {
			t.Fatalf("%s: expected valid, got %v", tc.name, err)
		}
		if !tc.ok && err == nil {
			t.Fatalf("%s: expected invalid", tc.name)
		}
	}
}

func TestParseWhitelistedDirectives(t *testing.T) {
	if _, err := parseWhitelistedDirectives("return 301 /;"); err == nil {
		t.Fatalf("expected forbidden directive error")
	}
	if _, err := parseWhitelistedDirectives("add_header X-Test test;\ninclude /etc/nginx/nginx.conf;"); err == nil {
		t.Fatalf("expected include injection to be blocked")
	}
	if _, err := parseWhitelistedDirectives("add_header X-Test test;\ncharset utf-8; drop"); err == nil {
		t.Fatalf("expected semicolon injection to be blocked")
	}
	if _, err := parseWhitelistedDirectives("add_header X-Frame-Options SAMEORIGIN;"); err != nil {
		t.Fatalf("expected allowed directive: %v", err)
	}
}

func TestWriteVhostAtomicallyIsIdempotent(t *testing.T) {
	path := filepath.Join(t.TempDir(), "example.com.conf")
	content := []byte("server { listen 80; }")

	changed, err := writeVhostAtomically(path, content)
	if err != nil || !changed {
		t.Fatalf("first write failed: changed=%v err=%v", changed, err)
	}

	changed, err = writeVhostAtomically(path, content)
	if err != nil {
		t.Fatalf("second write failed: %v", err)
	}
	if changed {
		t.Fatalf("expected idempotent write to return changed=false")
	}

	if _, err := os.Stat(path); err != nil {
		t.Fatalf("expected vhost to exist: %v", err)
	}
}
