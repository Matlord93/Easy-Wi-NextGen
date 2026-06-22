package main

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"

	"easywi/agent/internal/jobs"
)

const (
	teamspeakBackendStatusSDKClientNotInstalled   = "sdk_client_not_installed"
	teamspeakBackendStatusSDKClientDownloadFailed = "sdk_client_download_failed"
	teamspeakBackendStatusSDKClientChecksumFailed = "sdk_client_checksum_failed"
	teamspeakBackendStatusSDKClientInstalled      = "sdk_client_installed"
	teamspeakBackendStatusSDKClientLibraryMissing = "sdk_client_installed_library_missing"
	teamspeakBackendStatusSDKClientInvalid        = "sdk_client_invalid"
)

const teamspeakSDKClientDownloadTimeout = 120 * time.Second
const teamspeakSDKClientMaxDownloadBytes int64 = 100 * 1024 * 1024
const teamspeakSDKTarMaxFiles = 2000
const teamspeakSDKTarMaxBytes int64 = 200 * 1024 * 1024

var teamspeakSDKClientAllowedHosts = map[string]bool{
	"files.teamspeak-services.com": true,
}

var teamspeakSDKHTTPClient = &http.Client{
	Timeout: teamspeakSDKClientDownloadTimeout,
	CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if len(via) >= 5 {
			return errors.New("too many redirects")
		}
		return validateTeamspeakSDKClientURL(req.URL.String())
	},
	Transport: &http.Transport{
		DialContext: (&net.Dialer{
			Timeout:   15 * time.Second,
			KeepAlive: 30 * time.Second,
		}).DialContext,
		TLSHandshakeTimeout:   15 * time.Second,
		ResponseHeaderTimeout: 30 * time.Second,
	},
}

type teamspeakSDKClientConfig struct {
	NodeID               string
	Version              string
	DownloadURL          string
	ExpectedSHA256       string
	InstallPath          string
	RequestedBy          string
	AcceptedConfirmation bool
	InstallDependencies  bool
	RebuildBackendBinary bool
	BinarySourcePath     string
}

func teamspeakSDKClientConfigFromJob(job jobs.Job) teamspeakSDKClientConfig {
	return teamspeakSDKClientConfig{
		NodeID:               strings.TrimSpace(payloadValue(job.Payload, "node_id")),
		Version:              strings.TrimSpace(payloadValue(job.Payload, "version")),
		DownloadURL:          strings.TrimSpace(payloadValue(job.Payload, "download_url")),
		ExpectedSHA256:       strings.TrimSpace(payloadValue(job.Payload, "expected_sha256")),
		InstallPath:          strings.TrimSpace(payloadValue(job.Payload, "install_path")),
		RequestedBy:          strings.TrimSpace(payloadValue(job.Payload, "requested_by")),
		AcceptedConfirmation: payloadBool(job.Payload, "accepted_license_confirmation"),
		InstallDependencies:  payloadBool(job.Payload, "install_dependencies"),
		RebuildBackendBinary: payloadBool(job.Payload, "rebuild_backend_binary"),
		BinarySourcePath:     strings.TrimSpace(payloadValue(job.Payload, "binary_source_path")),
	}
}

