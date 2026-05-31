package main

import (
	"archive/tar"
	"compress/gzip"
	"crypto/sha256"
	"crypto/tls"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const defaultInstanceBackupBaseDir = "/var/lib/easywi/backups/instances"
const defaultInstanceBackupTimeout = 2 * time.Hour

// tarTypeLegacyRegular is the pre-POSIX regular-file typeflag. The standard
// library exposes this as deprecated tar.TypeRegA, so keep the literal behind
// a local name to support older archives without triggering staticcheck.
const tarTypeLegacyRegular byte = 0

func instanceBackupTimeout() time.Duration {
	if raw := strings.TrimSpace(os.Getenv("EASYWI_INSTANCE_BACKUP_TIMEOUT")); raw != "" {
		if minutes, err := strconv.Atoi(raw); err == nil && minutes > 0 {
			return time.Duration(minutes) * time.Minute
		}
	}
	return defaultInstanceBackupTimeout
}

func handleInstanceBackupCreate(job jobs.Job) (jobs.Result, func() error) {
	instanceID := payloadValue(job.Payload, "instance_id")
	if strings.TrimSpace(instanceID) == "" {
		return failureResult(job.ID, fmt.Errorf("instance_id is required"))
	}

	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}

	if _, err := os.Stat(instanceDir); err != nil {
		return failureResult(job.ID, fmt.Errorf("instance directory missing: %w", err))
	}

	backupTargetType := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "backup_target_type")))
	backupRoot := backupRootDir()
	if backupTargetType == "local" {
		resolvedRoot, resolveErr := resolveLocalBackupRoot(job.Payload, backupRoot)
		if resolveErr != nil {
			return failureResult(job.ID, resolveErr)
		}
		backupRoot = resolvedRoot
	}
	targetDir := filepath.Join(backupRoot, sanitizeIdentifier(instanceID))
	if err := os.MkdirAll(targetDir, 0o750); err != nil {
		return failureResult(job.ID, fmt.Errorf("create backup target dir: %w", err))
	}

	backupPath := filepath.Join(targetDir, fmt.Sprintf("instance-%s-%d.tar.gz", sanitizeIdentifier(instanceID), time.Now().UTC().Unix()))
	if err := createTarGzArchive(backupPath, instanceDir); err != nil {
		return failureResult(job.ID, fmt.Errorf("create backup archive: %w", err))
	}

	checksum, sizeBytes, err := computeFileChecksumAndSize(backupPath)
	if err != nil {
		return failureResult(job.ID, fmt.Errorf("compute backup metadata: %w", err))
	}

	if backupTargetType == "webdav" || backupTargetType == "nextcloud" {
		localPath := backupPath
		remotePath, err := uploadBackupToWebdav(job.Payload, localPath)
		if err != nil {
			return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{"error": err.Error(), "error_code": "backup_target_connection_failed"}, Completed: time.Now().UTC()}, nil
		}
		if err := os.Remove(localPath); err != nil && !os.IsNotExist(err) {
			return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{"error": fmt.Sprintf("remove local backup staging file: %v", err), "error_code": "backup_local_cleanup_failed"}, Completed: time.Now().UTC()}, nil
		}
		backupPath = remotePath
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"backup_id":   payloadValue(job.Payload, "backup_id"),
			"backup_path": backupPath,
			"size_bytes":  strconv.FormatInt(sizeBytes, 10),
			"sha256":      checksum,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func handleInstanceBackupRestore(job jobs.Job) (jobs.Result, func() error) {
	backupPath := payloadValue(job.Payload, "backup_path")
	if strings.TrimSpace(backupPath) == "" {
		return failureResult(job.ID, fmt.Errorf("backup_path is required"))
	}
	backupTargetType := strings.ToLower(strings.TrimSpace(payloadValue(job.Payload, "backup_target_type")))
	if backupTargetType == "webdav" || backupTargetType == "nextcloud" {
		if err := validateRemoteBackupURL(job.Payload, backupPath); err != nil {
			return failureResult(job.ID, err)
		}
		tmpPath, err := downloadBackupFromWebdav(job.Payload, backupPath)
		if err != nil {
			return jobs.Result{JobID: job.ID, Status: "failed", Output: map[string]string{"error": err.Error(), "error_code": "backup_target_connection_failed"}, Completed: time.Now().UTC()}, nil
		}
		backupPath = tmpPath
	}
	if _, err := os.Stat(backupPath); err != nil {
		return failureResult(job.ID, fmt.Errorf("backup archive missing: %w", err))
	}

	instanceDir, err := resolveInstanceDir(job.Payload)
	if err != nil {
		return failureResult(job.ID, err)
	}
	if err := os.MkdirAll(instanceDir, 0o750); err != nil {
		return failureResult(job.ID, fmt.Errorf("create instance dir: %w", err))
	}
	if err := validateBackupArchivePaths(backupPath, instanceDir); err != nil {
		return failureResult(job.ID, err)
	}

	if parsePayloadBool(payloadValue(job.Payload, "pre_backup"), false) {
		preBackupPath := filepath.Join(filepath.Dir(backupPath), fmt.Sprintf("pre-restore-%d.tar.gz", time.Now().UTC().Unix()))
		if err := createTarGzArchive(preBackupPath, instanceDir); err != nil {
			return failureResult(job.ID, fmt.Errorf("create pre-restore backup: %w", err))
		}
	}

	if err := extractTarGzArchive(backupPath, instanceDir); err != nil {
		return failureResult(job.ID, fmt.Errorf("restore backup archive: %w", err))
	}

	return jobs.Result{
		JobID:  job.ID,
		Status: "success",
		Output: map[string]string{
			"backup_id":     payloadValue(job.Payload, "backup_id"),
			"restored_from": backupPath,
		},
		Completed: time.Now().UTC(),
	}, nil
}

