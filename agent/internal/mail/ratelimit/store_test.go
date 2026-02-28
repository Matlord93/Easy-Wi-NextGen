package ratelimit

import (
	"context"
	"database/sql"
	"errors"
	"strings"
	"testing"
	"time"
)

type fakeDB struct {
	row      rowScanner
	lastSQL  string
	lastArgs []any
}

func (f *fakeDB) QueryRowContext(_ context.Context, query string, args ...any) rowScanner {
	f.lastSQL = query
	f.lastArgs = args
	return f.row
}

type fakeRow struct {
	windowStart time.Time
	count       int
	blocked     *time.Time
	err         error
}

func (r fakeRow) Scan(dest ...any) error {
	if r.err != nil {
		return r.err
	}
	*(dest[0].(*time.Time)) = r.windowStart
	*(dest[1].(*int)) = r.count
	n := dest[2].(*sql.NullTime)
	if r.blocked != nil {
		n.Time = *r.blocked
		n.Valid = true
	} else {
		n.Valid = false
	}
	return nil
}

func TestSQLContainsAtomicUpsert(t *testing.T) {
	if !strings.Contains(incrementSQL, "ON CONFLICT (mailbox_id)") || !strings.Contains(incrementSQL, "date_trunc('hour'") {
		t.Fatalf("increment SQL must use atomic upsert and hour window")
	}
}

func TestIncrementBlockedState(t *testing.T) {
	now := time.Date(2026, 2, 28, 15, 10, 0, 0, time.UTC)
	blocked := now.Add(30 * time.Minute)
	db := &fakeDB{row: fakeRow{windowStart: now.Truncate(time.Hour), count: 520, blocked: &blocked}}
	store := NewStoreWithQueryRower(db)

	state, err := store.IncrementAndCheck(context.Background(), 1, 2, 500, now, 1)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !state.Blocked {
		t.Fatalf("expected blocked")
	}
}

func TestIncrementWindowRolloverUnblocks(t *testing.T) {
	now := time.Date(2026, 2, 28, 16, 0, 1, 0, time.UTC)
	db := &fakeDB{row: fakeRow{windowStart: now.Truncate(time.Hour), count: 1, blocked: nil}}
	store := NewStoreWithQueryRower(db)

	state, err := store.IncrementAndCheck(context.Background(), 1, 2, 500, now, 1)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if state.Blocked {
		t.Fatalf("expected unblocked after window rollover")
	}
}

func TestIncrementClockSkewPastBlockedUntil(t *testing.T) {
	now := time.Date(2026, 2, 28, 16, 0, 1, 0, time.UTC)
	past := now.Add(-time.Minute)
	db := &fakeDB{row: fakeRow{windowStart: now.Truncate(time.Hour), count: 10, blocked: &past}}
	store := NewStoreWithQueryRower(db)

	state, err := store.IncrementAndCheck(context.Background(), 1, 2, 500, now, 1)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if state.Blocked {
		t.Fatalf("expected not blocked when blocked_until in past")
	}
}

func TestIncrementDBError(t *testing.T) {
	db := &fakeDB{row: fakeRow{err: errors.New("db down")}}
	store := NewStoreWithQueryRower(db)
	_, err := store.IncrementAndCheck(context.Background(), 1, 2, 500, time.Now().UTC(), 1)
	if err == nil {
		t.Fatalf("expected db error")
	}
}

func TestIncrementNormalizesBounds(t *testing.T) {
	now := time.Now().UTC()
	db := &fakeDB{row: fakeRow{windowStart: now.Truncate(time.Hour), count: 1, blocked: nil}}
	store := NewStoreWithQueryRower(db)
	_, err := store.IncrementAndCheck(context.Background(), 1, 2, 0, now, 0)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if db.lastArgs[2].(int) != 1 {
		t.Fatalf("maxHourly should be normalized to 1")
	}
	if db.lastArgs[4].(int) != 1 {
		t.Fatalf("increment should be normalized to 1")
	}
}