func handleMusicbotTeamspeakBackendInstallSDKClient(job jobs.Job) orchestratorResult {
	cfg := teamspeakSDKClientConfigFromJob(job)
	result := map[string]any{
		"status":          teamspeakBackendStatusSDKClientNotInstalled,
		"version":         cfg.Version,
		"download_url":    cfg.DownloadURL,
		"install_path":    cfg.InstallPath,
		"requested_by":    cfg.RequestedBy,
		"last_checked_at": time.Now().UTC().Format(time.RFC3339),
	}

	if !cfg.AcceptedConfirmation {
		result["status"] = teamspeakBackendStatusSDKClientInvalid
		result["last_error"] = "accepted_license_confirmation=true is required"
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}

	if err := validateTeamspeakSDKClientURL(cfg.DownloadURL); err != nil {
		result["status"] = teamspeakBackendStatusSDKClientInvalid
		result["last_error"] = err.Error()
		return orchestratorResult{status: "failed", errorText: err.Error(), resultPayload: result}
	}

	installPath, err := validateTeamspeakSDKInstallPath(cfg.InstallPath)
	if err != nil {
		result["status"] = teamspeakBackendStatusSDKClientInvalid
		result["last_error"] = err.Error()
		return orchestratorResult{status: "failed", errorText: err.Error(), resultPayload: result}
	}
	result["install_path"] = installPath

	if err := os.MkdirAll(installPath, 0o755); err != nil {
		result["status"] = teamspeakBackendStatusSDKClientInvalid
		result["last_error"] = fmt.Sprintf("create sdk install_path: %v", err)
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}

	tmpDir, err := os.MkdirTemp("", "easywi-ts3-sdk-*")
	if err != nil {
		result["status"] = teamspeakBackendStatusSDKClientInvalid
		result["last_error"] = fmt.Sprintf("create temp dir: %v", err)
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	defer func() { _ = os.RemoveAll(tmpDir) }()

	tarballPath := filepath.Join(tmpDir, "teamspeak-sdk.tar.gz")
	checksum, err := downloadTeamspeakSDKClient(cfg.DownloadURL, tarballPath)
	if err != nil {
		result["status"] = teamspeakBackendStatusSDKClientDownloadFailed
		result["last_error"] = err.Error()
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}
	result["checksum"] = checksum

	if cfg.ExpectedSHA256 != "" && !strings.EqualFold(cfg.ExpectedSHA256, checksum) {
		result["status"] = teamspeakBackendStatusSDKClientChecksumFailed
		result["last_error"] = "TeamSpeak SDK tarball checksum mismatch"
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}

	if err := extractTeamspeakSDKTarball(tarballPath, installPath); err != nil {
		result["status"] = teamspeakBackendStatusSDKClientInvalid
		result["last_error"] = fmt.Sprintf("extract SDK tarball: %v", err)
		return orchestratorResult{status: "failed", errorText: result["last_error"].(string), resultPayload: result}
	}

	if cfg.InstallDependencies {
		if depErr := installTeamspeakSDKDependencies(); depErr != nil {
			result["dependency_install_warning"] = depErr.Error()
		}
	}

	sdkLibPath := findFirstExistingFile(installPath, []string{"libteamspeak_sdk_client.so", "libts3client.so"})
	symlinkCreated := false
	if sdkLibPath != "" && filepath.Base(sdkLibPath) == "libteamspeak_sdk_client.so" {
		symlinkTarget := filepath.Join(filepath.Dir(sdkLibPath), "libts3client.so")
		if _, statErr := os.Lstat(symlinkTarget); os.IsNotExist(statErr) {
			if linkErr := os.Symlink(sdkLibPath, symlinkTarget); linkErr == nil {
				symlinkCreated = true
				sdkLibPath = symlinkTarget
			}
		} else {
			sdkLibPath = symlinkTarget
		}
	}

	result["sdk_client_last_installed_at"] = time.Now().UTC().Format(time.RFC3339)
	result["sdk_client_install_path"] = installPath
	result["sdk_symlink_created"] = symlinkCreated

	if sdkLibPath == "" {
		msg := "TeamSpeak SDK wurde entpackt, aber keine ladbare libteamspeak_sdk_client.so oder libts3client.so gefunden."
		result["status"] = teamspeakBackendStatusSDKClientLibraryMissing
		result["last_error"] = msg
		return orchestratorResult{status: "failed", errorText: msg, resultPayload: result}
	}

	result["library_path"] = sdkLibPath
	result["status"] = teamspeakBackendStatusSDKClientInstalled
	result["backend_type_suggestion"] = "client_library"

	if cfg.RebuildBackendBinary && cfg.BinarySourcePath != "" {
		if buildErr := buildTeamspeakClientBinary(cfg.BinarySourcePath, sdkLibPath); buildErr != nil {
			result["rebuild_warning"] = buildErr.Error()
		}
	}

	if info, err := os.Stat("/usr/local/bin/easywi-teamspeak-client"); err == nil && !info.IsDir() && info.Mode().Perm()&0o111 != 0 {
		result["backend_path_suggestion"] = "/usr/local/bin/easywi-teamspeak-client"
	}

	return orchestratorResult{status: "success", resultPayload: result}
}

func validateTeamspeakSDKClientURL(rawURL string) error {
	if strings.TrimSpace(rawURL) == "" {
		return errors.New("download_url is required")
	}
	parsed, err := url.Parse(rawURL)
	if err != nil {
		return fmt.Errorf("invalid download_url: %v", err)
	}
	if parsed.Scheme != "https" {
		return errors.New("download_url must use https")
	}
	host := parsed.Hostname()
	if !teamspeakSDKClientAllowedHosts[host] {
		return fmt.Errorf("download_url host %q is not allowed; only files.teamspeak-services.com is permitted", host)
	}
	return nil
}

func validateTeamspeakSDKInstallPath(path string) (string, error) {
	if strings.TrimSpace(path) == "" {
		return "", errors.New("install_path is required")
	}
	if strings.ContainsAny(path, "\x00\n\r;$`&|<>") {
		return "", errors.New("install_path contains invalid characters")
	}
	if !filepath.IsAbs(path) {
		return "", errors.New("install_path must be an absolute path")
	}
	clean := filepath.Clean(path)
	for _, forbidden := range []string{"/", "/etc", "/bin", "/sbin", "/usr", "/lib", "/lib64", "/boot", "/proc", "/sys", "/dev", "/run", "/tmp", "/root"} {
		if clean == forbidden {
			return "", fmt.Errorf("install_path %q is not allowed", clean)
		}
	}
	return clean, nil
}

func downloadTeamspeakSDKClient(downloadURL string, dest string) (string, error) {
	ctx, cancel := context.WithTimeout(context.Background(), teamspeakSDKClientDownloadTimeout)
	defer cancel()

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, downloadURL, nil)
	if err != nil {
		return "", fmt.Errorf("create request: %v", err)
	}
	req.Header.Set("User-Agent", "easywi-agent/1.0")

	resp, err := teamspeakSDKHTTPClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("download SDK: %v", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("download SDK: HTTP %d", resp.StatusCode)
	}

	f, err := os.Create(dest)
	if err != nil {
		return "", fmt.Errorf("create dest file: %v", err)
	}
	defer func() { _ = f.Close() }()

	h := sha256.New()
	limited := io.LimitReader(resp.Body, teamspeakSDKClientMaxDownloadBytes+1)
	n, err := io.Copy(io.MultiWriter(f, h), limited)
	if err != nil {
		return "", fmt.Errorf("write SDK tarball: %v", err)
	}
	if n > teamspeakSDKClientMaxDownloadBytes {
		return "", fmt.Errorf("SDK tarball exceeds maximum size of %d bytes", teamspeakSDKClientMaxDownloadBytes)
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}

func extractTeamspeakSDKTarball(tarballPath string, destDir string) error {
	f, err := os.Open(tarballPath)
	if err != nil {
		return fmt.Errorf("open tarball: %v", err)
	}
	defer func() { _ = f.Close() }()

	gz, err := gzip.NewReader(f)
	if err != nil {
		return fmt.Errorf("gzip reader: %v", err)
	}
	defer func() { _ = gz.Close() }()

	tr := tar.NewReader(gz)
	var fileCount int64
	var totalBytes int64
	cleanDest := filepath.Clean(destDir)

	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return fmt.Errorf("read tar entry: %v", err)
		}

		if hdr.Typeflag == tar.TypeSymlink || hdr.Typeflag == tar.TypeLink {
			// Strip one path component (SDK puts files in a top-level dir)
			name := stripSDKTarTopDir(hdr.Name)
			if name == "" {
				continue
			}
			target := hdr.Linkname
			// Only allow relative symlinks within destDir
			if filepath.IsAbs(target) {
				return fmt.Errorf("absolute symlink target %q is not allowed", target)
			}
			linkPath := filepath.Clean(filepath.Join(cleanDest, name))
			if !strings.HasPrefix(linkPath, cleanDest+string(filepath.Separator)) && linkPath != cleanDest {
				return fmt.Errorf("path traversal in symlink %q", hdr.Name)
			}
			// Check resolved target stays within destDir
			resolvedTarget := filepath.Clean(filepath.Join(filepath.Dir(linkPath), target))
			if !strings.HasPrefix(resolvedTarget, cleanDest+string(filepath.Separator)) && resolvedTarget != cleanDest {
				return fmt.Errorf("symlink %q points outside install directory", hdr.Name)
			}
			if err := os.MkdirAll(filepath.Dir(linkPath), 0o755); err != nil {
				return fmt.Errorf("mkdir for symlink %q: %v", name, err)
			}
			_ = os.Remove(linkPath)
			if err := os.Symlink(target, linkPath); err != nil {
				return fmt.Errorf("create symlink %q: %v", linkPath, err)
			}
			continue
		}

		if hdr.Typeflag != tar.TypeReg && hdr.Typeflag != tar.TypeDir {
			continue
		}

		name := stripSDKTarTopDir(hdr.Name)
		if name == "" {
			continue
		}

		target := filepath.Clean(filepath.Join(cleanDest, name))
		if !strings.HasPrefix(target, cleanDest+string(filepath.Separator)) && target != cleanDest {
			return fmt.Errorf("path traversal detected in tar entry %q", hdr.Name)
		}

		if hdr.Typeflag == tar.TypeDir {
			if err := os.MkdirAll(target, 0o755); err != nil {
				return fmt.Errorf("mkdir %q: %v", target, err)
			}
			continue
		}

		fileCount++
		if fileCount > teamspeakSDKTarMaxFiles {
			return fmt.Errorf("tar archive exceeds maximum file count of %d", teamspeakSDKTarMaxFiles)
		}

		if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
			return fmt.Errorf("mkdir for %q: %v", target, err)
		}

		perm := hdr.FileInfo().Mode().Perm()
		// Allow only r/w/x bits; force owner-readable
		perm = (perm & 0o755) | 0o400
		outFile, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, perm)
		if err != nil {
			return fmt.Errorf("create %q: %v", target, err)
		}
		limited := io.LimitReader(tr, teamspeakSDKTarMaxBytes-totalBytes+1)
		n, copyErr := io.Copy(outFile, limited)
		_ = outFile.Close()
		if copyErr != nil {
			return fmt.Errorf("write %q: %v", target, copyErr)
		}
		totalBytes += n
		if totalBytes > teamspeakSDKTarMaxBytes {
			return fmt.Errorf("tar archive exceeds maximum uncompressed size of %d bytes", teamspeakSDKTarMaxBytes)
		}
	}

	return nil
}

