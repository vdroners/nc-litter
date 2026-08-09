# NC Litter

![version](https://img.shields.io/badge/version-0.4.0-D8A45E)
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
- **Sensor health** — judges each reading against the ones around it, so a failing
  sensor is reported as a failing sensor rather than believed. See below.
- **Commands are verified, not assumed** — a commanded cycle is checked against the
  device's odometer, because the cloud accepting a request is not the robot acting
  on it
- Nextcloud Notifications + Activity
- Optional **Alfred** (OpenClaw) Talk integration: `@alfred litter status |
  clean | reset | light-on | light-off | lock | unlock | help`

## Sensor health

Added in 0.4.0, after this unit's laser (time-of-flight) board failed on
2026-08-07. The app had been sampling every five minutes throughout and recorded
four days of unmistakable warning signs **without raising one of them** — the
drawer sensor swinging 0 % → 100 % → 0 % inside single intervals, then the litter
sensor going silent and the app announcing "litter critically low" for a box that
had just been filled. Every reading looked like a number, so it was used as one.

`SensorHealthService` supplies the missing judgement, feeding 15 advisory rules
in `knowledge/maintenance_thresholds.json`:

| Detector | Fires on | Validated by |
|---|---|---|
| `drawer_sensor_implausible` | two opposing 60-point drawer transitions within 6 h | backtest: fires 08-04/05/06, silent 07-27→08-03 |
| `cycle_activity_stalled` | no completed cycle for 36 h (72 h → error) | backtest: threshold clears the measured 29.3 h healthy maximum |
| `laser_board_unprogrammed` | board firmware reads `0.0.0.0` | live faulted unit |
| `firmware_backend_contradiction` | version ≠ vendor target while the vendor says "no update needed" | live faulted unit |
| `weight_sensor_frozen` / `drawer_distance_frozen` | register bit-identical for 6 h, across ≥ 24 samples, through ≥ 1 cycle | unit tests only — these registers are new, so there is no history to backtest |
| `self_test_stuck` | a start-up display code held for an hour | unit tests only, as above |
| `command_not_executed` | a cycle acknowledged but the odometer never moved | unit tests |

Two things worth knowing about how these were chosen:

- **Thresholds were measured, not guessed.** `tests/fixtures/real-telemetry-2026-08.csv`
  is 2655 real samples spanning a healthy week and the failure, and
  `SensorHealthBacktestTest` replays them. The most important assertion in the
  suite is that **nothing fires during the healthy week** — an app that cries wolf
  on a healthy unit is how the real signal came to be ignored.
- **Two obvious detectors were built and then discarded** because the replay caught
  them false-positiving: a litter-rise check (the level legitimately wanders ±20
  points a day as the cat digs) and a bare large-drawer-jump check (emptying the
  drawer really does produce a 100 → 0 drop — only a *reversal* is impossible).

Consequences elsewhere: level notifications now need the condition to hold across
two consecutive samples and are suppressed for a sensor already known to be
unreliable, and the level chips show `sensor fault` instead of a number rather than
presenting a broken sensor's output as fact.

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

- Nextcloud app (`nc_litter`) — Vue 2.7 + Pinia + PHP 8.1+ (suites run on 8.3 and 8.5)
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
vendor/bin/phpunit                               # 137 backend tests, incl. the backtest
npx vitest run                                   # 116 frontend tests
make bridge-test                                 # 52 bridge tests, in the image (real pylitterbot)
make bridge-test-host                            # 44, fast inner loop — 8 contract tests SKIP
bash tools/litter-live-gates.sh                  # against the real device
```

`make bridge-test` runs inside the bridge image, the only environment with
`pylitterbot` installed, which is what makes the contract tests real. It used to
prefer a host `pytest` when one was present — and the host has no `pylitterbot`, so
those eight tests skipped while the target reported a confident pass. Fixed in
0.4.0. `make bridge-test-host` is still there for speed and says out loud what it
is not covering.

Note the running `nc_litter_bridge` container is *not* a shortcut: its Dockerfile
ships app code only (`COPY app.py litter_manager.py normalizer.py`), so
`docker exec … pytest /app/test` finds nothing unless tests were copied in by hand.

The bridge contract tests are the important ones: they bind to the *installed*
`pylitterbot` rather than a test double. A fake robot that implemented
`set_sleep_mode` is exactly how a permanently-broken Sleep button shipped — and a
stub that invented `getTempBaseDirectory()` is how an endpoint that returned HTTP
500 in production stayed green in CI. A double that does not mirror the real thing
tests only itself.

## Docs

- Static analysis / l10n: [`docs/STATIC_ANALYSIS.md`](docs/STATIC_ANALYSIS.md) — `l10n/en.json` is scaffolded; full Vue `t()` wrapping is follow-up
- Operator guide: [`docs/OPERATOR.md`](docs/OPERATOR.md)
- Install (stranger / GHCR bridge): [`docs/INSTALL.md`](docs/INSTALL.md)
- Architecture: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- Alfred ops plan (v0.3): [`docs/plans/nc-litter-v0.3-alfred-ops.md`](docs/plans/nc-litter-v0.3-alfred-ops.md)
- Sensor-health plan (v0.4): [`docs/plans/sensor-health-and-diagnostics.md`](docs/plans/sensor-health-and-diagnostics.md)
- **LR4 laser-board failure (2026-08)**: [`docs/lr4-laser-board-failure-2026-08.md`](docs/lr4-laser-board-failure-2026-08.md) — the hardware diagnosis this app's telemetry produced, written to hand to Whisker support
- Changelog: [`CHANGELOG.md`](CHANGELOG.md)
- Contributing: [`CONTRIBUTING.md`](CONTRIBUTING.md)

## License

AGPL-3.0-or-later. Bridge dependency [pylitterbot](https://github.com/natekspencer/pylitterbot) is MIT.
