package main

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestEnsureMinecraftEulaPaperFreshInstall(t *testing.T) {
	dir := t.TempDir()
	payload := map[string]any{"template_key": "minecraft_paper_all"}

	if err := ensureMinecraftEula(dir, payload); err != nil {
		t.Fatalf("ensureMinecraftEula failed: %v", err)
	}

	content, err := os.ReadFile(filepath.Join(dir, "eula.txt"))
	if err != nil {
		t.Fatalf("eula.txt not created: %v", err)
	}
	if !strings.Contains(string(content), "eula=true") {
		t.Fatalf("expected eula=true in eula.txt, got: %s", content)
	}
}

func TestEnsureMinecraftEulaVanillaFreshInstall(t *testing.T) {
	dir := t.TempDir()
	payload := map[string]any{"template_key": "minecraft_vanilla_all"}

	if err := ensureMinecraftEula(dir, payload); err != nil {
		t.Fatalf("ensureMinecraftEula failed: %v", err)
	}

	content, err := os.ReadFile(filepath.Join(dir, "eula.txt"))
	if err != nil {
		t.Fatalf("eula.txt not created: %v", err)
	}
	if !strings.Contains(string(content), "eula=true") {
		t.Fatalf("expected eula=true in eula.txt, got: %s", content)
	}
}

func TestEnsureMinecraftEulaUpdatesEulaFalse(t *testing.T) {
	dir := t.TempDir()
	payload := map[string]any{"template_key": "minecraft_vanilla_all"}

	existing := "#By changing the setting below to TRUE you are indicating your agreement to our EULA.\neula=false\n"
	if err := os.WriteFile(filepath.Join(dir, "eula.txt"), []byte(existing), 0o640); err != nil {
		t.Fatalf("setup: %v", err)
	}

	if err := ensureMinecraftEula(dir, payload); err != nil {
		t.Fatalf("ensureMinecraftEula failed: %v", err)
	}

	content, err := os.ReadFile(filepath.Join(dir, "eula.txt"))
	if err != nil {
		t.Fatalf("eula.txt missing: %v", err)
	}
	if strings.Contains(string(content), "eula=false") {
		t.Fatalf("expected eula=false to be replaced, got: %s", content)
	}
	if !strings.Contains(string(content), "eula=true") {
		t.Fatalf("expected eula=true in updated file, got: %s", content)
	}
	if !strings.Contains(string(content), "#By changing") {
		t.Fatalf("expected comment to be preserved, got: %s", content)
	}
}

func TestEnsureMinecraftEulaSkipsBedrock(t *testing.T) {
	dir := t.TempDir()
	payload := map[string]any{"template_key": "minecraft_bedrock"}

	if err := ensureMinecraftEula(dir, payload); err != nil {
		t.Fatalf("ensureMinecraftEula failed: %v", err)
	}

	if _, err := os.Stat(filepath.Join(dir, "eula.txt")); !os.IsNotExist(err) {
		t.Fatalf("expected eula.txt NOT to be created for Bedrock")
	}
}

func TestEnsureMinecraftEulaSkipsNonMinecraft(t *testing.T) {
	dir := t.TempDir()
	payload := map[string]any{"template_key": "cs2"}

	if err := ensureMinecraftEula(dir, payload); err != nil {
		t.Fatalf("ensureMinecraftEula failed: %v", err)
	}

	if _, err := os.Stat(filepath.Join(dir, "eula.txt")); !os.IsNotExist(err) {
		t.Fatalf("expected eula.txt NOT to be created for non-Minecraft server")
	}
}

func TestIsMinecraftJavaTemplateVanilla(t *testing.T) {
	if !isMinecraftJavaTemplate(map[string]any{"template_key": "minecraft_vanilla_all"}) {
		t.Fatal("expected vanilla to be detected as Minecraft Java")
	}
}

func TestIsMinecraftJavaTemplatePaper(t *testing.T) {
	if !isMinecraftJavaTemplate(map[string]any{"template_slug": "minecraft_paper_all"}) {
		t.Fatal("expected paper to be detected as Minecraft Java")
	}
}

func TestIsMinecraftJavaTemplateBedrockExcluded(t *testing.T) {
	if isMinecraftJavaTemplate(map[string]any{"template_key": "minecraft_bedrock"}) {
		t.Fatal("expected bedrock to NOT be detected as Minecraft Java")
	}
}

func TestIsMinecraftJavaTemplateBedrockViaGameType(t *testing.T) {
	if isMinecraftJavaTemplate(map[string]any{"game_type": "minecraft_bedrock"}) {
		t.Fatal("expected bedrock game_type to NOT be detected as Minecraft Java")
	}
}

func TestIsMinecraftJavaTemplateFalseForUnknown(t *testing.T) {
	if isMinecraftJavaTemplate(map[string]any{"template_key": "valheim"}) {
		t.Fatal("expected non-Minecraft server to return false")
	}
}

func TestEnsureMinecraftEulaPreservesExistingTrueUnchanged(t *testing.T) {
	dir := t.TempDir()
	payload := map[string]any{"template_key": "minecraft_vanilla_all"}

	existing := "#comment\neula=true\n"
	if err := os.WriteFile(filepath.Join(dir, "eula.txt"), []byte(existing), 0o640); err != nil {
		t.Fatalf("setup: %v", err)
	}

	if err := ensureMinecraftEula(dir, payload); err != nil {
		t.Fatalf("ensureMinecraftEula failed: %v", err)
	}

	content, err := os.ReadFile(filepath.Join(dir, "eula.txt"))
	if err != nil {
		t.Fatalf("eula.txt missing: %v", err)
	}
	if !strings.Contains(string(content), "eula=true") {
		t.Fatalf("expected eula=true to remain, got: %s", content)
	}
	if !strings.Contains(string(content), "#comment") {
		t.Fatalf("expected comment to be preserved, got: %s", content)
	}
}
