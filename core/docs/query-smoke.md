# Query Smoke: end-to-end query validation

Run cross-engine query smoke checks against live instances:

```bash
php bin/console gameserver:query-smoke --engines=source1,source2,minecraft_java,bedrock --max-per-engine=1
```

## Options
- `--instances=1,2,3` explicit instance IDs (skips auto-pick)
- `--engines=source1,source2,minecraft_java,bedrock` engine filter for auto-pick mode
- `--max-per-engine=2` max selected instances per engine in auto-pick mode
- `--fail-fast` stop on first failure
- `--json` output structured report for CI/artifacts

## Selection strategy
1. If `--instances` is provided, only those IDs are checked.
2. Otherwise, command scans available instances and picks representative instances per engine family up to `--max-per-engine`.

Engine family mapping rules:
- `bedrock` if query protocol/template indicates bedrock (`minecraft_bedrock`, `bedrock`, `mcpe`)
- `minecraft_java` for Java/minecraft markers
- `source2` for `cs2`/source2 markers
- otherwise `source1`

## Output interpretation
Each row reports:
- `engine`, `instance_id`, `game`
- `resolved_host:port`
- `ok` (`PASS`/`FAIL`)
- `players`, `map`, `latency`, `request_id`

On failures, command prints error line including:
- `error_code`, `error_message`, `request_id`
- debug fields (`resolved_host`, `resolved_port`, `resolved_protocol`, `timeout_ms`, `host_source`, `port_source`, `last_error_code`, `last_error_message`)

## Example output
```text
+-----------+------------+----------------------+----------------------+-----+---------+------------+---------+------------------------------+
| engine    | instance_id| game                 | resolved_host:port   | ok  | players | map        | latency | request_id                   |
+-----------+------------+----------------------+----------------------+-----+---------+------------+---------+------------------------------+
| source1   | 101        | l4d2                 | 203.0.113.10:27015   | PASS| 8       | c1m1_hotel | 22      | query-smoke-101-1-1730000000 |
| source2   | 102        | cs2                  | 203.0.113.10:27016   | PASS| 10      | de_dust2   | 18      | query-smoke-102-1-1730000001 |
| minecraft_java | 103   | minecraft_vanilla_all| 203.0.113.10:25565   | PASS| 4       | -          | 31      | query-smoke-103-1-1730000002 |
| bedrock   | 104        | minecraft_bedrock    | 203.0.113.10:19132   | PASS| 6       | -          | 29      | query-smoke-104-1-1730000003 |
+-----------+------------+----------------------+----------------------+-----+---------+------------+---------+------------------------------+
```
