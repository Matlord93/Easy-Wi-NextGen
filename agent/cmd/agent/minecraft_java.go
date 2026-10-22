package main

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"
)

var javaProvisionMu sync.Mutex
var javaVersionPattern = regexp.MustCompile(`(?:openjdk|java) version "(?:1\.)?([0-9]+)`)

// A managed job pins a Java major. Never change system alternatives or use an
// unverified system Java simply because it happens to be on PATH.
func ensureManagedMinecraftJava(payload map[string]any) error {
	majorText := payloadValue(payload, "minecraft_java_version")
	if majorText == "" {
		return nil
	} // Legacy/non-Minecraft jobs keep their existing behavior.
	major, err := strconv.Atoi(majorText)
	if err != nil || !supportedMinecraftJava(major) {
		return fmt.Errorf("unsupported Minecraft Java version %q", majorText)
	}
	javaProvisionMu.Lock()
	defer javaProvisionMu.Unlock()
	root := strings.TrimSpace(os.Getenv("EASYWI_JAVA_CACHE_DIR"))
	if root == "" {
		root = "/var/cache/easywi-java"
		if runtime.GOOS == "windows" {
			root = filepath.Join(os.Getenv("ProgramData"), "easywi", "java")
		}
	}
	target := filepath.Join(root, "temurin-"+majorText+"-"+runtime.GOOS+"-"+runtime.GOARCH)
	binary := filepath.Join(target, "bin", "java")
	if runtime.GOOS == "windows" {
		binary += ".exe"
	}
	if err := validateManagedJava(binary, major); err != nil {
		if err := installManagedJava(root, target, major); err != nil {
			return fmt.Errorf("Java %d provisioning failed: %w", major, err)
		}
		if err := validateManagedJava(binary, major); err != nil {
			return err
		}
	}
	payload["java_bin"] = binary
	payload["JAVA_BIN"] = binary
	return nil
}

func supportedMinecraftJava(major int) bool { return major >= 8 && major <= 99 }

func validateManagedJava(binary string, major int) error {
	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()
	output, err := exec.CommandContext(ctx, binary, "-version").CombinedOutput()
	if err != nil {
		return fmt.Errorf("cannot execute managed Java: %w", err)
	}
	match := javaVersionPattern.FindStringSubmatch(string(output))
	if len(match) != 2 || match[1] != strconv.Itoa(major) {
		return fmt.Errorf("Java runtime does not match required major %d", major)
	}
	return nil
}

func installManagedJava(root, target string, major int) error {
	arch := map[string]string{"amd64": "x64", "arm64": "aarch64"}[runtime.GOARCH]
	if arch == "" || (runtime.GOOS != "linux" && runtime.GOOS != "windows") {
		return fmt.Errorf("unsupported Java platform %s/%s", runtime.GOOS, runtime.GOARCH)
	}
	if err := os.MkdirAll(root, 0755); err != nil {
		return err
	}
	if err := os.Chmod(root, 0755); err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Minute)
	defer cancel()
	client := minecraftDownloadClient(8 * time.Minute)
	url := fmt.Sprintf("https://api.adoptium.net/v3/assets/latest/%d/hotspot?os=%s&architecture=%s&image_type=jdk&jvm_impl=hotspot&vendor=eclipse", major, runtime.GOOS, arch)
	req, _ := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("Adoptium metadata returned HTTP %d", resp.StatusCode)
	}
	var assets []struct {
		Binary struct {
			Package struct {
				Link     string `json:"link"`
				Checksum string `json:"checksum"`
			} `json:"package"`
		} `json:"binary"`
	}
	if err := json.NewDecoder(io.LimitReader(resp.Body, 4<<20)).Decode(&assets); err != nil {
		return err
	}
	if len(assets) == 0 {
		return fmt.Errorf("no Temurin JDK for Java %d", major)
	}
	pkg := assets[0].Binary.Package
	staging, err := os.MkdirTemp(root, ".java-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(staging)
	archive := filepath.Join(staging, "runtime.archive")
	if err := downloadVerifiedJava(ctx, client, pkg.Link, pkg.Checksum, archive); err != nil {
		return err
	}
	expanded := filepath.Join(staging, "expanded")
	if err := unpackJavaArchive(archive, expanded, runtime.GOOS == "windows"); err != nil {
		return err
	}
	entries, err := os.ReadDir(expanded)
	if err != nil {
		return err
	}
	if len(entries) != 1 || !entries[0].IsDir() {
		return fmt.Errorf("invalid JDK archive layout")
	}
	candidate := filepath.Join(expanded, entries[0].Name())
	exe := "java"
	if runtime.GOOS == "windows" {
		exe += ".exe"
	}
	if err := validateManagedJava(filepath.Join(candidate, "bin", exe), major); err != nil {
		return err
	}
	// Publish only a fully verified executable JDK. Preserve an invalid old
	// cache for diagnosis and restore it if publication fails.
	if err := os.Chmod(candidate, 0755); err != nil {
		return err
	}
	backup := ""
	if _, err := os.Lstat(target); err == nil {
		backup = fmt.Sprintf("%s.invalid-%d", target, time.Now().UnixNano())
		if err := os.Rename(target, backup); err != nil {
			return err
		}
	}
	if err := os.Rename(candidate, target); err != nil {
		if backup != "" {
			_ = os.Rename(backup, target)
		}
		return err
	}
	return nil

}

