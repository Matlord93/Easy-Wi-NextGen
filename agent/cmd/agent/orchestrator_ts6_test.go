package main

import (
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestTs6ArchiveExtractionDirUsesParentForPersistedArchiveRoot(t *testing.T) {
	installDir := filepath.Join(t.TempDir(), "teamspeak6-server-linux-amd64")
	archivePath := filepath.Join(installDir, "teamspeak6-server-linux-amd64.tar.xz")

	got := ts6ArchiveExtractionDir(installDir, archivePath)
	want := filepath.Dir(installDir)
	if got != want {
		t.Fatalf("expected extraction directory %q, got %q", want, got)
	}
}

func TestResolveTs6InstallDirFindsPreservedArchiveRoot(t *testing.T) {
	baseDir := t.TempDir()
	archiveRoot := filepath.Join(baseDir, "teamspeak6-server-linux-amd64")
	if err := os.MkdirAll(archiveRoot, 0o755); err != nil {
		t.Fatalf("create archive root: %v", err)
	}
	if err := os.WriteFile(filepath.Join(archiveRoot, "tsserver"), []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatalf("write tsserver: %v", err)
	}

	got, err := resolveTs6InstallDir(baseDir, filepath.Join(baseDir, "teamspeak6-server-linux-amd64.tar.xz"), baseDir)
	if err != nil {
		t.Fatalf("resolve TS6 install dir: %v", err)
	}
	if got != archiveRoot {
		t.Fatalf("expected install dir %q, got %q", archiveRoot, got)
	}
}

func TestResolveTs6InstallDirKeepsFlatInstall(t *testing.T) {
	installDir := t.TempDir()
	if err := os.WriteFile(filepath.Join(installDir, "tsserver"), []byte("#!/bin/sh\n"), 0o755); err != nil {
		t.Fatalf("write tsserver: %v", err)
	}

	got, err := resolveTs6InstallDir(installDir, filepath.Join(installDir, "custom.tar.xz"), installDir)
	if err != nil {
		t.Fatalf("resolve TS6 install dir: %v", err)
	}
	if got != installDir {
		t.Fatalf("expected install dir %q, got %q", installDir, got)
	}
}

func TestWaitForTs6QueryListenersAcceptsAnyConfiguredQueryPort(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("listen: %v", err)
	}
	defer func() { _ = listener.Close() }()

	done := make(chan struct{})
	go func() {
		defer close(done)
		conn, err := listener.Accept()
		if err == nil {
			_ = conn.Close()
		}
	}()

	port := listener.Addr().(*net.TCPAddr).Port
	if err := waitForTs6QueryListeners("0.0.0.0", 0, port, 0, time.Second); err != nil {
		t.Fatalf("wait for TS6 query listener: %v", err)
	}
	<-done
}

func TestBuildTs6ConfigUsesRequiredServerDatabaseAndQueryBlocks(t *testing.T) {
	got := buildTs6Config(ts6ConfigOptions{
		queryAdminPass:       "secret",
		workingDirectory:     "/home/teamspeak6",
		httpsCertificatePath: "query_https.crt",
		httpsPrivateKeyPath:  "query_https.key",
	})
	want := `server:
  license-path: .
  default-voice-port: 9987
  voice-ip:
    - 0.0.0.0
  log-path: logs
  log-append: 0
  no-default-virtual-server: 0
  filetransfer-port: 30033
  filetransfer-ip:
    - 0.0.0.0
  accept-license: accept
  crashdump-path: crashdumps

  database:
    plugin: sqlite3
    sql-path: /home/teamspeak6/sql/
    sql-create-path: /home/teamspeak6/sql/create_sqlite/
    client-keep-days: 30
    config:
      skip-integrity-check: 0
      host: 127.0.0.1
      port: 5432
      socket: ""
      timeout: 10
      name: teamspeak
      username: ""
      password: ""
      connections: 10
      log-queries: 0

  query:
    pool-size: 2
    log-timing: 3600
    ip-allow-list: query_ip_allowlist.txt
    ip-block-list: query_ip_denylist.txt
    admin-password: "secret"
    log-commands: 0
    skip-brute-force-check: 0
    buffer-mb: 20
    documentation-path: serverquerydocs
    timeout: 300

    http:
      enable: 1
      port: 10080
      ip:
      - 127.0.0.1

    https:
      enable: 1
      port: 10443
      ip:
      - 127.0.0.1
      certificate: "query_https.crt"
      private-key: "query_https.key"

    ssh:
      enable: 1
      port: 10022
      ip:
      - 0.0.0.0
      rsa-key: ssh_host_rsa_key
`
	if got != want {
		t.Fatalf("unexpected TS6 config:\n--- got ---\n%s\n--- want ---\n%s", got, want)
	}
}

func TestEnsureTs6HttpsCertificateCreatesCertificateAndKey(t *testing.T) {
	installDir := t.TempDir()
	certName, keyName, err := ensureTs6HttpsCertificate(installDir, os.Getuid(), os.Getgid())
	if err != nil {
		t.Fatalf("ensure TS6 HTTPS certificate: %v", err)
	}
	if certName != ts6HTTPSCertificateFilename || keyName != ts6HTTPSPrivateKeyFilename {
		t.Fatalf("unexpected certificate names: cert=%q key=%q", certName, keyName)
	}
	if _, err := os.Stat(filepath.Join(installDir, certName)); err != nil {
		t.Fatalf("certificate was not created: %v", err)
	}
	if _, err := os.Stat(filepath.Join(installDir, keyName)); err != nil {
		t.Fatalf("private key was not created: %v", err)
	}
}