func createTarGzArchive(archivePath, sourceDir string) (err error) {
	archive, err := os.Create(archivePath)
	if err != nil {
		return err
	}
	defer func() {
		if closeErr := archive.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()

	gzWriter := gzip.NewWriter(archive)
	defer func() {
		if closeErr := gzWriter.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()

	tarWriter := tar.NewWriter(gzWriter)
	defer func() {
		if closeErr := tarWriter.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()

	return filepath.WalkDir(sourceDir, func(path string, entry os.DirEntry, walkErr error) error {
		if walkErr != nil {
			return walkErr
		}
		rel, err := filepath.Rel(sourceDir, path)
		if err != nil {
			return err
		}
		if rel == "." {
			return nil
		}
		info, err := entry.Info()
		if err != nil {
			return err
		}
		header, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return err
		}
		header.Name = filepath.ToSlash(rel)
		if err := tarWriter.WriteHeader(header); err != nil {
			return err
		}
		if info.IsDir() || !info.Mode().IsRegular() {
			return nil
		}
		file, err := os.Open(path)
		if err != nil {
			return err
		}
		_, copyErr := io.Copy(tarWriter, file)
		closeErr := file.Close()
		if copyErr != nil {
			return copyErr
		}
		return closeErr
	})
}

func extractTarGzArchive(archivePath, destinationRoot string) (err error) {
	archive, err := os.Open(archivePath)
	if err != nil {
		return err
	}
	defer func() {
		if closeErr := archive.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()

	gzReader, err := gzip.NewReader(archive)
	if err != nil {
		return err
	}
	defer func() {
		if closeErr := gzReader.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()

	tarReader := tar.NewReader(gzReader)
	for {
		header, err := tarReader.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return err
		}
		target := filepath.Clean(filepath.Join(destinationRoot, header.Name))
		rel, relErr := filepath.Rel(destinationRoot, target)
		if relErr != nil || strings.HasPrefix(rel, "..") || filepath.IsAbs(header.Name) {
			return fmt.Errorf("backup archive contains unsafe path: %s", header.Name)
		}
		mode := os.FileMode(header.Mode)
		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, mode); err != nil {
				return err
			}
		case tar.TypeReg, tarTypeLegacyRegular:
			if err := os.MkdirAll(filepath.Dir(target), 0o750); err != nil {
				return err
			}
			file, err := os.OpenFile(target, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, mode)
			if err != nil {
				return err
			}
			_, copyErr := io.Copy(file, tarReader)
			closeErr := file.Close()
			if copyErr != nil {
				return copyErr
			}
			if closeErr != nil {
				return closeErr
			}
		}
	}
	return nil
}

func computeFileChecksumAndSize(path string) (checksum string, size int64, err error) {
	file, err := os.Open(path)
	if err != nil {
		return "", 0, err
	}
	defer func() {
		if closeErr := file.Close(); err == nil && closeErr != nil {
			err = closeErr
		}
	}()

	h := sha256.New()
	size, err = io.Copy(h, file)
	if err != nil {
		return "", 0, err
	}

	checksum = hex.EncodeToString(h.Sum(nil))
	return checksum, size, nil
}

func backupRootDir() string {
	if custom := strings.TrimSpace(os.Getenv("EASYWI_INSTANCE_BACKUP_DIR")); custom != "" {
		return custom
	}
	return defaultInstanceBackupBaseDir
}

func resolveLocalBackupRoot(payload map[string]any, fallback string) (string, error) {
	configured := firstNonEmpty(
		payloadNestedValue(payload, "backup_target_config", "base_path"),
		payloadNestedValue(payload, "backup_target_config", "basePath"),
		payloadNestedValue(payload, "backup_target_config", "path"),
		payloadNestedValue(payload, "backup_target_config", "directory"),
		payloadNestedValue(payload, "backup_target_config", "root_path"),
		payloadNestedValue(payload, "backup_target_config", "backup_path"),
		payloadValue(payload, "backup_base_path", "backup_root"),
	)
	if strings.TrimSpace(configured) == "" {
		return fallback, nil
	}
	if !filepath.IsAbs(configured) {
		return "", fmt.Errorf("local backup base path must be absolute: %s", configured)
	}
	return filepath.Clean(configured), nil
}

func firstNonEmpty(values ...string) string {
	for _, value := range values {
		if trimmed := strings.TrimSpace(value); trimmed != "" {
			return trimmed
		}
	}
	return ""
}

func validateRemoteBackupURL(payload map[string]any, remote string) error {
	baseURL := strings.TrimSpace(payloadNestedValue(payload, "backup_target_config", "url"))
	if baseURL == "" {
		return fmt.Errorf("webdav base url missing")
	}

	baseParsed, err := url.Parse(baseURL)
	if err != nil {
		return fmt.Errorf("invalid webdav base url")
	}
	remoteParsed, err := url.Parse(strings.TrimSpace(remote))
	if err != nil {
		return fmt.Errorf("invalid backup remote url")
	}
	if remoteParsed.Scheme != "https" && remoteParsed.Scheme != "http" {
		return fmt.Errorf("backup remote url must use http/https")
	}
	if !strings.EqualFold(baseParsed.Host, remoteParsed.Host) {
		return fmt.Errorf("backup remote host mismatch")
	}

	return nil
}

func validateBackupArchivePaths(archivePath, destinationRoot string) (err error) {
	archive, err := os.Open(archivePath)
	if err != nil {
		return fmt.Errorf("open backup archive: %w", err)
	}
	defer func() {
		if closeErr := archive.Close(); err == nil && closeErr != nil {
			err = fmt.Errorf("close backup archive: %w", closeErr)
		}
	}()

	gzReader, err := gzip.NewReader(archive)
	if err != nil {
		return fmt.Errorf("open backup gzip: %w", err)
	}
	defer func() {
		if closeErr := gzReader.Close(); err == nil && closeErr != nil {
			err = fmt.Errorf("close backup gzip: %w", closeErr)
		}
	}()

	tarReader := tar.NewReader(gzReader)
	for {
		header, err := tarReader.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return fmt.Errorf("read backup archive: %w", err)
		}

		entry := strings.TrimSpace(header.Name)
		if entry == "" {
			continue
		}
		if filepath.IsAbs(entry) {
			return fmt.Errorf("backup archive contains absolute path: %s", entry)
		}

		resolved := filepath.Clean(filepath.Join(destinationRoot, entry))
		rel, relErr := filepath.Rel(destinationRoot, resolved)
		if relErr != nil || strings.HasPrefix(rel, "..") {
			return fmt.Errorf("backup archive contains unsafe path: %s", entry)
		}
	}

	return nil
}

