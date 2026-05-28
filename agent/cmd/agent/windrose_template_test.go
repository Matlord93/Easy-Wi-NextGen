package main

import (
	"os"
	"strings"
	"testing"
)

func TestWindroseTemplateStartCommand(t *testing.T) {
	content, err := os.ReadFile("../../../core/src/Module/Core/Application/GameTemplateSeedCatalog.php")
	if err != nil {
		t.Fatal(err)
	}
	text := string(content)
	if !strings.Contains(text, "Windrose Dedicated Server (Linux via Wine)") {
		t.Fatal("Windrose Linux template is missing")
	}
	for _, want := range []string{
		"cd {{INSTANCE_DIR}}",
		"xvfb-run --auto-servernum",
		"--server-args='-screen 0 1024x768x24'",
		"WINE_NO_STRICT_PROT=1",
		"taskset -c 0-11",
		"wine R5/Binaries/Win64/WindroseServer-Win64-Shipping.exe",
		"-nullrhi -log",
	} {
		if !strings.Contains(text, want) {
			t.Fatalf("expected Windrose template to contain %q", want)
		}
	}
}
