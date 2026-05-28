package system

import (
	"archive/tar"
	"archive/zip"
	"bufio"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

// UpdateOptions defines the parameters for a self-update operation.
type UpdateOptions struct {
	DownloadURL string
	SHA256      string
}

// UpdateFromChecksumsOptions defines parameters for checksum file updates.
type UpdateFromChecksumsOptions struct {
	DownloadURL  string
	ChecksumsURL string
	SignatureURL string
	AssetName    string
}

// UpdatePlan describes a prepared update for the agent.
type UpdatePlan struct {
	BinaryPath string
	UpdatePath string
}

// ApplyWindowsUpdate finalizes a staged update on Windows and restarts the agent.
func ApplyWindowsUpdate(plan UpdatePlan) error {
	if plan.UpdatePath == "" {
		return fmt.Errorf("missing staged update path")
	}
	return stageWindowsUpdate(plan.BinaryPath, plan.UpdatePath)
}

// SelfUpdate downloads a release binary, verifies it, swaps, and restarts.
func SelfUpdate(ctx context.Context, opts UpdateOptions) error {
	if opts.DownloadURL == "" || opts.SHA256 == "" {
		return fmt.Errorf("missing update parameters")
	}

	binaryPath, err := os.Executable()
	if err != nil {
		return fmt.Errorf("locate executable: %w", err)
	}

	tempDir := filepath.Dir(binaryPath)
	tempFile := filepath.Join(tempDir, "agent.update")
	if err := downloadToFile(ctx, opts.DownloadURL, tempFile); err != nil {
		return err
	}

	if err := verifySHA256(tempFile, opts.SHA256); err != nil {
		return err
	}

	if runtime.GOOS == "windows" {
		return stageWindowsUpdate(binaryPath, tempFile)
	}

	if err := atomicSwap(binaryPath, tempFile); err != nil {
		return err
	}

	return RestartOrExit(binaryPath)
}

// ApplyUpdateFromChecksums downloads and swaps an update using a checksums file.
func ApplyUpdateFromChecksums(ctx context.Context, opts UpdateFromChecksumsOptions) (UpdatePlan, error) {
	if opts.DownloadURL == "" || opts.ChecksumsURL == "" {
		return UpdatePlan{}, fmt.Errorf("missing update parameters")
	}

	binaryPath, err := os.Executable()
	if err != nil {
		return UpdatePlan{}, fmt.Errorf("locate executable: %w", err)
	}

	tempDir := filepath.Dir(binaryPath)
	tempFile := filepath.Join(tempDir, "agent.update")
	if err := downloadToFile(ctx, opts.DownloadURL, tempFile); err != nil {
		return UpdatePlan{}, err
	}

	checksumsFile := filepath.Join(tempDir, "agent.update.checksums")
	if err := downloadToFile(ctx, opts.ChecksumsURL, checksumsFile); err != nil {
		return UpdatePlan{}, err
	}

	if signatureURL := strings.TrimSpace(opts.SignatureURL); signatureURL != "" {
		signatureFile := filepath.Join(tempDir, "agent.update.checksums.asc")
		if err := downloadToFile(ctx, signatureURL, signatureFile); err != nil {
			return UpdatePlan{}, err
		}

		if err := verifyChecksumsSignature(checksumsFile, signatureFile); err != nil {
			return UpdatePlan{}, err
		}
	}

	assetName := opts.AssetName
	if assetName == "" {
		assetName, err = assetNameFromURL(opts.DownloadURL)
		if err != nil {
			return UpdatePlan{}, err
		}
	}

	expectedSHA, err := checksumForAsset(checksumsFile, assetName)
	if err != nil {
		return UpdatePlan{}, err
	}

	if err := verifySHA256(tempFile, expectedSHA); err != nil {
		return UpdatePlan{}, err
	}

	resolvedUpdatePath, err := prepareUpdateBinary(tempFile, assetName)
	if err != nil {
		return UpdatePlan{}, err
	}

	if runtime.GOOS == "windows" {
		return UpdatePlan{
			BinaryPath: binaryPath,
			UpdatePath: resolvedUpdatePath,
		}, nil
	}

	if err := atomicSwap(binaryPath, resolvedUpdatePath); err != nil {
		return UpdatePlan{}, err
	}

	return UpdatePlan{BinaryPath: binaryPath}, nil
}

func prepareUpdateBinary(downloadedPath, assetName string) (string, error) {
	asset := strings.ToLower(strings.TrimSpace(assetName))
	switch {
	case strings.HasSuffix(asset, ".tar.gz"):
		return extractTarGzBinary(downloadedPath)
	case strings.HasSuffix(asset, ".zip"):
		return extractZipBinary(downloadedPath)
	default:
		return downloadedPath, nil
	}
}

func extractTarGzBinary(archivePath string) (string, error) {
	file, err := os.Open(archivePath)
	if err != nil {
		return "", fmt.Errorf("open tar.gz archive: %w", err)
	}
	defer func() {
		_ = file.Close()
	}()

	gzReader, err := gzip.NewReader(file)
	if err != nil {
		return "", fmt.Errorf("open gzip reader: %w", err)
	}
	defer func() {
		_ = gzReader.Close()
	}()

	tarReader := tar.NewReader(gzReader)
	for {
		header, err := tarReader.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return "", fmt.Errorf("read tar archive: %w", err)
		}
		if header.Typeflag != tar.TypeReg {
			continue
		}
		name := path.Base(header.Name)
		if name != "easywi-agent" && !strings.HasPrefix(name, "easywi-agent-") {
			continue
		}
		extractedPath := archivePath + ".extracted"
		out, err := os.OpenFile(extractedPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
		if err != nil {
			return "", fmt.Errorf("create extracted binary: %w", err)
		}
		if _, err := io.Copy(out, tarReader); err != nil {
			_ = out.Close()
			return "", fmt.Errorf("write extracted binary: %w", err)
		}
		if err := out.Close(); err != nil {
			return "", fmt.Errorf("close extracted binary: %w", err)
		}
		return extractedPath, nil
	}

	return "", fmt.Errorf("tar.gz archive does not contain agent binary")
}

func extractZipBinary(archivePath string) (string, error) {
	reader, err := zip.OpenReader(archivePath)
	if err != nil {
		return "", fmt.Errorf("open zip archive: %w", err)
	}
	defer func() {
		_ = reader.Close()
	}()

	for _, file := range reader.File {
		if file.FileInfo().IsDir() {
			continue
		}
		name := strings.ToLower(path.Base(file.Name))
		if name != "easywi-agent.exe" && name != "easywi-agent" {
			continue
		}
		src, err := file.Open()
		if err != nil {
			return "", fmt.Errorf("open zip file: %w", err)
		}
		defer func() {
			_ = src.Close()
		}()
		extractedPath := archivePath + ".extracted"
		out, err := os.OpenFile(extractedPath, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o755)
		if err != nil {
			return "", fmt.Errorf("create extracted binary: %w", err)
		}
		if _, err := io.Copy(out, src); err != nil {
			_ = out.Close()
			return "", fmt.Errorf("write extracted binary: %w", err)
		}
		if err := out.Close(); err != nil {
			return "", fmt.Errorf("close extracted binary: %w", err)
		}
		return extractedPath, nil
	}

	return "", fmt.Errorf("zip archive does not contain agent binary")
}
func downloadToFile(ctx context.Context, downloadURL, target string) (err error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, downloadURL, nil)
	if err != nil {
		return fmt.Errorf("build download request: %w", err)
	}

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	defer func() {
		if closeErr := resp.Body.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close download response: %w", closeErr)
		}
	}()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download failed: %s", resp.Status)
	}

	file, err := os.Create(target)
	if err != nil {
		return fmt.Errorf("create update file: %w", err)
	}
	defer func() {
		if closeErr := file.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close update file: %w", closeErr)
		}
	}()

	if _, err := io.Copy(file, resp.Body); err != nil {
		return fmt.Errorf("write update file: %w", err)
	}
	if err := file.Sync(); err != nil {
		return fmt.Errorf("sync update file: %w", err)
	}
	releaseFileFromPageCache(file)

	if err := file.Chmod(0o755); err != nil {
		return fmt.Errorf("chmod update file: %w", err)
	}

	return nil
}

