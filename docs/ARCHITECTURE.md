# NC Litter — Architecture

## Components

| Piece | Role |
|---|---|
| Nextcloud app (`nc_litter`) | Vue 2.7 + Pinia UI, PHP controllers, encrypted Whisker secrets, Activity / Notifications |
| `nc_litter_bridge` | Python FastAPI process owning **one** Whisker cloud session via `pylitterbot` |
| Litter-Robot 4 | Cloud-connected unit; no local MQTT broker used by this app |

```
┌────────────┐   HTTPS    ┌──────────────┐  HTTP (Docker DNS)  ┌──────────────────┐
│  Browser   │ ─────────► │  cloud_app   │ ──────────────────► │ nc_litter_bridge │
│ (NC Litter)│            │  nc_litter   │   /state /stream    │  pylitterbot     │
└────────────┘            │  PHP + Vue   │   /action /settings │  (Whisker cloud) │
                          └──────────────┘                     └────────┬─────────┘
                                                                        │ HTTPS
                                                                        ▼
                                                                 ┌─────────────┐
                                                                 │ Whisker API │
                                                                 └─────────────┘
```

There is **no** Soft-AP helper and **no** host `:8091` path for this app.

## Networking

- Compose file: [`docker-compose.bridge.yml`](../docker-compose.bridge.yml)
- Network: `nc-litter-net` (attach `cloud_app` / `cloud_cron` to it)
- Service DNS: `nc_litter_bridge` (aliases include `nc-litter-bridge`)
- **No host port publish** in production — only Docker-internal reachability

## Live state path

1. Bridge polls Whisker and normalizes state (`bridge/normalizer.py`).
2. PHP proxies `GET /state` and SSE `GET /stream` to the Vue store
   (`src/store/device.js`).
3. Store prefers EventSource; falls back to polling if SSE is blocked.
4. Dashboard gauges and History react to the same DTO — no second telemetry channel.

## Secrets

- Whisker account email + password stored encrypted (`enc:v1:` + Nextcloud `ICrypto`).
- Plaintext credentials exist only in bridge process memory after connect.
- Optional Alfred alert log path is confined under `nc_litter/` in config/data trees
  (`ConfinedFileReader`).

## Command surface

Mirrors `DeviceService::ALLOWED_ACTIONS` / bridge allow-list: clean, reset
(aliases empty/reset_drawer), night light, panel lock, wait time, power.
Deliberately **no** sleep_on/off — pylitterbot raises `NotImplementedError` for LR4.

## Multi-device schema

DB and API are device-id scoped. v0.x ships a single primary device row.
