package runtime

import (
	"testing"
	"time"
)

func TestBackoffBounded(t *testing.T) {
	b := Backoff{Base: time.Second, Max: 8 * time.Second}

	d := b.Next(10)
	if d > 8*time.Second || d < 4*time.Second {
		t.Fatalf("unexpected jittered duration: %s", d)
	}
}
