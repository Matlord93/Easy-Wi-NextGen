package main

import (
	"archive/tar"
	"archive/zip"
	"compress/gzip"
	"context"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func writeMinecraftTestJar(t *testing.T, path string, major uint16) {
	t.Helper()
	f, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	z := zip.NewWriter(f)
	w, err := z.Create("net/minecraft/Main.class")
	if err != nil {
		t.Fatal(err)
	}
	if _, err = w.Write([]byte{0xca, 0xfe, 0xba, 0xbe, 0, 0, byte((major + 44) >> 8), byte(major + 44)}); err != nil {
		t.Fatal(err)
	}
	if err := z.Close(); err != nil {
		t.Fatal(err)
	}
	if err := f.Close(); err != nil {
		t.Fatal(err)
	}
}

func TestManagedMinecraftPreflightRestoresReservedPortsAndPreservesGameSettings(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("Linux service preflight")
	}
	dir := t.TempDir()
	java := filepath.Join(dir, "java")
	if err := os.WriteFile(java, []byte("#!/bin/sh\necho 'openjdk version \"21.0.1\"' >&2\n"), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(java, 0755); err != nil {
		t.Fatal(err)
	}
	writeMinecraftTestJar(t, filepath.Join(dir, "server.jar"), 21)
	raw := "# customer settings\nmotd=My world\ndifficulty=hard\nserver-port=1\nserver-port:2\nserver-ip=203.0.113.42\n"
	if err := os.WriteFile(filepath.Join(dir, "server.properties"), []byte(raw), 0644); err != nil {
		t.Fatal(err)
	}
	payload := makeMinecraftPayload("minecraft_paper_all", map[string]any{"minecraft_managed": "true", "minecraft_channel": "paper", "minecraft_java_version": "21", "java_bin": java, "node_ip": "203.0.113.42"})
	payload["secrets"] = []any{}
	payload["env_vars"] = map[string]any{"SERVER_NAME": "Default name", "MAX_PLAYERS": "20", "RCON_PASSWORD": ""}
	manifest := minecraftPreflightManifest{Directory: dir, Payload: payload}
	data, _ := json.Marshal(manifest)
	path := filepath.Join(dir, "manifest.json")
	if err := os.WriteFile(path, data, 0600); err != nil {
		t.Fatal(err)
	}
	for i := 0; i < 2; i++ {
		if err := runMinecraftPreflight(path); err != nil {
			t.Fatal(err)
		}
	}
	content := mustReadFile(t, filepath.Join(dir, "server.properties"))
	for _, want := range []string{"motd=My world", "difficulty=hard", "server-port=25565", "server-ip=", "enable-query=false", "enable-rcon=false", "# customer settings"} {
		if !strings.Contains(content, want) {
			t.Fatalf("missing %q in %s", want, content)
		}
	}
	if strings.Count(content, "server-port=") != 1 || strings.Contains(content, "server-port:") {
		t.Fatalf("duplicate managed port in %s", content)
	}
}

func TestManagedMinecraftBedrockUsesDistinctReservedIPv6Port(t *testing.T) {
	dir := t.TempDir()
	payload := makeBedrockPayload(map[string]any{"minecraft_managed": "true", "required_ports": "game,gamev6", "ports": []any{19132, 19134}})
	if err := ensureMinecraftServerProperties(dir, payload); err != nil {
		t.Fatal(err)
	}
	content := mustReadFile(t, filepath.Join(dir, "server.properties"))
	assertProperty(t, content, "server-port", "19132")
	assertProperty(t, content, "server-portv6", "19134")
	assertProperty(t, content, "transport", "raknet")
	assertProperty(t, content, "enable-lan-visibility", "false")
}

func TestManagedMinecraftBedrockNetherNetConstrainsGameplayPorts(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, "server.properties"), []byte("transport=nethernet\nserver-udp-ports=49152-65535\n"), 0644)
	payload := makeBedrockPayload(map[string]any{"minecraft_managed": "true", "required_ports": "game,gamev6", "ports": []any{19132, 19134}})
	if err := ensureMinecraftServerProperties(dir, payload); err != nil {
		t.Fatal(err)
	}
	content := mustReadFile(t, filepath.Join(dir, "server.properties"))
	assertProperty(t, content, "transport", "nethernet")
	assertProperty(t, content, "server-udp-ports", "19134")
	payload["minecraft_bedrock_transport"] = "raknet"
	if err := ensureMinecraftServerProperties(dir, payload); err != nil {
		t.Fatal(err)
	}
	assertProperty(t, mustReadFile(t, filepath.Join(dir, "server.properties")), "transport", "raknet")
	assertProperty(t, content, "enable-lan-visibility", "false")
}

func TestManagedMinecraftRequiresAnAllocatedPort(t *testing.T) {
	payload := map[string]any{"game_key": "minecraft_vanilla_all", "minecraft_managed": "true"}
	if err := ensureMinecraftServerProperties(t.TempDir(), payload); err == nil {
		t.Fatal("unallocated port accepted")
	}
}

