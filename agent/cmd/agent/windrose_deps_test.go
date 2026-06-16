package main

import (
	"errors"
	"fmt"
	"io"
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
	// failPkgs lists package names whose apt-get install should fail.
	failPkgs     map[string]bool
	failCommands map[string]bool
}

func (f *fakeWindroseRunner) Run(name string, args []string, env []string) (string, error) {
	cmd := name + " " + strings.Join(args, " ")
	f.commands = append(f.commands, cmd)
	if f.failCommands[cmd] {
		return f.outputs[cmd], errors.New("command failed")
	}
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
		if len(args) == 2 && args[0] == "show" {
			pkg := args[1]
			if v, ok := f.outputs["apt-cache show "+pkg]; ok {
				return v, nil
			}
			if f.available[pkg] {
				return "Package: " + pkg + "\nVersion: 11.0~trixie-1\n", nil
			}
			return "", errors.New("not available")
		}
		if len(args) == 2 && args[0] == "policy" {
			pkg := args[1]
			if v, ok := f.outputs["apt-cache policy "+pkg]; ok {
				return v, nil
			}
			return "", errors.New("not available")
		}
		pkg := args[len(args)-1]
		if f.available[pkg] {
			return "Package: " + pkg, nil
		}
		return "", errors.New("not available")
	case "apt-get":
		if len(args) > 0 && args[0] == "install" {
			for _, arg := range args {
				if f.failPkgs[arg] {
					return "", errors.New("E: Unable to satisfy dependencies for " + arg)
				}
			}
		}
		return "ok", nil
	case "/usr/bin/wine":
		if out, ok := f.outputs[cmd]; ok {
			return out, nil
		}
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
	oldDearmor := dearmorReaderAtomic
	windroseDepsRunner = runner
	osReleasePath = filepath.Join(tmp, "os-release")
	wineDepsHTTPClient = server.Client()
	wineHQKeyURL = server.URL + "/wine-builds/winehq.key"
	wineHQKeyPath = filepath.Join(tmp, "keyrings", "winehq-archive.gpg")
	wineHQKeyringDir = filepath.Join(tmp, "keyrings")
	wineHQSourceDir = filepath.Join(tmp, "sources.list.d")
	dearmorReaderAtomic = func(r io.Reader, dest string, perm os.FileMode) error {
		content, err := io.ReadAll(r)
		if err != nil {
			return err
		}
		return writeFileAtomic(dest, append([]byte("gpg:"), content...), perm)
	}
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
		dearmorReaderAtomic = oldDearmor
	})
	return tmp, server.URL + "/wine-builds/ubuntu/dists/noble/winehq-noble.sources"
}

func TestWindroseConstantsUseGPGKeyringPath(t *testing.T) {
	if wineHQKeyPath != "/etc/apt/keyrings/winehq-archive.gpg" {
		t.Fatalf("unexpected WineHQ key path: %s", wineHQKeyPath)
	}
}

func TestNormalizeWineHQSourceUsesGPGKeyring(t *testing.T) {
	oldKey := wineHQKeyPath
	wineHQKeyPath = "/tmp/keyrings/winehq-archive.gpg"
	t.Cleanup(func() { wineHQKeyPath = oldKey })

	deb822 := "Types: deb\nURIs: https://dl.winehq.org/wine-builds/ubuntu\nSuites: resolute\nComponents: main\nArchitectures: amd64\nSigned-By: " + legacyWineHQKeyPath() + "\n"
	normalized := normalizeWineHQSource(deb822)
	if strings.Contains(normalized, legacyWineHQKeyPath()) || !strings.Contains(normalized, "Signed-By: "+wineHQKeyPath) {
		t.Fatalf("expected Deb822 source to use gpg keyring, got:\n%s", normalized)
	}

	classic := "deb https://dl.winehq.org/wine-builds/ubuntu resolute main\n"
	normalized = normalizeWineHQSource(classic)
	if !strings.Contains(normalized, "deb [signed-by="+wineHQKeyPath+"] https://dl.winehq.org/wine-builds/ubuntu resolute main") {
		t.Fatalf("expected classic source to receive signed-by gpg keyring, got:\n%s", normalized)
	}
}

