package main

import (
	"archive/tar"
	"bufio"
	"compress/gzip"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

type moduleArchiveManifest struct {
	Version    int      `json:"version"`
	Module     string   `json:"module"`
	ResourceID string   `json:"resource_id"`
	CustomerID string   `json:"customer_id"`
	Database   string   `json:"database,omitempty"`
	Roots      []string `json:"roots"`
}
type moduleRestoreRoot struct {
	moduleBackupRoot
	Container   string
	NewPath     string
	Previous    string
	HadPrevious bool
	Applied     bool
}

func moduleManifest(request moduleBackupRequest, roots []moduleBackupRoot) moduleArchiveManifest {
	m := moduleArchiveManifest{Version: 1, Module: request.Descriptor.Module, ResourceID: request.Descriptor.ResourceID, CustomerID: request.CustomerID, Roots: []string{}}
	if db := request.Descriptor.Database; db != nil {
		m.Database = db.Name
	}
	for _, root := range roots {
		m.Roots = append(m.Roots, root.Name)
	}
	return m
}
func (h *moduleBackupHandler) snapshot(ctx context.Context, request moduleBackupRequest, roots []moduleBackupRoot, services []string, archive, scratch string) (result error) {
	// Resolve and check sources before interrupting services.
	for _, root := range roots {
		if err := moduleNoSymlinkAncestors(root.Path); err != nil {
			return err
		}
		info, err := os.Lstat(root.Path)
		if err != nil {
			return fmt.Errorf("snapshot root %s is unavailable", root.Name)
		}
		if !info.IsDir() && !info.Mode().IsRegular() {
			return errors.New("snapshot roots must be regular files or directories")
		}
	}
	active, err := h.stopServices(ctx, services)
	defer func() {
		recovery, cancel := moduleRecoveryContext()
		defer cancel()
		result = errors.Join(result, h.startServices(recovery, active))
	}()
	if err != nil {
		return err
	}
	sqlPath := ""
	if db := request.Descriptor.Database; db != nil {
		sqlPath = filepath.Join(scratch, "database.sql")
		if err = h.dumpDatabase(ctx, db, sqlPath, scratch); err != nil {
			return err
		}
	}
	return h.writeArchive(ctx, request, roots, sqlPath, archive)
}
func (h *moduleBackupHandler) writeArchive(ctx context.Context, request moduleBackupRequest, roots []moduleBackupRoot, sqlPath, destination string) (result error) {
	out, err := os.OpenFile(destination, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	defer func() { result = errors.Join(result, out.Close()) }()
	gz := gzip.NewWriter(&moduleLimitWriter{writer: out, remaining: h.maxBytes})
	tw := tar.NewWriter(gz)
	defer func() { result = errors.Join(result, tw.Close(), gz.Close()) }()
	manifest, err := json.Marshal(moduleManifest(request, roots))
	if err != nil {
		return err
	}
	if err = tw.WriteHeader(&tar.Header{Name: ".module-backup.json", Typeflag: tar.TypeReg, Mode: 0600, Size: int64(len(manifest))}); err != nil {
		return err
	}
	if _, err = tw.Write(manifest); err != nil {
		return err
	}
	var expanded int64
	for _, root := range roots {
		err = filepath.Walk(root.Path, func(path string, info os.FileInfo, walkErr error) error {
			if walkErr != nil {
				return walkErr
			}
			if err := ctx.Err(); err != nil {
				return err
			}
			rel, err := filepath.Rel(root.Path, path)
			if err != nil {
				return err
			}
			rel = filepath.ToSlash(rel)
			if rel != "." && moduleExcluded(rel, request.Descriptor.Exclude) {
				if info.IsDir() {
					return filepath.SkipDir
				}
				return nil
			}
			if info.Mode()&os.ModeSymlink != 0 {
				return fmt.Errorf("snapshot root %s contains a symbolic link", root.Name)
			}
			// Runtime sockets, FIFOs, devices and PID files cannot be restored safely.
			if !info.IsDir() && !info.Mode().IsRegular() {
				return nil
			}
			if rel != "." && strings.HasSuffix(strings.ToLower(info.Name()), ".pid") {
				return nil
			}
			header, err := tar.FileInfoHeader(info, "")
			if err != nil {
				return err
			}
			header.Name = "files/" + root.Name
			if rel != "." {
				header.Name += "/" + rel
			}
			header.Mode &= 0777
			header.Uname = ""
			header.Gname = ""
			if info.IsDir() {
				header.Name += "/"
			} else {
				expanded += info.Size()
				if expanded > h.maxBytes {
					return errors.New("expanded archive exceeds configured size limit")
				}
			}
			if err = tw.WriteHeader(header); err != nil {
				return err
			}
			if info.Mode().IsRegular() {
				f, err := os.Open(path)
				if err != nil {
					return err
				}
				opened, statErr := f.Stat()
				if statErr != nil || !os.SameFile(info, opened) {
					_ = f.Close()
					return errors.New("source file changed while snapshotting")
				}
				_, err = io.Copy(tw, moduleContextReader{ctx, f})
				closeErr := f.Close()
				if err != nil {
					return err
				}
				return closeErr
			}
			return nil
		})
		if err != nil {
			return err
		}
	}
	if sqlPath != "" {
		f, err := os.Open(sqlPath)
		if err != nil {
			return err
		}
		defer func() { result = errors.Join(result, f.Close()) }()
		info, err := f.Stat()
		if err != nil {
			return err
		}
		expanded += info.Size()
		if expanded > h.maxBytes {
			return errors.New("expanded archive exceeds configured size limit")
		}
		if err = tw.WriteHeader(&tar.Header{Name: "database.sql", Typeflag: tar.TypeReg, Mode: 0600, Size: info.Size()}); err != nil {
			return err
		}
		if _, err = io.Copy(tw, moduleContextReader{ctx, f}); err != nil {
			return err
		}
	}
	return nil
}
func moduleExcluded(relative string, exclusions []string) bool {
	for _, exclude := range exclusions {
		if relative == exclude || strings.HasPrefix(relative, exclude+"/") {
			return true
		}
	}
	return false
}

func (h *moduleBackupHandler) prepareRestore(ctx context.Context, request moduleBackupRequest, roots []moduleBackupRoot, archive, scratch string) (prepared []*moduleRestoreRoot, sqlPath string, result error) {
	defer func() {
		if result != nil {
			for _, root := range prepared {
				_ = os.RemoveAll(root.Container)
			}
		}
	}()
	lookup := map[string]*moduleRestoreRoot{}
	for _, root := range roots {
		if err := moduleNoSymlinkAncestors(root.Path); err != nil {
			return prepared, "", err
		}
		dir, err := os.MkdirTemp(filepath.Dir(root.Path), ".easywi-module-restore-")
		if err != nil {
			return prepared, "", fmt.Errorf("create same-volume staging for %s: %w", root.Name, err)
		}
		p := &moduleRestoreRoot{moduleBackupRoot: root, Container: dir, NewPath: filepath.Join(dir, "new"), Previous: filepath.Join(dir, "previous")}
		prepared = append(prepared, p)
		lookup[root.Name] = p
	}
	in, err := os.Open(archive)
	if err != nil {
		return prepared, "", err
	}
	defer func() {
		if closeErr := in.Close(); result == nil {
			result = closeErr
		}
	}()
	compressed := bufio.NewReader(in)
	gz, err := gzip.NewReader(compressed)
	if err != nil {
		return prepared, "", errors.New("invalid gzip archive")
	}
	defer func() {
		if closeErr := gz.Close(); result == nil {
			result = closeErr
		}
	}()
	// One gzip member only; trailing data is rejected after CRC/size validation.
	gz.Multistream(false)
	tr := tar.NewReader(gz)
	seen := map[string]bool{}
	manifestSeen := false
	var expanded int64
	var directoryHeaders []struct {
		path   string
		header tar.Header
	}
	for {
		header, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return prepared, "", errors.New("invalid tar archive")
		}
		if err = ctx.Err(); err != nil {
			return prepared, "", err
		}
		name := strings.TrimSuffix(header.Name, "/")
		if !moduleSafeRelative(name) || seen[name] {
			return prepared, "", errors.New("unsafe or duplicate archive entry")
		}
		seen[name] = true
		if header.Typeflag != tar.TypeDir && header.Typeflag != tar.TypeReg && header.Typeflag != tarTypeLegacyRegular {
			return prepared, "", errors.New("archive contains links or special entries")
		}
		if header.Size < 0 {
			return prepared, "", errors.New("invalid archive entry size")
		}
		// The size quota covers resource data, matching the snapshot writer;
		// the separately bounded manifest must not make a valid full-quota
		// snapshot impossible to restore.
		if name != ".module-backup.json" {
			if header.Size > h.maxBytes-expanded {
				return prepared, "", errors.New("expanded archive exceeds configured size limit")
			}
			expanded += header.Size
		}
		if !manifestSeen && name != ".module-backup.json" {
			return prepared, "", errors.New("archive manifest must be first")
		}
		if name == ".module-backup.json" {
			if header.Typeflag == tar.TypeDir || header.Size > moduleDescriptorLimit {
				return prepared, "", errors.New("invalid archive manifest")
			}
			bytes, err := io.ReadAll(tr)
			if err != nil {
				return prepared, "", err
			}
			var got moduleArchiveManifest
			decoder := json.NewDecoder(strings.NewReader(string(bytes)))
			decoder.DisallowUnknownFields()
			if err = decoder.Decode(&got); err != nil {
				return prepared, "", errors.New("invalid archive manifest")
			}
			if err = decoder.Decode(new(any)); err != io.EOF {
				return prepared, "", errors.New("trailing archive manifest data")
			}
			want := moduleManifest(request, roots)
			gotJSON, _ := json.Marshal(got)
			wantJSON, _ := json.Marshal(want)
			if string(gotJSON) != string(wantJSON) {
				return prepared, "", errors.New("archive binding does not match customer, module, resource, roots or database")
			}
			manifestSeen = true
			continue
		}
		if name == "database.sql" {
			if request.Descriptor.Database == nil || header.Typeflag == tar.TypeDir {
				return prepared, "", errors.New("unexpected database payload")
			}
			sqlPath = filepath.Join(scratch, "restore-database.sql")
			if err = extractModuleRegular(ctx, tr, sqlPath, 0600); err != nil {
				return prepared, "", err
			}
			continue
		}
		components := strings.SplitN(name, "/", 3)
		if len(components) < 2 || components[0] != "files" {
			return prepared, "", errors.New("unexpected archive payload")
		}
		root := lookup[components[1]]
		if root == nil {
			return prepared, "", errors.New("unexpected archive root")
		}
		relative := "."
		if len(components) == 3 {
			relative = components[2]
		}
		if relative != "." && (moduleExcluded(relative, request.Descriptor.Exclude) || strings.HasSuffix(strings.ToLower(filepath.Base(relative)), ".pid")) {
			return prepared, "", errors.New("archive contains excluded runtime data")
		}
		target := root.NewPath
		if relative != "." {
			target = filepath.Join(target, filepath.FromSlash(relative))
		}
		if err = moduleNoSymlinkAncestors(target); err != nil {
			return prepared, "", err
		}
		if header.Uid < 0 || header.Gid < 0 || header.Uid > 1<<31-1 || header.Gid > 1<<31-1 {
			return prepared, "", errors.New("invalid archive ownership")
		}
		mode := os.FileMode(header.Mode) & 0777
		if header.Typeflag == tar.TypeDir {
			if header.Size != 0 {
				return prepared, "", errors.New("directory archive entry contains payload")
			}
			if err = os.MkdirAll(target, 0700); err != nil {
				return prepared, "", err
			}
			directoryHeaders = append(directoryHeaders, struct {
				path   string
				header tar.Header
			}{target, *header})
		} else {
			if err = os.MkdirAll(filepath.Dir(target), 0700); err != nil {
				return prepared, "", err
			}
			if err = extractModuleRegular(ctx, tr, target, mode); err != nil {
				return prepared, "", err
			}
			if err = os.Chown(target, header.Uid, header.Gid); err != nil {
				return prepared, "", fmt.Errorf("restore ownership: %w", err)
			}
		}
	}
	if !manifestSeen {
		return prepared, "", errors.New("archive manifest missing")
	}
	for _, root := range prepared {
		if !seen["files/"+root.Name] {
			return prepared, "", fmt.Errorf("archive root %s missing", root.Name)
		}
	}
	if request.Descriptor.Database != nil && sqlPath == "" {
		return prepared, "", errors.New("database payload missing")
	}
	// Drain gzip completely to verify its checksum, and require zero-only tar
	// padding. io.Copy(tar.Reader) alone would stop before gzip checksum errors.
	tail, err := io.ReadAll(io.LimitReader(moduleContextReader{ctx, gz}, 1024*1024+1))
	if err != nil || len(tail) > 1024*1024 {
		return prepared, "", errors.New("invalid archive trailer or gzip checksum")
	}
	for _, b := range tail {
		if b != 0 {
			return prepared, "", errors.New("unexpected archive trailer")
		}
	}
	if _, err = compressed.ReadByte(); err != io.EOF {
		return prepared, "", errors.New("archive has trailing compressed data")
	}
	// Apply directory metadata last so restrictive modes do not prevent staging.
	sort.Slice(directoryHeaders, func(i, j int) bool { return len(directoryHeaders[i].path) > len(directoryHeaders[j].path) })
	for _, dir := range directoryHeaders {
		if err = os.Chown(dir.path, dir.header.Uid, dir.header.Gid); err != nil {
			return prepared, "", err
		}
		if err = os.Chmod(dir.path, os.FileMode(dir.header.Mode)&0777); err != nil {
			return prepared, "", err
		}
	}
	return prepared, sqlPath, nil
}
func extractModuleRegular(ctx context.Context, r io.Reader, path string, mode os.FileMode) error {
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	_, copyErr := io.Copy(f, moduleContextReader{ctx, r})
	syncErr := f.Sync()
	closeErr := f.Close()
	if copyErr != nil {
		return copyErr
	}
	if syncErr != nil {
		return syncErr
	}
	if closeErr != nil {
		return closeErr
	}
	return os.Chmod(path, mode)
}

func (h *moduleBackupHandler) restore(ctx context.Context, request moduleBackupRequest, roots []moduleBackupRoot, services []string, archive, scratch string) (result error) {
	prepared, sqlPath, err := h.prepareRestore(ctx, request, roots, archive, scratch)
	if err != nil {
		return err
	}
	keepRecovery := false
	defer func() {
		for _, root := range prepared {
			if !keepRecovery || !root.HadPrevious {
				_ = os.RemoveAll(root.Container)
			}
		}
	}()
	// A dry run validates client availability and services without stopping them.
	if request.Descriptor.Database != nil {
		if _, err = moduleClientBinary("mariadb-dump", "mysqldump"); err != nil {
			return err
		}
		if _, err = moduleClientBinary("mariadb", "mysql"); err != nil {
			return err
		}
	}
	for _, service := range services {
		if _, err = h.serviceState(ctx, service); err != nil {
			return err
		}
	}
	if err = ctx.Err(); err != nil {
		return err
	}
	if request.DryRun {
		return nil
	}
	// Check again after lengthy extraction, before any service or live-data change.
	for _, root := range roots {
		if err = moduleNoSymlinkAncestors(root.Path); err != nil {
			return err
		}
	}
	active, err := h.stopServices(ctx, services)
	if err != nil {
		recovery, cancel := moduleRecoveryContext()
		defer cancel()
		return errors.Join(err, h.startServices(recovery, active))
	}
	dbPrevious := ""
	dbAttempted := false
	recoveryDir := ""
	if request.Descriptor.Database != nil {
		recoveryDir, err = moduleDatabaseRecoveryDir()
		if err == nil {
			dbPrevious = filepath.Join(recoveryDir, "previous.sql")
			err = h.dumpDatabase(ctx, request.Descriptor.Database, dbPrevious, scratch)
		}
	}
	if err == nil {
		err = applyModuleRoots(ctx, prepared)
	}
	if err == nil && sqlPath != "" {
		dbAttempted = true
		err = h.importDatabase(ctx, request.Descriptor.Database, sqlPath, scratch)
	}
	if err == nil {
		err = ctx.Err()
	}
	if err == nil {
		err = h.startServices(ctx, active)
	}
	if err == nil {
		keepRecovery = true
		// Previous roots and SQL intentionally remain in private recovery locations;
		// automatic deletion could destroy the only pre-restore recovery copy.
		return nil
	}
	recovery, cancel := moduleRecoveryContext()
	defer cancel()
	var rollbackErrors []error
	// A failed restart may already have started some services. Stop them before
	// restoring their data, even if only a later service failed to start.
	for _, service := range active {
		if stopErr := h.run(recovery, "systemctl", []string{"stop", "--", service}, nil, io.Discard); stopErr != nil {
			rollbackErrors = append(rollbackErrors, fmt.Errorf("rollback cannot stop %s: %w", service, stopErr))
		}
	}
	if len(rollbackErrors) == 0 {
		if dbAttempted {
			if rollbackErr := h.importDatabase(recovery, request.Descriptor.Database, dbPrevious, scratch); rollbackErr != nil {
				rollbackErrors = append(rollbackErrors, fmt.Errorf("database rollback failed; recovery SQL retained at %s: %w", dbPrevious, rollbackErr))
			}
		}
		rollbackErrors = append(rollbackErrors, rollbackModuleRoots(prepared))
	}
	if startErr := h.startServices(recovery, active); startErr != nil {
		rollbackErrors = append(rollbackErrors, startErr)
	}
	rollbackErr := errors.Join(rollbackErrors...)
	if rollbackErr != nil {
		keepRecovery = true
		return errors.Join(err, fmt.Errorf("automatic recovery incomplete; private recovery copies retained: %w", rollbackErr))
	}
	return fmt.Errorf("restore failed and previous state was recovered: %w", err)
}
func applyModuleRoots(ctx context.Context, roots []*moduleRestoreRoot) error {
	for _, root := range roots {
		if err := ctx.Err(); err != nil {
			return err
		}
		if err := moduleNoSymlinkAncestors(root.Path); err != nil {
			return err
		}
		info, err := os.Lstat(root.Path)
		if err == nil {
			if !info.IsDir() && !info.Mode().IsRegular() {
				return errors.New("restore target is not a regular file or directory")
			}
			if err = os.Rename(root.Path, root.Previous); err != nil {
				return fmt.Errorf("preserve previous root %s: %w", root.Name, err)
			}
			root.HadPrevious = true
		} else if !os.IsNotExist(err) {
			return err
		}
		if err = os.Rename(root.NewPath, root.Path); err != nil {
			return fmt.Errorf("apply root %s: %w", root.Name, err)
		}
		root.Applied = true
	}
	return nil
}
func rollbackModuleRoots(roots []*moduleRestoreRoot) error {
	var errs []error
	for i := len(roots) - 1; i >= 0; i-- {
		root := roots[i]
		if root.Applied {
			if err := os.Rename(root.Path, filepath.Join(root.Container, "failed-restored-data")); err != nil {
				errs = append(errs, fmt.Errorf("preserve failed restore for %s: %w", root.Name, err))
				continue
			}
			root.Applied = false
		}
		if root.HadPrevious {
			if err := os.Rename(root.Previous, root.Path); err != nil {
				errs = append(errs, fmt.Errorf("recover original root %s from %s: %w", root.Name, root.Previous, err))
			} else {
				root.HadPrevious = false
			}
		}
	}
	return errors.Join(errs...)
}
func moduleDatabaseRecoveryDir() (string, error) {
	base := os.Getenv("EASYWI_MODULE_BACKUP_RECOVERY_DIR")
	if base == "" {
		base = "/var/lib/easywi/backups/module-recovery"
	}
	if !filepath.IsAbs(base) || filepath.Clean(base) == "/" {
		return "", errors.New("invalid database recovery directory")
	}
	if err := moduleNoSymlinkAncestors(base); err != nil {
		return "", err
	}
	if err := os.MkdirAll(base, 0700); err != nil {
		return "", fmt.Errorf("create database recovery directory: %w", err)
	}
	info, err := os.Stat(base)
	if err != nil {
		return "", err
	}
	if info.Mode().Perm()&0077 != 0 {
		return "", errors.New("database recovery directory must be private (0700)")
	}
	return os.MkdirTemp(base, "restore-"+time.Now().UTC().Format("20060102T150405")+"-")
}
