package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

// Opt-in integration test. Downloads official artifacts and starts a temporary,
// loopback-only server; no customer worlds or system services are touched.
func TestManagedMinecraftLiveVanilla(t *testing.T) {
	if os.Getenv("EASYWI_TEST_MINECRAFT_LIVE") != "1" {
		t.Skip("set EASYWI_TEST_MINECRAFT_LIVE=1 to test official downloads and an actual server")
	}
	if runtime.GOOS != "linux" {
		t.Skip("Linux node integration")
	}
	client := minecraftDownloadClient(2 * time.Minute)
	fetch := func(url string) map[string]any {
		t.Helper()
		response, err := client.Get(url)
		if err != nil {
			t.Fatal(err)
		}
		defer response.Body.Close()
		if response.StatusCode != 200 {
			t.Fatalf("HTTP %d for %s", response.StatusCode, url)
		}
		var data map[string]any
		if err := json.NewDecoder(response.Body).Decode(&data); err != nil {
			t.Fatal(err)
		}
		return data
	}
	manifest := fetch("https://piston-meta.mojang.com/mc/game/version_manifest_v2.json")
	var details map[string]any
	for _, raw := range manifest["versions"].([]any) {
		v := raw.(map[string]any)
		if v["id"] == "1.21.11" {
			details = fetch(v["url"].(string))
			break
		}
	}
	if details == nil {
		t.Fatal("Minecraft 1.21.11 unavailable")
	}
	artifact := details["downloads"].(map[string]any)["server"].(map[string]any)
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := listener.Addr().(*net.TCPAddr).Port
	listener.Close()
	dir := t.TempDir()
	payload := map[string]any{"minecraft_managed": "true", "minecraft_channel": "vanilla", "minecraft_java_version": fmt.Sprint(details["javaVersion"].(map[string]any)["majorVersion"]), "minecraft_version": "1.21.11", "game_key": "minecraft_vanilla_all", "minecraft_download_url": artifact["url"], "minecraft_sha1": artifact["sha1"], "required_ports": "game", "ports": []int{port}, "bind_ip": "127.0.0.1", "env_vars": map[string]any{"SERVER_NAME": "Managed integration", "MAX_PLAYERS": "5"}}
	if err := prepareManagedMinecraftInstall(dir, "", payload); err != nil {
		t.Fatal(err)
	}
	t.Logf("verified Java %v at %v and official Minecraft artifact", payload["minecraft_java_version"], payload["java_bin"])
	// A small world keeps integration time and disk use bounded.
	props := filepath.Join(dir, "server.properties")
	f, err := os.OpenFile(props, os.O_APPEND|os.O_WRONLY, 0644)
	if err != nil {
		t.Fatal(err)
	}
	f.WriteString("view-distance=2\nsimulation-distance=2\nlevel-type=minecraft:flat\ngenerate-structures=false\n")
	f.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 150*time.Second)
	defer cancel()
	log, err := os.Create(filepath.Join(dir, "startup.log"))
	if err != nil {
		t.Fatal(err)
	}
	defer log.Close()
	command := exec.CommandContext(ctx, payloadValue(payload, "java_bin"), "-Xms256M", "-Xmx1G", "-jar", "server.jar", "nogui")
	command.Dir = dir
	command.Stdout = log
	command.Stderr = log
	stdin, err := command.StdinPipe()
	if err != nil {
		t.Fatal(err)
	}
	if err := command.Start(); err != nil {
		t.Fatal(err)
	}
	defer func() {
		stdin.Close()
		if command.ProcessState == nil {
			command.Process.Kill()
			command.Wait()
		}
	}()
	deadline := time.Now().Add(120 * time.Second)
	for time.Now().Before(deadline) {
		status, err := queryMinecraftJava("127.0.0.1", fmt.Sprint(port))
		if err == nil {
			t.Logf("actual Minecraft status response: %v", status)
			if !strings.Contains(fmt.Sprint(status), "1.21.11") {
				t.Fatalf("wrong version: %v", status)
			}
			fmt.Fprintln(stdin, "stop")
			stdin.Close()
			if err := command.Wait(); err != nil {
				t.Fatal(err)
			}
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	output, _ := os.ReadFile(filepath.Join(dir, "startup.log"))
	t.Fatalf("Minecraft did not become ready: %s", output)
}

func TestManagedMinecraftLiveJavaMajors(t *testing.T) {
	if os.Getenv("EASYWI_TEST_MINECRAFT_LIVE") != "1" {
		t.Skip("opt-in official Java download validation")
	}
	for _, major := range []string{"8", "16", "17", "21", "25"} {
		t.Run("Java"+major, func(t *testing.T) {
			payload := map[string]any{"minecraft_java_version": major}
			if err := ensureManagedMinecraftJava(payload); err != nil {
				t.Fatal(err)
			}
			t.Logf("Java %s: %s", major, payloadValue(payload, "java_bin"))
		})
	}
}

func TestManagedMinecraftLiveDistributions(t *testing.T) {
	if os.Getenv("EASYWI_TEST_MINECRAFT_DISTRIBUTIONS_LIVE") != "1" {
		t.Skip("opt-in official distribution install validation")
	}
	if runtime.GOOS != "linux" {
		t.Skip("Linux distributions")
	}
	client := minecraftDownloadClient(2 * time.Minute)
	fetch := func(url string, target any) {
		t.Helper()
		r, err := client.Get(url)
		if err != nil {
			t.Fatal(err)
		}
		defer r.Body.Close()
		if r.StatusCode != 200 {
			t.Fatalf("HTTP %d: %s", r.StatusCode, url)
		}
		if err := json.NewDecoder(r.Body).Decode(target); err != nil {
			t.Fatal(err)
		}
	}
	for _, channel := range []string{"paper", "bungeecord", "bedrock", "spigot"} {
		t.Run(channel, func(t *testing.T) {
			var url, checksum, version, build, java string
			switch channel {
			case "paper":
				var entries []struct {
					ID        int `json:"id"`
					Downloads map[string]struct {
						URL       string            `json:"url"`
						Checksums map[string]string `json:"checksums"`
					} `json:"downloads"`
				}
				fetch("https://fill.papermc.io/v3/projects/paper/versions/1.21.11/builds", &entries)
				if len(entries) == 0 {
					t.Fatal("no Paper builds")
				}
				url = entries[0].Downloads["server:default"].URL
				checksum = entries[0].Downloads["server:default"].Checksums["sha256"]
				version = "1.21.11"
				build = fmt.Sprint(entries[0].ID)
				java = "21"
			case "bungeecord":
				var job struct {
					Number int `json:"number"`
				}
				fetch("https://hub.spigotmc.org/jenkins/job/BungeeCord/lastSuccessfulBuild/api/json?tree=number", &job)
				build = fmt.Sprint(job.Number)
				version = "BungeeCord"
				java = "17"
				url = "https://hub.spigotmc.org/jenkins/job/BungeeCord/" + build + "/artifact/bootstrap/target/BungeeCord.jar"
			case "bedrock":
				var data struct {
					Result struct {
						Links []struct {
							Type string `json:"downloadType"`
							URL  string `json:"downloadUrl"`
						} `json:"links"`
					} `json:"result"`
				}
				fetch("https://net-secondary.web.minecraft-services.net/api/v1.0/download/links", &data)
				for _, link := range data.Result.Links {
					if link.Type == "serverBedrockLinux" {
						url = link.URL
					}
				}
				version = "current"
			case "spigot":
				var tool struct {
					Number int `json:"number"`
				}
				fetch("https://hub.spigotmc.org/jenkins/job/BuildTools/lastSuccessfulBuild/api/json?tree=number", &tool)
				version = "1.21.11"
				java = "21"
				url = fmt.Sprintf("https://hub.spigotmc.org/jenkins/job/BuildTools/%d/artifact/target/BuildTools.jar", tool.Number)
				// Pin the BuildTools bytes exactly as the catalog synchronizer does.
				r, err := client.Get(url)
				if err != nil {
					t.Fatal(err)
				}
				bytes, err := io.ReadAll(r.Body)
				r.Body.Close()
				if err != nil {
					t.Fatal(err)
				}
				sum := sha256.Sum256(bytes)
				checksum = hex.EncodeToString(sum[:])
			}
			listener, err := net.Listen("tcp", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			port := listener.Addr().(*net.TCPAddr).Port
			listener.Close()
			dir := t.TempDir()
			key := "minecraft_" + channel + "_all"
			if channel == "bedrock" {
				key = "minecraft_bedrock"
			}
			payload := map[string]any{"minecraft_managed": "true", "minecraft_channel": channel, "minecraft_version": version, "minecraft_build": build, "game_key": key, "minecraft_download_url": url, "minecraft_sha256": checksum, "required_ports": "game,gamev6", "ports": []int{port, port + 1}, "bind_ip": "127.0.0.1", "env_vars": map[string]any{"SERVER_NAME": "Managed " + channel, "MAX_PLAYERS": "5", "BUNGEE_BACKEND": "127.0.0.1:25566"}}
			if java != "" {
				payload["minecraft_java_version"] = java
			}
			user, err := user.Current()
			if err != nil {
				t.Fatal(err)
			}
			if channel == "spigot" {
				payload["minecraft_install_strategy"] = "buildtools"
			}
			if channel == "spigot" && os.Geteuid() != 0 && os.Getenv("EASYWI_MINECRAFT_BUILD_USER") == "" {
				t.Setenv("EASYWI_MINECRAFT_BUILD_USER", user.Username)
			}
			if err := prepareManagedMinecraftInstall(dir, user.Username, payload); err != nil {
				t.Fatal(err)
			}
			if channel == "bedrock" {
				if _, err := os.Stat(filepath.Join(dir, "bedrock_server")); err != nil {
					t.Fatal(err)
				}
			} else {
				if err := validateMinecraftJar(filepath.Join(dir, "server.jar"), payloadValue(payload, "minecraft_java_version")); err != nil {
					t.Fatal(err)
				}
			}
			t.Logf("installed %s %s build %s with Java %v and reserved port %d", channel, version, build, payload["minecraft_java_version"], port)
			if os.Getenv("EASYWI_TEST_MINECRAFT_START_LIVE") == "1" {
				startMinecraftDistribution(t, dir, channel, port, payload)
			}
		})
	}
}

func startMinecraftDistribution(t *testing.T, dir, channel string, port int, payload map[string]any) {
	t.Helper()
	if channel != "bungeecord" {
		file, err := os.OpenFile(filepath.Join(dir, "server.properties"), os.O_WRONLY|os.O_APPEND, 0640)
		if err != nil {
			t.Fatal(err)
		}
		file.WriteString("view-distance=2\nsimulation-distance=2\nlevel-type=minecraft:flat\ngenerate-structures=false\n")
		if channel == "bedrock" {
			// Local discovery test only; authentication requires external Xbox services.
			file.WriteString("online-mode=false\nallow-list=false\n")
		}
		file.Close()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 150*time.Second)
	defer cancel()
	var command *exec.Cmd
	if channel == "bedrock" {
		command = exec.CommandContext(ctx, filepath.Join(dir, "bedrock_server"))
		command.Env = append(os.Environ(), "LD_LIBRARY_PATH="+dir)
	} else {
		command = exec.CommandContext(ctx, payloadValue(payload, "java_bin"), "-Xms256M", "-Xmx1G", "-jar", "server.jar")
		if channel != "bungeecord" {
			command.Args = append(command.Args, "nogui")
		}
	}
	command.Dir = dir
	log, err := os.Create(filepath.Join(dir, "distribution-startup.log"))
	if err != nil {
		t.Fatal(err)
	}
	defer log.Close()
	command.Stdout = log
	command.Stderr = log
	stdin, err := command.StdinPipe()
	if err != nil {
		t.Fatal(err)
	}
	if err := command.Start(); err != nil {
		t.Fatal(err)
	}
	defer func() {
		stdin.Close()
		if command.ProcessState == nil {
			command.Process.Kill()
			command.Wait()
		}
	}()
	deadline := time.Now().Add(120 * time.Second)
	for time.Now().Before(deadline) {
		var status map[string]string
		var err error
		if channel == "bedrock" {
			status, err = queryMinecraftBedrock("127.0.0.1", fmt.Sprint(port))
		} else {
			status, err = queryMinecraftJava("127.0.0.1", fmt.Sprint(port))
		}
		if err == nil {
			t.Logf("%s live status: %v", channel, status)
			// Bedrock can answer discovery before world generation has completed;
			// its process is still stopped cleanly through the console afterwards.
			commandName := "stop"
			if channel == "bungeecord" {
				commandName = "end"
			}
			fmt.Fprintln(stdin, commandName)
			stdin.Close()
			if err := command.Wait(); err != nil {
				t.Fatal(err)
			}
			return
		}
		time.Sleep(500 * time.Millisecond)
	}
	output, _ := os.ReadFile(filepath.Join(dir, "distribution-startup.log"))
	t.Fatalf("%s did not become ready: %s", channel, output)
}
