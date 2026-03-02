package configgen

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRenderPostfixVirtualMapGolden(t *testing.T) {
	snapshot := Snapshot{
		NodeID: "node-1",
		Domains: []DomainConfig{
			{Domain: "b.example.com", Mailboxes: []string{"z@b.example.com", "a@b.example.com"}},
			{Domain: "a.example.com", Mailboxes: []string{"x@a.example.com"}},
		},
	}

	rendered, err := RenderPostfixVirtualMap(snapshot)
	if err != nil {
		t.Fatalf("render error: %v", err)
	}

	expectedPath := filepath.Join("testdata", "postfix_virtual_mailbox_map.golden")
	expected, err := os.ReadFile(expectedPath)
	if err != nil {
		t.Fatalf("read golden: %v", err)
	}

	normalize := func(v string) string {
		v = strings.ReplaceAll(v, "\r\n", "\n")
		return strings.TrimSuffix(v, "\n")
	}

	actual := normalize(string(rendered.Content))
	want := normalize(string(expected))
	if actual != want {
		t.Fatalf("golden mismatch\nexpected:\n%s\nactual:\n%s", string(expected), string(rendered.Content))
	}
}