func downloadVerifiedJava(ctx context.Context, client *http.Client, url, checksum, path string) error {
	if !strings.HasPrefix(url, "https://") {
		return fmt.Errorf("Java download requires HTTPS")
	}
	expected, err := hex.DecodeString(checksum)
	if err != nil || len(expected) != sha256.Size {
		return fmt.Errorf("missing or invalid Java SHA-256")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("Java download returned HTTP %d", resp.StatusCode)
	}
	f, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0600)
	if err != nil {
		return err
	}
	hash := sha256.New()
	n, copyErr := io.Copy(io.MultiWriter(f, hash), io.LimitReader(resp.Body, (1<<30)+1))
	closeErr := f.Close()
	if copyErr != nil {
		return copyErr
	}
	if closeErr != nil {
		return closeErr
	}
	if n > 1<<30 {
		return fmt.Errorf("Java archive exceeds size limit")
	}
	if !strings.EqualFold(hex.EncodeToString(hash.Sum(nil)), checksum) {
		return fmt.Errorf("Java archive checksum mismatch")
	}
	return nil
}

func javaArchivePath(root, name string) (string, error) {
	name = strings.ReplaceAll(name, "\\", "/")
	if strings.Contains(name, ":") || strings.HasPrefix(name, "/") {
		return "", fmt.Errorf("invalid JDK archive path")
	}
	path := filepath.Join(root, filepath.FromSlash(name))
	rel, err := filepath.Rel(root, path)
	if err != nil || rel == ".." || strings.HasPrefix(rel, ".."+string(os.PathSeparator)) {
		return "", fmt.Errorf("JDK archive path escapes destination")
	}
	return path, nil
}

func unpackJavaArchive(archive, root string, zipped bool) error {
	if err := os.MkdirAll(root, 0755); err != nil {
		return err
	}
	if err := os.Chmod(root, 0755); err != nil {
		return err
	}
	var total int64
	write := func(name string, mode os.FileMode, reader io.Reader) error {
		path, err := javaArchivePath(root, name)
		if err != nil {
			return err
		}
		if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
			return err
		}
		for parent := filepath.Dir(path); strings.HasPrefix(parent, root); parent = filepath.Dir(parent) {
			if err := os.Chmod(parent, 0755); err != nil {
				return err
			}
			if parent == root {
				break
			}
		}
		file, err := os.OpenFile(path, os.O_CREATE|os.O_EXCL|os.O_WRONLY, mode.Perm()&0755)
		if err != nil {
			return err
		}
		n, copyErr := io.Copy(file, io.LimitReader(reader, (2<<30)-total+1))
		total += n
		if err := file.Chmod(mode.Perm() & 0755); err != nil {
			file.Close()
			return err
		}
		closeErr := file.Close()
		if total > 2<<30 {
			return fmt.Errorf("expanded JDK exceeds size limit")
		}
		if copyErr != nil {
			return copyErr
		}
		return closeErr
	}
	if zipped {
		z, err := zip.OpenReader(archive)
		if err != nil {
			return err
		}
		defer z.Close()
		for _, entry := range z.File {
			path, err := javaArchivePath(root, entry.Name)
			if err != nil {
				return err
			}
			if entry.FileInfo().IsDir() {
				if err := os.MkdirAll(path, 0755); err != nil {
					return err
				}
				continue
			}
			if entry.Mode()&os.ModeSymlink != 0 {
				return fmt.Errorf("unsupported link in Java ZIP")
			}
			r, err := entry.Open()
			if err != nil {
				return err
			}
			err = write(entry.Name, 0644, r)
			r.Close()
			if err != nil {
				return err
			}
		}
		return nil
	}
	file, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer file.Close()
	gz, err := gzip.NewReader(file)
	if err != nil {
		return err
	}
	defer gz.Close()
	tr := tar.NewReader(gz)
	for {
		header, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return err
		}
		path, err := javaArchivePath(root, header.Name)
		if err != nil {
			return err
		}
		switch header.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(path, 0755); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := write(header.Name, os.FileMode(header.Mode), tr); err != nil {
				return err
			}
		case tar.TypeSymlink, tar.TypeLink:
			// Temurin's runtime uses regular binaries; optional archive links are not
			// followed or extracted, preventing traversal through linked directories.
		default:
			return fmt.Errorf("unsupported JDK archive entry %s", header.Name)
		}
	}
	return nil
}

func minecraftDownloadClient(timeout time.Duration) *http.Client {
	return &http.Client{Timeout: timeout, CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if req.URL.Scheme != "https" {
			return fmt.Errorf("insecure artifact redirect rejected")
		}
		if len(via) >= 10 {
			return fmt.Errorf("too many artifact redirects")
		}
		return nil
	}}
}