// stripSDKTarTopDir removes the first path component so SDK archives like
// "teamspeak-sdk-3.5.2/lib/libteamspeak_sdk_client.so" are extracted flat.
func stripSDKTarTopDir(name string) string {
	name = filepath.Clean(name)
	if name == "." {
		return ""
	}
	parts := strings.SplitN(name, string(filepath.Separator), 2)
	if len(parts) < 2 {
		return ""
	}
	return parts[1]
}

func installTeamspeakSDKDependencies() error {
	deps := []string{"libopus0", "libglib2.0-0"}
	managers := []struct {
		bin  string
		args []string
	}{
		{"apt-get", []string{"install", "-y", "--no-install-recommends"}},
		{"dnf", []string{"install", "-y"}},
		{"yum", []string{"install", "-y"}},
	}

	for _, mgr := range managers {
		if _, err := exec.LookPath(mgr.bin); err != nil {
			continue
		}
		ctx, cancel := context.WithTimeout(context.Background(), 120*time.Second)
		args := append(mgr.args, deps...)
		cmd := exec.CommandContext(ctx, mgr.bin, args...)
		err := cmd.Run()
		cancel()
		if err != nil {
			return fmt.Errorf("%s install: %v", mgr.bin, err)
		}
		return nil
	}
	return errors.New("no supported package manager found (apt-get, dnf, yum)")
}

