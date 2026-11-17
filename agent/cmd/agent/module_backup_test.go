package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/hex"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"easywi/agent/internal/fileapi"
)

func moduleTestHandler(t *testing.T) *moduleBackupHandler {
	t.Helper()
	root := t.TempDir()
	h := newModuleBackupHandler(fileapi.Config{AgentID: "agent-1", Secret: "test-secret", MaxSkew: time.Minute})
	h.roots = map[string][]string{"musicbot": {root}, "mail": {root}, "voice": {root}, "game": {root}}
	h.maxBytes = 1024 * 1024
	h.configErr = nil
	return h
}
func moduleTestRequest(path string, body []byte) *http.Request {
	request := httptest.NewRequest(http.MethodPost, path, bytes.NewReader(body))
	sum := sha256.Sum256(body)
	digest := hex.EncodeToString(sum[:])
	stamp := time.Now().UTC().Format(time.RFC3339)
	payload := strings.Join([]string{"agent-1", "customer-1", "POST", path, stamp, digest}, "\n")
	mac := hmac.New(sha256.New, []byte("test-secret"))
	_, _ = mac.Write([]byte(payload))
	request.Header.Set("X-Agent-ID", "agent-1")
	request.Header.Set("X-Customer-ID", "customer-1")
	request.Header.Set("X-Timestamp", stamp)
	request.Header.Set("X-Content-SHA256", digest)
	request.Header.Set("X-Signature", hex.EncodeToString(mac.Sum(nil)))
	return request
}
func moduleTestJSON(t *testing.T, request moduleBackupRequest) []byte {
	t.Helper()
	body, err := json.Marshal(request)
	if err != nil {
		t.Fatal(err)
	}
	return body
}
func moduleTestEnvelope(t *testing.T, request moduleBackupRequest, archive []byte) []byte {
	t.Helper()
	var out bytes.Buffer
	tw := tar.NewWriter(&out)
	for _, entry := range []struct {
		name string
		body []byte
	}{{"descriptor.json", moduleTestJSON(t, request)}, {"archive.tar.gz", archive}} {
		if err := tw.WriteHeader(&tar.Header{Name: entry.name, Typeflag: tar.TypeReg, Mode: 0600, Size: int64(len(entry.body))}); err != nil {
			t.Fatal(err)
		}
		if _, err := tw.Write(entry.body); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	return out.Bytes()
}
func moduleTestWrite(t *testing.T, path, value string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0750); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(value), 0640); err != nil {
		t.Fatal(err)
	}
}
func moduleTestRead(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}
func moduleTestSnapshot(t *testing.T, h *moduleBackupHandler, request moduleBackupRequest) []byte {
	t.Helper()
	out := httptest.NewRecorder()
	h.ServeHTTP(out, moduleTestRequest("/v1/module-backups/snapshot", moduleTestJSON(t, request)))
	if out.Code != http.StatusOK {
		t.Fatalf("snapshot %d: %s", out.Code, out.Body.String())
	}
	sum := sha256.Sum256(out.Body.Bytes())
	if out.Header().Get("X-Archive-SHA256") != hex.EncodeToString(sum[:]) {
		t.Fatal("snapshot digest mismatch")
	}
	if out.Header().Get("Content-Length") != fmt.Sprint(out.Body.Len()) {
		t.Fatal("missing snapshot length")
	}
	return append([]byte(nil), out.Body.Bytes()...)
}
func moduleTestRestore(t *testing.T, h *moduleBackupHandler, request moduleBackupRequest, archive []byte) *httptest.ResponseRecorder {
	t.Helper()
	out := httptest.NewRecorder()
	h.ServeHTTP(out, moduleTestRequest("/v1/module-backups/restore", moduleTestEnvelope(t, request, archive)))
	return out
}
func TestModuleBackupRoundTripMultipleRootsAndDryRun(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	primary := filepath.Join(base, "instance")
	config := filepath.Join(base, "dkim.key")
	moduleTestWrite(t, filepath.Join(primary, "state/data"), "before")
	moduleTestWrite(t, config, "private-key-before")
	moduleTestWrite(t, filepath.Join(primary, "runtime/socket-data"), "transient")
	moduleTestWrite(t, filepath.Join(primary, "musicbot.pid"), "123")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: primary, AdditionalPaths: map[string]string{"dkim": config}, Exclude: []string{"runtime"}}}
	archive := moduleTestSnapshot(t, h, request)
	moduleTestWrite(t, filepath.Join(primary, "state/data"), "after")
	moduleTestWrite(t, config, "private-key-after")
	request.DryRun = true
	result := moduleTestRestore(t, h, request, archive)
	if result.Code != http.StatusOK {
		t.Fatalf("dry-run: %s", result.Body.String())
	}
	if moduleTestRead(t, filepath.Join(primary, "state/data")) != "after" || moduleTestRead(t, config) != "private-key-after" {
		t.Fatal("dry-run changed live data")
	}
	stages, _ := filepath.Glob(filepath.Join(base, ".easywi-module-restore-*"))
	if len(stages) != 0 {
		t.Fatal("dry-run leaked staging directories")
	}
	request.DryRun = false
	result = moduleTestRestore(t, h, request, archive)
	if result.Code != http.StatusOK {
		t.Fatalf("restore %d: %s", result.Code, result.Body.String())
	}
	if moduleTestRead(t, filepath.Join(primary, "state/data")) != "before" || moduleTestRead(t, config) != "private-key-before" {
		t.Fatal("restored data differs")
	}
	for _, name := range []string{"runtime", "musicbot.pid"} {
		if _, err := os.Stat(filepath.Join(primary, name)); !os.IsNotExist(err) {
			t.Fatalf("transient data restored: %s", name)
		}
	}
	stages, _ = filepath.Glob(filepath.Join(base, ".easywi-module-restore-*"))
	if len(stages) != 2 {
		t.Fatalf("expected retained recovery roots, got %v", stages)
	}
	recovered := map[string]bool{}
	for _, stage := range stages {
		info, err := os.Stat(stage)
		if err != nil || info.Mode().Perm() != 0700 {
			t.Fatal("recovery directory is not private")
		}
		if b, err := os.ReadFile(filepath.Join(stage, "previous")); err == nil {
			recovered[string(b)] = true
		} else if b, err := os.ReadFile(filepath.Join(stage, "previous/state/data")); err == nil {
			recovered[string(b)] = true
		}
	}
	if !recovered["after"] || !recovered["private-key-after"] {
		t.Fatal("previous data was not retained")
	}
}

