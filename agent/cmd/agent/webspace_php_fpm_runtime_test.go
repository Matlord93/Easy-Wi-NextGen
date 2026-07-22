package main

import (
	"reflect"
	"strings"
	"testing"
)

func TestPhpFpmRuntimeDirsForEasyWISocket(t *testing.T) {
	got := phpFpmRuntimeDirsForListen("/run/easywi/php-fpm/ws1.sock")
	want := []string{"/run/easywi", "/run/easywi/php-fpm"}
	if !reflect.DeepEqual(got, want) {
		t.Fatalf("expected runtime dirs %v, got %v", want, got)
	}
}

func TestPhpFpmRuntimeDirsIgnoresNonEasyWISocket(t *testing.T) {
	if got := phpFpmRuntimeDirsForListen("/run/php/php8.4-fpm.sock"); got != nil {
		t.Fatalf("expected non-EasyWI socket to be ignored, got %v", got)
	}
}

func TestPhpFpmTmpfilesRuleUsesNginxGroup(t *testing.T) {
	got := phpFpmTmpfilesRule("www-data")
	want := "d /run/easywi 0750 root www-data -\nd /run/easywi/php-fpm 0750 root www-data -\n"
	if got != want {
		t.Fatalf("expected tmpfiles rule %q, got %q", want, got)
	}
}

func TestPhpFpmPoolTemplateUsesBoundedWorkerDefaults(t *testing.T) {
	template := phpFpmPoolTemplate("customer", "customer", "customer", "www-data", "www-data", "/run/easywi/php-fpm/customer.sock", "/var/www/customer", "/var/www/customer/logs", "/var/www/customer/tmp", "8.4", nil)

	for _, directive := range []string{
		"pm.max_children = 4",
		"pm.max_requests = 250",
		"request_terminate_timeout = 120s",
		"php_admin_value[memory_limit] = 256M",
	} {
		if !strings.Contains(template, directive) {
			t.Fatalf("expected pool template to contain %q", directive)
		}
	}
}

func TestPhpFpmPoolTemplateCapsCustomerMemoryLimit(t *testing.T) {
	template := phpFpmPoolTemplate("customer", "customer", "customer", "www-data", "www-data", "/run/easywi/php-fpm/customer.sock", "/var/www/customer", "/var/www/customer/logs", "/var/www/customer/tmp", "8.4", map[string]string{"memory_limit": "-1"})

	if !strings.Contains(template, "php_admin_value[memory_limit] = 256M") {
		t.Fatal("expected unlimited customer memory setting to be capped")
	}
}
