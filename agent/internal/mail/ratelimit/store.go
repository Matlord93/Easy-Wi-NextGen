package ratelimit

import (
	"context"
	"database/sql"
	"fmt"
	"time"
)

type CounterState struct {
	CounterWindowStart time.Time
	CurrentCount       int
	BlockedUntil       *time.Time
	Blocked            bool
}

type rowScanner interface {
	Scan(dest ...any) error
}

type queryRower interface {
	QueryRowContext(ctx context.Context, query string, args ...any) rowScanner
}

type Store struct {
	db queryRower
}

type sqlDBAdapter struct {
	db *sql.DB
}

func (a sqlDBAdapter) QueryRowContext(ctx context.Context, query string, args ...any) rowScanner {
	return a.db.QueryRowContext(ctx, query, args...)
}

func NewStore(db *sql.DB) *Store {
	return &Store{db: sqlDBAdapter{db: db}}
}

func NewStoreWithQueryRower(db queryRower) *Store {
	return &Store{db: db}
}

const incrementSQL = `
WITH upsert AS (
    INSERT INTO mail_rate_limits (mailbox_id, customer_id, max_mails_per_hour, counter_window_start, current_count, blocked_until, created_at, updated_at)
    VALUES ($1, $2, $3, date_trunc('hour', $4::timestamp), $5, NULL, $4, $4)
    ON CONFLICT (mailbox_id)
    DO UPDATE SET
        counter_window_start = CASE
            WHEN mail_rate_limits.counter_window_start < date_trunc('hour', EXCLUDED.updated_at)
                THEN date_trunc('hour', EXCLUDED.updated_at)
            ELSE mail_rate_limits.counter_window_start
        END,
        current_count = CASE
            WHEN mail_rate_limits.counter_window_start < date_trunc('hour', EXCLUDED.updated_at)
                THEN EXCLUDED.current_count
            ELSE mail_rate_limits.current_count + EXCLUDED.current_count
        END,
        blocked_until = CASE
            WHEN mail_rate_limits.counter_window_start < date_trunc('hour', EXCLUDED.updated_at)
                THEN NULL
            WHEN (mail_rate_limits.current_count + EXCLUDED.current_count) > mail_rate_limits.max_mails_per_hour
                THEN date_trunc('hour', EXCLUDED.updated_at) + interval '1 hour'
            ELSE mail_rate_limits.blocked_until
        END,
        updated_at = EXCLUDED.updated_at
    RETURNING counter_window_start, current_count, blocked_until
)
SELECT counter_window_start, current_count, blocked_until
FROM upsert
`

func (s *Store) IncrementAndCheck(ctx context.Context, mailboxID int, customerID int, maxHourly int, now time.Time, increment int) (CounterState, error) {
	if increment < 1 {
		increment = 1
	}
	if maxHourly < 1 {
		maxHourly = 1
	}

	var (
		windowStart  time.Time
		count        int
		blockedUntil sql.NullTime
	)

	err := s.db.QueryRowContext(ctx, incrementSQL, mailboxID, customerID, maxHourly, now.UTC(), increment).Scan(&windowStart, &count, &blockedUntil)
	if err != nil {
		return CounterState{}, fmt.Errorf("increment mail rate limit counter: %w", err)
	}

	state := CounterState{CounterWindowStart: windowStart.UTC(), CurrentCount: count}
	if blockedUntil.Valid {
		v := blockedUntil.Time.UTC()
		state.BlockedUntil = &v
		state.Blocked = v.After(now.UTC())
	}

	return state, nil
}
