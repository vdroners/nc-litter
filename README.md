# NC Litter

![version](https://img.shields.io/badge/version-0.3.1-D8A45E)
![license](https://img.shields.io/badge/license-AGPL--3.0--or--later-1a1a1c)
[![GitHub](https://img.shields.io/badge/github-vdroners%2Fnc--litter-181717?logo=github)](https://github.com/vdroners/nc-litter)

Nextcloud app to monitor and control a **Whisker Litter-Robot 4** through the
Whisker cloud. Remote access is via your Nextcloud URL; the private Python
bridge never binds a public port.

The UI brands itself around the **unit's Whisker display name** (e.g. *Poop
Roller*) with a charcoal / tabby-amber / cream look, live drawer and litter ring
gauges, and an animated globe on the Dashboard during a cycle.

Tested on a **Litter-Robot 4** against **Nextcloud 34 / PHP 8.5**.

## Features

- **Whisker account onboarding** in Admin settings — email + password, stored
  encrypted (`enc:v1:`), then pick the LR4 to bind
- Live status: Ready · Cleaning · Emptying · Drawer full · Sleeping · Paused ·
  Fault · Offline, with the raw LR4 status code decoded to plain English
- **Two ring gauges** — waste-drawer fullness and litter remaining
- Last cat weight, cycle count, cycles since the drawer filled, wait time,
  scoops saved, night-light and panel-lock state
- Controls: start a clean cycle, **Reset / clear error**, night light,
  panel lock, wait time (the device's own enum: 3/7/15/25/30 min), power
- Cycle history with a phase timeline, CSV/JSON export, and lifetime stats
- 22 cat-themed achievements
- Error decoder + maintenance hints + a connection-health drawer
- Nextcloud Notifications + Activity
- Optional **Alfred** (OpenClaw) Talk integration: `@alfred litter status |
  clean | reset | light-on | light-off | lock | unlock | help`

## What the LR4 genuinely cannot do

Recorded here because the app used to offer some of it and quietly fail. All of
this was verified by introspecting the installed `pylitterbot` and by probing the
real unit — see `bridge/test/test_pylitterbot_contract.py`, which fails on
purpose if upstream ever gains these capabilities.

| Thing | Reality |
|---|---|
| **Sleep mode** | Read-only. `LitterRobot4.set_sleep_mode` raises `NotImplementedError` and there is no sleep verb in `LitterRobot4Command`. The window is shown but changed in the Whisker app. |
| **Emptying the drawer** | Not a command. `reset()` sends a short reset press: it clears errors and may turn the globe once. Emptying is a manual job. |
| **Resetting the cycle counter** | No command exists. `cycles_after_drawer_full` is the device's own counter. |
| **Wi-Fi signal / SSID** | No such property. Only `wifi_mode_status`, which reads `OFF` even on a healthy unit — so there is no Wi-Fi UI. |
| **`last_seen`** | Present but unreliable (observed 3 days stale on a live, healthy unit). Never used as a freshness signal; `last_poll_ok_at` is. |
| **Immediate write feedback** | The Whisker cloud takes tens of seconds to report a write back. The bridge re-polls at +5/+10/+20s so the UI converges instead of appearing inert. |
| **Wait time as a range** | It is an enum — `[3, 7, 15, 25, 30]`. There is no 5. The device rejects anything else. |

## Stack

```
Browser ──► Nextcloud (nc_litter PHP + Vue)
                │
                ▼  Docker DNS (nc_litter_bridge:8080)
         nc-litter-bridge (Python: FastAPI + pylitterbot)
                │
                ▼  HTTPS (Cognito auth + GraphQL)
         Whisker cloud (lr4.iothings.site)
                │
                ▼
         Litter-Robot 4
```

- Nextcloud app (`nc_litter`) — Vue 2.7 + Pinia + PHP 8.1+
- Sidecar `nc-litter-bridge` — Python + [pylitterbot](https://github.com/natekspencer/pylitterbot)
- Deploy target: `cloud_app` → `/var/www/html/custom_apps/nc_litter`

There is no local transport. `whiskerless` (local MQTT/BLE) exists but requires
running a TLS broker and a one-time BLE re-provision that takes the unit off the
Whisker app, which is not wanted here.

## Quick start

```bash
cd /media/4TB/nc-litter
npm ci
# Real device (not mock) — .env is gitignored and must be chmod 600:
#   printf 'LITTER_MOCK=0\nWHISKER_EMAIL=you@example.com\nWHISKER_PASSWORD=...\n' > .env
#   chmod 600 .env
make ship                      # build + bridge-up + deploy + gate-preflight
make gate-live                 # live bridge gates against the real unit
make gate-gui                  # GUI source gates
make gate-live LITTER_MOCK=1   # gates with no Whisker account
```

Admin: Nextcloud → Administration → **NC Litter** → *Connect Whisker account*.
Operators must be in the `litter-operators` group.

### Important env / networking notes

| Item | Value |
|---|---|
| Bridge URL (from `cloud_app`) | `http://nc_litter_bridge:8080` |
| **App + cron on bridge net** | `cloud_app` and `cloud_cron` attach to `nc-litter-net` via `/media/4TB/cloud/docker-compose.yml` (external network). `make bridge-up` still reattaches if needed. |
| Mock mode | `LITTER_MOCK=1` (compose default) vs `LITTER_MOCK=0` for a real unit |
| Whisker creds | `WHISKER_EMAIL` / `WHISKER_PASSWORD` in `.env` (never committed) |
| Device selection | `LITTER_DEVICE_ID` (id or serial; blank = first on the account) |
| Poll cadence | `LITTER_REFRESH_S=30` — the ceiling on data freshness |
| Debug port | `127.0.0.1:18793` only; never bound to `0.0.0.0` |

Credentials live in two places and nowhere else: the gitignored `.env` for the
bridge, and the `creds_enc` column (`enc:v1:`, Nextcloud `ICrypto`) once
onboarded through the UI. They are never logged and never returned by any API
response.

## Testing

```bash
make gate-preflight   # layout + version sync + secret hygiene, then all suites
npx vitest run                                   # 106 frontend tests
cd bridge && python3 -m pytest test -q           # 33 (contract tests skip: no pylitterbot on host)
docker exec nc_litter_bridge python3 -m pytest /app/test -q   # 41, incl. the pylitterbot contract
bash tools/litter-live-gates.sh                  # against the real device
```

The bridge contract tests are the important ones: they bind to the *installed*
`pylitterbot` rather than a test double. A fake robot that implemented
`set_sleep_mode` is exactly how a permanently-broken Sleep button shipped.

## Docs

- Operator guide: [`docs/OPERATOR.md`](docs/OPERATOR.md)
- Install (stranger / GHCR bridge): [`docs/INSTALL.md`](docs/INSTALL.md)
- Architecture: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Alfred ops plan (v0.3): [`docs/plans/nc-litter-v0.3-alfred-ops.md`](docs/plans/nc-litter-v0.3-alfred-ops.md)
- Changelog: [`CHANGELOG.md`](CHANGELOG.md)
- Contributing: [`CONTRIBUTING.md`](CONTRIBUTING.md)

## License

AGPL-3.0-or-later. Bridge dependency [pylitterbot](https://github.com/natekspencer/pylitterbot) is MIT.
