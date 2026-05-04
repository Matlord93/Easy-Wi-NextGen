package main

import "testing"

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
