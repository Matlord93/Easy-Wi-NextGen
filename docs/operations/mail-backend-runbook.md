# Mail backend runbook (single active backend)

## Operating model
- Exactly one mail backend is active at a time: `none`, `local`, `panel`, or `external`.
- `none` means mail is intentionally disabled; mailbox and alias APIs must return `MAIL_BACKEND_DISABLED`.
- Domain capabilities are the source of truth:
  - `capabilities.webspace=true` enables webspace/vhost attachment.
  - `capabilities.mail=true` enables mail attachment and DNS planning.

## Backend switch procedure
1. Set maintenance window for mailbox mutations.
2. Export current mail settings and domain/mail inventory.
3. Switch `mail_backend` to the target backend.
4. For each mail-capable domain, verify DNS plan contains DKIM/SPF/DMARC.
5. Run smoke checks: mailbox create/update/delete, alias create/delete.
6. End maintenance window.

## Migration notes
- `none -> local|panel|external`: enable `mail_enabled`, set backend, then re-run DNS plan/apply.
- `local|panel|external -> none`: disable `mail_enabled`; all mailbox operations should fail with standard error payload.
- `local <-> panel <-> external`: keep `mail_enabled=true`, switch backend, then run synchronization and DNS verification.