func buildTeamspeakClientBinary(sourceDir string, sdkLibPath string) error {
	if strings.TrimSpace(sourceDir) == "" {
		return errors.New("binary_source_path is required for rebuild")
	}
	if strings.ContainsAny(sourceDir, "\x00\n\r;$`&|<>") {
		return errors.New("binary_source_path contains invalid characters")
	}
	if !filepath.IsAbs(sourceDir) {
		return errors.New("binary_source_path must be absolute")
	}
	if _, err := exec.LookPath("go"); err != nil {
		return errors.New("go toolchain not found; cannot rebuild easywi-teamspeak-client")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Second)
	defer cancel()

	libDir := filepath.Dir(sdkLibPath)
	cmd := exec.CommandContext(ctx, "go", "build", "-tags", "ts3clientlib", "-o", "/usr/local/bin/easywi-teamspeak-client", ".")
	cmd.Dir = sourceDir
	cmd.Env = append(os.Environ(),
		"CGO_ENABLED=1",
		fmt.Sprintf("CGO_LDFLAGS=-L%s -Wl,-rpath,%s", libDir, libDir),
	)
	out, err := cmd.CombinedOutput()
	if err != nil {
		msg := strings.TrimSpace(string(out))
		if len(msg) > 500 {
			msg = msg[:500]
		}
		return fmt.Errorf("go build failed: %v\n%s", err, msg)
	}
	return nil
}
