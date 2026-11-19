package main

import (
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

// Delete only the recorded archive after checking its identity, contents and target scope.
func handleInstanceBackupDelete(job jobs.Job) (jobs.Result, func() error) {
	p := job.Payload
	id, archive := payloadValue(p, "instance_id"), payloadValue(p, "backup_path")
	expected := payloadValue(p, "backup_sha256")
	size, err := strconv.ParseInt(payloadValue(p, "size_bytes"), 10, 64)
	if !regexp.MustCompile(`^[0-9]+$`).MatchString(id) || !regexp.MustCompile(`^[a-f0-9]{64}$`).MatchString(expected) || err != nil || size < 0 || size > 1<<50 {
		return failureResult(job.ID, errors.New("recorded backup identity, checksum and size required"))
	}
	kind := payloadValue(p, "backup_target_type")
	switch kind {
	case "webdav", "nextcloud":
		err = deleteRecordedWebdavBackup(p, id, archive, expected, size)
	case "local", "":
		if err = validateLocalBackupDownloadPath(id, archive, p); err == nil {
			err = rentalSafePath(archive)
		}
		if err == nil {
			before, statErr := os.Lstat(archive)
			if os.IsNotExist(statErr) {
				err = nil
			} else if statErr != nil {
				err = statErr
			} else if !before.Mode().IsRegular() {
				err = errors.New("backup is not a regular file")
			} else {
				checksum, actualSize, hashErr := computeFileChecksumAndSize(archive)
				after, afterErr := os.Lstat(archive)
				if hashErr != nil || afterErr != nil || checksum != expected || actualSize != size || !os.SameFile(before, after) || !before.ModTime().Equal(after.ModTime()) || after.Size() != before.Size() {
					err = errors.New("backup changed or does not match recorded proof")
				} else {
					err = os.Remove(archive)
				}
			}
		}
	default:
		err = errors.New("backup destination requires operator review")
	}
	if err != nil {
		return failureResult(job.ID, err)
	}
	return jobs.Result{JobID: job.ID, Status: "success", Output: map[string]string{"backup_id": payloadValue(p, "backup_id"), "deleted": "true"}, Completed: time.Now().UTC()}, nil
}

func rentalSafePath(path string) error {
	for current := filepath.Clean(path); ; current = filepath.Dir(current) {
		info, err := os.Lstat(current)
		if err != nil && !os.IsNotExist(err) {
			return err
		}
		if err == nil && info.Mode()&os.ModeSymlink != 0 {
			return errors.New("backup path must not contain symlinks")
		}
		parent := filepath.Dir(current)
		if parent == current || current == "." {
			break
		}
	}
	return nil
}

func deleteRecordedWebdavBackup(p map[string]any, id, archive, checksum string, size int64) error {
	base, folder := webdavBaseAndFolder(p)
	remote, err := url.Parse(archive)
	if err != nil || remote.User != nil || remote.RawQuery != "" || remote.Fragment != "" {
		return errors.New("invalid backup URL")
	}
	filename := filepath.Base(remote.Path)
	if !strings.HasPrefix(filename, "instance-"+id+"-") || !strings.HasSuffix(filename, ".tar.gz") {
		return errors.New("backup belongs to another instance")
	}
	expectedURL, err := url.Parse(base + webdavEscapedPath(folder) + "/" + url.PathEscape(filename))
	if err != nil || remote.String() != expectedURL.String() || !hasWebdavCredentials(p) {
		return errors.New("backup is outside configured remote target")
	}
	client := webdavClient(strings.ToLower(payloadNestedValue(p, "backup_target_config", "verify_tls")) != "false")
	client.Timeout = 30 * time.Second
	client.CheckRedirect = func(_ *http.Request, _ []*http.Request) error { return http.ErrUseLastResponse }
	req, err := http.NewRequest(http.MethodGet, archive, nil)
	if err != nil {
		return err
	}
	applyWebdavAuth(req, p)
	response, err := client.Do(req)
	if err != nil {
		return err
	}
	if response.StatusCode == http.StatusNotFound {
		_ = response.Body.Close()
		return nil
	}
	if response.StatusCode != http.StatusOK {
		_ = response.Body.Close()
		return fmt.Errorf("backup verification failed: HTTP %d", response.StatusCode)
	}
	etag := response.Header.Get("ETag")
	hash := sha256.New()
	count, readErr := io.Copy(hash, io.LimitReader(response.Body, size+1))
	_ = response.Body.Close()
	if readErr != nil || count != size || hex.EncodeToString(hash.Sum(nil)) != checksum || etag == "" || strings.HasPrefix(etag, "W/") {
		return errors.New("remote backup proof or strong ETag missing/mismatched")
	}
	req, err = http.NewRequest(http.MethodDelete, archive, nil)
	if err != nil {
		return err
	}
	applyWebdavAuth(req, p)
	req.Header.Set("If-Match", etag)
	response, err = client.Do(req)
	if err != nil {
		return err
	}
	defer func() { _ = response.Body.Close() }()
	if response.StatusCode != http.StatusOK && response.StatusCode != http.StatusNoContent && response.StatusCode != http.StatusNotFound {
		return fmt.Errorf("conditional backup deletion failed: HTTP %d", response.StatusCode)
	}
	return nil
}