type moduleUnreadableBody struct{ reads int }

func (b *moduleUnreadableBody) Read([]byte) (int, error) {
	b.reads++
	return 0, errors.New("must not read")
}
func (b *moduleUnreadableBody) Close() error { return nil }
func TestModuleBackupRejectsUnauthenticatedBodyBeforeRead(t *testing.T) {
	h := moduleTestHandler(t)
	r := moduleTestRequest("/v1/module-backups/restore", nil)
	r.Header.Set("X-Signature", "wrong")
	body := &moduleUnreadableBody{}
	r.Body = body
	out := httptest.NewRecorder()
	h.ServeHTTP(out, r)
	if out.Code != http.StatusUnauthorized || body.reads != 0 {
		t.Fatalf("status %d, reads %d", out.Code, body.reads)
	}
}
func TestModuleBackupRejectsChangedBodyBeforeMutation(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "original")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	archive := moduleTestSnapshot(t, h, request)
	envelope := moduleTestEnvelope(t, request, archive)
	r := moduleTestRequest("/v1/module-backups/restore", envelope)
	envelope = append(envelope, 1)
	r.Body = io.NopCloser(bytes.NewReader(envelope))
	out := httptest.NewRecorder()
	h.ServeHTTP(out, r)
	if out.Code == http.StatusOK || !strings.Contains(out.Body.String(), "body hash") {
		t.Fatal(out.Body.String())
	}
	if moduleTestRead(t, filepath.Join(path, "data")) != "original" {
		t.Fatal("bad body changed live data")
	}
}
func TestModuleBackupRejectsDifferentResourceCustomerOrDatabase(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "original")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	archive := moduleTestSnapshot(t, h, request)
	for _, kind := range []string{"customer", "resource", "database"} {
		t.Run(kind, func(t *testing.T) {
			changed := request
			switch kind {
			case "customer":
				changed.CustomerID = "different"
			case "resource":
				changed.Descriptor.ResourceID = "18"
			case "database":
				changed.Descriptor.Database = &moduleBackupDatabase{Host: "localhost", Port: 3306, Name: "other", Username: "user", TLSMode: "disabled"}
			}
			result := moduleTestRestore(t, h, changed, archive)
			if result.Code == http.StatusOK {
				t.Fatal("binding mismatch accepted")
			}
			if moduleTestRead(t, filepath.Join(path, "data")) != "original" {
				t.Fatal("binding mismatch changed data")
			}
		})
	}
}
func moduleTestArchive(t *testing.T, request moduleBackupRequest, roots []moduleBackupRoot, headers []*tar.Header) []byte {
	t.Helper()
	var out bytes.Buffer
	gz := gzip.NewWriter(&out)
	tw := tar.NewWriter(gz)
	manifest, _ := json.Marshal(moduleManifest(request, roots))
	if err := tw.WriteHeader(&tar.Header{Name: ".module-backup.json", Typeflag: tar.TypeReg, Size: int64(len(manifest))}); err != nil {
		t.Fatal(err)
	}
	_, _ = tw.Write(manifest)
	for _, header := range headers {
		if err := tw.WriteHeader(header); err != nil {
			t.Fatal(err)
		}
	}
	if err := tw.Close(); err != nil {
		t.Fatal(err)
	}
	if err := gz.Close(); err != nil {
		t.Fatal(err)
	}
	return out.Bytes()
}
func TestModuleBackupRejectsUnsafeArchiveEntriesAndCorruption(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "original")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	roots := []moduleBackupRoot{{"primary", path}}
	tests := map[string][]*tar.Header{
		"traversal":       {{Name: "files/primary/../../escape", Typeflag: tar.TypeReg}},
		"symlink":         {{Name: "files/primary/link", Typeflag: tar.TypeSymlink, Linkname: "/etc/passwd"}},
		"hardlink":        {{Name: "files/primary/link", Typeflag: tar.TypeLink, Linkname: "/etc/passwd"}},
		"special":         {{Name: "files/primary/fifo", Typeflag: tar.TypeFifo}},
		"duplicate":       {{Name: "files/primary", Typeflag: tar.TypeDir}, {Name: "files/primary", Typeflag: tar.TypeDir}},
		"unexpected-root": {{Name: "files/other", Typeflag: tar.TypeDir}},
		"runtime-pid":     {{Name: "files/primary/bot.pid", Typeflag: tar.TypeReg}},
	}
	for name, headers := range tests {
		t.Run(name, func(t *testing.T) {
			archive := moduleTestArchive(t, request, roots, headers)
			result := moduleTestRestore(t, h, request, archive)
			if result.Code == http.StatusOK {
				t.Fatal("unsafe archive accepted")
			}
			if moduleTestRead(t, filepath.Join(path, "data")) != "original" {
				t.Fatal("unsafe archive changed live data")
			}
		})
	}
	archive := moduleTestSnapshot(t, h, request)
	archive[len(archive)-1] ^= 1
	if result := moduleTestRestore(t, h, request, archive); result.Code == http.StatusOK {
		t.Fatal("gzip corruption accepted")
	}
}
func TestModuleBackupRejectsRootEscapesOverlapsAndMissingServices(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "x")
	original := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	for _, kind := range []string{"outside", "overlap", "symlink", "service"} {
		t.Run(kind, func(t *testing.T) {
			request := original
			switch kind {
			case "outside":
				request.Descriptor.Path = "/etc"
			case "overlap":
				request.Descriptor.AdditionalPaths = map[string]string{"child": filepath.Join(path, "data")}
			case "symlink":
				link := filepath.Join(base, "link")
				if err := os.Symlink(path, link); err != nil {
					t.Fatal(err)
				}
				request.Descriptor.Path = link
			case "service":
				request.Descriptor.Module = "voice"
			}
			out := httptest.NewRecorder()
			h.ServeHTTP(out, moduleTestRequest("/v1/module-backups/snapshot", moduleTestJSON(t, request)))
			if out.Code == http.StatusOK {
				t.Fatal("unsafe roots accepted")
			}
		})
	}
}
func TestModuleBackupServiceRestartFailureRollsBackFilesystem(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "archive-state")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	archive := moduleTestSnapshot(t, h, request)
	moduleTestWrite(t, filepath.Join(path, "data"), "live-state")
	request.Descriptor.Service = "musicbot-17.service"
	starts := 0
	stops := 0
	h.run = func(_ context.Context, name string, args []string, _ io.Reader, out io.Writer) error {
		if name != "systemctl" {
			t.Fatalf("unexpected process %s", name)
		}
		switch args[0] {
		case "show":
			_, _ = io.WriteString(out, "LoadState=loaded\nActiveState=active\n")
		case "stop":
			stops++
		case "start":
			starts++
			if starts == 1 {
				return errors.New("injected start failure")
			}
		}
		return nil
	}
	result := moduleTestRestore(t, h, request, archive)
	if result.Code == http.StatusOK || !strings.Contains(result.Body.String(), "previous state was recovered") {
		t.Fatal(result.Body.String())
	}
	if moduleTestRead(t, filepath.Join(path, "data")) != "live-state" {
		t.Fatal("restart failure did not rollback filesystem")
	}
	if starts != 2 || stops != 2 {
		t.Fatalf("starts %d, stops %d", starts, stops)
	}
}
func TestModuleBackupStopsAndRestartsServicesAfterSnapshotFailure(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "x")
	if err := os.Symlink("/etc/passwd", filepath.Join(path, "bad-link")); err != nil {
		t.Fatal(err)
	}
	var actions []string
	h.run = func(_ context.Context, _ string, args []string, _ io.Reader, out io.Writer) error {
		actions = append(actions, args[0])
		if args[0] == "show" {
			_, _ = io.WriteString(out, "LoadState=loaded\nActiveState=active\n")
		}
		return nil
	}
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "voice", ResourceID: "17", Path: path, Service: "voice-17"}}
	out := httptest.NewRecorder()
	h.ServeHTTP(out, moduleTestRequest("/v1/module-backups/snapshot", moduleTestJSON(t, request)))
	if out.Code == http.StatusOK {
		t.Fatal("symlink snapshot accepted")
	}
	if strings.Join(actions, ",") != "show,stop,start,show" {
		t.Fatal(actions)
	}
}
func TestModuleBackupOversizedDescriptorAndArchive(t *testing.T) {
	h := moduleTestHandler(t)
	for path, body := range map[string][]byte{"/v1/module-backups/snapshot": bytes.Repeat([]byte("x"), int(moduleDescriptorLimit)+1), "/v1/module-backups/restore": bytes.Repeat([]byte("x"), int(h.maxBytes+1024*1024)+1)} {
		out := httptest.NewRecorder()
		h.ServeHTTP(out, moduleTestRequest(path, body))
		if out.Code == http.StatusOK {
			t.Fatalf("oversized %s accepted", path)
		}
	}
}
func TestModuleBackupLockCancellation(t *testing.T) {
	request := moduleBackupRequest{CustomerID: "lock-test", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "lock"}}
	unlock, err := lockModuleOperation(context.Background(), request, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer unlock()
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if release, err := lockModuleOperation(ctx, request, nil); err == nil {
		release()
		t.Fatal("cancelled waiter acquired lock")
	}
}

