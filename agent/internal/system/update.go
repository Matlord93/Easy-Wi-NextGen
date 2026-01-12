package system

import (
	"bufio"
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

	if runtime.GOOS == "windows" {
		return UpdatePlan{
			BinaryPath: binaryPath,
			UpdatePath: tempFile,
		}, nil
	}

	if err := atomicSwap(binaryPath, tempFile); err != nil {
		return UpdatePlan{}, err
	}

	return UpdatePlan{BinaryPath: binaryPath}, nil
}

func downloadToFile(ctx context.Context, downloadURL, target string) error {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, downloadURL, nil)
	if err != nil {
		return fmt.Errorf("build download request: %w", err)
	}

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return fmt.Errorf("download: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download failed: %s", resp.Status)
	}

	file, err := os.Create(target)
	if err != nil {
		return fmt.Errorf("create update file: %w", err)
	}
	defer file.Close()

	if _, err := io.Copy(file, resp.Body); err != nil {
		return fmt.Errorf("write update file: %w", err)
	}

	if err := file.Chmod(0o755); err != nil {
		return fmt.Errorf("chmod update file: %w", err)
	}

	return nil
}

func verifySHA256(path, expected string) error {
	file, err := os.Open(path)
	if err != nil {
		return fmt.Errorf("open update: %w", err)
	}
	defer file.Close()

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

func writeArgsFile() (string, error) {
	file, err := os.CreateTemp("", "easywi-agent-args-*.txt")
	if err != nil {
		return "", fmt.Errorf("create args file: %w", err)
	}
	defer file.Close()

	for _, arg := range os.Args[1:] {
		if _, err := fmt.Fprintln(file, arg); err != nil {
			return "", fmt.Errorf("write args file: %w", err)
		}
	}

	return file.Name(), nil
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
		file.Close()
		return "", fmt.Errorf("write update script: %w", err)
	}
	if err := file.Close(); err != nil {
		return "", fmt.Errorf("close update script: %w", err)
	}
	return file.Name(), nil
}

// RestartOrExit restarts the agent, or exits for supervisors to relaunch it.
func RestartOrExit(binaryPath string) error {
	args := os.Args[1:]
	if runtime.GOOS == "windows" {
		cmd := exec.Command(binaryPath, args...)
		cmd.Stdout = os.Stdout
		cmd.Stderr = os.Stderr
		if err := cmd.Start(); err != nil {
			return fmt.Errorf("restart: %w", err)
		}
		os.Exit(0)
	}

	if os.Getenv("EASYWI_SUPERVISOR") != "" {
		os.Exit(0)
	}

	execCmd := exec.Command(binaryPath, args...)
	execCmd.Stdout = os.Stdout
	execCmd.Stderr = os.Stderr
	return execCmd.Start()
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

func checksumForAsset(checksumsPath, assetName string) (string, error) {
	file, err := os.Open(checksumsPath)
	if err != nil {
		return "", fmt.Errorf("open checksums: %w", err)
	}
	defer file.Close()

	checksum, err := parseChecksumForAsset(file, assetName)
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