func payloadNestedValue(payload map[string]any, objectKey string, key string) string {
	obj, ok := payload[objectKey]
	if !ok {
		return ""
	}
	m, ok := obj.(map[string]any)
	if !ok {
		return ""
	}
	v, ok := m[key]
	if !ok {
		return ""
	}
	return strings.TrimSpace(fmt.Sprintf("%v", v))
}

func webdavClient(verifyTLS bool) *http.Client {
	tr := &http.Transport{}
	if !verifyTLS {
		tr.TLSClientConfig = &tls.Config{InsecureSkipVerify: true} //nolint:gosec
	}
	return &http.Client{Timeout: 120 * time.Second, Transport: tr}
}

func webdavRemoteFolder(payload map[string]any) string {
	p := payloadNestedValue(payload, "backup_target_config", "remote_path")
	if p == "" {
		p = payloadNestedValue(payload, "backup_target_config", "root_path")
	}
	return "/" + strings.Trim(strings.TrimSpace(p), "/")
}

func webdavEscapedPath(path string) string {
	segments := make([]string, 0)
	for _, segment := range strings.Split(strings.Trim(path, "/"), "/") {
		segment = strings.TrimSpace(segment)
		if segment == "" {
			continue
		}
		segments = append(segments, url.PathEscape(segment))
	}
	if len(segments) == 0 {
		return ""
	}
	return "/" + strings.Join(segments, "/")
}