func moduleTestFakeDatabaseClients(t *testing.T) {
	t.Helper()
	bin := t.TempDir()
	for _, name := range []string{"mariadb", "mariadb-dump"} {
		if err := os.WriteFile(filepath.Join(bin, name), []byte("#!/bin/sh\nexit 99\n"), 0700); err != nil {
			t.Fatal(err)
		}
	}
	t.Setenv("PATH", bin)
	t.Setenv("EASYWI_MODULE_BACKUP_RECOVERY_DIR", filepath.Join(t.TempDir(), "recovery"))
}
func TestModuleBackupDatabaseRestoreFailureRecoversPreviousSQLAndProtectsCredentials(t *testing.T) {
	moduleTestFakeDatabaseClients(t)
	h := moduleTestHandler(t)
	state := "archive-database"
	failIncoming := false
	imports := 0
	var defaultsFiles []string
	db := &moduleBackupDatabase{Host: "127.0.0.1", Port: 3306, Name: "resource_db", Username: "resource_user", Password: "secret\"with\\characters\nand-newline", TLSMode: "disabled"}
	h.run = func(_ context.Context, name string, args []string, input io.Reader, out io.Writer) error {
		if strings.Contains(strings.Join(args, " "), db.Password) || strings.Contains(strings.Join(args, " "), "resource_user") {
			t.Fatal("credentials exposed in argv")
		}
		defaults := strings.TrimPrefix(args[0], "--defaults-extra-file=")
		defaultsFiles = append(defaultsFiles, defaults)
		info, err := os.Stat(defaults)
		if err != nil || info.Mode().Perm() != 0600 {
			t.Fatal("credentials file not private")
		}
		contents := moduleTestRead(t, defaults)
		if !strings.Contains(contents, "password="+moduleOptionQuote(db.Password)) || !strings.Contains(contents, "skip-ssl") {
			t.Fatal("invalid protected client configuration")
		}
		if strings.HasSuffix(name, "mariadb-dump") {
			if !strings.Contains(strings.Join(args, " "), "--add-drop-database --databases") {
				t.Fatal("dump cannot remove tables introduced since snapshot")
			}
			_, err = io.WriteString(out, state)
			return err
		}
		imports++
		if !strings.Contains(strings.Join(args, " "), "--binary-mode --local-infile=0 --skip-reconnect") {
			t.Fatal("client commands/local file loading not disabled")
		}
		bytes, err := io.ReadAll(input)
		if err != nil {
			return err
		}
		state = string(bytes)
		if failIncoming && state == "archive-database" {
			state = "partial-corrupted-import"
			return errors.New("injected import failure")
		}
		return nil
	}
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "db", ResourceID: "42", Database: db}}
	archive := moduleTestSnapshot(t, h, request)
	if bytes.Contains(archive, []byte(db.Password)) {
		t.Fatal("credentials leaked into archive")
	}
	state = "live-database"
	failIncoming = true
	result := moduleTestRestore(t, h, request, archive)
	if result.Code == http.StatusOK || !strings.Contains(result.Body.String(), "previous state was recovered") {
		t.Fatal(result.Body.String())
	}
	if state != "live-database" || imports != 2 {
		t.Fatalf("state=%s imports=%d", state, imports)
	}
	recovery := os.Getenv("EASYWI_MODULE_BACKUP_RECOVERY_DIR")
	backups, _ := filepath.Glob(filepath.Join(recovery, "restore-*", "previous.sql"))
	if len(backups) != 1 || moduleTestRead(t, backups[0]) != "live-database" {
		t.Fatal("pre-restore SQL not retained")
	}
	for _, name := range defaultsFiles {
		if _, err := os.Stat(name); !os.IsNotExist(err) {
			t.Fatal("credential file leaked")
		}
	}
}
func TestModuleBackupDatabaseRollbackFailureIsReportedAndSQLRetained(t *testing.T) {
	moduleTestFakeDatabaseClients(t)
	h := moduleTestHandler(t)
	h.run = func(_ context.Context, name string, _ []string, _ io.Reader, out io.Writer) error {
		if strings.HasSuffix(name, "mariadb-dump") {
			_, _ = io.WriteString(out, "previous database")
			return nil
		}
		return errors.New("database unavailable")
	}
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "db", ResourceID: "42", Database: &moduleBackupDatabase{Host: "localhost", Port: 3306, Name: "resource_db", Username: "user", TLSMode: "required"}}}
	archive := moduleTestSnapshot(t, h, request)
	result := moduleTestRestore(t, h, request, archive)
	if result.Code == http.StatusOK || !strings.Contains(result.Body.String(), "automatic recovery incomplete") {
		t.Fatal(result.Body.String())
	}
	backups, _ := filepath.Glob(filepath.Join(os.Getenv("EASYWI_MODULE_BACKUP_RECOVERY_DIR"), "restore-*", "previous.sql"))
	if len(backups) != 1 {
		t.Fatal("recovery SQL removed")
	}
}
func TestModuleBackupResponseSignaturesBindRequestAndBody(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "data")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	var archive []byte
	for _, operation := range []string{"snapshot", "restore"} {
		var body []byte
		if operation == "snapshot" {
			body = moduleTestJSON(t, request)
		} else {
			request.DryRun = true
			body = moduleTestEnvelope(t, request, archive)
		}
		signed := moduleTestRequest("/v1/module-backups/"+operation, body)
		out := httptest.NewRecorder()
		h.ServeHTTP(out, signed)
		if out.Code != http.StatusOK {
			t.Fatal(out.Body.String())
		}
		hash := sha256.Sum256(out.Body.Bytes())
		digest := hex.EncodeToString(hash[:])
		if out.Header().Get("X-Response-SHA256") != digest {
			t.Fatal("response digest invalid")
		}
		fields := []string{"agent-1", "customer-1", "POST", signed.URL.RequestURI(), signed.Header.Get("X-Timestamp"), signed.Header.Get("X-Content-SHA256"), "200", digest}
		mac := hmac.New(sha256.New, []byte("test-secret"))
		_, _ = mac.Write([]byte(strings.Join(fields, "\n")))
		if out.Header().Get("X-Operation-Signature") != hex.EncodeToString(mac.Sum(nil)) {
			t.Fatal("response signature invalid")
		}
		fields[len(fields)-1] = strings.Repeat("0", 64)
		tampered := hmac.New(sha256.New, []byte("test-secret"))
		_, _ = tampered.Write([]byte(strings.Join(fields, "\n")))
		if hmac.Equal(mac.Sum(nil), tampered.Sum(nil)) {
			t.Fatal("signature does not bind body")
		}
		if operation == "snapshot" {
			archive = append([]byte(nil), out.Body.Bytes()...)
		}
	}
}