func TestDecodePGPPublicKeyArmor(t *testing.T) {
	armored := strings.Join([]string{
		"-----BEGIN PGP PUBLIC KEY BLOCK-----",
		"Version: EasyWI test",
		"",
		"AQID",
		"BAUG",
		"=wxyz",
		"-----END PGP PUBLIC KEY BLOCK-----",
		"",
	}, "\n")
	decoded, err := decodePGPPublicKeyArmor(strings.NewReader(armored))
	if err != nil {
		t.Fatalf("decodePGPPublicKeyArmor failed: %v", err)
	}
	if string(decoded) != string([]byte{1, 2, 3, 4, 5, 6}) {
		t.Fatalf("unexpected decoded key bytes: %v", decoded)
	}
}

func TestDecodePGPPublicKeyArmorRejectsMissingBlock(t *testing.T) {
	_, err := decodePGPPublicKeyArmor(strings.NewReader("not an armored public key"))
	if err == nil || !strings.Contains(err.Error(), "missing PGP public key block") {
		t.Fatalf("expected missing block error, got %v", err)
	}
}

func TestParseOSReleaseUbuntuNoble(t *testing.T) {
	info := parseOSRelease("ID=ubuntu\nVERSION_CODENAME=noble\n")
	if info.ID != "ubuntu" || info.VersionCodename != "noble" {
		t.Fatalf("expected ubuntu noble, got %+v", info)
	}
}

func TestParseOSReleasePrefersUbuntuCodename(t *testing.T) {
	info := parseOSRelease("ID=ubuntu\nVERSION_CODENAME=jammy\nUBUNTU_CODENAME=questing\n")
	if info.VersionCodename != "questing" {
		t.Fatalf("expected UBUNTU_CODENAME to win, got %+v", info)
	}
}

func TestWindroseWineRequiresI386MatchesWineHQWoW64Distros(t *testing.T) {
	cases := []struct {
		info osReleaseInfo
		want bool
	}{
		{osReleaseInfo{ID: "ubuntu", VersionCodename: "noble"}, true},
		{osReleaseInfo{ID: "ubuntu", VersionCodename: "plucky"}, true},
		{osReleaseInfo{ID: "ubuntu", VersionCodename: "questing"}, false},
		{osReleaseInfo{ID: "ubuntu", VersionCodename: "resolute"}, false},
		{osReleaseInfo{ID: "debian", VersionCodename: "trixie"}, true},
		{osReleaseInfo{ID: "debian", VersionCodename: "forky"}, false},
	}
	for _, tc := range cases {
		if got := windroseWineRequiresI386(tc.info); got != tc.want {
			t.Fatalf("windroseWineRequiresI386(%+v)=%t, want %t", tc.info, got, tc.want)
		}
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

func TestWindroseUbuntuQuestingSkipsI386ForWoW64Packages(t *testing.T) {
	requests := []string{}
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests = append(requests, r.URL.Path)
		_, _ = w.Write([]byte("managed"))
	}))
	defer server.Close()
	runner := &fakeWindroseRunner{installed: map[string]bool{}, available: map[string]bool{}, outputs: map[string]string{}, paths: map[string]string{"wine": "/usr/bin/wine", "xvfb-run": "/usr/bin/xvfb-run", "taskset": "/usr/bin/taskset", "screen": "/usr/bin/screen"}}
	_, _ = setupWindroseDepsTest(t, runner, "ID=ubuntu\nVERSION_CODENAME=jammy\nUBUNTU_CODENAME=questing\n", server)
	oldWineSource := wineHQUbuntuSource
	wineHQUbuntuSource = func(codename string) (string, string) {
		return server.URL + "/wine-builds/ubuntu/dists/" + codename + "/winehq-" + codename + ".sources", filepath.Join(wineHQSourceDir, "winehq-"+codename+".sources")
	}
	t.Cleanup(func() { wineHQUbuntuSource = oldWineSource })

	var output strings.Builder
	if err := ensureWindroseWineDependencies(&output); err != nil {
		t.Fatalf("ensure failed: %v\n%s", err, output.String())
	}
	joined := strings.Join(runner.commands, "\n")
	for _, unwanted := range []string{"dpkg --add-architecture i386", "libgd3:i386", "wine-staging-i386"} {
		if strings.Contains(joined, unwanted) {
			t.Fatalf("did not expect %q for questing WoW64 packages, got:\n%s", unwanted, joined)
		}
	}
	if !strings.Contains(joined, "apt-get install -y --allow-downgrades libgd3:amd64") {
		t.Fatalf("expected only amd64 libgd package install, got:\n%s", joined)
	}
	if len(requests) != 2 || requests[0] != "/wine-builds/winehq.key" || requests[1] != "/wine-builds/ubuntu/dists/questing/winehq-questing.sources" {
		t.Fatalf("unexpected requests: %v", requests)
	}
	if !strings.Contains(output.String(), "windrose_wine_i386_required=false") {
		t.Fatalf("expected i386_required=false output, got:\n%s", output.String())
	}
}

