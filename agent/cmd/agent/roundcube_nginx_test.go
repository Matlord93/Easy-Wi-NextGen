package main

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestInsertRoundcubeIntoSSLBlock(t *testing.T) {
	cfg := "server {\n    listen 443 ssl http2;\n    location / { try_files $uri $uri/ /index.php?$query_string; }\n}\n"
	out := insertRoundcubeLocations(cfg, "\n    location ^~ /roundcube/ {}\n")
	if !strings.Contains(out, "listen 443") || !strings.Contains(out, "location ^~ /roundcube/") {
		t.Fatalf("missing ssl route: %s", out)
	}
}

func TestRoundcubeNotDuplicated(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip()
	}
	dir := t.TempDir()
	_ = os.MkdirAll(filepath.Join(dir, "etc/nginx/sites-available"), 0o755)
	vhost := filepath.Join("/etc/nginx/sites-available", "example.org")
	_ = os.MkdirAll("/etc/nginx/sites-available", 0o755)
	orig := "server {\n listen 80;\n location ^~ /roundcube/ { alias /usr/share/roundcube/; }\n}\n"
	if err := os.WriteFile(vhost, []byte(orig), 0o644); err != nil {
		t.Skip("requires writable /etc")
	}
	updated, reloaded, already, err := enableRoundcubeForWebspace("example.org", "/roundcube", "/usr/share/roundcube")
	if err != nil {
		t.Fatalf("unexpected err: %v", err)
	}
	if updated || reloaded || !already {
		t.Fatalf("expected no change")
	}
}

func TestRoundcubeKeepsPhpSocketAndRollback(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip()
	}
	bin := t.TempDir()
	if err := os.WriteFile(filepath.Join(bin, "nginx"), []byte("#!/bin/sh\nexit 1\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(bin, "systemctl"), []byte("#!/bin/sh\nexit 0\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	t.Setenv("PATH", bin+":"+os.Getenv("PATH"))
	_ = os.MkdirAll("/etc/nginx/sites-available", 0o755)
	vhost := filepath.Join("/etc/nginx/sites-available", "example.net")
	orig := "server {\n listen 443 ssl http2;\n location ~ \\.php$ { fastcgi_pass unix:/run/easywi/php-fpm/custom.sock; }\n}\n"
	if err := os.WriteFile(vhost, []byte(orig), 0o644); err != nil {
		t.Skip("requires writable /etc")
	}
	_, _, _, err := enableRoundcubeForWebspace("example.net", "/webmail", "/usr/share/roundcube")
	if err == nil {
		t.Fatalf("expected nginx -t failure")
	}
	after, _ := os.ReadFile(vhost)
	if string(after) != orig {
		t.Fatalf("expected rollback")
	}
}

func TestDetectSystemRoundcubePathFromPayload(t *testing.T) {
	root := t.TempDir()
	_ = os.MkdirAll(filepath.Join(root, "program/include"), 0o755)
	if err := os.WriteFile(filepath.Join(root, "program/include/iniset.php"), []byte("<?php"), 0o644); err != nil {
		t.Fatal(err)
	}
	path, err := detectSystemRoundcubePath(map[string]any{"roundcube_path": root})
	if err != nil || path != root {
		t.Fatalf("detect failed: %v %s", err, path)
	}
}
