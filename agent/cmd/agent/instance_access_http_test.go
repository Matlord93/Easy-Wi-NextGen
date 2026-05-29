package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"golang.org/x/crypto/bcrypt"
)

func TestBuildProFTPDManagedConfigMatchesConfirmedVirtualHostTemplate(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origHostKey := proFTPDHostKeyPath
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		proFTPDHostKeyPath = origHostKey
	})
	proFTPDAuthUserFilePath = linuxAuthUserFile
	proFTPDHostKeyPath = linuxHostKey

	want := strings.Join([]string{
		"# BEGIN EASYWI MANAGED",
		"<IfModule mod_sftp.c>",
		"  <VirtualHost 0.0.0.0>",
		"    Port 2222",
		"    SFTPEngine on",
		"    SFTPLog /var/log/proftpd/easywi-sftp.log",
		"    SFTPHostKey /etc/proftpd/keys/easywi_rsa_key",
		"    AuthUserFile /var/lib/easywi/proftpd/passwd",
		"    RequireValidShell off",
		"    DefaultRoot ~",
		"    AllowOverwrite on",
		"    TimeoutIdle 600",
		"  </VirtualHost>",
		"</IfModule>",
		"# END EASYWI MANAGED",
	}, "\n") + "\n"
	if got := buildProFTPDManagedConfig(2222); got != want {
		t.Fatalf("managed config mismatch\nwant:\n%s\ngot:\n%s", want, got)
	}
}

func TestBuildProFTPDManagedConfigContainsMarkers(t *testing.T) {
	cfg := buildProFTPDManagedConfig(2222)
	if !strings.Contains(cfg, "# BEGIN EASYWI MANAGED") || !strings.Contains(cfg, "# END EASYWI MANAGED") {
		t.Fatalf("managed markers missing")
	}
	if !strings.Contains(cfg, "<VirtualHost 0.0.0.0>") {
		t.Fatalf("VirtualHost listener missing from config: %s", cfg)
	}
	if !strings.Contains(cfg, "Port 2222") {
		t.Fatalf("port missing from config")
	}
	if !strings.Contains(cfg, "SFTPEngine on") {
		t.Fatalf("SFTPEngine missing from config")
	}
	if strings.Index(cfg, "<VirtualHost 0.0.0.0>") > strings.Index(cfg, "SFTPEngine on") {
		t.Fatalf("expected SFTPEngine inside the VirtualHost block")
	}
	if !strings.Contains(cfg, "AllowOverwrite on") {
		t.Fatalf("AllowOverwrite must be enabled so SFTP users can overwrite/edit existing files: %s", cfg)
	}
	if strings.Contains(cfg, "MaxInstances") {
		t.Fatalf("MaxInstances must not be emitted in the VirtualHost block: %s", cfg)
	}
}

func TestHandleInstanceAccessHTTPInvalidInput(t *testing.T) {
	req := httptest.NewRequest(http.MethodPost, "/v1/instances/7/access/provision", strings.NewReader(`{"username":""}`))
	w := httptest.NewRecorder()
	handled := handleInstanceAccessHTTP(w, req, "7")
	if !handled {
		t.Fatalf("expected handler to handle path")
	}
	if w.Code != http.StatusUnprocessableEntity {
		t.Fatalf("expected 422, got %d", w.Code)
	}
	if !strings.Contains(w.Body.String(), "INVALID_INPUT") {
		t.Fatalf("expected INVALID_INPUT, got %s", w.Body.String())
	}
}

func TestMapAccessErr(t *testing.T) {
	if got := mapAccessErr(assertErr("PACKAGE_INSTALL_FAILED: boom")); got != "PACKAGE_INSTALL_FAILED" {
		t.Fatalf("unexpected code %s", got)
	}
	if got := mapAccessErr(assertErr("ROOT_INVALID: bad")); got != "ROOT_INVALID" {
		t.Fatalf("unexpected code %s", got)
	}
}

func TestPackageManagerOutputIndicatesAptListLock(t *testing.T) {
	output := "Reading package lists... E: Could not get lock /var/lib/apt/lists/lock. It is held by process 292518 (apt-get) E: Unable to lock directory /var/lib/apt/lists/"
	if !packageManagerOutputIndicatesLock(output) {
		t.Fatalf("expected apt lists lock output to be retryable")
	}
}

func TestRunPackageManagerCommandRetriesLockAndReturnsSuccess(t *testing.T) {
	origRun := accessRunCommandLogged
	origSleep := accessPackageManagerRetrySleep
	t.Cleanup(func() {
		accessRunCommandLogged = origRun
		accessPackageManagerRetrySleep = origSleep
	})
	accessPackageManagerRetrySleep = 0
	attempts := 0
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		attempts++
		if attempts == 1 {
			return "E: Could not get lock /var/lib/apt/lists/lock. It is held by process 292518 (apt-get)", errors.New("locked")
		}
		return "done", nil
	}

	out, err := runPackageManagerCommandWithRetry("apt-get", []string{"update"}, 2)
	if err != nil {
		t.Fatalf("expected retry to succeed, got err=%v output=%q", err, out)
	}
	if attempts != 2 {
		t.Fatalf("expected 2 attempts, got %d", attempts)
	}
	if !strings.Contains(out, "attempt 1/2 failed") || !strings.Contains(out, "attempt 2/2 succeeded") {
		t.Fatalf("expected retry diagnostics in output, got %q", out)
	}
}

func TestLinuxPackagesForDistro(t *testing.T) {
	tests := []struct {
		name string
		osID string
		like string
		want []string
	}{
		{
			name: "debian like distro uses crypto module",
			osID: "ubuntu",
			want: []string{"proftpd-basic", "proftpd-mod-crypto"},
		},
		{
			name: "rhel like distro keeps mod_sftp package",
			osID: "rocky",
			want: []string{"proftpd", "proftpd-utils", "proftpd-mod_sftp"},
		},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			got := linuxPackagesForDistro(tc.osID, tc.like)
			if strings.Join(got, ",") != strings.Join(tc.want, ",") {
				t.Fatalf("linuxPackagesForDistro(%q,%q)=%v, want %v", tc.osID, tc.like, got, tc.want)
			}
		})
	}
}

func TestProFTPDModuleDetectionRequiresModSFTP(t *testing.T) {
	withModule := "Compiled-in modules:\n  mod_core.c\n  mod_sftp.c\n  mod_auth_file.c\n"
	if !proFTPDModuleListHasSFTP(withModule) {
		t.Fatal("expected module list with mod_sftp.c to be accepted")
	}

	withoutModule := "Compiled-in modules:\n  mod_core.c\n  mod_auth_file.c\n"
	if proFTPDModuleListHasSFTP(withoutModule) {
		t.Fatal("expected module list without mod_sftp.c to be rejected")
	}

	err := assertErr("PROFTPD_SFTP_MODULE_MISSING: ProFTPD mod_sftp is not installed or enabled")
	if got := mapAccessErr(err); got != "PROFTPD_SFTP_MODULE_MISSING" {
		t.Fatalf("expected PROFTPD_SFTP_MODULE_MISSING, got %s", got)
	}
}