func verifySHA256(path, expected string) (err error) {
	file, err := os.Open(path)
	if err != nil {
		return fmt.Errorf("open update: %w", err)
	}
	defer func() {
		if closeErr := file.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close update: %w", closeErr)
		}
	}()

	hash := sha256.New()
	if _, err := io.Copy(hash, file); err != nil {
		return fmt.Errorf("hash update: %w", err)
	}

	actual := hex.EncodeToString(hash.Sum(nil))
	if actual != expected {
		return fmt.Errorf("sha256 mismatch: expected %s got %s", expected, actual)
	}
	return nil
}

func atomicSwap(binaryPath, updatePath string) error {
	backupPath := binaryPath + ".bak"
	_ = os.Remove(backupPath)
	if err := os.Rename(binaryPath, backupPath); err != nil {
		return fmt.Errorf("backup binary: %w", err)
	}
	if err := os.Rename(updatePath, binaryPath); err != nil {
		_ = os.Rename(backupPath, binaryPath)
		return fmt.Errorf("swap binary: %w", err)
	}
	_ = os.Remove(backupPath)
	return nil
}

func stageWindowsUpdate(binaryPath, updatePath string) error {
	backupPath := binaryPath + ".bak"
	argsPath, err := writeArgsFile()
	if err != nil {
		return err
	}

	scriptPath, err := writeWindowsUpdateScript()
	if err != nil {
		return err
	}

	cmd := exec.Command(
		"powershell",
		"-NoProfile",
		"-ExecutionPolicy",
		"Bypass",
		"-File",
		scriptPath,
		"-BinaryPath",
		binaryPath,
		"-UpdatePath",
		updatePath,
		"-BackupPath",
		backupPath,
		"-ArgsPath",
		argsPath,
	)
	cmd.Stdout = os.Stdout
	cmd.Stderr = os.Stderr
	if err := cmd.Start(); err != nil {
		return fmt.Errorf("start update script: %w", err)
	}
	time.Sleep(200 * time.Millisecond)
	os.Exit(0)
	return nil
}

