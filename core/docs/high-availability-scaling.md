# High Availability & Scaling

## Queue scaling (T18.1)

**Goal:** scale Messenger workers with retries and backpressure.

### Configuration

The async transport now defines retry strategy and a dedicated failed queue. Configure these env values:

- `MESSENGER_TRANSPORT_DSN` (existing)
- `MESSENGER_PREFETCH_COUNT` (example: `10`)
- `MESSENGER_MAX_RETRIES` (example: `5`)
- `MESSENGER_RETRY_DELAY_MS` (example: `1000`)
- `MESSENGER_RETRY_MULTIPLIER` (example: `2`)
- `MESSENGER_MAX_RETRY_DELAY_MS` (example: `60000`)

`prefetch_count` provides backpressure on AMQP by limiting unacked messages per worker.

### Scaling workers

Run multiple consumers (process supervisor or container replicas), for example:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=256M --limit=100 -vv
```

Horizontal scaling = more consumers. Backpressure is handled by prefetch limits + retries.

## DB replication notes (T18.2)

**Goal:** allow read-only replicas via Doctrine DBAL replicas.

### Configuration

Production config supports a replica URL. Set in the environment:

- `DATABASE_REPLICA_URL` (read-only replica)

Doctrine routes writes to the primary and sends read-only queries to replicas when possible.
To force reads on primary for a request, wrap in a transaction or use the primary connection.

## Multi-core setup (T18.3)

**Goal:** support multiple Core instances behind a load balancer with shared state.

### Session sharing

Set `SESSION_SAVE_PATH` to a shared filesystem path (e.g. NFS) so PHP sessions are available to all Core instances.
For Redis or other handlers, provide a custom handler via service wiring and update `SESSION_SAVE_PATH` accordingly.

### Shared assets

Ensure any user-uploaded files or generated assets live on shared storage (NFS, object storage, or a volume mounted into all instances).
