package main

import (
	"bytes"
	"context"
	"crypto/x509"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

// A single process boundary is injectable in tests. No shell is involved and
// client errors never echo output containing database data or credentials.
type moduleCommandRunner func(context.Context, string, []string, io.Reader, io.Writer) error

func runModuleCommand(ctx context.Context, name string, args []string, in io.Reader, out io.Writer) error {
	command := exec.CommandContext(ctx, name, args...)
	command.Stdin = in
	command.Stdout = out
	if err := command.Run(); err != nil {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		return fmt.Errorf("%s command failed", filepath.Base(name))
	}
	return nil
}
func validateModuleDatabase(db *moduleBackupDatabase) error {
	if db.Host == "" || len(db.Host) > 253 || strings.ContainsAny(db.Host, "\x00\r\n/") || db.Port < 1 || db.Port > 65535 {
		return errors.New("invalid database address")
	}
	if db.Name == "" || len(db.Name) > 64 || strings.ContainsAny(db.Name, "\x00\r\n/") || strings.HasPrefix(db.Name, "-") || db.Username == "" || len(db.Username) > 128 || strings.ContainsAny(db.Username, "\x00\r\n") || strings.ContainsRune(db.Password, '\x00') {
		return errors.New("invalid database credentials or database name")
	}
	switch strings.ToLower(db.TLSMode) {
	case "disabled", "required", "verify_ca", "verify_identity":
	default:
		return errors.New("database tls_mode must be explicit: disabled, required, verify_ca or verify_identity")
	}
	if strings.EqualFold(db.TLSMode, "verify_ca") || strings.EqualFold(db.TLSMode, "verify_identity") {
		if len(db.CACert) == 0 || len(db.CACert) > 32*1024 || !x509.NewCertPool().AppendCertsFromPEM([]byte(db.CACert)) {
			return errors.New("verified database TLS requires a valid PEM CA certificate (maximum 32 KiB)")
		}
	}
	return nil
}
func moduleClientBinary(names ...string) (string, error) {
	for _, name := range names {
		if path, err := exec.LookPath(name); err == nil {
			return path, nil
		}
	}
	return "", fmt.Errorf("required database client missing: %s", strings.Join(names, " or "))
}
func moduleOptionQuote(value string) string {
	replacer := strings.NewReplacer("\\", "\\\\", "\"", "\\\"", "\n", "\\n", "\r", "\\r", "\t", "\\t")
	return "\"" + replacer.Replace(value) + "\""
}
func (h *moduleBackupHandler) databaseCommand(ctx context.Context, db *moduleBackupDatabase, scratch string, dump bool, input io.Reader, output io.Writer) error {
	names := []string{"mariadb", "mysql"}
	if dump {
		names = []string{"mariadb-dump", "mysqldump"}
	}
	binary, err := moduleClientBinary(names...)
	if err != nil {
		return err
	}
	options := "[client]\nhost=" + moduleOptionQuote(db.Host) + "\nport=" + strconv.Itoa(db.Port) + "\nuser=" + moduleOptionQuote(db.Username) + "\npassword=" + moduleOptionQuote(db.Password) + "\nprotocol=tcp\n"
	maria := strings.HasPrefix(filepath.Base(binary), "mariadb")
	mode := strings.ToLower(db.TLSMode)
	caPath := ""
	if mode == "verify_ca" || mode == "verify_identity" {
		if err := validateModuleDatabase(db); err != nil {
			return err
		}
		caFile, caErr := os.CreateTemp(scratch, "mysql-ca-*.pem")
		if caErr != nil {
			return caErr
		}
		caPath = caFile.Name()
		defer func() {
			if err := os.Remove(caPath); err != nil && !errors.Is(err, os.ErrNotExist) {
				log.Printf("module backup: remove temporary database CA certificate: %v", err)
			}
		}()
		if _, caErr = caFile.WriteString(db.CACert); caErr != nil {
			_ = caFile.Close()
			return caErr
		}
		if caErr = caFile.Close(); caErr != nil {
			return caErr
		}
	}
	if maria {
		if mode == "disabled" {
			options += "skip-ssl\n"
		} else {
			options += "ssl\n"
			if mode == "required" {
				options += "skip-ssl-verify-server-cert\n"
			} else {
				options += "ssl-verify-server-cert\nssl-ca=" + moduleOptionQuote(caPath) + "\n"
			}
		}
	} else {
		mysqlMode := map[string]string{"disabled": "DISABLED", "required": "REQUIRED", "verify_ca": "VERIFY_CA", "verify_identity": "VERIFY_IDENTITY"}[mode]
		options += "ssl-mode=" + mysqlMode + "\n"
		if caPath != "" {
			options += "ssl-ca=" + moduleOptionQuote(caPath) + "\n"
		}
	}
	file, err := os.CreateTemp(scratch, "mysql-defaults-*.cnf")
	if err != nil {
		return err
	}
	name := file.Name()
	defer func() {
		if err := os.Remove(name); err != nil && !errors.Is(err, os.ErrNotExist) {
			log.Printf("module backup: remove temporary database options file: %v", err)
		}
	}()
	if _, err = file.WriteString(options); err != nil {
		_ = file.Close()
		return err
	}
	if err = file.Close(); err != nil {
		return err
	}
	args := []string{"--defaults-extra-file=" + name}
	if dump {
		args = append(args, "--single-transaction", "--quick", "--hex-blob", "--routines", "--events", "--triggers", "--skip-lock-tables", "--no-tablespaces", "--skip-add-locks", "--add-drop-database", "--databases")
	}
	// -- prevents names supplied by the control plane from becoming CLI options.
	if !dump {
		args = append(args, "--binary-mode", "--local-infile=0", "--skip-reconnect")
	}
	args = append(args, "--", db.Name)
	return h.run(ctx, binary, args, input, output)
}
func (h *moduleBackupHandler) dumpDatabase(ctx context.Context, db *moduleBackupDatabase, path, scratch string) error {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	err = h.databaseCommand(ctx, db, scratch, true, nil, &moduleLimitWriter{writer: f, remaining: h.maxBytes})
	closeErr := f.Close()
	if err != nil {
		return fmt.Errorf("database snapshot failed: %w", err)
	}
	return closeErr
}
func (h *moduleBackupHandler) importDatabase(ctx context.Context, db *moduleBackupDatabase, path, scratch string) error {
	f, err := os.Open(path)
	if err != nil {
		return err
	}
	defer func() {
		if err := f.Close(); err != nil {
			log.Printf("module backup: close database restore input: %v", err)
		}
	}()
	if err = h.databaseCommand(ctx, db, scratch, false, f, io.Discard); err != nil {
		return fmt.Errorf("database restore failed: %w", err)
	}
	return nil
}
func (h *moduleBackupHandler) serviceState(ctx context.Context, service string) (bool, error) {
	var out bytes.Buffer
	if err := h.run(ctx, "systemctl", []string{"show", "--property=LoadState", "--property=ActiveState", "--", service}, nil, &out); err != nil {
		return false, fmt.Errorf("inspect resource service %s: %w", service, err)
	}
	state := map[string]string{}
	for _, line := range strings.Split(out.String(), "\n") {
		key, value, ok := strings.Cut(line, "=")
		if ok {
			state[key] = value
		}
	}
	if state["LoadState"] != "loaded" {
		return false, fmt.Errorf("resource service %s is not loaded", service)
	}
	switch state["ActiveState"] {
	case "active":
		return true, nil
	case "inactive", "failed":
		return false, nil
	default:
		return false, fmt.Errorf("resource service %s is changing state", service)
	}
}
func (h *moduleBackupHandler) stopServices(ctx context.Context, services []string) ([]string, error) {
	var active []string
	for _, service := range services {
		running, err := h.serviceState(ctx, service)
		if err != nil {
			return active, err
		}
		if running {
			active = append(active, service)
		}
	}
	// Record all original active services before stopping; restart is attempted
	// even when an interrupted stop command has an ambiguous outcome.
	for _, service := range active {
		if err := h.run(ctx, "systemctl", []string{"stop", "--", service}, nil, io.Discard); err != nil {
			return active, fmt.Errorf("stop resource service %s: %w", service, err)
		}
	}
	return active, nil
}
func (h *moduleBackupHandler) startServices(ctx context.Context, services []string) error {
	var errs []error
	for _, service := range services {
		if err := h.run(ctx, "systemctl", []string{"start", "--", service}, nil, io.Discard); err != nil {
			errs = append(errs, fmt.Errorf("restart resource service %s: %w", service, err))
			continue
		}
		active, err := h.serviceState(ctx, service)
		if err != nil {
			errs = append(errs, err)
		} else if !active {
			errs = append(errs, fmt.Errorf("resource service %s did not become active", service))
		}
	}
	return errors.Join(errs...)
}
func moduleRecoveryContext() (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.Background(), 2*time.Minute)
}

type moduleLimitWriter struct {
	writer    io.Writer
	remaining int64
}

func (w *moduleLimitWriter) Write(p []byte) (int, error) {
	if int64(len(p)) > w.remaining {
		return 0, errors.New("archive exceeds configured size limit")
	}
	n, err := w.writer.Write(p)
	w.remaining -= int64(n)
	return n, err
}
