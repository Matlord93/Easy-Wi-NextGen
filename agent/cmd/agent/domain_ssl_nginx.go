package main

import (
	"fmt"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"time"
)

func bestWebrootForDomain(domain, payloadWebRoot string) (string, string, error) {
	vhost := filepath.Join("/etc/nginx/sites-available", domain)
	content, _ := os.ReadFile(vhost)
	config := string(content)
	re := regexp.MustCompile(`(?m)^\s*root\s+([^;]+);`)
	if m := re.FindStringSubmatch(config); len(m) > 1 {
		return strings.TrimSpace(m[1]), config, nil
	}
	if payloadWebRoot != "" {
		candidate := filepath.Join(payloadWebRoot, "public")
		if nginxFileExists(filepath.Join(candidate, "index.php")) || nginxFileExists(filepath.Join(candidate, "index.html")) {
			return candidate, config, nil
		}
		return payloadWebRoot, config, nil
	}
	return "", config, fmt.Errorf("unable to detect webroot")
}

func testACMEPath(domain, webroot string) (string, error) {
	challengeDir := filepath.Join(webroot, ".well-known", "acme-challenge")
	if err := os.MkdirAll(challengeDir, 0o755); err != nil {
		return "", err
	}
	file := filepath.Join(challengeDir, "easywi-acme-test.txt")
	content := []byte("easywi-acme-test " + time.Now().UTC().Format(time.RFC3339))
	if err := os.WriteFile(file, content, 0o644); err != nil {
		return "", err
	}
	url := fmt.Sprintf("http://%s/.well-known/acme-challenge/easywi-acme-test.txt", domain)
	if _, err := runCommandOutput("curl", "-f", "--max-time", "10", url); err != nil {
		return url, fmt.Errorf("ACME challenge path is not reachable. Expected webroot: %s", webroot)
	}
	return url, nil
}

func ensureNginxSSLForDomain(domain, webroot, fullchain, privkey string) (bool, error) {
	vhost := filepath.Join("/etc/nginx/sites-available", domain)
	content, err := os.ReadFile(vhost)
	if err != nil {
		return false, err
	}
	now := time.Now().UTC().Format("20060102150405")
	backup := fmt.Sprintf("%s.bak.%s", vhost, now)
	if err := os.WriteFile(backup, content, 0o644); err != nil {
		return false, err
	}
	newConf := string(content)
	if !strings.Contains(newConf, "listen 443 ssl") {
		sslBlock := fmt.Sprintf(`
server {
    listen 443 ssl http2;
    server_name %s;

    root %s;
    index index.php index.html;

    ssl_certificate %s;
    ssl_certificate_key %s;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/easywi/php-fpm/ws1.sock;
    }
}
`, domain, webroot, fullchain, privkey)
		if strings.Contains(newConf, "location /") && !strings.Contains(newConf, ".well-known/acme-challenge") {
			newConf = strings.Replace(newConf, "location / {", "location ^~ /.well-known/acme-challenge/ {\n        root "+webroot+";\n        try_files $uri =404;\n    }\n\n    location / {", 1)
			newConf = strings.Replace(newConf, "try_files $uri $uri/ /index.php?$query_string;", "return 301 https://$host$request_uri;", 1)
		}
		newConf += "\n" + sslBlock
	}
	if err := os.WriteFile(vhost, []byte(newConf), 0o644); err != nil {
		return false, err
	}
	if err := runCommand("nginx", "-t"); err != nil {
		_ = os.WriteFile(vhost, content, 0o644)
		return false, fmt.Errorf("nginx validation failed: %w", err)
	}
	if err := reloadNginx(); err != nil {
		return false, err
	}
	return true, nil
}

func nginxFileExists(path string) bool { _, err := os.Stat(path); return err == nil }