func TestWindroseI386NotAddedWhenPresent(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { _, _ = w.Write([]byte("managed")) }))
	defer server.Close()
	runner := &fakeWindroseRunner{installed: map[string]bool{"winehq-stable": true, "screen": true, "xvfb": true, "xauth": true, "util-linux": true, "procps": true, "cabextract": true, "unzip": true, "p7zip-full": true, "curl": true, "wget": true, "ca-certificates": true, "tar": true, "fonts-liberation": true, "libgd3:amd64": true, "libgd3:i386": true}, outputs: map[string]string{"dpkg --print-foreign-architectures": "i386\n"}, paths: map[string]string{"wine": "/usr/bin/wine", "xvfb-run": "/usr/bin/xvfb-run", "taskset": "/usr/bin/taskset", "screen": "/usr/bin/screen"}}
	tmp, _ := setupWindroseDepsTest(t, runner, "ID=ubuntu\nVERSION_CODENAME=noble\n", server)
	_ = os.MkdirAll(filepath.Join(tmp, "keyrings"), 0o755)
	_ = os.WriteFile(filepath.Join(tmp, "keyrings", "winehq-archive.gpg"), []byte("key"), 0o644)
	_ = os.MkdirAll(filepath.Join(tmp, "sources.list.d"), 0o755)
	_ = os.WriteFile(filepath.Join(tmp, "sources.list.d", "winehq-noble.sources"), []byte("Types: deb\nURIs: https://dl.winehq.org/wine-builds/ubuntu\nSuites: noble\nComponents: main\nArchitectures: amd64\nSigned-By: "+wineHQKeyPath+"\n"), 0o644)
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

