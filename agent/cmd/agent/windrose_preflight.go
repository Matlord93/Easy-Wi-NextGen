package main

import (
	"fmt"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

const windroseExeRelativePath = "R5/Binaries/Win64/WindroseServer-Win64-Shipping.exe"

var windrosePreflightLookPath = exec.LookPath
var windrosePreflightCommand = func(name string, args ...string) ([]byte, error) {
	return exec.Command(name, args...).CombinedOutput()
}

func isWindroseTemplate(payload map[string]any, renderedStartCommand string) bool {
	candidates := []string{
		payloadValue(payload, "template_name"),
		payloadValue(payload, "template_key"),
		payloadValue(payload, "template_slug"),
		payloadValue(payload, "game_key"),
		renderedStartCommand,
	}
	for _, candidate := range candidates {
		value := strings.ToLower(strings.TrimSpace(candidate))
		if value == "windrose dedicated server (linux via wine)" || strings.Contains(value, "windrose") || strings.Contains(value, strings.ToLower(windroseExeRelativePath)) {
			return true
		}
	}
	return false
}

func runWindrosePreflight(instanceDir string, payload map[string]any, renderedStartCommand string) error {
	if !isWindroseTemplate(payload, renderedStartCommand) {
		return nil
	}
	missing := []string{}
	for _, tool := range []string{"wine", "xvfb-run", "taskset", "screen"} {
		path, err := windrosePreflightLookPath(tool)
		if err != nil || strings.TrimSpace(path) == "" {
			log.Printf("windrose_preflight_tool name=%s status=missing", tool)
			missing = append(missing, tool)
		} else {
			log.Printf("windrose_preflight_tool name=%s path=%s", tool, path)
		}
	}
	if len(missing) > 0 {
		return fmt.Errorf("WINDROSE_PREFLIGHT_FAILED missing tools=%s; activate/install node role 'game' to install Wine/Windrose runtime dependencies", strings.Join(missing, ","))
	}
	if out, err := windrosePreflightCommand("wine", "--version"); err != nil || strings.TrimSpace(string(out)) == "" {
		return fmt.Errorf("WINDROSE_PREFLIGHT_FAILED wine --version failed: %v output=%q; repair/reinstall node role 'game' to restore Wine/Windrose runtime dependencies", err, strings.TrimSpace(string(out)))
	} else {
		log.Printf("windrose_preflight_wine_version=%s", strings.TrimSpace(string(out)))
	}
	exePath := filepath.Join(instanceDir, filepath.FromSlash(windroseExeRelativePath))
	if st, err := os.Stat(exePath); err != nil || st.IsDir() {
		log.Printf("windrose_preflight_executable status=missing path=%s", exePath)
		return fmt.Errorf("WINDROSE_PREFLIGHT_FAILED missing executable=%s; run the Windrose install/update job and ensure SteamCMD installed app 4129620 successfully", exePath)
	}
	log.Printf("windrose_preflight_executable status=present path=%s", exePath)
	return nil
}
