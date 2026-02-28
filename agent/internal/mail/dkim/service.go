package dkim

import (
	"fmt"
	"path/filepath"
	"strings"
)

type KeyMetadata struct {
	Domain      string
	Selector    string
	PrivatePath string
	PublicDNS   string
}

func BuildMetadata(domain, selector, baseDir, publicKey string) (KeyMetadata, error) {
	domain = strings.ToLower(strings.TrimSpace(domain))
	selector = strings.TrimSpace(selector)
	if domain == "" || selector == "" {
		return KeyMetadata{}, fmt.Errorf("domain and selector are required")
	}
	privatePath := filepath.Join(baseDir, domain, selector+".private")
	return KeyMetadata{Domain: domain, Selector: selector, PrivatePath: privatePath, PublicDNS: publicKey}, nil
}
