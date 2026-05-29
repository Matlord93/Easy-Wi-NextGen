package main

import (
	"strings"
	"testing"
)

func TestRolePackagesIncludeSFTPStackForGameAndWeb(t *testing.T) {
	debianGame := rolePackages("game", "debian")
	if !containsString(debianGame, "proftpd-basic") {
		t.Fatalf("expected debian game packages to include proftpd-basic, got %v", debianGame)
	}
	if !containsString(debianGame, "proftpd-mod-crypto") {
		t.Fatalf("expected debian game packages to include proftpd-mod-crypto, got %v", debianGame)
	}

	rhelGame := rolePackages("game", "rhel")
	if !containsString(rhelGame, "proftpd") || !containsString(rhelGame, "proftpd-mod_sftp") {
		t.Fatalf("expected rhel game packages to include proftpd stack, got %v", rhelGame)
	}

	debianWeb := rolePackages("web", "debian")
	if !containsString(debianWeb, "proftpd-basic") {
		t.Fatalf("expected debian web packages to include proftpd-basic, got %v", debianWeb)
	}
	if !containsString(debianWeb, "certbot") {
		t.Fatalf("expected debian web packages to include certbot, got %v", debianWeb)
	}

	rhelWeb := rolePackages("web", "rhel")
	if !containsString(rhelWeb, "certbot") {
		t.Fatalf("expected rhel web packages to include certbot, got %v", rhelWeb)
	}
}

func TestWindowsRoleInstallPlanGameIncludesSSHService(t *testing.T) {
	plan := windowsRoleInstallPlan("game")
	if plan == nil {
		t.Fatal("expected windows game plan")
	}
	if !containsString(plan.services, "sshd") {
		t.Fatalf("expected windows game plan to start sshd, got %v", plan.services)
	}
}

func TestRolePackagesMailIncludeDovecotAndSasl(t *testing.T) {
	debianMail := rolePackages("mail", "debian")
	if !containsString(debianMail, "postfix") || !containsString(debianMail, "dovecot-imapd") || !containsString(debianMail, "libsasl2-modules") {
		t.Fatalf("expected debian mail packages to include postfix + dovecot-imapd + libsasl2-modules, got %v", debianMail)
	}

	rhelMail := rolePackages("mail", "rhel")
	if !containsString(rhelMail, "dovecot") || !containsString(rhelMail, "cyrus-sasl-plain") {
		t.Fatalf("expected rhel mail packages to include dovecot + cyrus-sasl-plain, got %v", rhelMail)
	}
}

func TestGameRolePackagesDoNotInstallTemurinJDK(t *testing.T) {
	for _, family := range []string{"debian", "rhel"} {
		packages := rolePackages("game", family)
		if containsString(packages, "temurin-25-jdk") {
			t.Fatalf("expected %s game packages not to include temurin-25-jdk, got %v", family, packages)
		}
	}
}

func TestMinecraftJavaRuntimeSpecs(t *testing.T) {
	specs := minecraftJavaRuntimeSpecs()
	if len(specs) != 4 {
		t.Fatalf("expected 4 minecraft java runtime specs, got %d", len(specs))
	}

	expectedTargets := map[string]string{
		"8":  "/opt/easywi/java/8",
		"16": "/opt/easywi/java/16",
		"17": "/opt/easywi/java/17",
		"21": "/opt/easywi/java/21",
	}
	for _, spec := range specs {
		expectedTarget, ok := expectedTargets[spec.version]
		if !ok {
			t.Fatalf("unexpected java runtime version %q", spec.version)
		}
		if spec.target != expectedTarget {
			t.Fatalf("expected java %s target %q, got %q", spec.version, expectedTarget, spec.target)
		}
		if !strings.Contains(spec.url, "/latest/"+spec.version+"/ga/linux/x64/") {
			t.Fatalf("expected java %s URL to use Adoptium linux x64 API, got %q", spec.version, spec.url)
		}
	}
}
