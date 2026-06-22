# Panel Adapter Certification Checklist (TASK-017)

Dieses Dokument operationalisiert die Zertifizierung aus `docs/integrations/panels/capabilities.yml`.

## Geltungsbereich

- Panels: Plesk, aaPanel, cPanel, DirectAdmin, ISPConfig, HestiaCP.
- Versionen: exakt gemäß Capability Matrix.
- Ziel: reproduzierbare Freigabe je Panel-Version mit dokumentiertem Least-Privilege-Modell.

## Zertifizierungs-Gates je Panel/Version

1. **AuthN/AuthZ Gate**
   - Credential bootstrap dokumentiert.
   - Rotationspfad validiert.
   - Scope/Role-Minimum nachgewiesen (least privilege).
2. **Lifecycle Gate**
   - Mindest-CRUD für Sites/Accounts/DNS/Mail gemäß Matrix grün.
   - Idempotenz der Create/Update/Delete Sequenz geprüft.
3. **Resilienz Gate**
   - Standardisierte Fehlercodes im Adaptervertrag geprüft.
   - Rollback-/Reconcile-Pfad bei absichtlichem Fehler validiert.
4. **Operations Gate**
   - Audit-Logs vorhanden und korrelierbar.
   - Alerting für Auth-Fehler und wiederholte Temporary Failures aktiv.

## CI/Smoke Mindestumfang

- Contract-Test: Capability Discovery und `executeAction` Fehlernormalisierung.
- Smoke-Test: Referenzadapter `tech-preview` (`ping`, unsupported action).
- Doku-Drift-Check: Capability Matrix YAML ↔ gerendertes Markdown konsistent halten (Review-Gate).

## Rollback-Policy

- Bei Gate-Fehlschlag wird Panel-Version auf `tech-preview`/`not-certified` zurückgestuft.
- Laufende Aufträge stoppen auf Adapterebene mit `ADAPTER_UNAVAILABLE` oder `ACTION_UNSUPPORTED`.
- Freigabe erst nach erneutem vollständigem Gate-Durchlauf.