func TestManagedMinecraftBungeePreservesBackendsAndUsesProxyConfig(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "config.yml")
	if err := os.WriteFile(path, []byte("# keep me\nlisteners:\n- host: 0.0.0.0:1\n  motd: Custom proxy\n  priorities: [survival]\nservers:\n  survival:\n    address: 10.0.0.5:25566\nonline_mode: true\n"), 0644); err != nil {
		t.Fatal(err)
	}
	payload := makeMinecraftPayload("minecraft_bungeecord_all", map[string]any{"minecraft_managed": "true", "minecraft_channel": "bungeecord"})
	if err := ensureMinecraftServerProperties(dir, payload); err != nil {
		t.Fatal(err)
	}
	content := mustReadFile(t, path)
	for _, want := range []string{"# keep me", "0.0.0.0:25565", "Custom proxy", "10.0.0.5:25566", "query_enabled: false"} {
		if !strings.Contains(content, want) {
			t.Fatalf("missing %q in %s", want, content)
		}
	}
	if _, err := os.Stat(filepath.Join(dir, "server.properties")); !os.IsNotExist(err) {
		t.Fatal("proxy must not write server.properties")
	}
	if err := ensureMinecraftEula(dir, payload); err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(dir, "eula.txt")); !os.IsNotExist(err) {
		t.Fatal("proxy must not write Minecraft EULA")
	}
}

func TestManagedMinecraftDownloadsVerifyChecksums(t *testing.T) {
	body := []byte("trusted artifact")
	server := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write(body) }))
	defer server.Close()
	sum := sha256.Sum256(body)
	sha := hex.EncodeToString(sum[:])
	shaOne := sha1.Sum(body)
	cases := []struct {
		name, sha256, sha1 string
		java, ok           bool
	}{
		{"java correct", sha, "", true, true}, {"java mismatch", strings.Repeat("0", 64), "", true, false}, {"java missing checksum", "", "", true, false},
		{"minecraft sha256", sha, "", false, true}, {"mojang sha1", "", hex.EncodeToString(shaOne[:]), false, true}, {"minecraft mismatch", strings.Repeat("0", 64), "", false, false},
	}
	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "download")
			var err error
			if tc.java {
				err = downloadVerifiedJava(context.Background(), server.Client(), server.URL, tc.sha256, path)
			} else {
				if e := os.WriteFile(path, nil, 0600); e != nil {
					t.Fatal(e)
				}
				err = downloadMinecraftFile(context.Background(), server.Client(), server.URL, path, tc.sha256, tc.sha1)
			}
			if (err == nil) != tc.ok {
				t.Fatalf("download error=%v want success=%v", err, tc.ok)
			}
		})
	}
}

func TestManagedMinecraftJarRejectsWrongJavaAndInvalidDownload(t *testing.T) {
	path := filepath.Join(t.TempDir(), "server.jar")
	writeMinecraftTestJar(t, path, 21)
	if err := validateMinecraftJar(path, "17"); err == nil {
		t.Fatal("Java 17 accepted for Java 21 classes")
	}
	if err := validateMinecraftJar(path, "21"); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("<html>server error</html>"), 0644); err != nil {
		t.Fatal(err)
	}
	if err := validateMinecraftJar(path, "21"); err == nil {
		t.Fatal("HTML accepted as server artifact")
	}
}

func TestManagedJavaArchiveRejectsTraversal(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "java.tar.gz")
	f, err := os.Create(path)
	if err != nil {
		t.Fatal(err)
	}
	gz := gzip.NewWriter(f)
	tw := tar.NewWriter(gz)
	if err := tw.WriteHeader(&tar.Header{Name: "../escape", Mode: 0755, Size: 1}); err != nil {
		t.Fatal(err)
	}
	tw.Write([]byte("x"))
	tw.Close()
	gz.Close()
	f.Close()
	if err := unpackJavaArchive(path, filepath.Join(dir, "out"), false); err == nil {
		t.Fatal("archive traversal accepted")
	}
	if _, err := os.Stat(filepath.Join(dir, "escape")); !os.IsNotExist(err) {
		t.Fatal("archive escaped destination")
	}
}

func TestManagedJavaReusesValidatedVersionWithoutNetwork(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("shell runtime fixture")
	}
	root := t.TempDir()
	t.Setenv("EASYWI_JAVA_CACHE_DIR", root)
	path := filepath.Join(root, "temurin-21-"+runtime.GOOS+"-"+runtime.GOARCH, "bin", "java")
	if err := os.MkdirAll(filepath.Dir(path), 0755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte("#!/bin/sh\necho 'openjdk version \"21.0.1\"' >&2\n"), 0755); err != nil {
		t.Fatal(err)
	}
	os.Chmod(path, 0755)
	payload := map[string]any{"minecraft_java_version": "21", "java_bin": "wrong"}
	if err := ensureManagedMinecraftJava(payload); err != nil {
		t.Fatal(err)
	}
	if payload["java_bin"] != path {
		t.Fatalf("wrong Java executable: %v", payload["java_bin"])
	}
	payload["minecraft_java_version"] = "0"
	if err := ensureManagedMinecraftJava(payload); err == nil {
		t.Fatal("unknown Java major accepted")
	}
}