func TestCapabilitiesEndpoint(t *testing.T) {
	req := httptest.NewRequest(http.MethodGet, "/v1/access/capabilities", nil)
	w := httptest.NewRecorder()
	handled := handleAccessCapabilitiesHTTP(w, req)
	if !handled {
		t.Fatalf("expected handled=true")
	}
	if w.Code != http.StatusOK {
		t.Fatalf("expected 200, got %d", w.Code)
	}
	if !strings.Contains(w.Body.String(), "supported_backends") {
		t.Fatalf("missing supported_backends in response: %s", w.Body.String())
	}
}

func TestProvisionUserWritesBcryptHashAndRoot(t *testing.T) {
	usersFile := filepath.Join(t.TempDir(), "users.json")
	if err := upsertWindowsEmbeddedUserFile(usersFile, "gs_7", "secret-1", `C:\games\instance7`); err != nil {
		t.Fatalf("upsert failed: %v", err)
	}
	raw, err := os.ReadFile(usersFile)
	if err != nil {
		t.Fatal(err)
	}
	var users []map[string]any
	if err := json.Unmarshal(raw, &users); err != nil {
		t.Fatal(err)
	}
	if len(users) != 1 {
		t.Fatalf("expected 1 user, got %d", len(users))
	}
	hash, _ := users[0]["password_hash"].(string)
	if hash == "" || bcrypt.CompareHashAndPassword([]byte(hash), []byte("secret-1")) != nil {
		t.Fatalf("password hash invalid")
	}
	if users[0]["root_path"] != `C:\games\instance7` {
		t.Fatalf("root path mismatch: %v", users[0]["root_path"])
	}
}

func TestHealthReachabilityCheck(t *testing.T) {
	ln, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if closeErr := ln.Close(); closeErr != nil && !errors.Is(closeErr, net.ErrClosed) {
			t.Errorf("close listener: %v", closeErr)
		}
	})
	port := ln.Addr().(*net.TCPAddr).Port
	if err := checkTCPListening(port); err != nil {
		t.Fatalf("expected reachable listener: %v", err)
	}
}

type assertErr string

func (e assertErr) Error() string { return string(e) }

func TestInstallLinuxPackagesInstallsModulePackagesEvenWhenProftpdExists(t *testing.T) {
	origLookPath := accessLookPath
	origRun := accessRunCommandLogged
	t.Cleanup(func() {
		accessLookPath = origLookPath
		accessRunCommandLogged = origRun
	})

	accessLookPath = func(file string) (string, error) {
		switch file {
		case "proftpd", "apt-get":
			return "/usr/bin/" + file, nil
		default:
			return "", errors.New("missing")
		}
	}
	commands := []string{}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		commands = append(commands, name+" "+strings.Join(args, " "))
		return "", nil
	}

	packages := []string{"proftpd-basic", "proftpd-mod-crypto"}
	if err := installLinuxPackages(packages); err != nil {
		t.Fatalf("installLinuxPackages failed: %v", err)
	}
	joined := strings.Join(commands, "\n")
	if !strings.Contains(joined, "apt-get install -y proftpd-basic proftpd-mod-crypto") {
		t.Fatalf("expected apt-get install with module package despite proftpd binary, got:\n%s", joined)
	}
}

