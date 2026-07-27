# NC Litter — Architecture

## Components

| Piece | Role |
|---|---|
| `apps` surface in Nextcloud (`nc_litter`) | Vue 2.7 + Pinia UI, PHP controllers, encrypted secrets, Activity / Notifications |
| `nc_litter_bridge` | Single Node process owning **one** local MQTT/TLS session to the robot |
| `nc-litter-wifi-helper` | Privileged host service (`:8091`) for Soft-AP Wi‑Fi join + factory `wlcfg` provision |
| Litter-Robot | Local MQTT broker on `:8883` (fw2 on 900-series; needs legacy TLS options) |

```
┌────────────┐   HTTPS    ┌──────────────┐  HTTP (Docker DNS)  ┌──────────────────┐
│  Browser   │ ─────────► │  cloud_app   │ ──────────────────► │ nc_litter_bridge │
│ (NC Litter)│            │  nc_litter   │   /state /stream    │  dorita980       │
└────────────┘            │  PHP + Vue   │   /action /discover │                  │
                          └──────────────┘                     └────────┬─────────┘
                                   Soft-AP setup                         │ TLS MQTT :8883
                                   via host.docker.internal:8091         ▼
                          ┌────────────────────────┐              ┌─────────┐
                          │ nc-litter-wifi-helper  │── Soft-AP ──►│ Litter-Robot  │
                          │ (host wlp2s0 / iw)     │              └─────────┘
                          └────────────────────────┘
```

## Networking

- Compose file: [`docker-compose.bridge.yml`](../docker-compose.bridge.yml)
- Network: `nc-litter-net` (attach `cloud_app` to it)
- Service DNS: `nc_litter_bridge` (aliases include `nc-litter-bridge`)
- **No host port publish** in production — only Docker-internal reachability

## Live state path

1. Bridge maintains MQTT and normalizes state (`bridge/lib/stateNormalizer.js`).
2. PHP proxies `GET /state` and SSE `GET /stream` to the Vue store
   (`src/store/robot.js`).
3. Store prefers EventSource; falls back to 5s polling if SSE is blocked.
4. Dashboard `MissionStage` and Location map react to the same DTO — no second
   telemetry channel.

## Secrets

- BLID + local password stored in `oc_nc_litter_robots.password_enc` as
  `enc:v1:` + Nextcloud `ICrypto` ciphertext.
- Home Wi‑Fi passphrase stored in appconfig `home_wifi_password` (same `enc:v1:`).
- Soft-AP helper requires `LITTER_WIFI_HELPER_TOKEN` (shared by `.env` + `/etc/nc-litter-wifi-helper.env`).
- Plaintext robot password is only held in the bridge process memory for the MQTT
  session after `POST /connect`.

## Soft-AP factory path

Admin wizard → PHP `POST /api/admin/setup/softap` → bridge
`POST /onboard/softap-provision` → helper join Soft-AP → MQTT auth-exchange +
`wifictl`/`wlcfg` → leave Soft-AP → LAN discover → `POST /connect`.

## fw2 TLS note

Litter-Robot 960 (firmware 2) lacks RFC 5746 secure renegotiation. OpenSSL 3
(Node ≥ 17) refuses the handshake unless `SSL_OP_LEGACY_SERVER_CONNECT` is
set. The bridge loads [`bridge/lib/tlsLegacy.js`](../bridge/lib/tlsLegacy.js)
before `dorita980` so only `:8883` connections get the relaxed options.

## Multi-robot schema

DB and API are robot-id scoped for a future fleet UI. v0.x ships a single
primary robot row and an Alfred-first operator workflow.
