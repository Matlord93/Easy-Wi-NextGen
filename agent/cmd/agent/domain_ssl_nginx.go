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
	vhost := nginxVhostPath(domain)
	content, _ := os.ReadFile(vhost)
	config := string(content)
	if root := parseNginxRoot(config); root != "" {
		return acmeWebrootForDocumentRoot(domain, root), config, nil
	}
	if payloadWebRoot != "" {
		candidate := filepath.Join(payloadWebRoot, "public")
		if nginxFileExists(filepath.Join(candidate, "index.php")) || nginxFileExists(filepath.Join(candidate, "index.html")) {
			return acmeWebrootForDocumentRoot(domain, candidate), config, nil
		}
		return acmeWebrootForDocumentRoot(domain, payloadWebRoot), config, nil
	}
	return "", config, fmt.Errorf("unable to detect webroot")
}

func testACMEPath(domain, webroot string) (string, error) {
	challengeDir := filepath.Join(webroot, ".well-known", "acme-challenge")
	if err := os.MkdirAll(challengeDir, 0o755); err != nil {
		return "", err
	}
	if err := os.Chmod(challengeDir, 0o755); err != nil {
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
	vhost := nginxVhostPath(domain)
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
	documentRoot := parseNginxRoot(newConf)
	if documentRoot == "" {
		documentRoot = webroot
	}
	newConf = ensureACMEChallengeLocation(newConf, webroot)
	if !strings.Contains(newConf, "listen 443 ssl") {
		sslBlock := fmt.Sprintf(`
server {
    listen 443 ssl http2;
    server_name %s;

    root %s;
    index index.php index.html;

    ssl_certificate %s;
    ssl_certificate_key %s;

%s    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/easywi/php-fpm/ws1.sock;
    }
}
`, domain, documentRoot, fullchain, privkey, acmeChallengeLocation(webroot))
		if strings.Contains(newConf, "location /") {
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

func nginxVhostPath(domain string) string {
	if dir := strings.TrimSpace(os.Getenv("EASYWI_NGINX_VHOST_DIR")); dir != "" {
		return filepath.Join(dir, domain+".conf")
	}
	easywiPath := filepath.Join("/etc/easywi/web/nginx/vhosts", domain+".conf")
	if nginxFileExists(easywiPath) {
		return easywiPath
	}
	return filepath.Join("/etc/nginx/sites-available", domain)
}

func parseNginxRoot(config string) string {
	re := regexp.MustCompile(`(?m)^\s*root\s+([^;]+);`)
	if m := re.FindStringSubmatch(config); len(m) > 1 {
		return strings.TrimSpace(m[1])
	}
	return ""
}

func acmeWebrootForDocumentRoot(domain, documentRoot string) string {
	documentRoot = filepath.Clean(strings.TrimSpace(documentRoot))
	if documentRoot == "." || documentRoot == string(filepath.Separator) {
		return documentRoot
	}
	laravelPublic := filepath.Join("/var/www", domain, "public", "public")
	if documentRoot == laravelPublic || strings.HasSuffix(documentRoot, string(filepath.Separator)+filepath.Join("public", "public")) {
		return filepath.Clean(filepath.Join(documentRoot, "..", ".."))
	}
	return documentRoot
}

func ensureACMEChallengeLocation(config, webroot string) string {
	location := acmeChallengeLocation(webroot)
	re := regexp.MustCompile(`(?ms)^\s*location \^~ /\.well-known/acme-challenge/ \{.*?^\s*\}\n\n?`)
	if re.MatchString(config) {
		return re.ReplaceAllString(config, location)
	}
	marker := "    location / {\n"
	if strings.Contains(config, marker) {
		return strings.Replace(config, marker, location+marker, 1)
	}
	return config
}

func acmeChallengeLocation(webroot string) string {
	challengeRoot := filepath.Join(webroot, ".well-known", "acme-challenge")
	return fmt.Sprintf(`    location ^~ /.well-known/acme-challenge/ {
        alias %s/;
        default_type "text/plain";
        try_files $uri =404;
        access_log off;
    }

`, challengeRoot)
}

func nginxFileExists(path string) bool { _, err := os.Stat(path); return err == nil }
