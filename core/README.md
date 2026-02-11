## Public Access Defaults
- Registrierung ist standardmäßig **deaktiviert** (`registration_enabled=false`).
- Math-Captcha ist standardmäßig **deaktiviert** (`anti_abuse_enable_captcha_registration=false`, `anti_abuse_enable_captcha_contact=false`).
- Rate-Limits für Login/2FA/Registrierung/Kontakt sind standardmäßig aktiv.

## Local Anti-Abuse Defaults
- PoW enabled for registration/contact (`anti_abuse_enable_pow_registration`, `anti_abuse_enable_pow_contact`)
- Minimum submit time: `anti_abuse_min_submit_seconds=2`
- Daily IP lock threshold: `anti_abuse_daily_ip_limit=20`
- PoW difficulty: `anti_abuse_pow_difficulty=4`

Diese Einstellungen können in **Admin → Settings → Security** für Beta/Public angepasst werden.