func writeArgsFile() (path string, err error) {
	file, err := os.CreateTemp("", "easywi-agent-args-*.txt")
	if err != nil {
		return "", fmt.Errorf("create args file: %w", err)
	}
	defer func() {
		if closeErr := file.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close args file: %w", closeErr)
		}
	}()

	for _, arg := range os.Args[1:] {
		if _, err := fmt.Fprintln(file, arg); err != nil {
			return "", fmt.Errorf("write args file: %w", err)
		}
	}

	path = file.Name()
	return path, nil
}

func writeWindowsUpdateScript() (string, error) {
	script := `param(
  [string]$BinaryPath,
  [string]$UpdatePath,
  [string]$BackupPath,
  [string]$ArgsPath
)
$ErrorActionPreference = "Stop"
Start-Sleep -Seconds 1
if (Test-Path $BackupPath) {
  Remove-Item -Path $BackupPath -Force
}
try {
  Rename-Item -Path $BinaryPath -NewName $BackupPath
  Rename-Item -Path $UpdatePath -NewName $BinaryPath
} catch {
  if (Test-Path $BackupPath) {
    Rename-Item -Path $BackupPath -NewName $BinaryPath -Force
  }
  throw
}
$argsList = @()
if (Test-Path $ArgsPath) {
  $argsList = Get-Content -Path $ArgsPath
  Remove-Item -Path $ArgsPath -Force
}
Start-Process -FilePath $BinaryPath -ArgumentList $argsList
`
	file, err := os.CreateTemp("", "easywi-agent-update-*.ps1")
	if err != nil {
		return "", fmt.Errorf("create update script: %w", err)
	}
	if _, err := file.WriteString(script); err != nil {
		if closeErr := file.Close(); closeErr != nil {
			return "", fmt.Errorf("write update script: %w; close update script: %v", err, closeErr)
		}
		return "", fmt.Errorf("write update script: %w", err)
	}
	if err := file.Close(); err != nil {
		return "", fmt.Errorf("close update script: %w", err)
	}
	return file.Name(), nil
}

