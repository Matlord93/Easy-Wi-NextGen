package system

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestParseChecksumForAsset(t *testing.T) {
	data := `
# comment
abcd1234 invalid-line
aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  easywi-agent-linux-amd64
bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  easywi-agent-linux-arm64
`

	checksum, err := parseChecksumForAsset(strings.NewReader(data), "easywi-agent-linux-arm64")
	if err != nil {
		t.Fatalf("expected checksum, got error: %v", err)
	}
	if checksum != strings.Repeat("b", 64) {
		t.Fatalf("unexpected checksum: %s", checksum)
	}
}

func TestParseChecksumForAssetMissing(t *testing.T) {
	data := "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  easywi-agent-linux-amd64\n"

	_, err := parseChecksumForAsset(strings.NewReader(data), "easywi-agent-linux-arm64")
	if err == nil {
		t.Fatal("expected error for missing checksum")
	}
}

func TestPrepareUpdateBinaryTarGz(t *testing.T) {
	tmpDir := t.TempDir()
	archive := filepath.Join(tmpDir, "agent.tar.gz")
	createTarGzWithFile(t, archive, "easywi-agent", "linux-binary")

	path, err := prepareUpdateBinary(archive, "easywi-agent-linux-amd64.tar.gz")
	if err != nil {
		t.Fatalf("expected success, got %v", err)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read extracted: %v", err)
	}
	if string(data) != "linux-binary" {
		t.Fatalf("unexpected extracted contents: %q", string(data))
	}
}

func TestPrepareUpdateBinaryZip(t *testing.T) {
	tmpDir := t.TempDir()
	archive := filepath.Join(tmpDir, "agent.zip")
	createZipWithFile(t, archive, "easywi-agent.exe", "windows-binary")

	path, err := prepareUpdateBinary(archive, "easywi-agent-windows-amd64.zip")
	if err != nil {
		t.Fatalf("expected success, got %v", err)
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("read extracted: %v", err)
	}
	if string(data) != "windows-binary" {
		t.Fatalf("unexpected extracted contents: %q", string(data))
	}
}

func createTarGzWithFile(t *testing.T, archivePath, fileName, content string) {
	t.Helper()
	f, err := os.Create(archivePath)
	if err != nil {
		t.Fatalf("create archive: %v", err)
	}
	defer f.Close()

	gz := gzip.NewWriter(f)
	defer gz.Close()

	tw := tar.NewWriter(gz)
	defer tw.Close()

	if err := tw.WriteHeader(&tar.Header{Name: fileName, Mode: 0o755, Size: int64(len(content))}); err != nil {
		t.Fatalf("write tar header: %v", err)
	}
	if _, err := tw.Write([]byte(content)); err != nil {
		t.Fatalf("write tar content: %v", err)
	}
}

func createZipWithFile(t *testing.T, archivePath, fileName, content string) {
	t.Helper()
	f, err := os.Create(archivePath)
	if err != nil {
		t.Fatalf("create archive: %v", err)
	}
	defer f.Close()

	zw := zip.NewWriter(f)
	defer zw.Close()

	entry, err := zw.Create(fileName)
	if err != nil {
		t.Fatalf("create zip entry: %v", err)
	}
	if _, err := entry.Write([]byte(content)); err != nil {
		t.Fatalf("write zip entry: %v", err)
	}
}
