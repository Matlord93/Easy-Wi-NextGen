package main

import (
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

var (
	wineHQKeyURL     = "https://dl.winehq.org/wine-builds/winehq.key"
	wineHQKeyPath    = "/etc/apt/keyrings/winehq-archive.key"
	wineHQSourceDir  = "/etc/apt/sources.list.d"
	wineHQKeyringDir = "/etc/apt/keyrings"
)

type osReleaseInfo struct {
	ID              string
	VersionCodename string
	IDLike          string
}

type windroseCommandRunner interface {
	Run(name string, args []string, env []string) (string, error)
	LookPath(name string) (string, error)
}

type defaultWindroseCommandRunner struct{}

func (defaultWindroseCommandRunner) Run(name string, args []string, env []string) (string, error) {
	return runCommandCaptureWithEnv(name, args, env)
}
func (defaultWindroseCommandRunner) LookPath(name string) (string, error) { return exec.LookPath(name) }

var (
	windroseDepsRunner windroseCommandRunner = defaultWindroseCommandRunner{}
	osReleasePath                            = "/etc/os-release"
	wineDepsHTTPClient                       = &http.Client{Timeout: 60 * time.Second}
)

func ensureWindroseWineDependencies(output *strings.Builder) error {
	info, err := readWindroseOSRelease(osReleasePath)
	if err != nil {
		return err
	}
	appendOutput(output, fmt.Sprintf("windrose_os_detected id=%s version_codename=%s", info.ID, info.VersionCodename))

	var sourceURLFn func(string) (string, string)
	switch info.ID {
	case "ubuntu":
		sourceURLFn = wineHQUbuntuSource
	case "debian":
		sourceURLFn = wineHQDebianSource
	default:
		return fmt.Errorf("windrose wine dependencies unsupported distribution: ID=%s VERSION_CODENAME=%s; currently Ubuntu and Debian are tested/supported", info.ID, info.VersionCodename)
	}
	if strings.TrimSpace(info.VersionCodename) == "" {
		return fmt.Errorf("windrose wine dependencies unsupported release for %s: VERSION_CODENAME is empty", info.ID)
	}

	archChanged, err := ensureI386Architecture(output)
	if err != nil {
		return err
	}
	keyChanged, err := ensureWineHQKey(output)
	if err != nil {
		return err
	}
	sourceURL, sourcePath := sourceURLFn(info.VersionCodename)
	appendOutput(output, "windrose_winehq_source_url="+sourceURL)
	sourceChanged, err := ensureWineHQSource(sourceURL, sourcePath, output)
	if err != nil {
		return err
	}

	missingMain := missingDebPackages(windroseMainPackages())
	missingLibGD := missingDebPackages([]string{"libgd3:amd64", "libgd3:i386"})
	wineInstalled := isDebPackageInstalled("winehq-stable") || isDebPackageInstalled("winehq-staging")
	if archChanged || keyChanged || sourceChanged || len(missingMain) > 0 || len(missingLibGD) > 0 || !wineInstalled {
		if _, err := runWindroseCommand("apt-get", []string{"update"}, debianNonInteractiveEnv(), output); err != nil {
			return err
		}
		appendOutput(output, "windrose_apt_update=executed")
	} else {
		appendOutput(output, "windrose_apt_update=skipped")
	}

	if len(missingMain) > 0 {
		args := append([]string{"install", "-y", "--install-recommends"}, missingMain...)
		if _, err := runWindroseCommand("apt-get", args, debianNonInteractiveEnv(), output); err != nil {
			return err
		}
		appendOutput(output, "windrose_packages_installed="+strings.Join(missingMain, ","))
	} else {
		appendOutput(output, "windrose_packages_installed=none")
	}

	if !wineInstalled {
		if err := ensureWindroseWineHQ(output); err != nil {
			return err
		}
	}
	libGDPackages := []string{"libgd3:amd64", "libgd3:i386"}
	if len(missingLibGD) > 0 || archChanged || sourceChanged {
		args := append([]string{"install", "-y", "--allow-downgrades"}, libGDPackages...)
		if _, err := runWindroseCommand("apt-get", args, debianNonInteractiveEnv(), output); err != nil {
			return err
		}
		appendOutput(output, "windrose_libgd_installed="+strings.Join(libGDPackages, ","))
	} else {
		appendOutput(output, "windrose_libgd_installed=already_present")
	}
	if isDebPackageAvailable("winetricks") && !isDebPackageInstalled("winetricks") {
		if _, err := runWindroseCommand("apt-get", []string{"install", "-y", "winetricks"}, debianNonInteractiveEnv(), output); err != nil {
			appendOutput(output, "windrose_optional_winetricks=failed:"+err.Error())
		} else {
			appendOutput(output, "windrose_optional_winetricks=installed")
		}
	}
	logWindroseRuntimeTools(output)
	return nil
}

func windroseMainPackages() []string {
	return []string{"screen", "xvfb", "xauth", "util-linux", "procps", "cabextract", "unzip", "p7zip-full", "curl", "wget", "ca-certificates", "tar", "fonts-liberation"}
}

// ensureWindroseWineHQ installs WineHQ, trying winehq-stable first and
// falling back to winehq-staging when stable has unresolvable dependencies
// (a known issue on newer Debian releases such as trixie).
// When both fail outright the i386 dummy fallback is attempted.
func ensureWindroseWineHQ(output *strings.Builder) error {
	for _, pkg := range []string{"winehq-stable", "winehq-staging"} {
		if isDebPackageInstalled(pkg) {
			appendOutput(output, "windrose_wine="+pkg+":already_installed")
			return nil
		}
		args := []string{"install", "-y", "--install-recommends", pkg}
		if _, err := runWindroseCommand("apt-get", args, debianNonInteractiveEnv(), output); err != nil {
			appendOutput(output, "windrose_wine_package_failed="+pkg)
			continue
		}
		appendOutput(output, "windrose_wine="+pkg+":installed")
		return nil
	}
	// Both direct installs failed (most likely wine-staging-i386 is absent from the
	// WineHQ trixie repo). Create a dummy i386 package that satisfies the dependency
	// and retry with winehq-staging.
	return ensureWindroseWineWithI386Dummy(output)
}

// windroseBuildDummyDebFn is injectable for tests.
var windroseBuildDummyDebFn = defaultBuildWineI386DummyDeb

// ensureWindroseWineWithI386Dummy creates a dummy wine-staging-i386 .deb that
// satisfies the multi-arch dependency, installs it, then installs winehq-staging.
func ensureWindroseWineWithI386Dummy(output *strings.Builder) error {
	version := detectWineStagingAmd64Version(output)
	if version == "" {
		return fmt.Errorf("failed to install WineHQ: tried winehq-stable and winehq-staging; " +
			"cannot detect wine-staging-amd64 version for i386 dummy workaround — check WineHQ repository")
	}
	appendOutput(output, "windrose_wine_i386_dummy_version="+version)

	debPath, cleanup, err := windroseBuildDummyDebFn("wine-staging-i386", version)
	if err != nil {
		return fmt.Errorf("build wine-staging-i386 dummy: %w", err)
	}
	defer cleanup()

	if _, err := runWindroseCommand("dpkg", []string{"-i", debPath}, nil, output); err != nil {
		return fmt.Errorf("install wine-staging-i386 dummy: %w", err)
	}
	appendOutput(output, "windrose_wine_i386_dummy_installed=true")

	args := []string{"install", "-y", "--install-recommends", "winehq-staging"}
	if _, err := runWindroseCommand("apt-get", args, debianNonInteractiveEnv(), output); err != nil {
		return fmt.Errorf("install winehq-staging with i386 dummy: %w", err)
	}
	appendOutput(output, "windrose_wine=winehq-staging:installed_with_i386_dummy")
	return nil
}

// detectWineStagingAmd64Version asks apt-cache for the version of
// wine-staging-amd64, which equals the required wine-staging-i386 version.
func detectWineStagingAmd64Version(output *strings.Builder) string {
	out, err := windroseDepsRunner.Run("apt-cache", []string{"show", "wine-staging-amd64"}, nil)
	if err != nil {
		appendOutput(output, "windrose_wine_amd64_version_detect=failed")
		return ""
	}
	for _, line := range strings.Split(out, "\n") {
		if strings.HasPrefix(line, "Version:") {
			return strings.TrimSpace(strings.TrimPrefix(line, "Version:"))
		}
	}
	return ""
}

// defaultBuildWineI386DummyDeb builds a minimal i386 .deb for pkgName at version
// using dpkg-deb (always available on Debian). Returns the .deb path and a
// cleanup func that removes the temp files.
func defaultBuildWineI386DummyDeb(pkgName, version string) (string, func(), error) {
	tmpDir, err := os.MkdirTemp("", "easywi-wine-dummy-*")
	if err != nil {
		return "", nil, fmt.Errorf("create temp dir: %w", err)
	}
	cleanup := func() { _ = os.RemoveAll(tmpDir) }

	if err := os.Mkdir(filepath.Join(tmpDir, "DEBIAN"), 0o755); err != nil {
		cleanup()
		return "", nil, fmt.Errorf("create DEBIAN dir: %w", err)
	}
	control := "Package: " + pkgName + "\n" +
		"Version: " + version + "\n" +
		"Architecture: i386\n" +
		"Installed-Size: 0\n" +
		"Maintainer: easywi-agent\n" +
		"Description: Dummy package to satisfy wine i386 multi-arch dependency\n"
	if err := os.WriteFile(filepath.Join(tmpDir, "DEBIAN", "control"), []byte(control), 0o644); err != nil {
		cleanup()
		return "", nil, fmt.Errorf("write control file: %w", err)
	}

	debPath := filepath.Join(tmpDir, pkgName+"_"+version+"_i386.deb")
	out, err := exec.Command("dpkg-deb", "--build", tmpDir, debPath).CombinedOutput()
	if err != nil {
		cleanup()
		return "", nil, fmt.Errorf("dpkg-deb --build: %w: %s", err, string(out))
	}
	return debPath, cleanup, nil
}

var wineHQUbuntuSource = func(codename string) (string, string) {
	codename = strings.ToLower(strings.TrimSpace(codename))
	file := fmt.Sprintf("winehq-%s.sources", codename)
	return fmt.Sprintf("https://dl.winehq.org/wine-builds/ubuntu/dists/%s/%s", codename, file), filepath.Join(wineHQSourceDir, file)
}

var wineHQDebianSource = func(codename string) (string, string) {
	codename = strings.ToLower(strings.TrimSpace(codename))
	file := fmt.Sprintf("winehq-%s.sources", codename)
	return fmt.Sprintf("https://dl.winehq.org/wine-builds/debian/dists/%s/%s", codename, file), filepath.Join(wineHQSourceDir, file)
}

func readWindroseOSRelease(path string) (osReleaseInfo, error) {
	content, err := os.ReadFile(path)
	if err != nil {
		return osReleaseInfo{}, fmt.Errorf("read os-release: %w", err)
	}
	return parseOSRelease(string(content)), nil
}

func parseOSRelease(content string) osReleaseInfo {
	var info osReleaseInfo
	for _, line := range strings.Split(content, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		value = strings.Trim(value, `"'`)
		switch key {
		case "ID":
			info.ID = strings.ToLower(value)
		case "VERSION_CODENAME":
			info.VersionCodename = strings.ToLower(value)
		case "UBUNTU_CODENAME":
			if info.VersionCodename == "" {
				info.VersionCodename = strings.ToLower(value)
			}
		case "ID_LIKE":
			info.IDLike = strings.ToLower(value)
		}
	}
	return info
}

func ensureI386Architecture(output *strings.Builder) (bool, error) {
	out, err := runWindroseCommand("dpkg", []string{"--print-foreign-architectures"}, nil, output)
	if err != nil {
		return false, err
	}
	for _, arch := range strings.Fields(out) {
		if arch == "i386" {
			appendOutput(output, "windrose_i386_architecture=present")
			return false, nil
		}
	}
	if _, err := runWindroseCommand("dpkg", []string{"--add-architecture", "i386"}, nil, output); err != nil {
		return false, err
	}
	appendOutput(output, "windrose_i386_architecture=added")
	return true, nil
}

func ensureWineHQKey(output *strings.Builder) (bool, error) {
	if st, err := os.Stat(wineHQKeyPath); err == nil && st.Size() > 0 {
		appendOutput(output, "windrose_winehq_key=present")
		return false, nil
	}
	if err := os.MkdirAll(wineHQKeyringDir, 0o755); err != nil {
		return false, fmt.Errorf("create WineHQ keyring dir: %w", err)
	}
	if err := os.Chmod(wineHQKeyringDir, 0o755); err != nil {
		return false, fmt.Errorf("chmod WineHQ keyring dir: %w", err)
	}
	if err := downloadFileAtomic(wineHQKeyURL, wineHQKeyPath, 0o644); err != nil {
		return false, err
	}
	appendOutput(output, "windrose_winehq_key=downloaded url="+wineHQKeyURL)
	return true, nil
}

func ensureWineHQSource(sourceURL, sourcePath string, output *strings.Builder) (bool, error) {
	if !strings.HasPrefix(filepath.Clean(sourcePath), filepath.Clean(wineHQSourceDir)+string(os.PathSeparator)) {
		return false, fmt.Errorf("invalid WineHQ source path: %s", sourcePath)
	}
	if existing, err := os.ReadFile(sourcePath); err == nil && strings.Contains(string(existing), "dl.winehq.org/wine-builds/ubuntu") {
		appendOutput(output, "windrose_winehq_source=present path="+sourcePath)
		return false, nil
	}
	if err := os.MkdirAll(wineHQSourceDir, 0o755); err != nil {
		return false, fmt.Errorf("create WineHQ source dir: %w", err)
	}
	if err := downloadFileAtomic(sourceURL, sourcePath, 0o644); err != nil {
		return false, err
	}
	appendOutput(output, "windrose_winehq_source=downloaded path="+sourcePath)
	return true, nil
}

func downloadFileAtomic(url, dest string, perm os.FileMode) error {
	resp, err := wineDepsHTTPClient.Get(url)
	if err != nil {
		return fmt.Errorf("download %s: %w", url, err)
	}
	defer func() { _ = resp.Body.Close() }()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("download %s failed: HTTP %d", url, resp.StatusCode)
	}
	tmp, err := os.CreateTemp(filepath.Dir(dest), "."+filepath.Base(dest)+".*.tmp")
	if err != nil {
		return fmt.Errorf("create temp file for %s: %w", dest, err)
	}
	tmpName := tmp.Name()
	defer func() { _ = os.Remove(tmpName) }()
	if _, err := io.Copy(tmp, resp.Body); err != nil {
		_ = tmp.Close()
		return fmt.Errorf("write temp file for %s: %w", dest, err)
	}
	if err := tmp.Chmod(perm); err != nil {
		_ = tmp.Close()
		return fmt.Errorf("chmod temp file for %s: %w", dest, err)
	}
	if err := tmp.Close(); err != nil {
		return fmt.Errorf("close temp file for %s: %w", dest, err)
	}
	if err := os.Rename(tmpName, dest); err != nil {
		return fmt.Errorf("install %s: %w", dest, err)
	}
	return nil
}

