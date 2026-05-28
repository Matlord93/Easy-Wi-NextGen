package main

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

type fakeWindroseRunner struct {
	installed map[string]bool
	available map[string]bool
	paths     map[string]string
	outputs   map[string]string
	commands  []string
}

func (f *fakeWindroseRunner) Run(name string, args []string, env []string) (string, error) {
	cmd := name + " " + strings.Join(args, " ")
	f.commands = append(f.commands, cmd)
	switch name {
	case "dpkg":
		if len(args) == 1 && args[0] == "--print-foreign-architectures" {
			return f.outputs[cmd], nil
		}
		return "", nil
	case "dpkg-query":
		pkg := args[len(args)-1]
		if f.installed[pkg] {
			return "install ok installed", nil
		}
		return "", errors.New("not installed")
	case "apt-cache":
		pkg := args[len(args)-1]
		if f.available[pkg] {
			return "Package: " + pkg, nil
		}
		return "", errors.New("not available")
	case "apt-get":
		return "ok", nil
	case "/usr/bin/wine":
		return "wine-9.0", nil
	}
	return "", nil
}

func (f *fakeWindroseRunner) LookPath(name string) (string, error) {
	if path := f.paths[name]; path != "" {
		return path, nil
	}
	return "", errors.New("missing")
}

func setupWindroseDepsTest(t *testing.T, runner *fakeWindroseRunner, osRelease string, server *httptest.Server) (tmp string, sourceURL string) {
	t.Helper()
	tmp = t.TempDir()
	oldRunner := windroseDepsRunner
	oldOSRelease := osReleasePath
	oldClient := wineDepsHTTPClient
	oldKeyURL := wineHQKeyURL
	oldKey := wineHQKeyPath
	oldSourceDir := wineHQSourceDir
	oldKeyringDir := wineHQKeyringDir
	windroseDepsRunner = runner
	osReleasePath = filepath.Join(tmp, "os-release")
	wineDepsHTTPClient = server.Client()
	wineHQKeyURL = server.URL + "/wine-builds/winehq.key"
	wineHQKeyPath = filepath.Join(tmp, "keyrings", "winehq-archive.key")
	wineHQKeyringDir = filepath.Join(tmp, "keyrings")
	wineHQSourceDir = filepath.Join(tmp, "sources.list.d")
	if err := os.WriteFile(osReleasePath, []byte(osRelease), 0o644); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		windroseDepsRunner = oldRunner
		osReleasePath = oldOSRelease
		wineDepsHTTPClient = oldClient
		wineHQKeyURL = oldKeyURL
		wineHQKeyPath = oldKey
		wineHQSourceDir = oldSourceDir
		wineHQKeyringDir = oldKeyringDir
	})
	return tmp, server.URL + "/wine-builds/ubuntu/dists/noble/winehq-noble.sources"
}

func TestParseOSReleaseUbuntuNoble(t *testing.T) {
	info := parseOSRelease("ID=ubuntu\nVERSION_CODENAME=noble\n")
	if info.ID != "ubuntu" || info.VersionCodename != "noble" {
		t.Fatalf("expected ubuntu noble, got %+v", info)
	}
}

func TestWindroseConstantsUseOfficialWineHQURLs(t *testing.T) {
	oldSourceDir := wineHQSourceDir
	wineHQSourceDir = "/etc/apt/sources.list.d"
	t.Cleanup(func() { wineHQSourceDir = oldSourceDir })
	if wineHQKeyURL != "https://dl.winehq.org/wine-builds/winehq.key" {
		t.Fatalf("unexpected key URL: %s", wineHQKeyURL)
	}
	url, path := wineHQUbuntuSource("noble")
	if url != "https://dl.winehq.org/wine-builds/ubuntu/dists/noble/winehq-noble.sources" {
		t.Fatalf("unexpected source URL: %s", url)
	}
	if path != "/etc/apt/sources.list.d/winehq-noble.sources" {
		t.Fatalf("unexpected source path: %s", path)
	}
}