func TestModuleBackupRefusesConcatenatedGzipMembers(t *testing.T) {
	h := moduleTestHandler(t)
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	moduleTestWrite(t, filepath.Join(path, "data"), "state")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	archive := moduleTestSnapshot(t, h, request)
	archive = append(archive, archive...)
	result := moduleTestRestore(t, h, request, archive)
	if result.Code == http.StatusOK {
		t.Fatal("concatenated gzip members accepted")
	}
	if !strings.Contains(result.Body.String(), "trailing compressed data") {
		t.Fatal(fmt.Sprint(result.Code) + result.Body.String())
	}
}

// Keep real streaming helpers covered with short reads and cancellations.
func TestModuleBackupSpoolCancellation(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := spoolModuleBody(ctx, io.NopCloser(strings.NewReader("data")), filepath.Join(t.TempDir(), "body"), "digest", 100); err == nil {
		t.Fatal("cancelled upload accepted")
	}
}

func TestModuleBackupExpandedQuotaBoundaryRoundTrip(t *testing.T) {
	h := moduleTestHandler(t)
	h.maxBytes = 4096
	base := h.roots["musicbot"][0]
	path := filepath.Join(base, "instance")
	original := strings.Repeat("x", int(h.maxBytes))
	moduleTestWrite(t, filepath.Join(path, "data"), original)
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "musicbot", ResourceID: "17", Path: path}}
	archive := moduleTestSnapshot(t, h, request)
	moduleTestWrite(t, filepath.Join(path, "data"), "after")
	result := moduleTestRestore(t, h, request, archive)
	if result.Code != http.StatusOK {
		t.Fatal(result.Body.String())
	}
	if moduleTestRead(t, filepath.Join(path, "data")) != original {
		t.Fatal("full-quota resource was not restored")
	}
}

