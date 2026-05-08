package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestBestWebrootForDomainUsesEasyWILaravelDomainRoot(t *testing.T) {
	vhostDir := t.TempDir()
	domain := "matlort.de"
	domainRoot := filepath.Join(t.TempDir(), domain)
	documentRoot := filepath.Join(domainRoot, "public", "public")
	if err := os.MkdirAll(documentRoot, 0o755); err != nil {
		t.Fatalf("mkdir document root: %v", err)
	}
	config := "server {\n    server_name matlort.de;\n    root " + documentRoot + ";\n    location / {\n        try_files $uri $uri/ /index.php?$query_string;\n    }\n}\n"
	if err := os.WriteFile(filepath.Join(vhostDir, domain+".conf"), []byte(config), 0o644); err != nil {
		t.Fatalf("write vhost: %v", err)
	}
	t.Setenv("EASYWI_NGINX_VHOST_DIR", vhostDir)

	webroot, nginxConfig, err := bestWebrootForDomain(domain, "")
	if err != nil {
		t.Fatalf("best webroot failed: %v", err)
	}
	if webroot != domainRoot {
		t.Fatalf("expected ACME domain root %q, got %q", domainRoot, webroot)
	}
	if !strings.Contains(nginxConfig, "root "+documentRoot+";") {
		t.Fatalf("expected original nginx root in config, got: %s", nginxConfig)
	}
}

func TestEnsureACMEChallengeLocationUsesAliasWithoutChangingLaravelRoot(t *testing.T) {
	domainRoot := "/var/www/matlort.de"
	laravelRoot := domainRoot + "/public/public"
	config := "server {\n    server_name matlort.de;\n    root " + laravelRoot + ";\n\n    location / {\n        try_files $uri $uri/ /index.php?$query_string;\n    }\n}\n"

	updated := ensureACMEChallengeLocation(config, domainRoot)

	if !strings.Contains(updated, "root "+laravelRoot+";") {
		t.Fatalf("Laravel root changed or disappeared: %s", updated)
	}
	if !strings.Contains(updated, "location ^~ /.well-known/acme-challenge/") {
		t.Fatalf("ACME location missing: %s", updated)
	}
	expectedAlias := "alias " + nginxPathJoin(domainRoot, ".well-known", "acme-challenge") + "/;"
	if !strings.Contains(updated, expectedAlias) {
		t.Fatalf("ACME alias does not point at domain root: %s", updated)
	}
	if strings.Index(updated, "location ^~ /.well-known/acme-challenge/") > strings.Index(updated, "location / {") {
		t.Fatalf("ACME location must be inserted before Laravel catch-all location: %s", updated)
	}
}

func TestEnsureNginxSSLForDomainKeepsDocumentRootAndAddsACMEAlias(t *testing.T) {
	originalRunner := commandOutputRunner
	commandOutputRunner = func(name string, args ...string) (string, error) {
		return "", nil
	}
	t.Cleanup(func() { commandOutputRunner = originalRunner })

	vhostDir := t.TempDir()
	domain := "matlort.de"
	domainRoot := filepath.Join(t.TempDir(), domain)
	laravelRoot := filepath.Join(domainRoot, "public", "public")
	config := "server {\n    listen 80;\n    server_name matlort.de;\n    root " + laravelRoot + ";\n\n    location / {\n        try_files $uri $uri/ /index.php?$query_string;\n    }\n}\n"
	vhost := filepath.Join(vhostDir, domain+".conf")
	if err := os.WriteFile(vhost, []byte(config), 0o644); err != nil {
		t.Fatalf("write vhost: %v", err)
	}
	t.Setenv("EASYWI_NGINX_VHOST_DIR", vhostDir)

	reloaded, err := ensureNginxSSLForDomain(domain, domainRoot, "/cert/fullchain.pem", "/cert/privkey.pem")
	if err != nil {
		t.Fatalf("ensure nginx ssl failed: %v", err)
	}
	if !reloaded {
		t.Fatal("expected nginx reload")
	}
	updatedBytes, err := os.ReadFile(vhost)
	if err != nil {
		t.Fatalf("read updated vhost: %v", err)
	}
	updated := string(updatedBytes)
	if strings.Count(updated, "root "+laravelRoot+";") != 2 {
		t.Fatalf("expected HTTP and HTTPS blocks to keep Laravel root %q, got: %s", laravelRoot, updated)
	}
	expectedAlias := "alias " + nginxPathJoin(domainRoot, ".well-known", "acme-challenge") + "/;"
	if !strings.Contains(updated, expectedAlias) {
		t.Fatalf("expected ACME alias to domain root, got: %s", updated)
	}
	if strings.Contains(updated, "root "+domainRoot+";") {
		t.Fatalf("ACME domain root must not replace Laravel document root: %s", updated)
	}
}