func webdavBaseAndFolder(payload map[string]any) (string, string) {
	baseURL := strings.TrimRight(payloadNestedValue(payload, "backup_target_config", "url"), "/")
	remoteFolder := webdavRemoteFolder(payload)
	if strings.EqualFold(strings.TrimSpace(payloadValue(payload, "backup_target_type")), "nextcloud") {
		username := webdavUsername(payload)
		if username != "" && !strings.Contains(baseURL, "/remote.php/dav/files/") {
			baseURL = baseURL + "/remote.php/dav/files/" + url.PathEscape(username)
		}
	}

	return baseURL, remoteFolder
}

func webdavUsername(payload map[string]any) string {
	u := payloadNestedValue(payload, "backup_target_config", "username")
	if u == "" {
		u = payloadNestedValue(payload, "backup_target_secret", "username")
	}
	return u
}

func applyWebdavAuth(req *http.Request, payload map[string]any) {
	token := payloadNestedValue(payload, "backup_target_secret", "token")
	if token != "" {
		req.Header.Set("Authorization", "Bearer "+token)
		return
	}
	req.SetBasicAuth(webdavUsername(payload), payloadNestedValue(payload, "backup_target_secret", "password"))
}

func hasWebdavCredentials(payload map[string]any) bool {
	token := payloadNestedValue(payload, "backup_target_secret", "token")
	if token != "" {
		return true
	}
	return webdavUsername(payload) != "" && payloadNestedValue(payload, "backup_target_secret", "password") != ""
}

