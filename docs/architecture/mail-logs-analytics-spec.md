# Mail Logs Analytics Spec (Admin-fähig)

## 1) Design/Spec
`mail_logs` wird als zentrales, strukturiertes Event-Store für Mail-Analyse geführt.

Pflichtfelder:
- `id`, `created_at`
- `level` (`info|warning|error|critical`)
- `source` (`postfix|dovecot|opendkim|agent|dns|rspamd`)
- `message`
- `domain_id` (FK)
- `user_id` (nullable FK)
- `event_type` (`delivery|auth|tls|spam|bounce|dns_check|queue|policy`)
- `payload` JSONB (normalisiert + flexibel)

## 2) Datenmodell/Migration
Migration: `core/migrations/Version20261015181000.php`
- erweitert/bereinigt `mail_logs` auf das Analytics-Schema
- Backfill aus Legacy-Spalten in `message`/`payload`
- CHECK-Constraints für `level/source/event_type`
- Indizes:
  - `created_at`
  - `(domain_id, created_at)`
  - `(level, created_at)`
  - `user_id`

## 3) API/DTO
### Admin Read API
`GET /api/v1/admin/mail/logs?domain=&level=&from=&to=&limit=`

### Agent Ingest API (Batch)
`POST /api/v1/agent/mail/logs-batch`

Body:
```json
{
  "events": [
    {
      "created_at": "2026-02-28T15:00:00Z",
      "level": "error",
      "source": "postfix",
      "domain": "example.com",
      "user_id": 123,
      "event_type": "bounce",
      "message": "mail bounced",
      "payload": {"queue_id": "ABC123"}
    }
  ]
}
```

## 4) Agent-Contract
- Parser: `agent/internal/mail/logstream/parser.go`
- Batch Sender: `agent/internal/mail/logstream/sender.go`
- Panel Client: `agent/internal/panelagent/api/client.go` (`PostMailLogsBatch`)
- Ingest Endpoint: `AgentApiController::ingestMailLogsBatch`

## 5) Beispiel-Events (8)
1. **Bounce** (`source=postfix`, `event_type=bounce`, `level=error`)
2. **Auth Fail** (`source=dovecot`, `event_type=auth`, `level=warning`)
3. **DKIM Sign Fail** (`source=opendkim`, `event_type=policy`, `level=error`)
4. **TLS Hostname Mismatch** (`source=postfix`, `event_type=tls`, `level=error`)
5. **Spam Reject** (`source=rspamd`, `event_type=spam`, `level=warning`)
6. **DNS Check Failure** (`source=dns`, `event_type=dns_check`, `level=error`)
7. **Queue Spike** (`source=agent`, `event_type=queue`, `level=warning`)
8. **Delivery Success** (`source=postfix`, `event_type=delivery`, `level=info`)
