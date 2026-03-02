package trace

import "testing"

func TestNormalizeGeneratesUUIDs(t *testing.T) {
	req, corr := Normalize("", "")
	if !isValidUUID(req) {
		t.Fatalf("request id invalid: %s", req)
	}
	if req != corr {
		t.Fatalf("expected correlation to fallback to request id")
	}
}

func TestNormalizePreservesValidIDs(t *testing.T) {
	req, corr := Normalize("77a96e16-ab58-4f39-a8b0-df57f12983ea", "0fed9f91-d67f-4f85-a640-4b44fd4ad6ae")
	if req != "77a96e16-ab58-4f39-a8b0-df57f12983ea" || corr != "0fed9f91-d67f-4f85-a640-4b44fd4ad6ae" {
		t.Fatalf("unexpected ids req=%s corr=%s", req, corr)
	}
}
