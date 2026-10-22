package main

import (
	"strings"
	"testing"
)

func TestWebStackReloadRejectsMissingPHPAndFailedReloads(t *testing.T) {
	for _, tc := range []struct {
		name     string
		services map[string]bool
		succeeds bool
		want     bool
	}{
		{"no PHP", map[string]bool{"nginx": true}, true, false},
		{"reload and restart fail", map[string]bool{"php8.4-fpm": true}, false, false},
		{"FPM works", map[string]bool{"php8.4-fpm": true, "nginx": true}, true, true},
		{"Apache mod PHP", map[string]bool{"apache2": true}, true, true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			var log strings.Builder
			got := reloadWebStack(&log, func(s string) bool { return tc.services[s] }, func(string, string, *strings.Builder) bool { return tc.succeeds })
			if got != tc.want {
				t.Fatalf("success=%v want=%v details=%s", got, tc.want, log.String())
			}
		})
	}
}
func TestWebStackReloadCoversAllActivePHPVersionsAndFallback(t *testing.T) {
	var log strings.Builder
	calls := []string{}
	ok := reloadWebStack(&log, func(s string) bool { return s == "php8.3-fpm" || s == "php8.4-fpm" }, func(action, service string, out *strings.Builder) bool {
		calls = append(calls, action+" "+service)
		return action == "restart" || service == "php8.4-fpm"
	})
	if !ok {
		t.Fatal("restart fallback should succeed")
	}
	want := "reload php8.4-fpm,reload php8.3-fpm,restart php8.3-fpm"
	if strings.Join(calls, ",") != want {
		t.Fatalf("reload calls %v", calls)
	}
}