func moduleTestCAPEM(t *testing.T) string {
	t.Helper()
	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := &x509.Certificate{SerialNumber: big.NewInt(1), Subject: pkix.Name{CommonName: "Module backup test CA"}, NotBefore: time.Now().Add(-time.Hour), NotAfter: time.Now().Add(time.Hour), IsCA: true, BasicConstraintsValid: true, KeyUsage: x509.KeyUsageCertSign}
	certificate, err := x509.CreateCertificate(rand.Reader, template, template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	return string(pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: certificate}))
}
func TestModuleBackupVerifiedTLSStagesPEMCAAndCleansIt(t *testing.T) {
	moduleTestFakeDatabaseClients(t)
	for _, mode := range []string{"verify_ca", "verify_identity"} {
		t.Run(mode, func(t *testing.T) {
			h := moduleTestHandler(t)
			certificate := moduleTestCAPEM(t)
			db := &moduleBackupDatabase{Host: "localhost", Port: 3306, Name: "resource_db", Username: "user", TLSMode: mode, CACert: certificate}
			if err := validateModuleDatabase(db); err != nil {
				t.Fatal(err)
			}
			scratch := t.TempDir()
			var caPath string
			processFailure := false
			h.run = func(_ context.Context, _ string, args []string, _ io.Reader, _ io.Writer) error {
				if strings.Contains(strings.Join(args, " "), "CERTIFICATE") {
					t.Fatal("PEM certificate leaked into argv")
				}
				configuration := moduleTestRead(t, strings.TrimPrefix(args[0], "--defaults-extra-file="))
				files, err := filepath.Glob(filepath.Join(scratch, "mysql-ca-*.pem"))
				if err != nil || len(files) != 1 {
					t.Fatalf("private CA staging missing: %v %v", files, err)
				}
				caPath = files[0]
				if moduleTestRead(t, caPath) != certificate || !strings.Contains(configuration, "ssl-ca="+moduleOptionQuote(caPath)) {
					t.Fatal("client does not use original node PEM")
				}
				info, err := os.Stat(caPath)
				if err != nil || info.Mode().Perm() != 0600 {
					t.Fatal("CA file is not private")
				}
				if processFailure {
					return errors.New("injected TLS client failure")
				}
				return nil
			}
			for _, fail := range []bool{false, true} {
				processFailure = fail
				err := h.databaseCommand(context.Background(), db, scratch, false, strings.NewReader("SELECT 1"), io.Discard)
				if fail && err == nil || !fail && err != nil {
					t.Fatalf("client error mismatch: %v", err)
				}
				if _, err := os.Stat(caPath); !os.IsNotExist(err) {
					t.Fatal("CA certificate file leaked after client completion")
				}
				files, err := os.ReadDir(scratch)
				if err != nil || len(files) != 0 {
					t.Fatal("private client files leaked")
				}
			}
		})
	}
	for _, certificate := range []string{"/etc/ssl/certs/ca-certificates.crt", "not a PEM certificate", strings.Repeat("x", 32*1024+1)} {
		if err := validateModuleDatabase(&moduleBackupDatabase{Host: "localhost", Port: 3306, Name: "db", Username: "u", TLSMode: "verify_identity", CACert: certificate}); err == nil {
			t.Fatal("invalid node CA accepted")
		}
	}
}