func TestWindroseUbuntuNobleInstallsRequiredPackagesAndAddsI386(t *testing.T) {
	requests := []string{}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests = append(requests, r.URL.Path)
		_, _ = w.Write([]byte("managed"))
	}))
	defer server.Close()
	runner := &fakeWindroseRunner{installed: map[string]bool{}, available: map[string]bool{}, outputs: map[string]string{}, paths: map[string]string{"wine": "/usr/bin/wine", "xvfb-run": "/usr/bin/xvfb-run", "taskset": "/usr/bin/taskset", "screen": "/usr/bin/screen"}}
	_, nobleURL := setupWindroseDepsTest(t, runner, "ID=ubuntu\nVERSION_CODENAME=noble\n", server)
	oldWineSource := wineHQUbuntuSource
	wineHQUbuntuSource = func(codename string) (string, string) {
		return nobleURL, filepath.Join(wineHQSourceDir, "winehq-"+codename+".sources")
	}
	t.Cleanup(func() { wineHQUbuntuSource = oldWineSource })

	var output strings.Builder
	if err := ensureWindroseWineDependencies(&output); err != nil {
		t.Fatalf("ensure failed: %v\n%s", err, output.String())
	}
	joined := strings.Join(runner.commands, "\n")
	for _, want := range []string{"dpkg --add-architecture i386", "apt-get update", "winehq-stable", "screen", "xvfb", "xauth", "libgd3:amd64", "libgd3:i386"} {
		if !strings.Contains(joined, want) {
			t.Fatalf("expected command log to contain %q, got:\n%s", want, joined)
		}
	}
	if !strings.Contains(joined, "apt-get install -y --install-recommends") || !strings.Contains(joined, "apt-get install -y --allow-downgrades libgd3:amd64 libgd3:i386") {
		t.Fatalf("expected special apt flags, got:\n%s", joined)
	}
	if len(requests) != 2 || requests[0] != "/wine-builds/winehq.key" || requests[1] != "/wine-builds/ubuntu/dists/noble/winehq-noble.sources" {
		t.Fatalf("unexpected downloads: %v", requests)
	}
}

func TestWindroseI386NotAddedWhenPresent(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("managed")) }))
	defer server.Close()
	runner := &fakeWindroseRunner{installed: map[string]bool{"winehq-stable": true, "screen": true, "xvfb": true, "xauth": true, "util-linux": true, "procps": true, "cabextract": true, "unzip": true, "p7zip-full": true, "curl": true, "wget": true, "ca-certificates": true, "tar": true, "fonts-liberation": true, "libgd3:amd64": true, "libgd3:i386": true}, outputs: map[string]string{"dpkg --print-foreign-architectures": "i386\n"}, paths: map[string]string{"wine": "/usr/bin/wine", "xvfb-run": "/usr/bin/xvfb-run", "taskset": "/usr/bin/taskset", "screen": "/usr/bin/screen"}}
	tmp, _ := setupWindroseDepsTest(t, runner, "ID=ubuntu\nVERSION_CODENAME=noble\n", server)
	_ = os.MkdirAll(filepath.Join(tmp, "keyrings"), 0o755)
	_ = os.WriteFile(filepath.Join(tmp, "keyrings", "winehq-archive.key"), []byte("key"), 0o644)
	_ = os.MkdirAll(filepath.Join(tmp, "sources.list.d"), 0o755)
	_ = os.WriteFile(filepath.Join(tmp, "sources.list.d", "winehq-noble.sources"), []byte("https://dl.winehq.org/wine-builds/ubuntu"), 0o644)
	var output strings.Builder
	if err := ensureWindroseWineDependencies(&output); err != nil {
		t.Fatalf("ensure failed: %v", err)
	}
	joined := strings.Join(runner.commands, "\n")
	if strings.Contains(joined, "dpkg --add-architecture i386") {
		t.Fatalf("did not expect i386 add command, got:\n%s", joined)
	}
}

