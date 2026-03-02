# Environment Variables (Core + Agent)

## 1) Inventory of environment keys

### Core (runtime)

`AGENT_NONCE_TTL_SECONDS`, `AGENT_SIGNATURE_SKEW_SECONDS`, `APP_ADMIN_AUTHORIZED_KEYS_PATH`, `APP_AGENT_FILE_TIMEOUT_SECONDS`, `APP_AGENT_JWT_AUDIENCE`, `APP_AGENT_JWT_ISSUER`, `APP_AGENT_JWT_MAX_TTL_SECONDS`, `APP_AGENT_RELEASE_CACHE_TTL`, `APP_AGENT_RELEASE_CHANNEL`, `APP_AGENT_RELEASE_REPOSITORY`, `APP_AGENT_SERVICE_PORT`, `APP_CHANGELOG_CACHE_TTL`, `APP_CHANGELOG_REPOSITORY`, `APP_CORE_RELEASE_CACHE_TTL`, `APP_CORE_RELEASE_CHANNEL`, `APP_CORE_RELEASE_REPOSITORY`, `APP_CORE_UPDATE_BACKUPS_DIR`, `APP_CORE_UPDATE_CURRENT_SYMLINK`, `APP_CORE_UPDATE_EXCLUDES`, `APP_CORE_UPDATE_INSTALL_DIR`, `APP_CORE_UPDATE_JOBS_DIR`, `APP_CORE_UPDATE_LOCK_FILE`, `APP_CORE_UPDATE_LOGS_DIR`, `APP_CORE_UPDATE_MANIFEST_URL`, `APP_CORE_UPDATE_PACKAGE_URL`, `APP_CORE_UPDATE_RELEASES_DIR`, `APP_CORE_UPDATE_REPOSITORY`, `APP_CORE_UPDATE_RUNNER`, `APP_CORE_UPDATE_WORKFLOW`, `APP_CORE_VERSION`, `APP_GITHUB_TOKEN`, `APP_HOSTING_PANEL_SECRET_KEY`, `APP_MAIL_ALIAS_MAP_PATH`, `APP_MAIL_PASSWORD_HASH_ALGORITHM`, `APP_PAYMENT_DUMMY_ENABLED`, `APP_RECOVERY_ALLOWED_IPS`, `APP_SECRET`, `APP_SECRET_FALLBACKS`, `APP_SINUSBOT_QUOTA_MAX`, `APP_SINUSBOT_QUOTA_MIN`, `APP_STATUS_AGENT_SHARED_SECRET`, `APP_STATUS_AGENT_SIGNATURE_SKEW_SECONDS`, `APP_STATUS_STALE_GRACE_SECONDS`, `APP_WINDOWS_NODES_ENABLED`, `AUTH_IDENTIFIER_HASH_PEPPER`, `DATABASE_URL`, `DEFAULT_URI`, `EASYWI_DB_CONFIG_PATH`, `EASYWI_SECRET_KEY_PATH`, `EASYWI_PHP_BIN`, `PHP_CLI_BIN`, `MAILER_DSN`, `MESSENGER_TRANSPORT_DSN`, `REDIS_DSN`, `SYMFONY_TRUSTED_PROXIES`.

### Agent (detected, for cross-component operations)

`DB_ADMIN_SECRET`, `DB_ADMIN_USER`, `EASYWI_AGENT_ID`, `EASYWI_AGENT_JWT_AUDIENCE`, `EASYWI_AGENT_JWT_ISSUER`, `EASYWI_FORCE_TTY`, `EASYWI_INSTANCE_BACKUP_DIR`, `EASYWI_INSTANCE_BASE_DIR`, `EASYWI_LOG_COMMANDS`, `EASYWI_MAIL_AGENT_LISTEN`, `EASYWI_MAIL_AGENT_PORT`, `EASYWI_PDNS_API_KEY_FILE`, `EASYWI_PORT_POOL_START`, `EASYWI_ROLES`, `EASYWI_SFTP_BASE_DIR`, `EASYWI_SFTP_GROUP`, `EASYWI_STREAM_PREFIX`, `EASYWI_SUPERVISOR`, `EASYWI_TS6_SUPPORTED`, `EASYWI_TTY_INSTALLER`, `PANEL_AGENT_CACHE`, `PANEL_AGENT_TOKEN`, `PANEL_AGENT_UUID`, `PANEL_ENDPOINT`, `QUERY_A2S_DEBUG`, `QUERY_PAYLOAD_DEBUG`, `STATUS_AGENT_ID`, `STATUS_BACKEND_URL`, `STATUS_SHARED_SECRET`.

## 2) Core environment matrix

| Variable | dev | stage | prod | Required | Default in app | Secret source |
|---|---|---|---|---|---|---|
| APP_SECRET | set in `.env.local` | external secret | external secret | yes | none | Vault/KMS/Compose secret |
| DEFAULT_URI | `http://localhost` | stage URL | prod URL | yes | none | config env |
| MAILER_DSN | local smtp/mailhog | stage smtp | prod smtp | yes | none | Vault/KMS/Compose secret |
| MESSENGER_TRANSPORT_DSN | local doctrine/redis | managed broker | managed broker | yes | none | config env |
| REDIS_DSN | local redis | stage redis | prod redis | yes | none | Vault/KMS/Compose secret |
| AGENT_SIGNATURE_SKEW_SECONDS | 300 | 300 | 300 | yes | none | config env |
| AGENT_NONCE_TTL_SECONDS | 600 | 600 | 600 | yes | none | config env |
| APP_AGENT_RELEASE_CACHE_TTL | 300 | 300 | 300 | yes | none | config env |
| APP_CHANGELOG_CACHE_TTL | 300 | 300 | 300 | yes | none | config env |
| APP_CORE_RELEASE_CACHE_TTL | 300 | 300 | 300 | yes | none | config env |
| APP_CORE_UPDATE_RELEASES_DIR | project path | persistent path | persistent path | yes | none | config env |
| APP_CORE_UPDATE_CURRENT_SYMLINK | project path | release symlink | release symlink | yes | none | config env |
| APP_CORE_UPDATE_LOCK_FILE | tmp lock | shared lock | shared lock | yes | none | config env |
| APP_GITHUB_TOKEN | optional | optional/required by workflow | optional/required by workflow | no | empty | Vault/KMS/Compose secret |
| AUTH_IDENTIFIER_HASH_PEPPER | dev placeholder | strong random | strong random | recommended | placeholder | Vault/KMS/Compose secret |

> Remaining core env keys are optional/tunable and documented in `core/.env.example`.

## 3) Startup validation

Startup validation runs during Symfony container loading (`core/config/startup_env_validation.php`).
If a required variable is missing or empty, startup fails fast with an explicit error listing all missing keys.
This prevents implicit `null` behavior and late runtime faults.

## 4) Secret provisioning standard

Preferred order:
1. **Vault/KMS-backed injection** (Kubernetes External Secrets, cloud secret manager adapters).
2. **Compose/Docker secrets** mounted into env at container start.
3. `.env.local` only for local development (never commit real credentials).

Operational rules:
- Rotate `APP_SECRET`, peppers, tokens on compromise.
- Keep `.env.example` non-secret (names + examples only).
- Validate startup in CI using a minimal env file before rollout.