func uploadBackupToWebdav(payload map[string]any, localPath string) (string, error) {
	baseURL, remoteFolder := webdavBaseAndFolder(payload)
	verifyTLS := strings.ToLower(payloadNestedValue(payload, "backup_target_config", "verify_tls")) != "false"
	if baseURL == "" || !hasWebdavCredentials(payload) {
		return "", fmt.Errorf("webdav target credentials/config missing")
	}

	filename := filepath.Base(localPath)
	u, err := url.Parse(baseURL + webdavEscapedPath(remoteFolder) + "/" + url.PathEscape(filename))
	if err != nil {
		return "", err
	}

	if err := ensureWebdavCollection(payload, baseURL, remoteFolder, verifyTLS); err != nil {
		return "", err
	}

	var lastErr error
	for attempt := 1; attempt <= 2; attempt++ {
		f, err := os.Open(localPath)
		if err != nil {
			return "", err
		}

		req, err := http.NewRequest(http.MethodPut, u.String(), f)
		if err != nil {
			_ = f.Close()
			return "", err
		}
		applyWebdavAuth(req, payload)

		resp, err := webdavClient(verifyTLS).Do(req)
		_ = f.Close()
		if err != nil {
			lastErr = err
			time.Sleep(500 * time.Millisecond)
			continue
		}

		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			body, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
			_ = resp.Body.Close()
			lastErr = fmt.Errorf("webdav upload failed: %d %s", resp.StatusCode, strings.TrimSpace(string(body)))
			time.Sleep(500 * time.Millisecond)
			continue
		}
		_ = resp.Body.Close()

		return u.String(), nil
	}

	return "", lastErr
}

func ensureWebdavCollection(payload map[string]any, baseURL string, remoteFolder string, verifyTLS bool) error {
	remoteFolder = "/" + strings.Trim(strings.TrimSpace(remoteFolder), "/")
	if remoteFolder == "/" {
		return nil
	}

	client := webdavClient(verifyTLS)
	current := ""
	for _, segment := range strings.Split(strings.Trim(remoteFolder, "/"), "/") {
		segment = strings.TrimSpace(segment)
		if segment == "" {
			continue
		}
		current += "/" + url.PathEscape(segment)
		collectionURL := strings.TrimRight(baseURL, "/") + current

		req, err := http.NewRequest("MKCOL", collectionURL, nil)
		if err != nil {
			return err
		}
		applyWebdavAuth(req, payload)

		resp, err := client.Do(req)
		if err != nil {
			return fmt.Errorf("webdav create collection failed: %w", err)
		}
		if resp.StatusCode >= 200 && resp.StatusCode < 300 || resp.StatusCode == http.StatusMethodNotAllowed {
			_ = resp.Body.Close()
			continue
		}
		body, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
		_ = resp.Body.Close()
		return fmt.Errorf("webdav create collection failed: %d %s", resp.StatusCode, strings.TrimSpace(string(body)))
	}

	return nil
}

func downloadBackupFromWebdav(payload map[string]any, remote string) (string, error) {
	verifyTLS := strings.ToLower(payloadNestedValue(payload, "backup_target_config", "verify_tls")) != "false"
	if remote == "" || !hasWebdavCredentials(payload) {
		return "", fmt.Errorf("webdav restore credentials/config missing")
	}

	var lastErr error
	for attempt := 1; attempt <= 2; attempt++ {
		req, err := http.NewRequest(http.MethodGet, remote, nil)
		if err != nil {
			return "", err
		}
		applyWebdavAuth(req, payload)

		resp, err := webdavClient(verifyTLS).Do(req)
		if err != nil {
			lastErr = err
			time.Sleep(500 * time.Millisecond)
			continue
		}

		if resp.StatusCode < 200 || resp.StatusCode >= 300 {
			body, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
			_ = resp.Body.Close()
			lastErr = fmt.Errorf("webdav download failed: %d %s", resp.StatusCode, strings.TrimSpace(string(body)))
			time.Sleep(500 * time.Millisecond)
			continue
		}

		tmp, err := os.CreateTemp("", "easywi-restore-*.tar.gz")
		if err != nil {
			_ = resp.Body.Close()
			return "", err
		}
		if _, err := io.Copy(tmp, resp.Body); err != nil {
			_ = resp.Body.Close()
			_ = tmp.Close()
			return "", err
		}
		_ = resp.Body.Close()
		if err := tmp.Close(); err != nil {
			return "", err
		}

		return tmp.Name(), nil
	}

	return "", lastErr
}
