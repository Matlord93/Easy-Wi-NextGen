package main

import (
	"fmt"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
)

var (
	domainLabelPattern   = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$`)
	allowedDirectiveKeys = map[string]struct{}{
		"client_max_body_size": {},
		"index":                {},
		"add_header":           {},
		"expires":              {},
		"charset":              {},
	}
)

func validateDomainName(domain string) error {
	domain = strings.ToLower(strings.TrimSpace(domain))
	if domain == "" || len(domain) > 253 {
		return fmt.Errorf("invalid_domain_name")
	}
	if strings.HasPrefix(domain, ".") || strings.HasSuffix(domain, ".") || strings.Contains(domain, "..") {
		return fmt.Errorf("invalid_domain_name")
	}
	labels := strings.Split(domain, ".")
	if len(labels) < 2 {
		return fmt.Errorf("invalid_domain_name")
	}
	for _, label := range labels {
		if !domainLabelPattern.MatchString(label) {
			return fmt.Errorf("invalid_domain_name")
		}
	}
	return nil
}

func parseWhitelistedDirectives(raw string) ([]string, error) {
	if strings.TrimSpace(raw) == "" {
		return nil, nil
	}
	lines := strings.Split(raw, "\n")
	directives := make([]string, 0, len(lines))
	for _, line := range lines {
		trimmed := strings.TrimSpace(line)
		if trimmed == "" {
			continue
		}
		if strings.ContainsAny(trimmed, "{}") {
			return nil, fmt.Errorf("forbidden_directive")
		}
		if strings.Contains(trimmed, "$") {
			return nil, fmt.Errorf("forbidden_directive")
		}
		parts := strings.Fields(trimmed)
		if len(parts) < 2 {
			return nil, fmt.Errorf("forbidden_directive")
		}
		key := strings.TrimSuffix(strings.ToLower(parts[0]), ";")
		if _, ok := allowedDirectiveKeys[key]; !ok {
			return nil, fmt.Errorf("forbidden_directive")
		}
		if !strings.HasSuffix(trimmed, ";") {
			trimmed += ";"
		}
		directives = append(directives, trimmed)
	}
	sort.Strings(directives)
	return directives, nil
}

func buildManagedNginxVhost(domainName string, aliases []string, docroot, phpFpmListen string, redirectHTTPS bool, directives []string) string {
	all := []string{domainName}
	all = append(all, aliases...)
	serverNames := strings.Join(uniqueNames(all), " ")

	extra := ""
	for _, directive := range directives {
		extra += "\n    " + directive
	}

	httpsRedirect := ""
	if redirectHTTPS {
		httpsRedirect = "\n    return 301 https://$host$request_uri;"
	}

	return fmt.Sprintf(`## Managed by Easy-Wi agent
server {
    listen 80;
    server_name %s;
    root %s;
    index index.php index.html;%s%s

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass %s;
    }
}
`, serverNames, filepath.Clean(docroot), httpsRedirect, extra, phpFpmListen)
}

func uniqueNames(values []string) []string {
	out := make([]string, 0, len(values))
	seen := map[string]struct{}{}
	for _, v := range values {
		v = strings.ToLower(strings.TrimSpace(v))
		if v == "" {
			continue
		}
		if _, ok := seen[v]; ok {
			continue
		}
		seen[v] = struct{}{}
		out = append(out, v)
	}
	return out
}
