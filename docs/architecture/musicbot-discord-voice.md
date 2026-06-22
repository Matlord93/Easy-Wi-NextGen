# Musicbot Discord Voice Architecture

This document describes the prepared Discord Voice architecture for Musicbot instances.

## Current state

Discord support is intentionally a placeholder. The runtime exposes the `DiscordVoiceClient` interface and uses `PlaceholderDiscordVoiceClient` by default. The placeholder validates the configuration path and reports capability status, but it does **not** connect to Discord voice, does **not** encode audio, and does **not** broadcast audio.

Supported capability statuses:

- `placeholder`: non-production placeholder state.
- `voice_backend_required`: a dedicated Discord voice backend is required before audio can be sent.
- `ready`: a future real backend reports that gateway/voice state is ready.
- `error`: validation or backend failure.

## Required future backend

A real implementation must provide the `DiscordVoiceClient` contract:

- Connect/disconnect the Discord gateway.
- Join/leave a configured guild voice channel.
- Send already-prepared Opus frames.
- Report voice state and last errors without exposing secrets.
- Reconnect according to the configured reconnect policy.
- Close all network resources cleanly.

The backend should be implemented as a separate runtime component and wired in place of `PlaceholderDiscordVoiceClient` only when it is complete and tested.

## Configuration

Discord connection configuration is split between normal config and encrypted secret config.

Normal config:

- `application_id`
- `guild_id`
- `voice_channel_id`
- `command_mode`
- `slash_commands_enabled`
- `reconnect_policy`

Secret config:

- `bot_token`

Bot tokens must be handled through `MusicbotSecretConfigService` and must never be returned in runtime status, API responses, logs, audit entries, or test output.

## Panel/API behavior

The panel and API may test the Discord connection and display capability status, but they must clearly state when only the placeholder is active. Slash-command support is displayed as a placeholder until a real Discord command registration/sync flow exists.

## Explicit non-goals

- Do not add YouTube, Spotify, or other scraping/downloading features.
- Do not claim that Discord Voice playback works while the placeholder backend is active.
- Do not log or expose Discord bot tokens.
