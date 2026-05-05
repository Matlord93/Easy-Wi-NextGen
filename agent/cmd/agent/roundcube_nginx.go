package main

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

func normalizeRoute(route string) string {
	r := strings.TrimSpace(route)
	if r == "" {
		return "/roundcube"
	}
	if !strings.HasPrefix(r, "/") {
		r = "/" + r
	}
	return strings.TrimRight(r, "/")
}

func enableRoundcubeForWebspace(domain, route, roundcubePath string) (bool, bool, bool, error) {
	vhost := filepath.Join("/etc/nginx/sites-available", domain)
	orig, err := os.ReadFile(vhost)
	if err != nil {
		return false, false, false, err
	}
	cfg := string(orig)
	route = normalizeRoute(route)
	if strings.Contains(cfg, "location ^~ "+route+"/") {
		return false, false, true, nil
	}
	phpPass := "unix:/run/easywi/php-fpm/ws1.sock"
	if m := regexp.MustCompile(`(?m)^\s*fastcgi_pass\s+([^;]+);`).FindStringSubmatch(cfg); len(m) > 1 {
		phpPass = strings.TrimSpace(m[1])
	}
	loc := fmt.Sprintf(`
    location ^~ %s/ {
        alias %s/;
        index index.php index.html;
        try_files $uri $uri/ %s/index.php?$query_string;
    }

    location ~ ^%s/(.+\.php)$ {
        alias %s/$1;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME %s/$1;
        fastcgi_pass %s;
    }
`, route, roundcubePath, route, route, roundcubePath, roundcubePath, phpPass)

	cfg2 := insertRoundcubeLocations(cfg, loc)
	backup := fmt.Sprintf("%s.bak.%s", vhost, time.Now().UTC().Format("20060102150405"))
	if err := os.WriteFile(backup, orig, 0o644); err != nil {
		return false, false, false, err
	}
	if err := os.WriteFile(vhost, []byte(cfg2), 0o644); err != nil {
		return false, false, false, err
	}
	if err := runCommand("nginx", "-t"); err != nil {
		_ = os.WriteFile(vhost, orig, 0o644)
		return true, false, false, fmt.Errorf("nginx -t failed: %w", err)
	}
	if err := reloadNginx(); err != nil {
		return true, false, false, err
	}
	return true, true, false, nil
}

func insertRoundcubeLocations(cfg, loc string) string {
	idx443 := strings.Index(cfg, "listen 443")
	if idx443 >= 0 {
		end := strings.Index(cfg[idx443:], "}\n")
		if end > 0 {
			p := idx443 + end
			return cfg[:p] + loc + cfg[p:]
		}
	}
	idx80 := strings.Index(cfg, "listen 80")
	if idx80 >= 0 {
		end := strings.Index(cfg[idx80:], "}\n")
		if end > 0 {
			p := idx80 + end
			return cfg[:p] + loc + cfg[p:]
		}
	}
	return cfg + "\n" + loc
}
