package liveconsole

import (
	"os"
	"path/filepath"
	"runtime"
	"strings"
)

func RuntimeDir() string {
	if p := strings.TrimSpace(os.Getenv("EASYWI_RUNTIME_DIR")); p != "" {
		return p
	}
	if runtime.GOOS == "windows" {
		base := os.Getenv("ProgramData")
		if base == "" {
			base = `C:\ProgramData`
		}
		return filepath.Join(base, "EasyWI", "run", "instances")
	}
	return "/run/easywi/instances"
}