func TestWindroseWineHQUsesI386DummyWhenBothVariantsFail(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("managed"))
	}))
	defer server.Close()
	runner := &fakeWindroseRunner{
		installed: map[string]bool{},
		available: map[string]bool{"wine-staging-amd64": true},
		outputs: map[string]string{
			"apt-cache show wine-staging-amd64": "Package: wine-staging-amd64\nVersion: 11.9~trixie-1\n",
		},
		paths:    map[string]string{"wine": "/usr/bin/wine"},
		failPkgs: map[string]bool{"winehq-stable": true, "winehq-staging": true},
	}
	setupWindroseDepsTest(t, runner, "ID=debian\nVERSION_CODENAME=trixie\n", server)
	oldWineSource := wineHQDebianSource
	wineHQDebianSource = func(codename string) (string, string) {
		return server.URL + "/wine-builds/debian/dists/trixie/winehq-trixie.sources", filepath.Join(wineHQSourceDir, "winehq-trixie.sources")
	}
	t.Cleanup(func() { wineHQDebianSource = oldWineSource })

	// Replace the real dpkg-deb builder with a no-op stub.
	oldBuild := windroseBuildDummyDebFn
	windroseBuildDummyDebFn = func(pkgName, version string) (string, func(), error) {
		if pkgName != "wine-staging-i386" || version != "11.9~trixie-1" {
			return "", func() {}, fmt.Errorf("unexpected dummy build: pkg=%s version=%s", pkgName, version)
		}
		return "/tmp/wine-staging-i386_11.9~trixie-1_i386.deb", func() {}, nil
	}
	t.Cleanup(func() { windroseBuildDummyDebFn = oldBuild })

	// The final winehq-staging install must succeed (remove from failPkgs after the
	// dummy is installed — simulated by making the runner succeed for the second attempt).
	// The fake runner already succeeds for winehq-staging when invoked after dpkg -i
	// because the failPkgs check is per-package-name only and the dummy install
	// (dpkg -i) is a different sub-command path.
	// We instead allow a one-shot retry: remove winehq-staging from failPkgs after
	// the first failure so the second attempt (post-dummy) succeeds.
	origFailPkgs := runner.failPkgs
	runner.failPkgs = map[string]bool{"winehq-stable": true, "winehq-staging": true}
	firstStagingFailed := false
	oldRun := windroseDepsRunner
	windroseDepsRunner = &oneShotFailRunner{inner: runner, failPkgs: origFailPkgs, firstStagingFailed: &firstStagingFailed}
	t.Cleanup(func() { windroseDepsRunner = oldRun })

	var output strings.Builder
	if err := ensureWindroseWineDependencies(&output); err != nil {
		t.Fatalf("ensure failed: %v\n%s", err, output.String())
	}
	out := output.String()
	if !strings.Contains(out, "windrose_wine_package_failed=winehq-stable") {
		t.Fatalf("expected winehq-stable failure note, got:\n%s", out)
	}
	if !strings.Contains(out, "windrose_wine_package_failed=winehq-staging") {
		t.Fatalf("expected winehq-staging failure note, got:\n%s", out)
	}
	if !strings.Contains(out, "windrose_wine_i386_dummy_version=11.9~trixie-1") {
		t.Fatalf("expected dummy version note, got:\n%s", out)
	}
	if !strings.Contains(out, "windrose_wine_i386_dummy_installed=true") {
		t.Fatalf("expected dummy installed note, got:\n%s", out)
	}
	if !strings.Contains(out, "windrose_wine=winehq-staging:installed_with_i386_dummy") {
		t.Fatalf("expected installed_with_i386_dummy note, got:\n%s", out)
	}
}

func TestWindroseInstalledButUnusableTriggersI386DummyRepair(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("managed"))
	}))
	defer server.Close()
	runner := &fakeWindroseRunner{
		installed: map[string]bool{"winehq-staging": true},
		available: map[string]bool{"wine-staging-amd64": true},
		outputs: map[string]string{
			"apt-cache show wine-staging-amd64": "Package: wine-staging-amd64\nVersion: 11.9~trixie-1\n",
			"/usr/bin/wine --version":           "",
		},
		paths:        map[string]string{"wine": "/usr/bin/wine"},
		failPkgs:     map[string]bool{"winehq-stable": true},
		failCommands: map[string]bool{"/usr/bin/wine --version": true},
	}
	setupWindroseDepsTest(t, runner, "ID=debian\nVERSION_CODENAME=trixie\n", server)
	oldWineSource := wineHQDebianSource
	wineHQDebianSource = func(codename string) (string, string) {
		return server.URL + "/wine-builds/debian/dists/trixie/winehq-trixie.sources", filepath.Join(wineHQSourceDir, "winehq-trixie.sources")
	}
	t.Cleanup(func() { wineHQDebianSource = oldWineSource })
	oldBuild := windroseBuildDummyDebFn
	windroseBuildDummyDebFn = func(pkgName, version string) (string, func(), error) {
		return "/tmp/wine-staging-i386_" + version + "_i386.deb", func() {}, nil
	}
	t.Cleanup(func() { windroseBuildDummyDebFn = oldBuild })

	var output strings.Builder
	if err := ensureWindroseWineDependencies(&output); err != nil {
		t.Fatalf("ensure failed: %v\n%s", err, output.String())
	}
	out := output.String()
	if !strings.Contains(out, "windrose_wine_runtime=unusable") {
		t.Fatalf("expected unusable wine runtime note, got:\n%s", out)
	}
	if !strings.Contains(out, "windrose_wine=winehq-staging:installed_but_unusable") {
		t.Fatalf("expected installed_but_unusable note, got:\n%s", out)
	}
	if !strings.Contains(out, "windrose_wine=winehq-staging:installed_with_i386_dummy") {
		t.Fatalf("expected dummy repair note, got:\n%s", out)
	}
}