func TestModuleBackupSharedServiceSerializesDistinctResourcesAndCleansLocks(t *testing.T) {
	first := moduleBackupRequest{CustomerID: "service-lock-customer-1", Descriptor: moduleBackupDescriptor{Module: "mail", ResourceID: "domain-1", Path: "/unique-mail-root/domain-1", Service: "shared-module-lock", Services: []string{"shared-module-lock.service", "shared-module-lock"}}}
	second := moduleBackupRequest{CustomerID: "service-lock-customer-2", Descriptor: moduleBackupDescriptor{Module: "mail", ResourceID: "domain-2", Path: "/unique-mail-root/domain-2", Services: []string{"shared-module-lock.service"}}}
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	releaseFirst, err := lockModuleOperation(ctx, first, []moduleBackupRoot{{"primary", first.Descriptor.Path}})
	if err != nil {
		t.Fatalf("service aliases caused self-deadlock: %v", err)
	}
	released := false
	defer func() {
		if !released {
			releaseFirst()
		}
	}()
	waiter, cancelWaiter := context.WithTimeout(context.Background(), 30*time.Millisecond)
	defer cancelWaiter()
	if releaseSecond, err := lockModuleOperation(waiter, second, []moduleBackupRoot{{"primary", second.Descriptor.Path}}); !errors.Is(err, context.DeadlineExceeded) {
		if err == nil {
			releaseSecond()
		}
		t.Fatalf("distinct roots must wait for shared service: %v", err)
	}
	moduleOperationLocks.Lock()
	shared := moduleOperationLocks.entries["service:shared-module-lock.service"]
	if shared == nil || shared.references != 1 {
		moduleOperationLocks.Unlock()
		t.Fatal("cancelled service waiter leaked its reference")
	}
	for _, key := range []string{"path:" + second.Descriptor.Path, "resource:mail:" + second.CustomerID + ":" + second.Descriptor.ResourceID} {
		if moduleOperationLocks.entries[key] != nil {
			moduleOperationLocks.Unlock()
			t.Fatalf("cancelled waiter leaked lock %s", key)
		}
	}
	moduleOperationLocks.Unlock()
	releaseFirst()
	released = true
	next, cancelNext := context.WithTimeout(context.Background(), time.Second)
	defer cancelNext()
	releaseSecond, err := lockModuleOperation(next, second, []moduleBackupRoot{{"primary", second.Descriptor.Path}})
	if err != nil {
		t.Fatalf("released service remains locked: %v", err)
	}
	releaseSecond()
	moduleOperationLocks.Lock()
	defer moduleOperationLocks.Unlock()
	for _, key := range []string{"service:shared-module-lock.service", "path:" + first.Descriptor.Path, "path:" + second.Descriptor.Path, "resource:mail:" + first.CustomerID + ":" + first.Descriptor.ResourceID, "resource:mail:" + second.CustomerID + ":" + second.Descriptor.ResourceID} {
		if moduleOperationLocks.entries[key] != nil {
			t.Errorf("completed operation leaked lock %s", key)
		}
	}
}
func TestModuleBackupNormalizesServiceAliasesBeforeLifecycleOperations(t *testing.T) {
	h := moduleTestHandler(t)
	root := filepath.Join(h.roots["mail"][0], "domain")
	request := moduleBackupRequest{CustomerID: "customer-1", Descriptor: moduleBackupDescriptor{Module: "mail", ResourceID: "mail-17", Path: root, Service: "postfix", Services: []string{"postfix.service", "dovecot", "dovecot.service"}}}
	_, services, err := h.validate(request)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(services, ",") != "dovecot.service,postfix.service" {
		t.Fatalf("service aliases not canonicalized/deduplicated: %v", services)
	}
}
