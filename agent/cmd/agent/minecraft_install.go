package main

import (
	"archive/zip"
	"context"
	"crypto/sha1"
	"crypto/sha256"
	"encoding/binary"
	"encoding/hex"
	"fmt"
	"hash"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

func prepareManagedMinecraftInstall(dir, user string, payload map[string]any, callbacks ...func(string)) error {
	if payloadValue(payload, "minecraft_managed") != "true" {
		return nil
	}
	progress := func(message string) {
		for _, callback := range callbacks {
			callback(message)
		}
	}
	progress("Minecraft: preparing the required Java runtime")
	if err := ensureManagedMinecraftJava(payload); err != nil {
		return err
	}
	channel := payloadValue(payload, "minecraft_channel")
	buildSpigot := channel == "spigot" && payloadValue(payload, "minecraft_install_strategy") == "buildtools"
	if buildSpigot {
		minecraftBuildMu.Lock()
		defer minecraftBuildMu.Unlock()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Minute)
	defer cancel()
	cacheRoot := minecraftArtifactCacheRoot()
	if err := os.MkdirAll(cacheRoot, 0755); err != nil {
		return err
	}
	if err := os.Chmod(cacheRoot, 0755); err != nil {
		return err
	}
	staging, err := os.MkdirTemp(cacheRoot, ".install-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(staging)
	tmp, err := os.CreateTemp(staging, ".minecraft-download-")
	if err != nil {
		return err
	}
	path := tmp.Name()
	tmp.Close()
	defer os.Remove(path)
	cache := spigotCachePath(payload)
	cached := buildSpigot && restoreSpigotCache(cache, path, payload)
	if cached {
		progress("Minecraft: using verified cached Spigot build")
	}
	if !cached {
		progress("Minecraft: downloading selected server artifact")
		if err := downloadMinecraftFile(ctx, minecraftDownloadClient(10*time.Minute), payloadValue(payload, "minecraft_download_url"), path, payloadValue(payload, "minecraft_sha256"), payloadValue(payload, "minecraft_sha1")); err != nil {
			return err
		}
	}

	if channel == "bedrock" {
		if _, err := readMinecraftConfig(dir, "server.properties"); os.IsNotExist(err) {
			payload["minecraft_seed_config"] = "true"
		}
		if err := installBedrockArchive(path, dir, payload); err != nil {
			return err
		}
		progress("Minecraft: applying reserved network ports and server configuration")
		return ensureMinecraftServerProperties(dir, payload)
	}
	if buildSpigot && !cached {
		progress("Minecraft: building selected Spigot version with BuildTools")
		if _, err := exec.LookPath("git"); err != nil {
			return fmt.Errorf("Spigot BuildTools requires Git on the node: %w", err)
		}
		builder, home, work, err := prepareSpigotWorkspace()
		if err != nil {
			return err
		}
		if err := publishMinecraftArtifact(path, work, "BuildTools.jar", 0644); err != nil {
			return err
		}

		version := payloadValue(payload, "minecraft_version")
		if strings.ContainsAny(version, "/\\\x00\n\r") {
			return fmt.Errorf("invalid Spigot version")
		}
		buildPayload := make(map[string]any, len(payload))
		for key, value := range payload {
			buildPayload[key] = value
		}
		if version := payloadValue(payload, "minecraft_build_java_version"); version != "" {
			buildPayload["minecraft_java_version"] = version
		}
		if minimum, err := minecraftJarRequiredJava(filepath.Join(work, "BuildTools.jar")); err != nil {
			return err
		} else {
			var requested int
			fmt.Sscanf(payloadValue(buildPayload, "minecraft_java_version"), "%d", &requested)
			if minimum > requested {
				buildPayload["minecraft_java_version"] = fmt.Sprint(minimum)
			}
		}
		if err := ensureManagedMinecraftJava(buildPayload); err != nil {
			return err
		}
		progress("Minecraft: using JDK " + payloadValue(buildPayload, "minecraft_java_version") + " for Spigot BuildTools")
		command := "cd " + shellEscape(work) + " && " + shellEscape(payloadValue(buildPayload, "java_bin")) + " " + shellEscape("-Duser.home="+home) + " -jar BuildTools.jar --rev " + shellEscape(version) + " --compile SPIGOT"
		output, err := runMinecraftBuildTools(ctx, builder, home, command)
		if err != nil {
			return fmt.Errorf("Spigot BuildTools failed: %w: %s", err, trimOutput(output, 2000))
		}
		jar := filepath.Join(work, "spigot-"+version+".jar")
		if _, err := os.Stat(jar); err != nil {
			return fmt.Errorf("BuildTools did not produce the selected Spigot version: %w", err)
		}
		if err := copyFile(jar, path, 0644); err != nil {
			return err
		}
	}
	// A failed or HTML download can never replace a working server.jar.
	required, err := minecraftJarRequiredJava(path)
	if err != nil {
		return err
	}
	var selected int
	fmt.Sscanf(payloadValue(payload, "minecraft_java_version"), "%d", &selected)
	if required > selected {
		selected = 0
		for _, candidate := range []int{8, 16, 17, 21, 25} {
			if candidate >= required {
				selected = candidate
				break
			}
		}
		if selected == 0 && supportedMinecraftJava(required) {
			selected = required
		}
		if selected == 0 {
			return fmt.Errorf("Minecraft artifact requires unsupported Java %d", required)
		}
		payload["minecraft_java_version"] = fmt.Sprint(selected)
		if err := ensureManagedMinecraftJava(payload); err != nil {
			return err
		}
	}
	if err := validateMinecraftJar(path, payloadValue(payload, "minecraft_java_version")); err != nil {
		return err
	}
	if buildSpigot && !cached {
		if err := publishSpigotCache(cache, path); err != nil {
			return fmt.Errorf("Spigot cache: %w", err)
		}
	}
	if err := os.Chmod(path, 0644); err != nil {
		return err
	}
	if err := ensureMinecraftEula(dir, payload); err != nil {
		return err
	}
	progress("Minecraft: applying reserved network ports and server configuration")
	if err := ensureMinecraftServerProperties(dir, payload); err != nil {
		return err
	}
	return publishMinecraftArtifact(path, dir, "server.jar", 0644)

}

func downloadMinecraftFile(ctx context.Context, client *http.Client, url, path, sha256sum, sha1sum string) error {
	if !strings.HasPrefix(url, "https://") {
		return fmt.Errorf("Minecraft downloads require HTTPS")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return err
	}
	req.Header.Set("User-Agent", "EasyWi-MinecraftAgent/1.0 (https://github.com/Matlord93/webinterface)")
	resp, err := client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != 200 {
		return fmt.Errorf("Minecraft artifact returned HTTP %d", resp.StatusCode)
	}
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_TRUNC, 0600)
	if err != nil {
		return err
	}
	var h hash.Hash = sha256.New()
	expected := sha256sum
	if expected == "" && sha1sum != "" {
		h = sha1.New()
		expected = sha1sum
	}
	if expected != "" {
		decoded, e := hex.DecodeString(expected)
		if e != nil || len(decoded) != h.Size() {
			f.Close()
			return fmt.Errorf("invalid upstream artifact checksum")
		}
	}
	n, err := io.Copy(io.MultiWriter(f, h), io.LimitReader(resp.Body, (1<<30)+1))
	closeErr := f.Close()
	if err != nil {
		return err
	}
	if closeErr != nil {
		return closeErr
	}
	if n > 1<<30 {
		return fmt.Errorf("Minecraft artifact exceeds size limit")
	}
	if expected != "" && !strings.EqualFold(hex.EncodeToString(h.Sum(nil)), expected) {
		return fmt.Errorf("Minecraft artifact checksum mismatch")
	}
	return nil
}

func minecraftJarRequiredJava(path string) (int, error) {
	root, err := os.OpenRoot(filepath.Dir(path))
	if err != nil {
		return 0, err
	}
	defer root.Close()
	file, err := root.Open(filepath.Base(path))
	if err != nil {
		return 0, err
	}
	defer file.Close()
	info, err := file.Stat()
	if err != nil {
		return 0, err
	}
	z, err := zip.NewReader(file, info.Size())
	if err != nil {
		return 0, fmt.Errorf("invalid Minecraft JAR: %w", err)
	}
	required := 0
	classes := 0
	for _, f := range z.File {
		if !strings.HasSuffix(f.Name, ".class") || strings.HasPrefix(f.Name, "META-INF/versions/") {
			continue
		}
		r, e := f.Open()
		if e != nil {
			return 0, e
		}
		var header [8]byte
		_, e = io.ReadFull(r, header[:])
		r.Close()
		if e != nil || binary.BigEndian.Uint32(header[:4]) != 0xcafebabe {
			return 0, fmt.Errorf("invalid Java class in Minecraft artifact")
		}
		major := int(binary.BigEndian.Uint16(header[6:])) - 44
		if major > required {
			required = major
		}
		classes++
	}
	if classes == 0 {
		return 0, fmt.Errorf("Minecraft JAR has no Java classes")
	}
	return required, nil
}

func validateMinecraftJar(path, javaMajor string) error {
	required, err := minecraftJarRequiredJava(path)
	if err != nil {
		return err
	}
	var actual int
	fmt.Sscanf(javaMajor, "%d", &actual)
	if required > actual {
		return fmt.Errorf("Minecraft artifact requires Java %d, selected Java %d", required, actual)
	}
	return nil
}

func installBedrockArchive(path, dir string, payloads ...map[string]any) error {
	z, err := zip.OpenReader(path)
	if err != nil {
		return fmt.Errorf("invalid Bedrock ZIP: %w", err)
	}
	defer z.Close()
	// Stage all entries first so malformed archives cannot partially overwrite an installation.
	staging, err := os.MkdirTemp(filepath.Dir(path), ".bedrock-")
	if err != nil {
		return err
	}
	defer os.RemoveAll(staging)
	var total int64
	for _, f := range z.File {
		target, e := javaArchivePath(staging, f.Name)
		if e != nil {
			return e
		}
		if f.Mode()&os.ModeSymlink != 0 {
			return fmt.Errorf("Bedrock archive contains unsupported symlink")
		}
		if f.FileInfo().IsDir() {
			if e := os.MkdirAll(target, 0755); e != nil {
				return e
			}
			continue
		}
		if e := os.MkdirAll(filepath.Dir(target), 0755); e != nil {
			return e
		}
		r, e := f.Open()
		if e != nil {
			return e
		}
		out, e := os.OpenFile(target, os.O_CREATE|os.O_EXCL|os.O_WRONLY, 0644)
		if e != nil {
			r.Close()
			return e
		}
		n, e := io.Copy(out, io.LimitReader(r, (2<<30)-total+1))
		total += n
		closeErr := out.Close()
		r.Close()
		if e != nil {
			return e
		}
		if closeErr != nil {
			return closeErr
		}
		if total > 2<<30 {
			return fmt.Errorf("Bedrock archive exceeds expanded size limit")
		}
	}
	if _, err := os.Stat(filepath.Join(staging, "bedrock_server")); err != nil {
		return fmt.Errorf("Bedrock server binary missing")
	}
	// Upgrades must use the transport required by the newly installed binary,
	// even when preserving an older customer's server.properties.
	defaults, err := os.ReadFile(filepath.Join(staging, "server.properties"))
	if err != nil && !os.IsNotExist(err) {
		return err
	}
	transport := minecraftPropertyValue(parsePropertyLines(string(defaults)), "transport")
	if transport == "" {
		transport = "raknet"
	}
	for _, payload := range payloads {
		payload["minecraft_bedrock_transport"] = transport
	}
	return filepath.WalkDir(staging, func(path string, entry os.DirEntry, err error) error {
		if err != nil {
			return err
		}
		rel, _ := filepath.Rel(staging, path)
		if rel == "." {
			return nil
		}
		target := filepath.Join(dir, rel)
		if entry.IsDir() {
			root, err := os.OpenRoot(dir)
			if err != nil {
				return err
			}
			defer root.Close()
			return root.MkdirAll(rel, 0755)
		}
		if rel == "server.properties" || rel == "permissions.json" || rel == "allowlist.json" {
			if _, e := os.Stat(target); e == nil {
				return nil
			}
		}
		if err := publishMinecraftArtifact(path, dir, rel, 0644); err != nil {
			return err
		}
		if rel == "bedrock_server" || strings.HasSuffix(rel, ".sh") {
			root, err := os.OpenRoot(dir)
			if err != nil {
				return err
			}
			defer root.Close()
			return root.Chmod(rel, 0755)
		}
		return nil
	})
}

func firstMinecraftLogSender(senders []JobLogSender) JobLogSender {
	if len(senders) > 0 {
		return senders[0]
	}
	return nil
}
func minecraftInstallProgress(id string, sender JobLogSender) func(string) {
	return func(message string) {
		if sender != nil && id != "" {
			sender.Send(id, []string{message}, nil)
		}
	}
}
