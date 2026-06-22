# Internationalization Strategy (DE/EN)

## Translator architecture
- A single runtime locale source is used: `PortalLocaleSubscriber`.
- Locale whitelist is fixed to `de` and `en` via `PortalLocale::SUPPORTED`; values are normalized to lowercase and invalid input is ignored.
- Symfony `Translator` domains are split by concern:
  - `portal` for UI labels, buttons, navigation, and controller/UI-level validation messages.
  - `mail` for mail subjects and mail body keys.
  - `security` for authentication/security layer messages.
  - `validators` for Symfony validator constraint messages.
- Translation files are maintained in locale pairs (`*.de.yaml` and `*.en.yaml`) with identical key sets.

## Locale precedence and persistence
Priority order is deterministic to avoid DB/session conflicts:
1. Query parameter `?lang=de|en` (immediate switch).
2. Authenticated user preference from `invoice_preferences.portal_language`.
3. Session value `portal_language`.
4. Cookie `portal_language`.
5. Fallback `de`.

Persistence behavior:
- Session is always updated with the resolved locale.
- Cookie is always updated on response (`Secure` follows request scheme, `SameSite=Lax`, host domain when applicable).
- Database is updated only for authenticated users and only when a valid `lang` query switch is present.

## Fallback rationale
- Default and translator fallback are `de` to keep language output consistent in legacy portal flows and prevent partial EN/DE mixes when a key is temporarily unavailable.

## Cleanup status
- Removed installer-specific locale resolver/subscriber in favor of centralized locale handling.
- Removed legacy `?locale=` switching path to keep one supported switch input (`?lang=`).
- Replaced hardcoded admin preference validation strings with translation keys.
- Added translation quality gates for multiple domains and mixed-domain resolution tests.

## Quality gates
- Lint translations: `bin/console lint:translations de en`.
- PHPUnit includes:
  - anonymous and authenticated locale switching tests,
  - invalid locale injection test,
  - mixed-domain translation resolution test,
  - parity test across `portal`, `mail`, `security`, `validators`, and `installer` domains.
