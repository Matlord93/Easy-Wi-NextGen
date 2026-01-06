package system

import (
	"strings"
	"testing"
)

func TestParseChecksumForAsset(t *testing.T) {
	data := `
# comment
abcd1234 invalid-line
aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  easywi-agent-linux-amd64
bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb  easywi-agent-linux-arm64
`

	checksum, err := parseChecksumForAsset(strings.NewReader(data), "easywi-agent-linux-arm64")
	if err != nil {
		t.Fatalf("expected checksum, got error: %v", err)
	}
	if checksum != strings.Repeat("b", 64) {
		t.Fatalf("unexpected checksum: %s", checksum)
	}
}

func TestParseChecksumForAssetMissing(t *testing.T) {
	data := "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa  easywi-agent-linux-amd64\n"

	_, err := parseChecksumForAsset(strings.NewReader(data), "easywi-agent-linux-arm64")
	if err == nil {
		t.Fatal("expected error for missing checksum")
	}
}
