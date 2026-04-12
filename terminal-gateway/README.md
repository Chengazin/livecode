# Terminal Gateway

WebSocket + PTY bridge for project terminal sessions.

By default gateway loads environment from project root `.env`, then overrides with `terminal-gateway/.env` if present.

## Environment

Required:

- `TERMINAL_SHARED_SECRET` (must match Laravel `TERMINAL_SHARED_SECRET`)

Optional:

- `TERMINAL_GATEWAY_HOST` (default: `127.0.0.1`)
- `TERMINAL_GATEWAY_PORT` (default: `8090`)
- `TERMINAL_GATEWAY_PATH` (default: `/terminal`)
- `TERMINAL_GATEWAY_ALLOWED_ORIGINS` (default: `http://localhost:8080,http://127.0.0.1:8080`)
- `TERMINAL_GATEWAY_IDLE_TIMEOUT_SECONDS` (default: `900`)
- `TERMINAL_GATEWAY_HARD_TIMEOUT_SECONDS` (default: `28800`)
- `TERMINAL_GATEWAY_MAX_PAYLOAD_BYTES` (default: `131072`)
- `TERMINAL_GATEWAY_MAX_INPUT_BYTES` (default: `65536`)
- `TERMINAL_GATEWAY_MAX_OUTPUT_CHUNK_BYTES` (default: `65536`)
- `TERMINAL_GATEWAY_DEFAULT_COLS` (default: `100`)
- `TERMINAL_GATEWAY_DEFAULT_ROWS` (default: `30`)
- `TERMINAL_BACKEND_CALLBACK_URL` (example: `http://127.0.0.1:8000/api/terminal/gateway/sessions/{terminalSessionId}/close`)
- `TERMINAL_BACKEND_CALLBACK_SECRET` (must match backend callback secret)
- `TERMINAL_BACKEND_CALLBACK_HEADER` (default: `X-Terminal-Gateway-Secret`)
- `TERMINAL_BACKEND_CALLBACK_TIMEOUT_MS` (default: `5000`)

Runner mode:

- `TERMINAL_GATEWAY_RUNNER=host|docker` (default: `docker`)
- `TERMINAL_GATEWAY_ALLOW_UNSAFE_HOST_RUNNER=true|false` (default: `false`)

`host` runner executes shells on the gateway host and cannot enforce hard filesystem isolation for multi-tenant use.
Use `docker` runner for shared environments.

Docker runner options (`TERMINAL_GATEWAY_RUNNER=docker`):

- `TERMINAL_DOCKER_BINARY` (default: `docker`)
- `TERMINAL_DOCKER_IMAGE` (default: `alpine:3.20`)
- `TERMINAL_DOCKER_SHELL` (default: `/bin/sh`)
- `TERMINAL_DOCKER_WORKDIR` (default: `/workspace`)
- `TERMINAL_DOCKER_NETWORK` (default: `none`)
- `TERMINAL_DOCKER_CPU_LIMIT` (example: `1.0`)
- `TERMINAL_DOCKER_MEMORY_LIMIT` (example: `512m`)
- `TERMINAL_DOCKER_PIDS_LIMIT` (example: `256`)
- `TERMINAL_DOCKER_USER` (optional, example: `65534:65534` for non-root)
- `TERMINAL_DOCKER_CAP_DROP_ALL` (`true|false`, default: `true`)
- `TERMINAL_DOCKER_NO_NEW_PRIVILEGES` (`true|false`, default: `true`)
- `TERMINAL_DOCKER_TMPFS_MOUNTS` (comma-separated, default: `/tmp`)
- `TERMINAL_DOCKER_HOME` (default: `/workspace`)
- `TERMINAL_DOCKER_READ_ONLY` (`true|false`, default: `true`)
- `TERMINAL_DOCKER_EXTRA_ARGS` (comma-separated extra docker run args)

## Run

```bash
npm install
npm run dev
```

Health endpoint:

- `GET /healthz`
