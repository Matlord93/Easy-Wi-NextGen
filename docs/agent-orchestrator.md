# Agent Orchestrator (TS3/TS6/Sinusbot)

This module replaces the legacy HTTP agent API calls for Teamspeak 3/6 and Sinusbot with a polling, signed job orchestrator.

## Overview

- **Panel → Agent**: The panel persists `agent_jobs` and the agent polls `/agent/{nodeId}/jobs`.
- **Auth**: HMAC-SHA256 with `X-Agent-ID`, `X-Timestamp`, `X-Nonce`, `X-Signature`.
- **Replay protection**: Nonce + timestamp window enforced by `AgentSignatureVerifier`.

## Endpoints

### Admin (panel)

- `POST /admin/nodes/{id}/jobs`
  - Body: `{ "type": "...", "payload": { ... } }`
- `GET /admin/jobs/{id}`

### Agent (polling)

- `GET /agent/{nodeId}/jobs?status=queued&limit=1`
- `POST /agent/{nodeId}/jobs/{jobId}/start`
- `POST /agent/{nodeId}/jobs/{jobId}/finish`
  - Body: `{ "status": "success|failed", "log_text": "...", "error_text": "...", "result_payload": { ... } }`

## Job Payload Schemas

### TS3 Node

- `ts3.install`
  - `{ node_id, download_url, install_dir, instance_name, service_name, query_port, voice_port?, file_port? }`
- `ts3.service.action`
  - `{ node_id, service_name, action: start|stop|restart }`
- `ts3.status`
  - `{ node_id, service_name }`

### TS3 Instance

- `ts3.instance.create`
  - `{ instance_id, customer_id, node_id, name, voice_port, query_port, file_port, db_mode, db_host?, db_port?, db_name?, db_username?, db_password? }`
- `ts3.instance.action`
  - `{ instance_id, node_id, service_name, action, slots?, backup_path?, restore_path? }`

### TS3 Virtual Server

- `ts3.virtual.create`
  - `{ virtual_server_id, node_id, name, params }`
- `ts3.virtual.action`
  - `{ virtual_server_id, node_id, sid, action }`
- `ts3.virtual.token.rotate`
  - `{ virtual_server_id, node_id, sid }`

### TS6 Node

- `ts6.install`
  - `{ node_id, download_url, install_dir, instance_name, service_name, voice_ip?, default_voice_port?, filetransfer_port?, filetransfer_ip?, query_https_enable?, query_bind_ip?, query_https_port?, admin_password? }`
- `ts6.service.action`
  - `{ node_id, service_name, action: start|stop|restart }`
- `ts6.status`
  - `{ node_id, service_name }`

### TS6 Instance

- `ts6.instance.create`
  - `{ instance_id, customer_id, node_id, name }`
- `ts6.instance.action`
  - `{ instance_id, node_id, service_name, action, backup_path?, restore_path? }`

### TS6 Virtual Server

- `ts6.virtual.create`
  - `{ virtual_server_id, node_id, name, params }`
- `ts6.virtual.action`
  - `{ virtual_server_id, node_id, sid, action }`
- `ts6.virtual.token.rotate`
  - `{ virtual_server_id, node_id, sid }`

### Sinusbot

- `sinusbot.install`
  - `{ node_id, download_url, install_dir, instance_root, web_bind_ip, web_port_base, admin_username, admin_password?, ts3_client_install, ts3_client_download_url? }`
- `sinusbot.status`
  - `{ node_id, service_name }`
- `sinusbot.service.action`
  - `{ node_id, service_name, action: start|stop|restart }`

### Viewer snapshots

- `ts3.viewer.snapshot` / `ts6.viewer.snapshot`
  - `{ virtual_server_id, node_id, sid, cache_key }`

## Agent configuration

`agent.conf` must include `agent_id`, `secret`, `api_url` for signed requests.

## Troubleshooting

- If jobs are stuck in `queued`, ensure the agent can reach the panel and that `agent_id` + `secret` match.
- If signature errors appear, verify timestamps are in sync and nonce cache is configured.
