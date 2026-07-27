# NC Litter

![version](https://img.shields.io/badge/version-0.9.1-C4A574)
![license](https://img.shields.io/badge/license-AGPL--3.0--or--later-1a1a1c)

Nextcloud app to control a Litter-Robot over the **local LAN MQTT API**.
Remote access is via your Nextcloud URL; the private Node bridge never
binds a public port.

The UI brands itself around the **robot’s display name** (e.g. Alfred on
this install) with a butler-style charcoal / brass / cream look, a live
mission stage on the Dashboard, and an advanced Location map when the
robot publishes pose.

## Features

- **Factory Soft-AP setup wizard** (960/980 class) — join home Wi‑Fi without the iRobot app
- Start / pause / resume / stop / dock / spot / find
- Auto discover (LAN `:8883` scan + UDP) for IP / BLID
- Live status strip (battery, bin, Wi‑Fi, phase)
- **Mission stage** — realtime phase animation + coverage / duration counters
- Location map with trail + heading when pose is available; mission theater fallback otherwise
- Mission history from install (local only)
- Schedule week editor, preferences, retention
- Error decoder, maintenance hints, connection health drawer
- Nextcloud Notifications + Activity

## Stack

```
Browser ──► Nextcloud (nc_litter PHP + Vue)
                │
                ▼  Docker DNS (nc_litter_bridge:8080)
         nc-litter-bridge (Node + dorita980)
           │                │
           │                ▼  host.docker.internal:8091
           │         nc-litter-wifi-helper (Soft-AP)
           ▼  TLS MQTT :8883 (LAN only)
         Litter-Robot
```

- Nextcloud app (`nc_litter`) — Vue 2.7 + Pinia + PHP 8.1+
- Sidecar `nc-litter-bridge` — Node + [dorita980](https://github.com/koalazak/dorita980)
- Host helper `wifi-helper/` — Soft-AP Wi‑Fi provision (systemd)
- Deploy target: `cloud_app` → `/var/www/html/custom_apps/nc_litter`

## Quick start

```bash
cd /media/4TB/nc-litter
npm ci
make helper-install                 # Soft-AP wifi helper + token in .env
# Real robot (not mock):
#   echo 'LITTER_MOCK=0' >> .env
#   echo 'ROBOT_IP=10.0.0.242' >> .env
#   echo 'LITTER_DISCOVER_SUBNETS=10.0.0.0/24' >> .env
LITTER_MOCK=0 make ship RESTART=1   # build + bridge-up + deploy + gate-preflight
make gate-gui
make gate-live LITTER_MOCK=1        # live gates without a robot
```

Admin: Nextcloud → Administration → NC Litter → **Factory setup wizard**
(Soft-AP). Advanced: Auto discover + hold HOME. Operators must be in the
`litter-operators` group.

### Important env / networking notes

| Item | Value |
|---|---|
| Bridge URL (from `cloud_app`) | `http://nc_litter_bridge:8080` (underscores; hyphen alias also works) |
| Mock mode | `LITTER_MOCK=1` (compose default) vs `LITTER_MOCK=0` for a real robot |
| Discover subnets | `LITTER_DISCOVER_SUBNETS` (CIDR list, default `10.0.0.0/24`) |
| Soft-AP helper | `LITTER_WIFI_HELPER_URL` / `LITTER_WIFI_HELPER_TOKEN` |
| fw2 TLS | Litter-Robot 960 needs the bridge TLS shim (`bridge/lib/tlsLegacy.js`) — already baked in |

## Docs

- Operator notes: [`docs/OPERATOR.md`](docs/OPERATOR.md)
- Architecture: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Contributing / gates: [`CONTRIBUTING.md`](CONTRIBUTING.md)
- Plan (v0.3 Soft-AP wizard): [`.cursor/plans/nc-litter-softap-wizard-v0.3.md`](.cursor/plans/nc-litter-softap-wizard-v0.3.md)
- Changelog: [`CHANGELOG.md`](CHANGELOG.md)

## License

AGPL-3.0-or-later. Bridge dependency dorita980 is MIT.
