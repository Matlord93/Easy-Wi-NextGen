# Mail RateLimit Atomicity Spec (MUST HAVE #7)

## 1) Design / Spec

Entscheidung: **atomarer Counter in PostgreSQL via UPSERT** (nicht in PHP, nicht nur lokal im Agent).

Begründung:
- Multi-Node Agent Setup benötigt konsistente globale Sicht je Mailbox.
- Lokaler Token-Bucket je Node würde bei mehreren Submission-Pfaden zu Drift/Race führen.
- Postgres `INSERT ... ON CONFLICT ... DO UPDATE` bietet atomare Increment- und Rollover-Logik pro `mailbox_id`.

Konsistenzmodell:
- **Strong per mailbox row** (single-row atomic upsert).
- Last-write gewinnt nicht blind: SQL enthält Rollover/Increment-Logik in einem Statement.
- Race Conditions zwischen Nodes werden durch Row-Level Konfliktauflösung im UPSERT korrekt serialisiert.

## 2) Datenmodell / Migration

`mail_rate_limits` erweitert um:
- `counter_window_start` (timestamp)
- `current_count` (int)
- `blocked_until` (timestamp nullable)

Migration: `core/migrations/Version20261015193000.php`
- zusätzliche Indizes:
  - `idx_mail_rate_limits_counter_window`
  - `idx_mail_rate_limits_blocked_until`
- Constraint: `current_count >= 0`

## 3) API / DTO

Panel zeigt Counters **read-only**:
- `GET /api/v1/admin/mail/rate-limits?domain=&limit=`

Response-Felder:
- mailbox/domain IDs
- `max_hourly_emails`, `max_recipients`
- `counter_window_start`, `current_count`, `blocked_until`

## 4) Agent Contract + SQL UPSERT

Go Store:
- `agent/internal/mail/ratelimit/store.go`
- Methode: `IncrementAndCheck(...)`

### Atomic SQL (Ausschnitt)
```sql
INSERT INTO mail_rate_limits (..., counter_window_start, current_count, blocked_until, ...)
VALUES (..., date_trunc('hour', $4::timestamp), $5, NULL, ...)
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
  END
RETURNING counter_window_start, current_count, blocked_until;
```

## 5) Tests / Edgecases (6)
1. Window rollover (new hour) setzt Counter zurück auf Increment.
2. Blocking wird gesetzt wenn `current_count > max_mails_per_hour`.
3. Unblock nach Fensterwechsel (`blocked_until` wird auf `NULL` gesetzt).
4. Clock skew: `blocked_until` in Vergangenheit => nicht blockiert.
5. DB Fehler propagiert als Fehlerfall.
6. Invalid inputs (`increment<=0`, `maxHourly<=0`) werden auf sichere Minima normalisiert.