func TestEnableProFTPDSFTPModuleInFileIsIdempotent(t *testing.T) {
	path := filepath.Join(t.TempDir(), "modules.conf")
	if err := os.WriteFile(path, []byte("LoadModule mod_sftp.c mod_sftp.so\nLoadModule mod_tls.c\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	repaired, err := enableProFTPDSFTPModuleInFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if repaired {
		t.Fatal("expected already-enabled module file not to be repaired")
	}
	raw, _ := os.ReadFile(path)
	if got := strings.Count(string(raw), "LoadModule mod_sftp.c"); got != 1 {
		t.Fatalf("expected exactly one active module line, got %d in %q", got, string(raw))
	}
}

func TestEnableProFTPDSFTPModuleInFileUncommentsModule(t *testing.T) {
	path := filepath.Join(t.TempDir(), "modules.conf")
	if err := os.WriteFile(path, []byte("#LoadModule mod_sftp.c\nLoadModule mod_tls.c\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	repaired, err := enableProFTPDSFTPModuleInFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if !repaired {
		t.Fatal("expected commented module line to be repaired")
	}
	raw, _ := os.ReadFile(path)
	if !strings.Contains(string(raw), "LoadModule mod_sftp.c mod_sftp.so\n") {
		t.Fatalf("expected uncommented module line, got %q", string(raw))
	}
	if strings.Contains(string(raw), "#LoadModule mod_sftp.c") {
		t.Fatalf("expected commented module line to be removed, got %q", string(raw))
	}
}

func TestChooseProFTPDModuleConfigPathPrefersAlternativeLoadModuleFile(t *testing.T) {
	origCandidates := proFTPDModuleConfigCandidates
	origManaged := proFTPDManagedModulesPath
	t.Cleanup(func() {
		proFTPDModuleConfigCandidates = origCandidates
		proFTPDManagedModulesPath = origManaged
	})
	dir := t.TempDir()
	plain := filepath.Join(dir, "modules.conf")
	alt := filepath.Join(dir, "conf.modules.d", "00-base.conf")
	if err := os.MkdirAll(filepath.Dir(alt), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(plain, []byte("# no modules here\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(alt, []byte("LoadModule mod_tls.c mod_tls.so\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	proFTPDModuleConfigCandidates = func() []string { return []string{plain, alt} }
	proFTPDManagedModulesPath = func() (string, string, error) { return filepath.Join(dir, "managed.conf"), dir, nil }

	got, confDir, err := chooseProFTPDModuleConfigPath()
	if err != nil {
		t.Fatal(err)
	}
	if got != alt || confDir != "" {
		t.Fatalf("chooseProFTPDModuleConfigPath()=(%q,%q), want (%q,%q)", got, confDir, alt, "")
	}
}

func TestChooseProFTPDModuleConfigPathUsesManagedFileWhenOnlyMainConfigExists(t *testing.T) {
	origCandidates := proFTPDModuleConfigCandidates
	origManaged := proFTPDManagedModulesPath
	t.Cleanup(func() {
		proFTPDModuleConfigCandidates = origCandidates
		proFTPDManagedModulesPath = origManaged
	})
	dir := t.TempDir()
	mainConfig := filepath.Join(dir, "proftpd.conf")
	managed := filepath.Join(dir, "conf.d", "easywi-modules.conf")
	if err := os.WriteFile(mainConfig, []byte("ServerName test\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	proFTPDModuleConfigCandidates = func() []string { return []string{mainConfig} }
	proFTPDManagedModulesPath = func() (string, string, error) { return managed, filepath.Dir(managed), nil }

	got, confDir, err := chooseProFTPDModuleConfigPath()
	if err != nil {
		t.Fatal(err)
	}
	if got != managed || confDir != filepath.Dir(managed) {
		t.Fatalf("chooseProFTPDModuleConfigPath()=(%q,%q), want managed (%q,%q)", got, confDir, managed, filepath.Dir(managed))
	}
}

func TestEnableProFTPDSFTPModuleWritesManagedFileWhenModulesConfMissing(t *testing.T) {
	origCandidates := proFTPDModuleConfigCandidates
	origPatterns := proFTPDModuleSearchPatterns
	origManaged := proFTPDManagedModulesPath
	t.Cleanup(func() {
		proFTPDModuleConfigCandidates = origCandidates
		proFTPDModuleSearchPatterns = origPatterns
		proFTPDManagedModulesPath = origManaged
	})
	dir := t.TempDir()
	moduleSO := filepath.Join(dir, "usr", "lib64", "proftpd", "mod_sftp.so")
	if err := os.MkdirAll(filepath.Dir(moduleSO), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(moduleSO, []byte("so"), 0o644); err != nil {
		t.Fatal(err)
	}
	managed := filepath.Join(dir, "etc", "proftpd", "conf.d", "easywi-modules.conf")
	proFTPDModuleConfigCandidates = func() []string { return nil }
	proFTPDModuleSearchPatterns = func() []string { return []string{filepath.Join(dir, "usr", "lib64", "proftpd", "*.so")} }
	proFTPDManagedModulesPath = func() (string, string, error) { return managed, filepath.Dir(managed), nil }

	repaired, diag, err := enableProFTPDSFTPModuleWithDiagnostics()
	if err != nil {
		t.Fatal(err)
	}
	if !repaired || diag.ModuleConfigPath != managed || !diag.ModuleSOFound || diag.ModuleSOPath != moduleSO {
		t.Fatalf("unexpected repair result repaired=%t diag=%+v", repaired, diag)
	}
	raw, err := os.ReadFile(managed)
	if err != nil {
		t.Fatal(err)
	}
	if got := strings.Count(string(raw), "LoadModule mod_sftp.c mod_sftp.so"); got != 1 {
		t.Fatalf("expected one managed SFTP LoadModule line, got %d in %q", got, string(raw))
	}
}

func TestFindProFTPDSFTPModuleSOUsesProFTPDSharedModuleDirectory(t *testing.T) {
	origPatterns := proFTPDModuleSearchPatterns
	origLookPath := accessLookPath
	origRun := accessRunCommandLogged
	t.Cleanup(func() {
		proFTPDModuleSearchPatterns = origPatterns
		accessLookPath = origLookPath
		accessRunCommandLogged = origRun
	})
	dir := t.TempDir()
	sharedDir := filepath.Join(dir, "custom", "proftpd")
	moduleSO := filepath.Join(sharedDir, "mod_sftp.so")
	if err := os.MkdirAll(sharedDir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(moduleSO, []byte("so"), 0o644); err != nil {
		t.Fatal(err)
	}
	proFTPDModuleSearchPatterns = func() []string { return []string{filepath.Join(dir, "fallback", "mod_sftp.so")} }
	accessLookPath = func(file string) (string, error) {
		if file == "proftpd" {
			return "/usr/sbin/proftpd", nil
		}
		return "", errors.New("missing")
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		if name == "proftpd" && strings.Join(args, " ") == "-V" {
			return "Shared Module Directory: " + sharedDir + "\n", nil
		}
		return "", nil
	}

	got, searched := findProFTPDSFTPModuleSO()
	if got != moduleSO {
		t.Fatalf("expected proftpd -V shared module dir path %q, got %q (searched=%v)", moduleSO, got, searched)
	}
}

func TestFindProFTPDSFTPModuleSOChecksDebianAndRHELPaths(t *testing.T) {
	origPatterns := proFTPDModuleSearchPatterns
	t.Cleanup(func() { proFTPDModuleSearchPatterns = origPatterns })
	dir := t.TempDir()
	debian := filepath.Join(dir, "usr", "lib", "x86_64-linux-gnu", "proftpd", "mod_sftp.so")
	rhel := filepath.Join(dir, "usr", "lib64", "proftpd", "mod_sftp.so")
	for _, path := range []string{debian, rhel} {
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte("so"), 0o644); err != nil {
			t.Fatal(err)
		}
	}
	proFTPDModuleSearchPatterns = func() []string {
		return []string{
			filepath.Join(dir, "usr", "lib", "proftpd", "mod_sftp.so"),
			filepath.Join(dir, "usr", "lib64", "proftpd", "mod_sftp.so"),
			filepath.Join(dir, "usr", "lib", "*", "proftpd", "mod_sftp.so"),
		}
	}
	got, searched := findProFTPDSFTPModuleSO()
	if got != rhel {
		t.Fatalf("expected first existing RHEL-style path %q, got %q (searched=%v)", rhel, got, searched)
	}
}

func TestEnableProFTPDSFTPModuleMissingSOReportsDiagnostics(t *testing.T) {
	origCandidates := proFTPDModuleConfigCandidates
	origPatterns := proFTPDModuleSearchPatterns
	origLookPath := accessLookPath
	origRun := accessRunCommandLogged
	t.Cleanup(func() {
		proFTPDModuleConfigCandidates = origCandidates
		proFTPDModuleSearchPatterns = origPatterns
		accessLookPath = origLookPath
		accessRunCommandLogged = origRun
	})
	dir := t.TempDir()
	proFTPDModuleConfigCandidates = func() []string { return []string{filepath.Join(dir, "modules.conf")} }
	proFTPDModuleSearchPatterns = func() []string { return []string{filepath.Join(dir, "missing", "mod_sftp.so")} }
	accessLookPath = func(file string) (string, error) {
		if file == "apt-get" {
			return "/usr/bin/apt-get", nil
		}
		return "", errors.New("missing")
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		return name + " " + strings.Join(args, " ") + " failed", errors.New("install failed")
	}

	_, diag, err := enableProFTPDSFTPModuleWithDiagnostics()
	if err == nil {
		t.Fatal("expected missing module error")
	}
	msg := diag.String()
	for _, want := range []string{"module_so_found=false", "package_manager=apt-get", "attempted_packages=proftpd-mod-crypto", "searched_module_paths="} {
		if !strings.Contains(msg, want) {
			t.Fatalf("expected diagnostic %q in %q", want, msg)
		}
	}
}

func TestEnsureProFTPDAuthFileIsIdempotent(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origRun := accessRunCommandLogged
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		accessRunCommandLogged = origRun
	})
	tmpDir := t.TempDir()
	proFTPDAuthUserFilePath = filepath.Join(tmpDir, "proftpd", "passwd")
	accessRunCommandLogged = func(name string, args ...string) (string, error) { return "", nil }
	fileModeAssertionsSupported := chmodModeSupported(t, tmpDir, false, 0o640)
	dirModeAssertionsSupported := chmodModeSupported(t, tmpDir, true, 0o750)

	if err := ensureProFTPDAuthFile(); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(proFTPDAuthUserFilePath, []byte("existing\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := ensureProFTPDAuthFile(); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(proFTPDAuthUserFilePath)
	if err != nil {
		t.Fatal(err)
	}
	if fileModeAssertionsSupported && info.Mode().Perm() != 0o640 {
		t.Fatalf("expected auth file mode 0640, got %o", info.Mode().Perm())
	}
	dirInfo, err := os.Stat(filepath.Dir(proFTPDAuthUserFilePath))
	if err != nil {
		t.Fatal(err)
	}
	if dirModeAssertionsSupported && dirInfo.Mode().Perm() != 0o750 {
		t.Fatalf("expected auth dir mode 0750, got %o", dirInfo.Mode().Perm())
	}
	raw, err := os.ReadFile(proFTPDAuthUserFilePath)
	if err != nil {
		t.Fatal(err)
	}
	if string(raw) != "existing\n" {
		t.Fatalf("expected existing auth content to be preserved, got %q", string(raw))
	}
}

func chmodModeSupported(t *testing.T, dir string, isDir bool, mode os.FileMode) bool {
	t.Helper()
	probePath := filepath.Join(dir, "chmod-probe")
	if isDir {
		if err := os.Mkdir(probePath, 0o777); err != nil {
			t.Fatalf("create chmod probe dir: %v", err)
		}
	} else {
		if err := os.WriteFile(probePath, []byte("probe"), 0o666); err != nil {
			t.Fatalf("create chmod probe file: %v", err)
		}
	}
	defer func() {
		if err := os.RemoveAll(probePath); err != nil {
			t.Errorf("remove chmod probe: %v", err)
		}
	}()
	if err := os.Chmod(probePath, mode); err != nil {
		t.Logf("skipping exact chmod(%o) assertion because chmod probe failed: %v", mode, err)
		return false
	}
	info, err := os.Stat(probePath)
	if err != nil {
		t.Fatalf("stat chmod probe: %v", err)
	}
	if info.Mode().Perm() != mode {
		t.Logf("skipping exact chmod(%o) assertion because this filesystem reports %o after chmod", mode, info.Mode().Perm())
		return false
	}
	return true
}

func TestEnsureProFTPDUserPreservesExistingRootOwner(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("POSIX file owners are required for ProFTPD account mapping")
	}

	origAuth := proFTPDAuthUserFilePath
	origRunOutput := accessRunCommandOutput
	origRunLogged := accessRunCommandLogged
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		accessRunCommandOutput = origRunOutput
		accessRunCommandLogged = origRunLogged
	})

	tmpDir := t.TempDir()
	rootPath := filepath.Join(tmpDir, "gameserver")
	if err := os.Mkdir(rootPath, 0o750); err != nil {
		t.Fatal(err)
	}
	rootInfo, err := os.Stat(rootPath)
	if err != nil {
		t.Fatal(err)
	}
	rootUID, rootGID := fileOwnerIDs(rootInfo)
	if rootUID == 0 {
		if err := os.Chown(rootPath, 12345, 23456); err != nil {
			t.Skipf("test requires a non-root POSIX-owned temp directory and could not chown probe dir: %v", err)
		}
		rootInfo, err = os.Stat(rootPath)
		if err != nil {
			t.Fatal(err)
		}
		rootUID, rootGID = fileOwnerIDs(rootInfo)
	}
	if rootUID <= 0 || rootGID < 0 {
		t.Skipf("test requires a non-root POSIX-owned temp directory, got uid=%d gid=%d", rootUID, rootGID)
	}

	proFTPDAuthUserFilePath = filepath.Join(tmpDir, "proftpd", "passwd")
	accessRunCommandOutput = func(name string, args ...string) (string, error) {
		if name == "openssl" && strings.Join(args, " ") == "passwd -6 secret" {
			return "$6$hash", nil
		}
		return "", errors.New("unexpected command: " + name + " " + strings.Join(args, " "))
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		switch name + " " + strings.Join(args, " ") {
		case "id -u proftpd", "getent group nogroup", "chown root:nogroup " + filepath.Dir(proFTPDAuthUserFilePath), "chown root:nogroup " + proFTPDAuthUserFilePath:
			return "", nil
		default:
			return "", errors.New("unexpected command: " + name + " " + strings.Join(args, " "))
		}
	}

	if err := ensureProFTPDUser("sftp42", "secret", rootPath); err != nil {
		t.Fatal(err)
	}

	afterInfo, err := os.Stat(rootPath)
	if err != nil {
		t.Fatal(err)
	}
	afterUID, afterGID := fileOwnerIDs(afterInfo)
	if afterUID != rootUID || afterGID != rootGID {
		t.Fatalf("expected root owner to stay %d:%d, got %d:%d", rootUID, rootGID, afterUID, afterGID)
	}

	raw, err := os.ReadFile(proFTPDAuthUserFilePath)
	if err != nil {
		t.Fatal(err)
	}
	wantEntry := fmt.Sprintf("sftp42:$6$hash:%d:%d::%s:/usr/sbin/nologin", rootUID, rootGID, rootPath)
	if strings.TrimSpace(string(raw)) != wantEntry {
		t.Fatalf("unexpected auth entry\nwant: %s\ngot:  %s", wantEntry, strings.TrimSpace(string(raw)))
	}
}

func TestEnsureProFTPDUserRepairsWritableTree(t *testing.T) {
	if runtime.GOOS == "windows" {
		t.Skip("POSIX file owners are required for ProFTPD account mapping")
	}

	origAuth := proFTPDAuthUserFilePath
	origRunOutput := accessRunCommandOutput
	origRunLogged := accessRunCommandLogged
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		accessRunCommandOutput = origRunOutput
		accessRunCommandLogged = origRunLogged
	})

	tmpDir := t.TempDir()
	rootPath := filepath.Join(tmpDir, "gameserver")
	nestedDir := filepath.Join(rootPath, "game", "csgo", "addons", "configs", "fake_rcon")
	if err := os.MkdirAll(nestedDir, 0o700); err != nil {
		t.Fatal(err)
	}
	configPath := filepath.Join(nestedDir, "cache.ini")
	if err := os.WriteFile(configPath, []byte("cache"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(nestedDir, 0o500); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(configPath, 0o400); err != nil {
		t.Fatal(err)
	}
	rootInfo, err := os.Stat(rootPath)
	if err != nil {
		t.Fatal(err)
	}
	rootUID, rootGID := fileOwnerIDs(rootInfo)
	if rootUID == 0 {
		if err := os.Chown(rootPath, 12345, 23456); err != nil {
			t.Skipf("test requires a non-root POSIX-owned root directory and could not chown probe dir: %v", err)
		}
		rootInfo, err = os.Stat(rootPath)
		if err != nil {
			t.Fatal(err)
		}
		rootUID, rootGID = fileOwnerIDs(rootInfo)
	}
	if rootUID <= 0 || rootGID < 0 {
		t.Skipf("test requires a non-root POSIX-owned temp directory, got uid=%d gid=%d", rootUID, rootGID)
	}

	proFTPDAuthUserFilePath = filepath.Join(tmpDir, "proftpd", "passwd")
	accessRunCommandOutput = func(name string, args ...string) (string, error) {
		if name == "openssl" && strings.Join(args, " ") == "passwd -6 secret" {
			return "$6$hash", nil
		}
		return "", errors.New("unexpected command: " + name + " " + strings.Join(args, " "))
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		switch name + " " + strings.Join(args, " ") {
		case "id -u proftpd", "getent group nogroup", "chown root:nogroup " + filepath.Dir(proFTPDAuthUserFilePath), "chown root:nogroup " + proFTPDAuthUserFilePath:
			return "", nil
		default:
			return "", errors.New("unexpected command: " + name + " " + strings.Join(args, " "))
		}
	}

	if err := ensureProFTPDUser("sftp42", "secret", rootPath); err != nil {
		t.Fatal(err)
	}

	dirInfo, err := os.Stat(nestedDir)
	if err != nil {
		t.Fatal(err)
	}
	if dirInfo.Mode().Perm()&0o700 != 0o700 {
		t.Fatalf("expected nested directory to be owner-readable/writable/executable, got %o", dirInfo.Mode().Perm())
	}
	fileInfo, err := os.Stat(configPath)
	if err != nil {
		t.Fatal(err)
	}
	if fileInfo.Mode().Perm()&0o600 != 0o600 {
		t.Fatalf("expected nested file to be owner-readable/writable, got %o", fileInfo.Mode().Perm())
	}
}

func TestAuthUserFileConfigErrorDoesNotMapToMissingSFTPModule(t *testing.T) {
	output := "fatal: AuthUserFile '/var/lib/easywi/proftpd/passwd': No such file or directory on line 7 of '/etc/proftpd/conf.d/easywi-sftp.conf'"
	if !proFTPDTestOutputHasAuthUserFileError(output) {
		t.Fatalf("expected AuthUserFile error to be detected")
	}
	if proFTPDTestOutputHasSFTPModuleError(output) {
		t.Fatalf("AuthUserFile error in easywi-sftp.conf must not be treated as a mod_sftp load error")
	}
	if got := mapAccessErr(assertErr("CONFIG_INVALID: ProFTPD configuration test failed: " + output)); got != "CONFIG_INVALID" {
		t.Fatalf("expected CONFIG_INVALID, got %s", got)
	}
}

func TestEnsureProFTPDSFTPModuleInstalledAcceptsLoadedDSOConfig(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origCandidates := proFTPDModuleConfigCandidates
	origPatterns := proFTPDModuleSearchPatterns
	origLookPath := accessLookPath
	origRun := accessRunCommandLogged
	origDetectService := detectProFTPDServiceNameFunc
	origEnsureService := ensureServiceRunningFunc
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		proFTPDModuleConfigCandidates = origCandidates
		proFTPDModuleSearchPatterns = origPatterns
		accessLookPath = origLookPath
		accessRunCommandLogged = origRun
		detectProFTPDServiceNameFunc = origDetectService
		ensureServiceRunningFunc = origEnsureService
	})
	dir := t.TempDir()
	proFTPDAuthUserFilePath = filepath.Join(dir, "var", "lib", "easywi", "proftpd", "passwd")
	moduleSO := filepath.Join(dir, "usr", "lib", "proftpd", "mod_sftp.so")
	if err := os.MkdirAll(filepath.Dir(moduleSO), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(moduleSO, []byte("so"), 0o644); err != nil {
		t.Fatal(err)
	}
	modulesConf := filepath.Join(dir, "etc", "proftpd", "modules.conf")
	if err := os.MkdirAll(filepath.Dir(modulesConf), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(modulesConf, []byte("LoadModule mod_sftp.c\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	proFTPDModuleConfigCandidates = func() []string { return []string{modulesConf} }
	proFTPDModuleSearchPatterns = func() []string { return []string{moduleSO} }
	accessLookPath = func(file string) (string, error) {
		if file == "proftpd" {
			return "/usr/sbin/proftpd", nil
		}
		return "", errors.New("missing")
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		cmd := name + " " + strings.Join(args, " ")
		switch cmd {
		case "proftpd -l":
			return "Compiled-in modules:\n mod_core.c\n mod_dso.c\n", nil
		case "proftpd -t":
			return "Checking syntax\nmod_sftp/1.1.1\nSyntax check complete\n", nil
		case "proftpd -td10":
			return "dispatching auth request to mod_sftp/1.1.1\n", nil
		default:
			return "", nil
		}
	}
	detectProFTPDServiceNameFunc = func() (string, error) { return "proftpd", nil }
	ensureServiceRunningFunc = func(service string) error { return nil }

	if err := ensureProFTPDSFTPModuleInstalled(); err != nil {
		t.Fatalf("expected DSO-backed mod_sftp config to be accepted, got %v", err)
	}
	if _, err := os.Stat(proFTPDAuthUserFilePath); err != nil {
		t.Fatalf("expected auth file to be created before proftpd -t: %v", err)
	}
}

func TestCheckLinuxProFTPDHealthRepairsMissingAuthUserFile(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origHostKey := proFTPDHostKeyPath
	origConfDir := detectProFTPDConfDirFunc
	origCandidates := proFTPDModuleConfigCandidates
	origPatterns := proFTPDModuleSearchPatterns
	origLookPath := accessLookPath
	origRun := accessRunCommandLogged
	origRunOutput := accessRunCommandOutput
	origDetectService := detectProFTPDServiceNameFunc
	origEnsureService := ensureServiceRunningFunc
	origCheckTCP := checkTCPListeningFunc
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		proFTPDHostKeyPath = origHostKey
		detectProFTPDConfDirFunc = origConfDir
		proFTPDModuleConfigCandidates = origCandidates
		proFTPDModuleSearchPatterns = origPatterns
		accessLookPath = origLookPath
		accessRunCommandLogged = origRun
		accessRunCommandOutput = origRunOutput
		detectProFTPDServiceNameFunc = origDetectService
		ensureServiceRunningFunc = origEnsureService
		checkTCPListeningFunc = origCheckTCP
	})
	dir := t.TempDir()
	confDir := filepath.Join(dir, "etc", "proftpd", "conf.d")
	if err := os.MkdirAll(confDir, 0o755); err != nil {
		t.Fatal(err)
	}
	proFTPDAuthUserFilePath = filepath.Join(dir, "var", "lib", "easywi", "proftpd", "passwd")
	proFTPDHostKeyPath = filepath.Join(dir, "etc", "proftpd", "keys", "easywi_rsa_key")
	if err := os.MkdirAll(filepath.Dir(proFTPDHostKeyPath), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(proFTPDHostKeyPath, []byte("key"), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(confDir, "easywi-sftp.conf"), []byte(buildProFTPDManagedConfig(defaultAccessListenPort)), 0o644); err != nil {
		t.Fatal(err)
	}
	moduleSO := filepath.Join(dir, "usr", "lib", "proftpd", "mod_sftp.so")
	if err := os.MkdirAll(filepath.Dir(moduleSO), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(moduleSO, []byte("so"), 0o644); err != nil {
		t.Fatal(err)
	}
	modulesConf := filepath.Join(dir, "etc", "proftpd", "modules.conf")
	if err := os.WriteFile(modulesConf, []byte("LoadModule mod_sftp.c mod_sftp.so\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	detectProFTPDConfDirFunc = func() (string, error) { return confDir, nil }
	proFTPDModuleConfigCandidates = func() []string { return []string{modulesConf} }
	proFTPDModuleSearchPatterns = func() []string { return []string{moduleSO} }
	accessLookPath = func(file string) (string, error) {
		if file == "proftpd" || file == "systemctl" {
			return "/usr/bin/" + file, nil
		}
		return "", errors.New("missing")
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		cmd := name + " " + strings.Join(args, " ")
		switch cmd {
		case "proftpd -l":
			return "Compiled-in modules:\n mod_core.c\n mod_dso.c\n", nil
		case "proftpd -t":
			return "mod_sftp/1.1.1\nSyntax check complete\n", nil
		case "proftpd -td10":
			return "mod_sftp/1.1.1\n", nil
		default:
			return "", nil
		}
	}
	accessRunCommandOutput = func(name string, args ...string) (string, error) { return "active\n", nil }
	detectProFTPDServiceNameFunc = func() (string, error) { return "proftpd", nil }
	ensureServiceRunningFunc = func(service string) error { return nil }
	checkTCPListeningFunc = func(port int) error { return nil }

	if err := checkLinuxProFTPDHealth(); err != nil {
		t.Fatalf("expected healthcheck to repair missing auth file, got %v", err)
	}
	if _, err := os.Stat(proFTPDAuthUserFilePath); err != nil {
		t.Fatalf("expected auth file repair, got %v", err)
	}
}

func TestEnsureProFTPDConfigWritesManagedConfigIdempotently(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origHostKey := proFTPDHostKeyPath
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		proFTPDHostKeyPath = origHostKey
	})
	dir := t.TempDir()
	confDir := filepath.Join(dir, "conf.d")
	proFTPDAuthUserFilePath = filepath.Join(dir, "var", "lib", "easywi", "proftpd", "passwd")
	proFTPDHostKeyPath = filepath.Join(dir, "etc", "proftpd", "keys", "easywi_rsa_key")
	if err := os.MkdirAll(confDir, 0o755); err != nil {
		t.Fatal(err)
	}
	oldConfig := strings.Join([]string{
		"# BEGIN EASYWI MANAGED",
		"<IfModule mod_sftp.c>",
		"  <VirtualHost 0.0.0.0>",
		"    Port 2222",
		"    SFTPEngine on",
		"    MaxInstances 30",
		"  </VirtualHost>",
		"</IfModule>",
		"# END EASYWI MANAGED",
	}, "\n") + "\n"
	if err := os.WriteFile(filepath.Join(confDir, "easywi-sftp.conf"), []byte(oldConfig), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := ensureProFTPDConfig(confDir); err != nil {
		t.Fatal(err)
	}
	if err := ensureProFTPDConfig(confDir); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(filepath.Join(confDir, "easywi-sftp.conf"))
	if err != nil {
		t.Fatal(err)
	}
	cfg := string(raw)
	if strings.Count(cfg, "# BEGIN EASYWI MANAGED") != 1 || strings.Count(cfg, "<VirtualHost 0.0.0.0>") != 1 {
		t.Fatalf("expected one managed VirtualHost config, got %q", cfg)
	}
	if strings.Contains(cfg, "MaxInstances") {
		t.Fatalf("expected managed config without MaxInstances, got %q", cfg)
	}
	if !strings.Contains(cfg, "AuthUserFile "+proFTPDAuthUserFilePath) || !strings.Contains(cfg, "SFTPHostKey "+proFTPDHostKeyPath) {
		t.Fatalf("expected overridden paths in managed config, got %q", cfg)
	}
}

func TestCheckLinuxProFTPDHealthRewritesConfigAndRestartsWhenPortNotListening(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origHostKey := proFTPDHostKeyPath
	origConfDir := detectProFTPDConfDirFunc
	origCandidates := proFTPDModuleConfigCandidates
	origPatterns := proFTPDModuleSearchPatterns
	origLookPath := accessLookPath
	origRun := accessRunCommandLogged
	origRunOutput := accessRunCommandOutput
	origDetectService := detectProFTPDServiceNameFunc
	origEnsureService := ensureServiceRunningFunc
	origCheckTCP := checkTCPListeningFunc
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		proFTPDHostKeyPath = origHostKey
		detectProFTPDConfDirFunc = origConfDir
		proFTPDModuleConfigCandidates = origCandidates
		proFTPDModuleSearchPatterns = origPatterns
		accessLookPath = origLookPath
		accessRunCommandLogged = origRun
		accessRunCommandOutput = origRunOutput
		detectProFTPDServiceNameFunc = origDetectService
		ensureServiceRunningFunc = origEnsureService
		checkTCPListeningFunc = origCheckTCP
	})
	dir := t.TempDir()
	confDir := filepath.Join(dir, "etc", "proftpd", "conf.d")
	if err := os.MkdirAll(confDir, 0o755); err != nil {
		t.Fatal(err)
	}
	proFTPDAuthUserFilePath = filepath.Join(dir, "var", "lib", "easywi", "proftpd", "passwd")
	proFTPDHostKeyPath = filepath.Join(dir, "etc", "proftpd", "keys", "easywi_rsa_key")
	if err := os.MkdirAll(filepath.Dir(proFTPDHostKeyPath), 0o700); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(proFTPDHostKeyPath, []byte("key"), 0o600); err != nil {
		t.Fatal(err)
	}
	legacyConfig := strings.Join([]string{
		"# BEGIN EASYWI MANAGED",
		"<IfModule mod_sftp.c>",
		"  SFTPEngine on",
		"  Port 2222",
		"</IfModule>",
		"# END EASYWI MANAGED",
	}, "\n") + "\n"
	if err := os.WriteFile(filepath.Join(confDir, "easywi-sftp.conf"), []byte(legacyConfig), 0o644); err != nil {
		t.Fatal(err)
	}
	moduleSO := filepath.Join(dir, "usr", "lib", "proftpd", "mod_sftp.so")
	if err := os.MkdirAll(filepath.Dir(moduleSO), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(moduleSO, []byte("so"), 0o644); err != nil {
		t.Fatal(err)
	}
	modulesConf := filepath.Join(dir, "etc", "proftpd", "modules.conf")
	if err := os.WriteFile(modulesConf, []byte("LoadModule mod_sftp.c mod_sftp.so\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	detectProFTPDConfDirFunc = func() (string, error) { return confDir, nil }
	proFTPDModuleConfigCandidates = func() []string { return []string{modulesConf} }
	proFTPDModuleSearchPatterns = func() []string { return []string{moduleSO} }
	accessLookPath = func(file string) (string, error) {
		if file == "proftpd" || file == "systemctl" || file == "ss" {
			return "/usr/bin/" + file, nil
		}
		return "", errors.New("missing")
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		cmd := name + " " + strings.Join(args, " ")
		switch cmd {
		case "proftpd -l":
			return "Compiled-in modules:\n mod_core.c\n mod_dso.c\n", nil
		case "proftpd -t":
			return "mod_sftp/1.1.1\nSyntax check complete\n", nil
		case "proftpd -td10":
			return "mod_sftp/1.1.1\n", nil
		case "ss -tulpn":
			return "tcp LISTEN 0 128 0.0.0.0:21 0.0.0.0:* users:((\"proftpd\",pid=1,fd=3))\n", nil
		default:
			return "", nil
		}
	}
	accessRunCommandOutput = func(name string, args ...string) (string, error) { return "active\n", nil }
	detectProFTPDServiceNameFunc = func() (string, error) { return "proftpd", nil }
	restarts := 0
	ensureServiceRunningFunc = func(service string) error { restarts++; return nil }
	checks := 0
	checkTCPListeningFunc = func(port int) error {
		checks++
		if checks == 1 {
			return errors.New("connection refused")
		}
		return nil
	}

	if err := checkLinuxProFTPDHealth(); err != nil {
		t.Fatalf("expected healthcheck to repair config/restart when port is closed, got %v", err)
	}
	if restarts == 0 {
		t.Fatal("expected ProFTPD restart during port repair")
	}
	raw, err := os.ReadFile(filepath.Join(confDir, "easywi-sftp.conf"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(raw), "<VirtualHost 0.0.0.0>") {
		t.Fatalf("expected config rewrite to VirtualHost listener, got %q", string(raw))
	}
	if strings.Contains(string(raw), "MaxInstances") {
		t.Fatalf("expected config rewrite to remove MaxInstances, got %q", string(raw))
	}
}

func TestPortNotListeningErrorDoesNotMapToMissingSFTPModule(t *testing.T) {
	if got := mapAccessErr(assertErr("PORT_NOT_LISTENING: ProFTPD is active but SFTP port 2222 is not reachable")); got != "PORT_NOT_LISTENING" {
		t.Fatalf("expected PORT_NOT_LISTENING, got %s", got)
	}
}

func TestEnsureProFTPDIncludeInFileIsIdempotent(t *testing.T) {
	path := filepath.Join(t.TempDir(), "proftpd.conf")
	confDir := filepath.Join(t.TempDir(), "conf.d")
	if err := os.WriteFile(path, []byte("ServerName test\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	repaired, err := ensureProFTPDIncludeInFile(path, confDir)
	if err != nil {
		t.Fatal(err)
	}
	if !repaired {
		t.Fatal("expected missing include to be repaired")
	}
	repaired, err = ensureProFTPDIncludeInFile(path, confDir)
	if err != nil {
		t.Fatal(err)
	}
	if repaired {
		t.Fatal("expected second include repair to be idempotent")
	}
	raw, _ := os.ReadFile(path)
	include := "Include " + confDir + "/*.conf"
	if got := strings.Count(string(raw), include); got != 1 {
		t.Fatalf("expected exactly one include line, got %d in %q", got, string(raw))
	}
}

func TestProvisionLinuxProFTPDUsesSharedReadyPath(t *testing.T) {
	origReady := ensureLinuxProFTPDSFTPReadyFunc
	origUser := ensureProFTPDUserFunc
	origHealth := checkLinuxProFTPDHealthFunc
	t.Cleanup(func() {
		ensureLinuxProFTPDSFTPReadyFunc = origReady
		ensureProFTPDUserFunc = origUser
		checkLinuxProFTPDHealthFunc = origHealth
	})
	calls := []string{}
	ensureLinuxProFTPDSFTPReadyFunc = func() error { calls = append(calls, "ready"); return nil }
	ensureProFTPDUserFunc = func(username, password, rootPath string) error {
		calls = append(calls, "user:"+username+":"+rootPath)
		return nil
	}
	checkLinuxProFTPDHealthFunc = func() error { calls = append(calls, "health"); return nil }

	if err := provisionLinuxProFTPD("gs_1", "secret", "/srv/game"); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(calls, ","); got != "ready,user:gs_1:/srv/game,health" {
		t.Fatalf("unexpected calls %s", got)
	}
}

func TestEnsureProFTPDAuthFileUsesProFTPDGroupAndNonWorldReadableModes(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origRun := accessRunCommandLogged
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		accessRunCommandLogged = origRun
	})
	tmpDir := t.TempDir()
	proFTPDAuthUserFilePath = filepath.Join(tmpDir, "easywi", "proftpd", "passwd")
	commands := []string{}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		commands = append(commands, name+" "+strings.Join(args, " "))
		switch name + " " + strings.Join(args, " ") {
		case "id -u proftpd", "getent group nogroup":
			return "ok", nil
		case "chown root:nogroup " + filepath.Dir(proFTPDAuthUserFilePath), "chown root:nogroup " + proFTPDAuthUserFilePath:
			return "", nil
		default:
			return "", nil
		}
	}

	if err := ensureProFTPDAuthFile(); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(proFTPDAuthUserFilePath)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm()&0o004 != 0 {
		t.Fatalf("expected auth file not to be world-readable, got %o", info.Mode().Perm())
	}
	if info.Mode().Perm() != 0o640 {
		t.Fatalf("expected auth file mode 0640, got %o", info.Mode().Perm())
	}
	dirInfo, err := os.Stat(filepath.Dir(proFTPDAuthUserFilePath))
	if err != nil {
		t.Fatal(err)
	}
	if dirInfo.Mode().Perm() != 0o750 {
		t.Fatalf("expected auth dir mode 0750, got %o", dirInfo.Mode().Perm())
	}
	joined := strings.Join(commands, "\n")
	if !strings.Contains(joined, "chown root:nogroup "+filepath.Dir(proFTPDAuthUserFilePath)) || !strings.Contains(joined, "chown root:nogroup "+proFTPDAuthUserFilePath) {
		t.Fatalf("expected auth dir/file chown to root:nogroup, got:\n%s", joined)
	}
}

func TestProFTPDPrimaryGroupFallsBackToRuntimeGroup(t *testing.T) {
	origRun := accessRunCommandLogged
	t.Cleanup(func() { accessRunCommandLogged = origRun })
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		switch name + " " + strings.Join(args, " ") {
		case "id -u proftpd":
			return "123", nil
		case "getent group nogroup":
			return "", errors.New("missing group")
		case "id -gn proftpd":
			return "proftpdgrp\n", nil
		default:
			return "", nil
		}
	}
	group, err := proFTPDPrimaryGroupName()
	if err != nil {
		t.Fatal(err)
	}
	if group != "proftpdgrp" {
		t.Fatalf("expected fallback runtime group, got %q", group)
	}
}

func TestEnsureProFTPDAppArmorAccessIsIdempotent(t *testing.T) {
	origProfile := proFTPDAppArmorProfilePath
	origLocal := proFTPDAppArmorLocalPath
	origActive := proFTPDAppArmorActiveFunc
	origLook := accessLookPath
	origRun := accessRunCommandLogged
	t.Cleanup(func() {
		proFTPDAppArmorProfilePath = origProfile
		proFTPDAppArmorLocalPath = origLocal
		proFTPDAppArmorActiveFunc = origActive
		accessLookPath = origLook
		accessRunCommandLogged = origRun
	})
	dir := t.TempDir()
	proFTPDAppArmorProfilePath = filepath.Join(dir, "etc", "apparmor.d", "proftpd")
	proFTPDAppArmorLocalPath = filepath.Join(dir, "etc", "apparmor.d", "local", "proftpd")
	if err := os.MkdirAll(filepath.Dir(proFTPDAppArmorProfilePath), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(proFTPDAppArmorProfilePath, []byte("profile proftpd {}\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	proFTPDAppArmorActiveFunc = func() bool { return true }
	reloads := 0
	accessLookPath = func(file string) (string, error) {
		if file == "apparmor_parser" {
			return "/sbin/apparmor_parser", nil
		}
		return "", errors.New("missing")
	}
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		if name == "apparmor_parser" && strings.Join(args, " ") == "-r "+proFTPDAppArmorProfilePath {
			reloads++
			return "", nil
		}
		return "", nil
	}

	if err := ensureProFTPDAppArmorAccess(); err != nil {
		t.Fatal(err)
	}
	if err := ensureProFTPDAppArmorAccess(); err != nil {
		t.Fatal(err)
	}
	raw, err := os.ReadFile(proFTPDAppArmorLocalPath)
	if err != nil {
		t.Fatal(err)
	}
	content := string(raw)
	for _, rule := range []string{"# EasyWI ProFTPD/SFTP auth files", "/var/lib/easywi/proftpd/ r,", "/var/lib/easywi/proftpd/passwd r,"} {
		if got := strings.Count(content, rule); got != 1 {
			t.Fatalf("expected AppArmor rule %q exactly once, got %d in %q", rule, got, content)
		}
	}
	if reloads != 2 {
		t.Fatalf("expected profile reload on each repair run, got %d", reloads)
	}
}

func TestEnsureLinuxProFTPDSFTPReadyRunsConfigtestBeforeRestart(t *testing.T) {
	origAuth := proFTPDAuthUserFilePath
	origHostKey := proFTPDHostKeyPath
	origConfDir := detectProFTPDConfDirFunc
	origService := detectProFTPDServiceNameFunc
	origEnsureService := ensureServiceRunningFunc
	origHealth := checkLinuxProFTPDHealthFunc
	origLook := accessLookPath
	origRun := accessRunCommandLogged
	origOutput := accessRunCommandOutput
	origProfile := proFTPDAppArmorProfilePath
	origLocal := proFTPDAppArmorLocalPath
	origActive := proFTPDAppArmorActiveFunc
	t.Cleanup(func() {
		proFTPDAuthUserFilePath = origAuth
		proFTPDHostKeyPath = origHostKey
		detectProFTPDConfDirFunc = origConfDir
		detectProFTPDServiceNameFunc = origService
		ensureServiceRunningFunc = origEnsureService
		checkLinuxProFTPDHealthFunc = origHealth
		accessLookPath = origLook
		accessRunCommandLogged = origRun
		accessRunCommandOutput = origOutput
		proFTPDAppArmorProfilePath = origProfile
		proFTPDAppArmorLocalPath = origLocal
		proFTPDAppArmorActiveFunc = origActive
	})
	dir := t.TempDir()
	proFTPDAuthUserFilePath = filepath.Join(dir, "easywi", "proftpd", "passwd")
	proFTPDHostKeyPath = filepath.Join(dir, "easywi_rsa_key")
	if err := os.WriteFile(proFTPDHostKeyPath, []byte("key"), 0o600); err != nil {
		t.Fatal(err)
	}
	confDir := filepath.Join(dir, "conf.d")
	detectProFTPDConfDirFunc = func() (string, error) { return confDir, nil }
	detectProFTPDServiceNameFunc = func() (string, error) { return "proftpd", nil }
	checkLinuxProFTPDHealthFunc = func() error { return nil }
	proFTPDAppArmorProfilePath = filepath.Join(dir, "missing-profile")
	proFTPDAppArmorLocalPath = filepath.Join(dir, "local-profile")
	proFTPDAppArmorActiveFunc = func() bool { return false }
	calls := []string{}
	ensureServiceRunningFunc = func(service string) error {
		calls = append(calls, "restart:"+service)
		return nil
	}
	accessLookPath = func(file string) (string, error) {
		switch file {
		case "apt-get", "proftpd":
			return "/usr/bin/" + file, nil
		default:
			return "", errors.New("missing")
		}
	}
	accessRunCommandOutput = func(name string, args ...string) (string, error) { return "", nil }
	accessRunCommandLogged = func(name string, args ...string) (string, error) {
		cmd := name + " " + strings.Join(args, " ")
		switch cmd {
		case "id -u easywi", "id -u proftpd", "getent group nogroup", "chown root:nogroup " + filepath.Dir(proFTPDAuthUserFilePath), "chown root:nogroup " + proFTPDAuthUserFilePath, "apt-get update", "apt-get install -y proftpd-basic proftpd-mod-crypto":
			return "", nil
		case "proftpd -l":
			return "mod_sftp.c\n", nil
		case "proftpd -t":
			calls = append(calls, "configtest")
			return "Syntax check complete", nil
		default:
			return "", nil
		}
	}

	if err := ensureLinuxProFTPDSFTPReady(); err != nil {
		t.Fatal(err)
	}
	if got := strings.Join(calls, ","); got != "configtest,restart:proftpd" {
		t.Fatalf("expected configtest before restart, got %s", got)
	}
}
