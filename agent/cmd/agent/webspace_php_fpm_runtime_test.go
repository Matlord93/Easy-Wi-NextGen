package main

import (
	"reflect"
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
