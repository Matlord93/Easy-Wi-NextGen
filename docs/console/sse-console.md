# SSE Console Streaming Runbook

## Data flow
1. Agent stream endpoint (`/v1/console/stream`) is consumed by `app:console:relay`.
2. Relay writes events into Redis (`console:{id}` + `consolebuf:{id}`).
3. SSE controller reads Redis bus and replays buffer for `Last-Event-ID`.
4. Browser console receives SSE and appends output.

## Required services
- Redis reachable via `REDIS_DSN`.
- Relay worker running (`easywi-console-relay.service`).
- Panel has an agent endpoint source configured (`agent.serviceBaseUrl`, metadata `grpc_endpoint`/`gamesvc_url`, or recent heartbeat IP with `agent_service_port`).

## Diagnostics
Use `/admin/diagnostics/console-stream` and verify:
- `active_grpc_client_class` is `GrpcConsoleAgentGrpcClient` in prod.
- `redis_ping_ok` is `true`.
- `relay_heartbeat_age_seconds` is low (<20s).
- `sample_node_endpoint_present` is `true`.

## Troubleshooting
- `backend_not_configured`: prod DI is still using null client.
- `redis_unavailable`: check `REDIS_DSN`, Redis process, auth.
- `relay_stale`: relay worker not running/crashed.
- `node_endpoint_missing`: node has no endpoint source (missing `grpc_endpoint`/`gamesvc_url`, empty `serviceBaseUrl`, and no usable heartbeat IP fallback).
