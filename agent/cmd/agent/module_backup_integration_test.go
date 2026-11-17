//go:build modulebackup_integration

package main

import (
	"bytes"
	"context"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/fileapi"
)

// External-service integration tests require the modulebackup_integration tag.
// Missing setup is a failure when an integration test is explicitly selected.
func TestModuleBackupNativeDatabaseRoundTrip(t *testing.T) {
	if os.Getenv("EASYWI_MODULE_BACKUP_NATIVE_CONTAINER") == "" {
		t.Fatal("native database container is required")
	}
	root := os.Getenv("EASYWI_MODULE_BACKUP_NATIVE_ROOT")
	if root == "" {
		t.Fatal("native database integration root missing")
	}
	h := newModuleBackupHandler(fileapi.Config{AgentID: "agent-1", Secret: "test-secret", MaxSkew: time.Minute})
	db := &moduleBackupDatabase{Host: "127.0.0.1", Port: 3306, Name: "module_backup_native", Username: "root", Password: "module-backup-native-test", TLSMode: "disabled"}
	if caFile := os.Getenv("EASYWI_MODULE_BACKUP_NATIVE_CA_PEM_FILE"); caFile != "" {
		certificate, err := os.ReadFile(caFile)
		if err != nil {
			t.Fatal(err)
		}
		db.CACert = string(certificate)
		db.TLSMode = "verify_ca"
	}
	sql := func(statement string) string {
		t.Helper()
		var out bytes.Buffer
		if err := h.databaseCommand(context.Background(), db, root, false, strings.NewReader(statement), &out); err != nil {
			t.Fatal(err)
		}
		return out.String()
	}
	sql("CREATE TABLE restore_probe(id INT PRIMARY KEY, value VARCHAR(100)); INSERT INTO restore_probe VALUES(1,'archived');")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "db", ResourceID: "native", Database: db}}
	archive := moduleTestSnapshot(t, h, request)
	sql("UPDATE restore_probe SET value='live'; CREATE TABLE obsolete_probe(id INT);")
	request.DryRun = true
	result := moduleTestRestore(t, h, request, archive)
	if result.Code != http.StatusOK {
		t.Fatalf("native dryrun %d: %s", result.Code, result.Body.String())
	}
	if got := sql("SELECT value FROM restore_probe;"); !strings.Contains(got, "live") {
		t.Fatalf("dryrun changed database: %s", got)
	}
	request.DryRun = false
	result = moduleTestRestore(t, h, request, archive)
	if result.Code != http.StatusOK {
		t.Fatalf("native restore %d: %s", result.Code, result.Body.String())
	}
	if got := sql("SELECT value FROM restore_probe;"); !strings.Contains(got, "archived") {
		t.Fatalf("native restore failed: %s", got)
	}
	if got := sql("SELECT COUNT(*) AS obsolete_count FROM information_schema.tables WHERE table_schema='module_backup_native' AND table_name='obsolete_probe';"); !strings.Contains(got, "\n0\n") {
		t.Fatalf("restore retained obsolete table: %s", got)
	}
	backups, _ := filepath.Glob(filepath.Join(os.Getenv("EASYWI_MODULE_BACKUP_RECOVERY_DIR"), "restore-*", "previous.sql"))
	if len(backups) != 1 {
		t.Fatalf("native recovery SQL missing: %v", backups)
	}
	sql("UPDATE restore_probe SET value='recovery-state';")
	badSQL := filepath.Join(root, "intentional-import-failure.sql")
	if err := os.WriteFile(badSQL, []byte("DROP DATABASE `module_backup_native`; CREATE DATABASE `module_backup_native`; USE `module_backup_native`; CREATE TABLE broken_probe(id INT); INTENTIONALLY INVALID SQL;"), 0600); err != nil {
		t.Fatal(err)
	}
	badArchive := filepath.Join(root, "intentional-import-failure.tar.gz")
	if err := h.writeArchive(context.Background(), request, nil, badSQL, badArchive); err != nil {
		t.Fatal(err)
	}
	broken, err := os.ReadFile(badArchive)
	if err != nil {
		t.Fatal(err)
	}
	result = moduleTestRestore(t, h, request, broken)
	if result.Code == http.StatusOK || !strings.Contains(result.Body.String(), "previous state was recovered") {
		t.Fatalf("native failed import recovery: %d %s", result.Code, result.Body.String())
	}
	if got := sql("SELECT value FROM restore_probe;"); !strings.Contains(got, "recovery-state") {
		t.Fatalf("native rollback did not recover database: %s", got)
	}
	if got := sql("SELECT COUNT(*) AS broken_count FROM information_schema.tables WHERE table_schema='module_backup_native' AND table_name='broken_probe';"); !strings.Contains(got, "\n0\n") {
		t.Fatalf("rollback retained partially imported tables: %s", got)
	}
	t.Log("native snapshot, dry-run, destructive schema restore, actual failed-import rollback and retained recovery SQL passed")
}

// The PHP integration runner owns the chosen fixture directory and signals
// completion by creating <ready-file>.stop. This is only a test server.
func TestModuleBackupIntegrationServer(t *testing.T) {
	address := os.Getenv("EASYWI_MODULE_BACKUP_INTEGRATION_ADDR")
	if address == "" {
		t.Fatal("integration listen address is required")
	}
	ready := os.Getenv("EASYWI_MODULE_BACKUP_INTEGRATION_READY_FILE")
	root := os.Getenv("EASYWI_MODULE_BACKUP_INTEGRATION_ROOT")
	if ready == "" || root == "" {
		t.Fatal("integration ready file and root are required")
	}
	h := newModuleBackupHandler(fileapi.Config{AgentID: "integration-agent", Secret: "integration-secret", MaxSkew: time.Minute})
	h.roots = map[string][]string{"musicbot": {root}, "mail": {root}, "voice": {root}, "game": {root}}
	if h.configErr != nil {
		t.Fatal(h.configErr)
	}
	listener, err := net.Listen("tcp", address)
	if err != nil {
		t.Fatal(err)
	}
	server := httptest.NewUnstartedServer(h)
	_ = server.Listener.Close()
	server.Listener = listener
	server.Start()
	defer server.Close()
	info, _ := json.Marshal(map[string]string{"url": server.URL, "root": root, "agent_id": "integration-agent", "secret": "integration-secret", "customer_id": "customer-1"})
	if err = os.WriteFile(ready, info, 0600); err != nil {
		t.Fatal(err)
	}
	t.Logf("integration endpoint: %s", server.URL)
	deadline := time.NewTimer(10 * time.Minute)
	defer deadline.Stop()
	ticker := time.NewTicker(200 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-deadline.C:
			t.Fatal("PHP integration server timed out")
		case <-ticker.C:
			if _, err = os.Stat(ready + ".stop"); err == nil {
				return
			}
		}
	}
}
