# Cookie-Dokumentation (Public + Panels)

Diese Übersicht beschreibt alle **aktuell vom System selbst gesetzten First-Party-Cookies**.

## Cookie-Liste

| Name | Zweck | Kategorie | Laufzeit | Provider |
|---|---|---|---|---|
| `easywi_session` | Authentifizierung für Admin- und interne Sessions. | Notwendig | 30 Tage | Easy-WI (First-Party) |
| `easywi_customer_session` | Authentifizierung für Customer-Bereich-Sessions. | Notwendig | 30 Tage | Easy-WI (First-Party) |
| `PHPSESSID` | Symfony-Session (u.a. Formularzustand, CSRF-Session-Token, Flash-Messages). | Notwendig | Session | Easy-WI / Symfony (First-Party) |
| `cookie_consent` | Speichert Einwilligungsstatus (Version + Kategorien). | Notwendig | 12 Monate | Easy-WI (First-Party) |
| `portal_language` | Speichert Sprachpräferenz. | Notwendig | 12 Monate | Easy-WI (First-Party) |

## Consent-Schema

Das Consent-Cookie `cookie_consent` speichert JSON im Format:

```json
{
  "version": 1,
  "necessary": true,
  "statistics": false,
  "marketing": false
}
```

- `necessary` ist immer aktiv.
- `statistics` und `marketing` sind optional (Opt-In).

## Optionale Skripte

Optionale Skripte dürfen erst nach Opt-In geladen/ausgeführt werden.
Im Public-Layout wird dies über Consent-Kategorien (`statistics`, `marketing`) gesteuert.
