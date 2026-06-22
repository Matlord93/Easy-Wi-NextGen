# Go-Agent DNS Validation Engine (`internal/mail/validator`)

## 1) Design / Spec

Deterministischer Flow:

```text
Agent Job mail.dns.validate
  -> validator.Engine.ValidateDomain(request)
    -> DNS Query Engine (TXT/MX/PTR)
    -> STARTTLS Probe (SMTP)
    -> optional MTA-STS fetch
  -> structured JSON (findings + aggregate statuses)
  -> Job Result zurück ans Panel
       - mail_domains.{dkim,spf,dmarc,mx,tls}_status
       - mail_domains.dns_last_checked_at
       - detailed findings for mail_logs ingestion
```

**Sicherheitsprinzipien**
- Keine externen Binaries (nur Go stdlib: `net`, `net/http`, `crypto/tls`).
- Timeouts, Retry + Exponential Backoff.
- Globales Rate-Limit (`NewGlobalLimiter`) für DNS/Probe-Calls.
- Parallelisierte Checks pro Domain mit begrenzter Parallelität.

## 2) Datenmodell / Migration Mapping

Panel-seitiges Mapping der Rückgabe:

```json
{
  "dkim_status": "ok|warning|error",
  "spf_status": "ok|warning|error",
  "dmarc_status": "ok|warning|error",
  "mx_status": "ok|warning|error",
  "tls_status": "ok|warning|error",
  "dns_last_checked_at": "RFC3339",
  "findings_json": "[...]"
}
```

Dieses Format ist kompatibel mit der zentralen `mail_domains` Aggregate-Erweiterung.

## 3) API / DTO

Request-Schema (`DomainValidationRequest`):

```go
DomainValidationRequest{
  Domain            string
  Selector          string
  ExpectedMXTargets []string
  KnownIPs          []string
  TLSHost           string
  TLSPort           int
  MTASTSEnabled     bool
  Timeout           time.Duration
}
```

Output-Schema (`Finding`):

```json
{
  "check": "spf|dkim|dmarc|mx|ptr|tls|mta_sts",
  "status": "ok|warning|error",
  "severity": "info|warning|critical",
  "details": "human readable result",
  "observed_value": "actual value",
  "expected_value": "baseline/expected",
  "timestamp": "RFC3339"
}
```

## 4) Agent-Contract

### Package-Struktur

```text
agent/internal/mail/validator/
  types.go          // DTOs + output schema
  interfaces.go     // DNSResolver, TLSProber, MTASTSFetcher, Limiter, Backoff
  defaults.go       // stdlib resolver/prober/fetcher + limiter/backoff
  io.go             // retry wrappers
  checks.go         // SPF, DKIM, DMARC, MX, PTR, TLS, MTA-STS checks
  engine.go         // orchestration, parallelism, status aggregation
```

### Integration in Job Runner

`mail.dns.validate` ist in `cmd/agent/main.go` registriert und ruft `handleMailDNSValidate()` auf.

## 5) Tests / Edgecases

Abgedeckte 10 Edgecases:
1. Multiple SPF records (RFC-fail).
2. DKIM selector malformed (z. B. CNAME/Bad TXT Effekt).
3. DMARC `p=none` als Warning.
4. DMARC ohne `rua` als Warning.
5. MX zeigt nicht auf erwartete Targets.
6. PTR fehlt für bekannte IP.
7. STARTTLS Zertifikat-Hostname mismatch.
8. Optionales MTA-STS Fetch-Failure als Warning.
9. SPF mit `+all` als Warning.
10. Fehlende Domain im Request (hard fail).

Zusätzlich: Retry-Path Test (`temporary DNS error` -> success on retry).

## Beispiel JSON

```json
{
  "domain": "example.com",
  "dkim_status": "ok",
  "spf_status": "warning",
  "dmarc_status": "ok",
  "mx_status": "ok",
  "tls_status": "ok",
  "dns_last_checked_at": "2026-02-28T15:00:00Z",
  "findings": [
    {
      "check": "spf",
      "status": "warning",
      "severity": "warning",
      "details": "SPF too permissive (+all)",
      "observed_value": "v=spf1 +all",
      "expected_value": "-all or ~all",
      "timestamp": "2026-02-28T15:00:00Z"
    },
    {
      "check": "dkim",
      "status": "ok",
      "severity": "info",
      "details": "DKIM selector record valid",
      "observed_value": "v=DKIM1; p=MIIB...",
      "expected_value": "v=DKIM1; p=...",
      "timestamp": "2026-02-28T15:00:00Z"
    }
  ]
}
```