func TestManagedMinecraftNeverFollowsCustomerConfigSymlinksOutsideInstance(t *testing.T) {
	dir := t.TempDir()
	outside := filepath.Join(t.TempDir(), "private")
	if err := os.WriteFile(outside, []byte("private outside data"), 0600); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(dir, "server.properties")); err != nil {
		t.Skip(err)
	}
	payload := makeMinecraftPayload("minecraft_vanilla_all", map[string]any{"minecraft_managed": "true"})
	if err := ensureMinecraftServerProperties(dir, payload); err == nil {
		t.Fatal("outside symlink read accepted")
	}
	if data, _ := os.ReadFile(outside); string(data) != "private outside data" {
		t.Fatal("outside file changed")
	}
	if err := publishMinecraftFile(dir, "server.properties", strings.NewReader("server-port=25565\n"), 0640); err != nil {
		t.Fatal(err)
	}
	if data, _ := os.ReadFile(outside); string(data) != "private outside data" {
		t.Fatal("atomic replace followed outside symlink")
	}
}

func TestManagedSpigotCacheRejectsModifiedArtifact(t *testing.T) {
	root := t.TempDir()
	cache := filepath.Join(root, "cached.jar")
	source := filepath.Join(root, "built.jar")
	target := filepath.Join(root, "target.jar")
	writeMinecraftTestJar(t, source, 21)
	if err := publishSpigotCache(cache, source); err != nil {
		t.Fatal(err)
	}
	payload := map[string]any{"minecraft_java_version": "21"}
	if !restoreSpigotCache(cache, target, payload) {
		t.Fatal("verified compiled artifact not reused")
	}
	if err := os.WriteFile(cache, []byte("tampered"), 0644); err != nil {
		t.Fatal(err)
	}
	if restoreSpigotCache(cache, target, payload) {
		t.Fatal("modified cached artifact accepted")
	}
}

func TestManagedMinecraftNormalizesEscapedAndContinuedNetworkKeys(t *testing.T) {
	lines := []string{`server-port=1`, `\u0073erver-port=2`, `server-\`, ` port:3`, `motd=My server`}
	result := strings.Join(setServerProperty(lines, "server-port", "25565"), "\n")
	if result != "server-port=25565\nmotd=My server" {
		t.Fatalf("managed aliases survived normalization: %q", result)
	}
}

func TestManagedMinecraftPreservesCustomerRconPassword(t *testing.T) {
	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "server.properties"), []byte("rcon.password=customer-secret\n"), 0600); err != nil {
		t.Fatal(err)
	}
	payload := makeMinecraftPayload("minecraft_paper_all", map[string]any{"minecraft_managed": "true"})
	if err := ensureMinecraftServerProperties(dir, payload); err != nil {
		t.Fatal(err)
	}
	content := mustReadFile(t, filepath.Join(dir, "server.properties"))
	assertProperty(t, content, "rcon.password", "customer-secret")
	assertProperty(t, content, "enable-rcon", "true")
}

func TestManagedMinecraftNetherNetStatusDoesNotInventPlayerCounts(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/v1/join" {
			http.NotFound(w, r)
			return
		}
		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()
	host, port, _ := net.SplitHostPort(strings.TrimPrefix(server.URL, "http://"))
	status, err := queryMinecraftBedrock(host, port)
	if err != nil {
		t.Fatal(err)
	}
	if status["transport"] != "nethernet" || status["query_details_unavailable"] != "true" {
		t.Fatalf("unexpected status %v", status)
	}
	if _, exists := status["players"]; exists {
		t.Fatalf("invented player count: %v", status)
	}
}

func TestManagedMinecraftNetherNetRejectsOrdinaryWebServer(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("not Bedrock")) }))
	defer server.Close()
	host, port, _ := net.SplitHostPort(strings.TrimPrefix(server.URL, "http://"))
	if _, err := queryMinecraftBedrockNetherNet(host, port); err == nil {
		t.Fatal("accepted unrelated HTTP endpoint")
	}
}

func TestManagedMinecraftConfigBackupCannotReadOutsideSymlink(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("symlink permissions")
	}
	dir := t.TempDir()
	outside := filepath.Join(t.TempDir(), "outside")
	os.WriteFile(outside, []byte("private"), 0600)
	if err := os.Symlink(outside, filepath.Join(dir, "server.properties")); err != nil {
		t.Fatal(err)
	}
	if _, err := writeManagedMinecraftConfig(dir, "server.properties", []byte("motd=new"), true); err == nil {
		t.Fatal("read outside symlink")
	}
	if got := mustReadFile(t, outside); got != "private" {
		t.Fatal("modified outside file")
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 {
		t.Fatal("outside data copied into backup")
	}
}
