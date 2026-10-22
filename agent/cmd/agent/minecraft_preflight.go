package main

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
)

type minecraftPreflightManifest struct {
	Directory string         `json:"directory"`
	Payload   map[string]any `json:"payload"`
}

func writeMinecraftPreflightManifest(dir, user string, payload map[string]any) (string, error) {
	id := payloadValue(payload, "instance_id")
	if !regexp.MustCompile(`^[A-Za-z0-9_-]+$`).MatchString(id) {
		return "", fmt.Errorf("invalid Minecraft instance ID")
	}
	root := "/var/lib/easywi-minecraft"
	if err := os.MkdirAll(root, 0755); err != nil {
		return "", err
	}
	if err := os.Chmod(root, 0755); err != nil {
		return "", err
	}
	selected := map[string]any{}
	for _, key := range []string{"instance_id", "customer_id", "minecraft_managed", "minecraft_channel", "minecraft_bedrock_transport", "minecraft_java_version", "java_bin", "game_key", "required_ports", "ports", "port_reservations", "bind_ip"} {
		if value, ok := payload[key]; ok {
			selected[key] = value
		}
	}
	values := buildInstanceTemplateValues(dir, dir, payloadValue(payload, "required_ports"), parsePayloadPorts(payload), payload)
	env := map[string]any{}
	for _, key := range []string{"SERVER_NAME", "MAX_PLAYERS", "RCON_PASSWORD", "BUNGEE_BACKEND"} {
		env[key] = values[key]
	}
	selected["env_vars"] = env
	content, err := json.Marshal(minecraftPreflightManifest{Directory: dir, Payload: selected})
	if err != nil {
		return "", err
	}
	_, gid, err := lookupIDs(user, user)
	if err != nil {
		return "", err
	}
	file, err := os.CreateTemp(root, ".manifest-")
	if err != nil {
		return "", err
	}
	defer os.Remove(file.Name())
	if _, err := file.Write(content); err != nil {
		file.Close()
		return "", err
	}
	if err := file.Close(); err != nil {
		return "", err
	}
	if err := os.Chown(file.Name(), 0, gid); err != nil {
		return "", err
	}
	if err := os.Chmod(file.Name(), 0640); err != nil {
		return "", err
	}
	target := filepath.Join(root, id+".json")
	if err := os.Rename(file.Name(), target); err != nil {
		return "", err
	}
	return target, nil
}

func addMinecraftSystemdPreflight(unit, dir, user string, payload map[string]any) (string, error) {
	if payloadValue(payload, "minecraft_managed") != "true" {
		return unit, nil
	}
	manifest, err := writeMinecraftPreflightManifest(dir, user, payload)
	if err != nil {
		return "", err
	}
	executable, err := os.Executable()
	if err != nil {
		return "", err
	}
	// ExecStartPre runs on every service start, including host reboots. The
	// root-owned unit and manifest keep listener ports outside customer control.
	directive := "ExecStartPre=" + strconv.Quote(strings.ReplaceAll(executable, "%", "%%")) + " --minecraft-preflight " + strconv.Quote(strings.ReplaceAll(manifest, "%", "%%")) + "\n"
	return strings.Replace(unit, "[Service]\n", "[Service]\n"+directive, 1), nil
}

func runMinecraftPreflight(path string) error {
	data, err := os.ReadFile(path)
	if err != nil {
		return err
	}
	var manifest minecraftPreflightManifest
	if err := json.Unmarshal(data, &manifest); err != nil {
		return err
	}
	if !filepath.IsAbs(manifest.Directory) {
		return fmt.Errorf("invalid Minecraft manifest directory")
	}
	if !isMinecraftBedrockTemplate(manifest.Payload) {
		var major int
		fmt.Sscanf(payloadValue(manifest.Payload, "minecraft_java_version"), "%d", &major)
		if err := validateManagedJava(payloadValue(manifest.Payload, "java_bin"), major); err != nil {
			return err
		}
		if err := validateMinecraftJar(filepath.Join(manifest.Directory, "server.jar"), fmt.Sprint(major)); err != nil {
			return err
		}
	}
	return ensureMinecraftServerProperties(manifest.Directory, manifest.Payload)
}