// RestartOrExit restarts the agent, or exits for supervisors to relaunch it.
type restartMode int

const (
	restartModeManual restartMode = iota
	restartModeSystemd
	restartModeSupervisorExit
)

type restartPlan struct {
	mode restartMode
	name string
	args []string
}

func planRestart(binaryPath string, args []string, goos string, supervisor bool, systemdActive bool) restartPlan {
	if goos == "windows" {
		return restartPlan{mode: restartModeManual, name: binaryPath, args: args}
	}
	if systemdActive {
		return restartPlan{mode: restartModeSystemd, name: "systemctl", args: []string{"restart", "--no-block", "easywi-agent.service"}}
	}
	if supervisor {
		return restartPlan{mode: restartModeSupervisorExit}
	}
	return restartPlan{mode: restartModeManual, name: binaryPath, args: args}
}

func RestartOrExit(binaryPath string) error {
	args := os.Args[1:]
	plan := planRestart(binaryPath, args, runtime.GOOS, os.Getenv("EASYWI_SUPERVISOR") != "", IsSystemdServiceActive("easywi-agent.service"))
	switch plan.mode {
	case restartModeSystemd:
		if err := CleanupStaleAgentProcesses("easywi-agent.service"); err != nil {
			return err
		}
		cmd := exec.Command(plan.name, plan.args...)
		if err := cmd.Run(); err != nil {
			return fmt.Errorf("systemd restart easywi-agent.service: %w", err)
		}
		os.Exit(0)
	case restartModeSupervisorExit:
		os.Exit(0)
	case restartModeManual:
		cmd := exec.Command(plan.name, plan.args...)
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr
		if err := cmd.Start(); err != nil {
			return fmt.Errorf("restart: %w", err)
		}
		os.Exit(0)
	}
	return nil
}

func IsSystemdServiceActive(service string) bool {
	if runtime.GOOS != "linux" {
		return false
	}
	if _, err := exec.LookPath("systemctl"); err != nil {
		return false
	}
	return exec.Command("systemctl", "is-active", "--quiet", service).Run() == nil
}

func assetNameFromURL(downloadURL string) (string, error) {
	parsed, err := url.Parse(downloadURL)
	if err != nil {
		return "", fmt.Errorf("parse download url: %w", err)
	}
	name := path.Base(parsed.Path)
	if name == "." || name == "/" || name == "" {
		return "", fmt.Errorf("invalid download url path: %s", downloadURL)
	}
	return name, nil
}

func checksumForAsset(checksumsPath, assetName string) (checksum string, err error) {
	file, err := os.Open(checksumsPath)
	if err != nil {
		return "", fmt.Errorf("open checksums: %w", err)
	}
	defer func() {
		if closeErr := file.Close(); closeErr != nil && err == nil {
			err = fmt.Errorf("close checksums: %w", closeErr)
		}
	}()

	checksum, err = parseChecksumForAsset(file, assetName)
	if err != nil {
		return "", err
	}
	return checksum, nil
}

func parseChecksumForAsset(r io.Reader, assetName string) (string, error) {
	scanner := bufio.NewScanner(r)
	for scanner.Scan() {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		fields := strings.Fields(line)
		if len(fields) < 2 {
			continue
		}
		if fields[1] != assetName {
			continue
		}
		if len(fields[0]) != 64 {
			return "", fmt.Errorf("invalid checksum for %s", assetName)
		}
		return fields[0], nil
	}
	if err := scanner.Err(); err != nil {
		return "", fmt.Errorf("scan checksums: %w", err)
	}
	return "", fmt.Errorf("checksum for %s not found", assetName)
}