func missingDebPackages(packages []string) []string {
	missing := make([]string, 0, len(packages))
	for _, pkg := range packages {
		if !isDebPackageInstalled(pkg) {
			missing = append(missing, pkg)
		}
	}
	return missing
}

func isDebPackageInstalled(pkg string) bool {
	out, err := windroseDepsRunner.Run("dpkg-query", []string{"-W", "-f=${Status}", pkg}, nil)
	return err == nil && strings.Contains(out, "install ok installed")
}

func isDebPackageAvailable(pkg string) bool {
	_, err := windroseDepsRunner.Run("apt-cache", []string{"show", pkg}, nil)
	return err == nil
}

func logWindroseRuntimeTools(output *strings.Builder) {
	for _, tool := range []string{"wine", "xvfb-run", "taskset", "screen"} {
		if path, err := windroseDepsRunner.LookPath(tool); err == nil {
			appendOutput(output, "windrose_tool_"+tool+"="+path)
		} else {
			appendOutput(output, "windrose_tool_"+tool+"=missing")
		}
	}
	if winePath, err := windroseDepsRunner.LookPath("wine"); err == nil {
		if version, err := windroseDepsRunner.Run(winePath, []string{"--version"}, nil); err == nil {
			appendOutput(output, "windrose_wine_version="+strings.TrimSpace(version))
		}
	}
}

func debianNonInteractiveEnv() []string { return []string{"DEBIAN_FRONTEND=noninteractive"} }

func runWindroseCommand(name string, args []string, env []string, output *strings.Builder) (string, error) {
	cmdOutput, err := windroseDepsRunner.Run(name, args, env)
	appendOutput(output, fmt.Sprintf("cmd=%s %s", name, strings.Join(args, " ")))
	if strings.TrimSpace(cmdOutput) != "" {
		appendOutput(output, strings.TrimSpace(cmdOutput))
	}
	if err != nil {
		return cmdOutput, fmt.Errorf("command %s %s failed: %w; output=%s", name, strings.Join(args, " "), err, strings.TrimSpace(cmdOutput))
	}
	return cmdOutput, nil
}

func runCommandCaptureWithEnv(name string, args []string, env []string) (string, error) {
	cmd := exec.Command(name, args...)
	if len(env) > 0 {
		cmd.Env = append(os.Environ(), env...)
	}
	out, err := cmd.CombinedOutput()
	return string(out), err
}
