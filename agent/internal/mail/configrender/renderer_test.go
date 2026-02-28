package configrender

import (
	"bytes"
	"testing"
	"time"
)

func TestRenderDeterministicByteIdentical(t *testing.T) {
	snapshot := Snapshot{
		Revision:  "rev-1",
		Generated: time.Date(2026, 2, 1, 12, 0, 0, 0, time.UTC),
		Domains:   []Domain{{Name: "B.example.com"}, {Name: "a.example.com"}},
		Users: []User{
			{Address: "z@a.example.com", PasswordHash: "x", MailboxPath: "/var/vmail/z", Enabled: true},
			{Address: "a@a.example.com", PasswordHash: "y", MailboxPath: "/var/vmail/a", Enabled: true},
		},
		Aliases:      []Alias{{Address: "sales@a.example.com", Destinations: []string{"b@a.example.com", "a@a.example.com"}, Enabled: true}},
		Forwardings:  []Forwarding{{Source: "f@a.example.com", Destination: "d@x.test", Enabled: true}},
		DKIMKeys:     []DKIMKeyMetadata{{Domain: "a.example.com", Selector: "mail202610", PrivateKeyPath: "/etc/opendkim/keys/a/mail202610.private", Enabled: true}},
		TrustedHosts: []string{"10.0.0.2", "10.0.0.1"},
	}

	renderer := NewRenderer()
	first, err := renderer.Render(snapshot)
	if err != nil {
		t.Fatalf("render 1 failed: %v", err)
	}
	second, err := renderer.Render(snapshot)
	if err != nil {
		t.Fatalf("render 2 failed: %v", err)
	}

	if len(first.Files) != len(second.Files) {
		t.Fatalf("different file count")
	}
	for i := range first.Files {
		if first.Files[i].Path != second.Files[i].Path {
			t.Fatalf("different file order at %d", i)
		}
		if !bytes.Equal(first.Files[i].Body, second.Files[i].Body) {
			t.Fatalf("file body differs for %s", first.Files[i].Path)
		}
	}
}

func TestRenderContainsExpectedOutputs(t *testing.T) {
	renderer := NewRenderer()
	bundle, err := renderer.Render(Snapshot{
		Revision: "rev-2",
		Domains:  []Domain{{Name: "example.com"}},
		Users:    []User{{Address: "user@example.com", PasswordHash: "hash", MailboxPath: "/var/vmail/user@example.com", Enabled: true}},
	})
	if err != nil {
		t.Fatalf("render failed: %v", err)
	}

	if len(bundle.Files) < 6 {
		t.Fatalf("expected multiple rendered files, got %d", len(bundle.Files))
	}
}
