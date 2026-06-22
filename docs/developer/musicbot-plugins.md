# Musicbot Plugin Developer Guide

This guide documents the current Musicbot plugin foundation and the intended extension model. It is written for plugin authors and Easy-Wi developers who want to prepare manifests, configuration schemas and workflow integrations.

> **Current status:** the plugin system currently discovers and stores plugin metadata/configuration. It does **not** execute uploaded or third-party plugin code. Runtime status exposes plugin manifest summaries and explicitly reports `execution_enabled: false`.

## Zielbild

Musicbot plugins are intended to become installable, permission-scoped extensions for a single customer and optionally a single Musicbot instance. The target architecture is:

1. A plugin ships a `manifest.json` with identity, version, supported platforms, requested permissions, configuration schema and panel extension metadata.
2. The Symfony core discovers manifests from trusted plugin registry directories.
3. Customers or admins assign a discovered plugin to a Musicbot instance.
4. The panel renders declared configuration fields and stores sanitized config on the `MusicbotPlugin` entity.
5. Workflows can listen for plugin-originated events through the `plugin.event` trigger.
6. A future sandboxed runtime/backend may execute approved plugin logic and dispatch commands/events, but this is not implemented yet.

Non-goals for the current implementation:

- no direct execution of uploaded PHP, Go, JavaScript, shell or binary code;
- no direct access from plugins to arbitrary customer data;
- no plaintext secrets in plugin config or runtime status;
- no unrestricted outbound network access.

## Current implementation map

