# PTY Console v1 (Agent PTY + gRPC + Redis/SSE)

## Architektur
1. Browser sendet Command an `POST /instances/{id}/console/command`.
2. Symfony validiert AuthZ, CSRF (Cookie-Auth), Rate-Limit, Länge/Control-Chars und schreibt Audit-Hash.
3. Symfony ruft gRPC `SendConsoleCommand` am Agent auf.
4. Agent schreibt Command in PTY stdin.
5. Agent streamt PTY Chunks per `AttachConsoleStream`.
6. Relay (`app:console:relay`) publisht Events nach Redis `console:{id}` und in Ringbuffer `consolebuf:{id}`.
7. SSE Endpoint `GET /instances/{id}/console/stream` liefert Replay (`Last-Event-ID`) und Live-Events.

## Robustheit
- PTY Read Chunks: 4KB.
- Backpressure im Agent über bounded Subscriber-Channels + Drop-Policy.
- Command-Idempotency auf Agent-Sitzungsebene.
- Relay reconnectt mit Backoff + Jitter.

## Ops
- Relay starten: `php bin/console app:console:relay`
- Redis prüfen: `redis-cli ping`
- SSE im Browser prüfen: `EventSource` auf `/instances/{id}/console/stream`