func TestGameRoleTriggersWindroseDependenciesOnlyForGame(t *testing.T) {
	if !shouldEnsureWindroseWineDependencies("game", "debian") {
		t.Fatal("expected Debian-family game role to trigger Windrose Wine dependency installation")
	}
	for _, role := range []string{"web", "core", "mail", "db", "dns"} {
		if shouldEnsureWindroseWineDependencies(role, "debian") {
			t.Fatalf("role %s must not trigger Windrose Wine dependency installation", role)
		}
	}
	if shouldEnsureWindroseWineDependencies("game", "rhel") {
		t.Fatal("rhel game role must not trigger Ubuntu/WineHQ dependency installation")
	}
}

func TestWindroseUnsupportedDistributionMessage(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("managed")) }))
	defer server.Close()
	runner := &fakeWindroseRunner{installed: map[string]bool{}, outputs: map[string]string{}, paths: map[string]string{}}
	setupWindroseDepsTest(t, runner, "ID=fedora\nVERSION_CODENAME=\n", server)
	var output strings.Builder
	err := ensureWindroseWineDependencies(&output)
	if err == nil || !strings.Contains(err.Error(), "unsupported distribution") {
		t.Fatalf("expected unsupported distribution message, got %v", err)
	}
}

func TestWindroseConstantsUseOfficialWineHQDebianURLs(t *testing.T) {
	oldSourceDir := wineHQSourceDir
	wineHQSourceDir = "/etc/apt/sources.list.d"
	t.Cleanup(func() { wineHQSourceDir = oldSourceDir })
	url, path := wineHQDebianSource("bookworm")
	if url != "https://dl.winehq.org/wine-builds/debian/dists/bookworm/winehq-bookworm.sources" {
		t.Fatalf("unexpected Debian source URL: %s", url)
	}
	if path != "/etc/apt/sources.list.d/winehq-bookworm.sources" {
		t.Fatalf("unexpected Debian source path: %s", path)
	}
}

func TestWindroseDebianInstallsRequiredPackagesAndAddsI386(t *testing.T) {
	requests := []string{}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests = append(requests, r.URL.Path)
		_, _ = w.Write([]byte("managed"))
	}))
	defer server.Close()
	runner := &fakeWindroseRunner{installed: map[string]bool{}, available: map[string]bool{}, outputs: map[string]string{}, paths: map[string]string{"wine": "/usr/bin/wine", "xvfb-run": "/usr/bin/xvfb-run", "taskset": "/usr/bin/taskset", "screen": "/usr/bin/screen"}}
	_, _ = setupWindroseDepsTest(t, runner, "ID=debian\nVERSION_CODENAME=trixie\n", server)
	oldWineSource := wineHQDebianSource
	wineHQDebianSource = func(codename string) (string, string) {
		return server.URL + "/wine-builds/debian/dists/trixie/winehq-trixie.sources", filepath.Join(wineHQSourceDir, "winehq-"+codename+".sources")
	}
	t.Cleanup(func() { wineHQDebianSource = oldWineSource })

	var output strings.Builder
	if err := ensureWindroseWineDependencies(&output); err != nil {
		t.Fatalf("ensure failed: %v\n%s", err, output.String())
	}
	joined := strings.Join(runner.commands, "\n")
	for _, want := range []string{"dpkg --add-architecture i386", "apt-get update", "winehq-stable", "screen", "xvfb", "xauth", "libgd3:amd64", "libgd3:i386"} {
		if !strings.Contains(joined, want) {
			t.Fatalf("expected command log to contain %q, got:\n%s", want, joined)
		}
	}
	if len(requests) != 2 || requests[0] != "/wine-builds/winehq.key" || requests[1] != "/wine-builds/debian/dists/trixie/winehq-trixie.sources" {
		t.Fatalf("unexpected downloads: %v", requests)
	}
}