| Area | Current behavior |
| --- | --- |
| Manifest DTO | `PluginManifest` stores `identifier`, `name`, `version`, `author`, `description`, `permissions`, `supported_platforms`, `config_schema` and `panel_extensions`. |
| Registry | `PluginRegistryService` discovers `manifest.json` files below `musicbot/plugins/*/manifest.json` and `var/musicbot/plugins/*/manifest.json` under the Symfony project directory. Invalid manifests are skipped by list operations. |
| Identifier validation | Identifiers must match `^[a-z0-9][a-z0-9._-]{2,119}$` and must not contain `..`, `/` or `\`. |
| Permissions | Manifest permissions are restricted to the `MusicbotPluginPermission` enum values documented below. Unknown values are rejected. |
| Supported platforms | Current allowed platform values are `teamspeak` and `discord`. |
| Assignment | `MusicbotPluginService` creates/updates a `MusicbotPlugin` for a customer + instance + identifier and copies name/version/permissions from the manifest. |
| Config | `PluginConfigService` filters saved config by `config_schema.properties` when present, otherwise recursively keeps scalar/null values and safe string keys. |
| Runtime | Runtime config includes `plugin_dir`; status reports manifest summaries (`identifier`, `name`, `version`) and `execution_enabled: false`. |
| Workflows | `MusicbotWorkflowTriggerType::PluginEvent` is defined as `plugin.event`. Runtime emission of real plugin events is still future work. |

## Manifest structure

A plugin is described by `manifest.json`. The manifest must be located under one of the plugin roots as:

```text
musicbot/plugins/<plugin-directory>/manifest.json
var/musicbot/plugins/<plugin-directory>/manifest.json
```

The directory name is not the trust boundary; the `identifier` inside the manifest is validated and used as the stable plugin key.

### Fields

| Field | Type | Required | Current behavior |
| --- | --- | --- | --- |
| `identifier` | string | Yes | Lowercased, trimmed and validated. Used for assignment and lookup. |
| `name` | string | No | Defaults to `identifier` if absent. |
| `version` | string | No | Defaults to `0.0.0` if absent. |
| `author` | string | No | Stored in manifest DTO/API output. |
| `description` | string | No | Stored in manifest DTO/API output. |
| `permissions` | string[] | No | Must be known permission values. Stored on assigned plugin. |
| `supported_platforms` | string[] | No | Allowed values: `teamspeak`, `discord`. |
| `config_schema` | object | No | Used by `PluginConfigService` to filter/coerce config. |
| `panel_extensions` | object | No | Stored and exposed as manifest metadata. Rendering/extension execution is prepared but not a full plugin UI runtime. |

### Example manifest

```json
{
  "identifier": "example.vote-skip",
  "name": "Vote Skip",
  "version": "1.0.0",
  "author": "Example Plugins",
  "description": "Collects listener votes and requests a skip once the configured threshold is reached.",
  "permissions": [
    "events.subscribe",
    "commands.register",
    "playback.control",
    "queue.manage"
  ],
  "supported_platforms": ["teamspeak", "discord"],
  "config_schema": {
    "type": "object",
    "properties": {
      "enabled": { "type": "boolean", "title": "Enable plugin" },
      "threshold_percent": { "type": "integer", "title": "Vote threshold percent", "default": 60 },
      "cooldown_seconds": { "type": "integer", "title": "Cooldown seconds", "default": 30 },
      "command_name": { "type": "string", "title": "Command", "default": "voteskip" }
    }
  },
  "panel_extensions": {
    "settings_sections": [
      {
        "id": "vote-skip",
        "title": "Vote Skip",
        "description": "Configure vote threshold and cooldown."
      }
    ],
    "dashboard_cards": [
      {
        "id": "vote-skip-status",
        "title": "Vote Skip Status"
      }
    ]
  }
}
```

## Permissions

Plugins must request every capability explicitly in `permissions`. The current allowed values are:

| Permission | Intended scope |
| --- | --- |
| `playback.control` | Request playback actions such as play, pause, stop, skip or volume changes. Future execution backends must still enforce instance/customer scope. |
| `queue.manage` | Read or modify the queue for the assigned instance. |
| `playlist.manage` | Read or modify playlists owned by the customer/instance. |
| `tracks.read` | Read local track metadata for the customer/instance. |
| `tracks.write` | Create/update/delete local tracks if a future plugin backend supports it. |
| `events.subscribe` | Subscribe to runtime/core events. |
| `commands.register` | Declare chat/slash/text commands for future connector integrations. This does not currently register real Discord slash commands. |
| `panel.extend` | Declare panel extension metadata. This does not execute arbitrary frontend code. |
| `external.http` | Request constrained outbound HTTP access for future backends. This must be allowlisted/rate-limited before any real execution is enabled. |

Unknown permissions are rejected when manifests are parsed.

## Config schema

`config_schema` follows a small JSON-schema-like shape. The current sanitizer cares primarily about `properties` and each property's `type`.

Supported coercions in the current config service:

| Schema type | Stored value behavior |
| --- | --- |
| `boolean` | Parsed with boolean validation; invalid values become `false`. |
| `integer` | Cast to integer. |
| `number` | Cast to float. |
| `array` | Stored only if the submitted value is an array; nested values are sanitized recursively. |
| `string` or unknown | Scalar/null values become strings; non-scalar values become an empty string. |

When `config_schema` or `config_schema.properties` is missing, config is still sanitized: unsafe keys containing path traversal or slashes are dropped, and only scalar/null values or recursively sanitized arrays are retained.

Example config payload for the API/panel:

```json
{
  "enabled": true,
  "config": {
    "threshold_percent": "60",
    "cooldown_seconds": 30,
    "command_name": "voteskip"
  }
}
```

The stored config after filtering would contain typed values for schema properties only.

## Events

### Current event-related pieces

- Runtime/core event logging exists for Musicbot behavior such as queue updates, playback commands, stream events and Auto-DJ events.
- Workflow trigger type `plugin.event` exists as a reserved trigger for plugin-originated events.
- `events.subscribe` is a manifest permission value.

### Prepared event envelope

A future plugin runtime should emit events with an explicit customer/instance/plugin scope, for example:

```json
{
  "type": "plugin.event",
  "plugin_identifier": "example.vote-skip",
  "event_name": "vote.threshold_reached",
  "customer_id": 7,
  "instance_id": 42,
  "payload": {
    "votes": 5,
    "threshold_percent": 60
  }
}
```

Future event dispatchers must validate that the plugin is assigned to the instance and that emitted payloads cannot contain secrets.

## Commands

`commands.register` is currently a manifest declaration, not a live command-registration system. The intended command model is:

- a plugin declares commands in manifest metadata or config;
- a connector-specific backend maps those commands to TeamSpeak text commands, Discord slash commands or panel-triggered actions;
- command execution is routed through core/runtime services with customer isolation, permission checks and audit logging.

Do not document or advertise a plugin command as active until the backend actually registers and dispatches it.

Example future command declaration shape:

```json
{
  "commands": [
    {
      "name": "voteskip",
      "description": "Vote to skip the current track.",
      "permission": "playback.control"
    }
  ]
}
```

## Panel extensions

`panel_extensions` is currently stored/exposed as manifest metadata. It is intended to support safe panel integration without executing arbitrary plugin frontend code.

Recommended safe extension primitives:

- settings sections generated from `config_schema`;
- dashboard cards rendered by trusted core templates/components;
- status badges based on sanitized runtime/core state;
- links to documentation or support pages that pass URL allowlist validation.

Do not allow these in the first implementation step:

- arbitrary uploaded JavaScript;
- arbitrary Twig/PHP templates from plugins;
- direct DOM injection;
- remote scripts or stylesheets;
- iframe embeds without strict allowlists and sandboxing.

## Workflow integration

Plugins can be integrated into workflows in two directions:

1. **Plugin as trigger source:** a future plugin backend emits `plugin.event`; workflows with trigger type `plugin.event` match on `plugin_identifier`, `event_name` and payload conditions.
2. **Workflow as plugin action source:** a future workflow action can invoke a plugin command/action if the plugin is assigned and has the required permission.

Example workflow trigger concept:

```json
{
  "trigger_type": "plugin.event",
  "trigger_config": {
    "plugin_identifier": "example.webhook-notifier",
    "event_name": "webhook.received"
  },
  "conditions": [
    { "field": "payload.kind", "operator": "equals", "value": "queue_empty" }
  ],
  "actions": [
    { "type": "autodj.run" }
  ]
}
```

Current caveat: the trigger enum exists, but no untrusted third-party plugin runtime currently emits real plugin events.

## Security model

### Customer and instance isolation

- A plugin assignment is stored with `customer` and `instance` references.
- Services must always look up plugins through customer-scoped methods or verify `plugin.customer === currentCustomer`.
- A plugin must never read or mutate data for another customer or another instance unless an explicit future admin-level plugin mode is designed and audited.

### Secrets

- Plugin config must not store plaintext bot tokens, TeamSpeak passwords, stream tokens or API keys.
- Secrets must use dedicated secret services/config objects, encryption or one-way hashes depending on use case.
- Secrets must never appear in runtime status, manifest output, workflow payloads, logs or test output.

### No uploaded code execution in the first step

The current system does not execute uploaded plugin code. This is intentional. Before execution is introduced, the project needs at minimum:

- a sandbox or out-of-process execution model;
- signed/trusted plugin packages or an allowlisted registry;
- resource limits and timeouts;
- permission enforcement at every API boundary;
- audit logs for plugin-triggered changes;
- deterministic tests for secret masking and isolation.

### Explicit permissions

- Every plugin capability must be declared in `permissions`.
- Unknown permissions must fail manifest validation.
- Permission checks must happen at runtime/action dispatch, not only at manifest assignment time.
- Admin UI should display requested permissions before assignment.

### `external.http`

`external.http` is high risk and must be constrained before any real plugin execution uses it:

- require explicit permission in the manifest;
- require customer/admin approval;
- restrict destinations with allowlists or deny private/internal networks;
- enforce timeouts, response size limits and rate limits;
- redact headers and tokens in logs;
- block metadata services and local control sockets;
- provide audit events for outbound calls.

## What works today vs. prepared only

| Capability | Status |
| --- | --- |
| Manifest discovery from trusted directories | Works. |
| Manifest validation for identifier, permissions and supported platforms | Works. |
| Plugin assignment to customer/instance | Works. |
| Enable/disable and sanitized config storage | Works. |
| Plugin listing in customer/admin APIs and panel surfaces | Works. |
| Runtime plugin directory and manifest summary status | Works. |
| Runtime execution of third-party plugin code | Prepared only; not implemented. |
| Plugin commands in TeamSpeak/Discord | Prepared only; not implemented. |
| Discord slash-command registration from plugins | Prepared only; current slash-command status is placeholder. |
| Panel extensions rendered as plugin-provided UI | Prepared only; metadata exists, arbitrary code/templates must not be executed. |
| `plugin.event` workflows from real plugin runtime events | Prepared only; trigger type exists. |
| `external.http` outbound requests from plugins | Prepared only; must be restricted before enabling. |

## Example plugin ideas

### Auto-DJ Assistant

- Permissions: `queue.manage`, `playlist.manage`, `events.subscribe`.
- Concept: reacts to queue-empty or low-queue events and asks the core Auto-DJ service to fill from local playlists.
- Current status: should use existing Auto-DJ core features; plugin execution is not active yet.

### Vote-Skip

- Permissions: `events.subscribe`, `commands.register`, `playback.control`.
- Concept: registers a `voteskip` command and dispatches `skip` once a threshold is reached.
- Current status: command registration and listener identity integration are prepared only.

### Welcome Sound

- Permissions: `events.subscribe`, `queue.manage`, `tracks.read`.
- Concept: on `user.joined`, queue a short local uploaded track.
- Current status: workflow trigger types exist for user events, but real voice/listener event plumbing is placeholder.

### Webhook Notifier

- Permissions: `events.subscribe`, `external.http`.
- Concept: send selected Musicbot events to a configured webhook URL.
- Current status: must wait for constrained outbound HTTP support and secret-safe webhook config.

### Playlist Scheduler

- Permissions: `playlist.manage`, `queue.manage`, `events.subscribe`.
- Concept: load playlists based on schedule/workflow conditions.
- Current status: core scheduler/workflows already provide much of this; plugin runtime execution is future work.

### Workflow Trigger Plugin

- Permissions: `events.subscribe`.
- Concept: convert external or connector-specific events into `plugin.event` workflow triggers.
- Current status: `plugin.event` trigger type is available, but real plugin-originated event dispatch is not implemented.

## Developer checklist

Before adding a plugin manifest to the registry:

1. Choose a stable lowercase identifier such as `vendor.feature-name`.
2. Request only the permissions required by the plugin idea.
3. Keep `supported_platforms` to the platforms actually needed.
4. Define a minimal `config_schema.properties` map so saved config is filtered predictably.
5. Do not include secrets in default config or examples.
6. Keep `panel_extensions` declarative and renderable by trusted core components only.
7. Add tests for manifest parsing, config filtering and customer isolation when new behavior is implemented.
8. Document clearly whether the plugin is metadata-only, workflow-assisted, or backed by a future runtime executor.
