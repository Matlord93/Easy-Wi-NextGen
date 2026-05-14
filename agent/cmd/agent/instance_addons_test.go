package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"

	"easywi/agent/internal/jobs"
)

func TestHandleInstanceAddonInstallCreatesMissingExtractSubdir(t *testing.T) {
	baseDir := t.TempDir()
	instanceDir := filepath.Join(baseDir, "gs24")
	archive := buildAddonTarGz(t, map[string]string{
		"addons/metamod/bin/server.so": "metamod",
	})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write(archive)
	}))
	defer server.Close()

	result, cleanup := handleInstanceAddonInstall(jobs.Job{
		ID: "job-addon-install",
		Payload: map[string]any{
			"instance_dir":          instanceDir,
			"base_dir":              baseDir,
			"plugin_id":             "metamod-source",
			"plugin_name":           "Metamod:Source",
			"plugin_version":        "2.0.0-git1399",
			"plugin_download_url":   server.URL + "/mmsource-2.0.0-git1399-linux.tar.gz",
			"plugin_extract_subdir": "left4dead2",
			"plugin_install_mode":   "extract",
		},
	})
	if cleanup != nil {
		defer func() { _ = cleanup() }()
	}
	if result.Status != "success" {
		t.Fatalf("handleInstanceAddonInstall status=%q output=%v", result.Status, result.Output)
	}

	installedFile := filepath.Join(instanceDir, "left4dead2", "addons", "metamod", "bin", "server.so")
	if data, err := os.ReadFile(installedFile); err != nil || string(data) != "metamod" {
		t.Fatalf("installed file data=%q err=%v", string(data), err)
	}

	manifestData, err := os.ReadFile(addonManifestPath(instanceDir, "metamod-source"))
	if err != nil {
		t.Fatalf("read manifest: %v", err)
	}
	var manifest addonManifest
	if err := json.Unmarshal(manifestData, &manifest); err != nil {
		t.Fatalf("decode manifest: %v", err)
	}
	if len(manifest.Entries) != 1 || manifest.Entries[0] != filepath.Join("left4dead2", "addons", "metamod", "bin", "server.so") {
		t.Fatalf("manifest entries=%v", manifest.Entries)
	}
}

func TestNormalizeAddonExtractSubdirRejectsEscapes(t *testing.T) {
	invalid := []string{"..", "../left4dead2", "/home/gs24/left4dead2", "   "}
	for _, subdir := range invalid {
		if normalized, err := normalizeAddonExtractSubdir(subdir); err == nil {
			t.Fatalf("normalizeAddonExtractSubdir(%q)=%q, want error", subdir, normalized)
		}
	}

	normalized, err := normalizeAddonExtractSubdir("./left4dead2")
	if err != nil {
		t.Fatalf("normalizeAddonExtractSubdir returned error: %v", err)
	}
	if normalized != "left4dead2" {
		t.Fatalf("normalizeAddonExtractSubdir=%q, want left4dead2", normalized)
	}
}

func buildAddonTarGz(t *testing.T, files map[string]string) []byte {
	t.Helper()
	var buf bytes.Buffer
	gzipWriter := gzip.NewWriter(&buf)
	tarWriter := tar.NewWriter(gzipWriter)
	for name, content := range files {
		data := []byte(content)
		if err := tarWriter.WriteHeader(&tar.Header{
			Name: name,
			Mode: 0o644,
			Size: int64(len(data)),
		}); err != nil {
			t.Fatalf("write tar header: %v", err)
		}
		if _, err := tarWriter.Write(data); err != nil {
			t.Fatalf("write tar file: %v", err)
		}
	}
	if err := tarWriter.Close(); err != nil {
		t.Fatalf("close tar writer: %v", err)
	}
	if err := gzipWriter.Close(); err != nil {
		t.Fatalf("close gzip writer: %v", err)
	}
	return buf.Bytes()
}

func TestHandleInstanceAddonRemoveDoesNotDeleteSharedCfgDirectory(t *testing.T) {
	baseDir := t.TempDir()
	instanceDir := filepath.Join(baseDir, "gs24")
	cfgDir := filepath.Join(instanceDir, "left4dead2", "cfg")
	if err := os.MkdirAll(cfgDir, 0o755); err != nil {
		t.Fatalf("create cfg dir: %v", err)
	}
	userConfig := filepath.Join(cfgDir, "server.cfg")
	if err := os.WriteFile(userConfig, []byte("hostname user"), 0o644); err != nil {
		t.Fatalf("write user config: %v", err)
	}
	addonConfig := filepath.Join(cfgDir, "sourcemod.cfg")
	if err := os.WriteFile(addonConfig, []byte("addon config"), 0o644); err != nil {
		t.Fatalf("write addon config: %v", err)
	}

	manifest := addonManifest{
		PluginID: "sourcemod",
		Name:     "SourceMod",
		Version:  "1.12",
		Entries: []string{
			filepath.Join("left4dead2", "cfg"),
			filepath.Join("left4dead2", "cfg", "sourcemod.cfg"),
		},
	}
	if err := writeAddonManifest(instanceDir, "sourcemod", manifest); err != nil {
		t.Fatalf("write manifest: %v", err)
	}

	result, cleanup := handleInstanceAddonRemove(jobs.Job{
		ID: "job-addon-remove",
		Payload: map[string]any{
			"instance_dir": instanceDir,
			"base_dir":     baseDir,
			"plugin_id":    "sourcemod",
		},
	})
	if cleanup != nil {
		defer func() { _ = cleanup() }()
	}
	if result.Status != "success" {
		t.Fatalf("handleInstanceAddonRemove status=%q output=%v", result.Status, result.Output)
	}

	if data, err := os.ReadFile(userConfig); err != nil || string(data) != "hostname user" {
		t.Fatalf("user config data=%q err=%v", string(data), err)
	}
	if _, err := os.Stat(addonConfig); !os.IsNotExist(err) {
		t.Fatalf("addon config still exists or unexpected err=%v", err)
	}
	if _, err := os.Stat(cfgDir); err != nil {
		t.Fatalf("shared cfg dir was removed: %v", err)
	}
}