// oneShotFailRunner wraps fakeWindroseRunner and allows winehq-staging to
// succeed on its second apt-get install attempt (after the dummy is in place).
type oneShotFailRunner struct {
	inner              *fakeWindroseRunner
	failPkgs           map[string]bool
	firstStagingFailed *bool
}

func (r *oneShotFailRunner) Run(name string, args []string, env []string) (string, error) {
	if name == "apt-get" && len(args) > 0 && args[0] == "install" {
		for _, a := range args {
			if a == "winehq-staging" && r.failPkgs["winehq-staging"] {
				if !*r.firstStagingFailed {
					*r.firstStagingFailed = true
					r.inner.commands = append(r.inner.commands, name+" "+strings.Join(args, " "))
					return "", errors.New("E: Unable to satisfy dependencies for winehq-staging")
				}
				// Second attempt — succeed.
				r.inner.commands = append(r.inner.commands, name+" "+strings.Join(args, " "))
				return "ok", nil
			}
			if r.failPkgs[a] {
				r.inner.commands = append(r.inner.commands, name+" "+strings.Join(args, " "))
				return "", errors.New("E: Unable to satisfy dependencies for " + a)
			}
		}
	}
	return r.inner.Run(name, args, env)
}
func (r *oneShotFailRunner) LookPath(name string) (string, error) { return r.inner.LookPath(name) }

func TestWindroseWineHQFallsBackToStagingWhenStableFails(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("managed"))
	}))
	defer server.Close()
	runner := &fakeWindroseRunner{
		installed: map[string]bool{},
		available: map[string]bool{},
		outputs:   map[string]string{},
		paths:     map[string]string{"wine": "/usr/bin/wine"},
		failPkgs:  map[string]bool{"winehq-stable": true},
	}
	setupWindroseDepsTest(t, runner, "ID=debian\nVERSION_CODENAME=trixie\n", server)
	oldWineSource := wineHQDebianSource
	wineHQDebianSource = func(codename string) (string, string) {
		return server.URL + "/wine-builds/debian/dists/trixie/winehq-trixie.sources", filepath.Join(wineHQSourceDir, "winehq-trixie.sources")
	}
	t.Cleanup(func() { wineHQDebianSource = oldWineSource })

	var output strings.Builder
	if err := ensureWindroseWineDependencies(&output); err != nil {
		t.Fatalf("ensure failed: %v\n%s", err, output.String())
	}
	joined := strings.Join(runner.commands, "\n")
	if !strings.Contains(joined, "winehq-stable") {
		t.Fatal("expected winehq-stable to be attempted first")
	}
	if !strings.Contains(joined, "winehq-staging") {
		t.Fatal("expected winehq-staging to be tried as fallback")
	}
	out := output.String()
	if !strings.Contains(out, "windrose_wine_package_failed=winehq-stable") {
		t.Fatalf("expected winehq-stable failure note in output, got:\n%s", out)
	}
	if !strings.Contains(out, "windrose_wine=winehq-staging:installed") {
		t.Fatalf("expected winehq-staging installed note in output, got:\n%s", out)
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

func TestWindroseLibGD3PinnedToI386CandidateVersion(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte("managed"))
	}))
	defer server.Close()
	runner := &fakeWindroseRunner{
		installed: map[string]bool{},
		available: map[string]bool{},
		outputs: map[string]string{
			"apt-cache policy libgd3:i386": "libgd3:i386:\n  Installed: (none)\n  Candidate: 2.3.3-13\n",
		},
		paths: map[string]string{"wine": "/usr/bin/wine", "xvfb-run": "/usr/bin/xvfb-run", "taskset": "/usr/bin/taskset", "screen": "/usr/bin/screen"},
	}
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
	if !strings.Contains(joined, "apt-get install -y --allow-downgrades libgd3:amd64=2.3.3-13 libgd3:i386=2.3.3-13") {
		t.Fatalf("expected version-pinned libgd3 install, got:\n%s", joined)
	}
}
