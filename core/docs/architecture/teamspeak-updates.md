# Teamspeak Update-System

## Provider-Konzept
- `TeamspeakUpdateProviderInterface` definiert die Quelle pro Server-Typ.
- `Teamspeak6GithubUpdateProvider` nutzt die GitHub Releases API von TeamSpeak 6.
- `Teamspeak3UpdateProvider` liefert bewusst `source_not_configured`.

## Quellen / ENV
- TS6-Quelle: `https://api.github.com/repos/teamspeak/teamspeak6-server/releases`.
- Erlaubte Assets: nur `https://github.com/teamspeak/teamspeak6-server/...`.
- Optionales Token: `APP_GITHUB_TOKEN`.
- Strict Checksum Mode: `TEAMSPEAK_UPDATE_REQUIRE_CHECKSUM=true|false`.

## Checksum-Verhalten
- Wenn Checksum im Release verfügbar ist (Asset-Digest oder Release-Body), wird immer SHA256 validiert.
- Wenn Checksum fehlt:
  - Default (`false`): Warnung (`checksum_missing`), Update läuft weiter.
  - Strict Mode (`true`): Update bricht ab.
- Bei falscher Checksum: `checksum_failed`, Update bricht ab, Log=`failed`, Recovery-Start wird versucht.

## Installationsablauf (TS6)
1. Update-Lock pro Instanz.
2. Re-Check Update-Verfügbarkeit.
3. Update-Log auf `running`.
4. Backup.
5. Stop-Job dispatch (`stop_requested`).
6. Download.
7. Checksum resolve/verify (`checksum_resolved|checksum_missing|checksum_verified|checksum_failed`).
8. Sichere Extraktion.
9. Replace.
10. Start-Job dispatch (`start_requested`).
11. Log auf `success` oder `failed`.

## Runtime-Status (Stop/Start)
- Stop/Start wird aktuell dispatch-basiert ausgelöst.
- Es gibt derzeit im Update-Workflow keine garantierte synchrone Bestätigung des Job-Abschlusses.
- Deshalb werden nur angeforderte Runtime-Steps protokolliert (inkl. Job-ID), nicht fälschlich `*_confirmed`.

## Bekannte Einschränkungen
- TS3-Quelle nicht konfiguriert.
- `.sha256`-Asset-Erkennung ist vorbereitet; ohne direkten Abruf wird primär Asset-Digest/Release-Body verwendet.

## Manuelle Testschritte
1. `POST /ts6/{id}/updates/check`
2. `POST /ts6/{id}/updates/install`
3. `GET /ts6/{id}/updates/logs`
4. Prüfen, ob Checksum-Steps und Runtime-Step-Details im Log enthalten sind.

## i18n / Mehrsprachigkeit
- User-facing Texte laufen über `portal.de.yaml` und `portal.en.yaml`.
- Namespace für neue Texte: `teamspeak.update.*`.
- API liefert maschinenlesbare Felder wie `status`, `error_code`, `message_key`, `message_params`.
- Frontend übersetzt Status-/Step-Codes (z. B. `teamspeak.update.status.*`, `teamspeak.update.step.*`) statt Rohwerte direkt anzuzeigen.
