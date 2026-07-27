# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.9.1] - 2026-07-26

### Fixed

- **`Not ready 15` looked like an error after docking.** The robot briefly
  reports `notReady 15` (and similar) while it settles onto the dock and starts
  charging; the code wasn't in the catalog, so the app showed a scary "No
  catalog entry" message. Added entries for 15 / 16 / 31 (charging / just-docked
  / docked-and-charging) with reassuring copy, and reworded the generic
  unknown-code fallback to explain it's a benign transient that clears itself.

### Added

- **Twelve more achievements** (14 → 26): higher mission/run-hour/area tiers
  (Half Century, Old Faithful, Household Legend, The Full Work Week, Master of
  the House, Square Shooter, Ballpark Figure) plus playful wear-counter
  milestones (Scrub Life, Cliff Daredevil, Featherweight Feet, The Perfectionist,
  Clockwork Butler) — all derived from real robot counters.

## [0.9.0] - 2026-07-26

### Added

- **Live cleaning footprint / floor map.** The bridge now accumulates a
  mission-scoped pose trail + a 25 cm covered-cell grid (reset on each new
  mission) and emits `pose_trail` / `covered_cells` in the DTO. The Location map
  and Dashboard mission stage render a **swept-area footprint** (translucent
  cells, brighter where the robot dwelled/re-passed — often walls/edges), the
  crisp path, and the robot marker — built entirely from the robot's live pose.
- **Derived coverage + duration.** The 960 reports `sqft`/`mssn_m` as 0 live, so
  the bridge derives `mission_m_est` (from `started_at`) and `sqft_est` (unique
  swept-cell area); the UI shows them labelled "est." when the robot's own value
  is absent.

### Fixed

- **Map heading was wrong.** The marker used `rotate(theta)` while the Y axis is
  flipped; corrected to `rotate(-theta - 90)` so the cone points along travel.
- **Map position barely moved.** Replaced the fixed `±500 cm` viewBox with one
  that **auto-fits** the dock + trail + current pose, so real motion fills the
  frame.
- **Mission-stage metric labels/values clipped.** `dt` letter-spacing reduced +
  wrap; `dd` uses a fluid `clamp()` font size and the box clips internally, so
  long values (now with "est.") scale down instead of overflowing.

### Changed

- **Denser dashboard layout.** On wide screens the dashboard fills the width
  (container up to 1400px) with a 12-column grid — hero + controls on top, the
  large mission stage beside a timeline/lifetime rail — instead of a single
  narrow column with big empty margins. Single column on phones.
- Location copy is explicit that the 960 doesn't publish a full carpet/room map
  over the local API (that would need the iRobot cloud) — the footprint is the
  honest, robot-reported coverage, not a fabricated map.

## [0.8.0] - 2026-07-26

### Added

- **OpenClaw "Alfred" integration (optional, off by default).** Two-way:
  - Alfred (the household/ops agent) can drive the robot from Nextcloud Talk —
    `@alfred litter status | clean | spot | pause | resume | dock | find | stop`
    — via a new OpenClaw `litter` skill (SKILL.md + `litter-dispatch-exec.sh`,
    `litter-talk-fast-path.sh`, `litter-monitor.sh`, `load-litter-env.sh`).
    Commands route through the app's PHP API as the `alfred` operator, so the
    operator ACL and command-audit log govern them (no bridge bypass).
  - A `litter-monitor` posts `[litter]` mission/bin alerts to the family Talk
    room and appends them to a rolling tail.
  - **In-app surface:** a Dashboard **"Ask Alfred"** card (`AlfredPanel.vue`)
    that links to the Talk room, shows example commands, and mirrors the recent
    alerts. Gated behind a new **Admin → Alfred assistant** toggle
    (`alfred_enabled` / `alfred_talk_room` appconfig); a read-only
    `/api/alfred/alerts` endpoint feeds the mirror.
  - Everything is off unless both `LITTER_ENABLED=1` (OpenClaw) and
    `alfred_enabled` (app) are set. The robot and its "Alfred" name now share
    one assistant identity.

## [0.7.3] - 2026-07-26

### Fixed

- **Cleaning-preference changes snapped back to Auto and didn't stick.** Two
  causes: (1) `SettingsController::setPreferences` returned the robot's confirmed
  block under a `body` wrapper while the client (and `getPreferences`) expected
  it under `preferences`, so the save response deserialized to defaults; and
  (2) the Settings view's watcher re-applied `store.preferences` on every live
  poll, overwriting an unsaved selection before the operator could Save. The
  controller now returns the same `preferences` shape as `getPreferences`, and
  the watcher only adopts robot values when there are no unsaved edits. (The
  write always did reach the robot — it just takes a second to echo back.)

## [0.7.2] - 2026-07-26

### Fixed

- **Cleaning preferences didn't reflect the robot's current settings** and
  toggling a radio showed no selection. The carpet-boost / cleaning-passes radio
  groups were bound with `:checked`, but `NcCheckboxRadioSwitch` (v8) derives a
  radio's checked state from `model-value === value` when a `value` is set,
  ignoring `checked`. Switched the two radio groups to
  `:model-value` / `@update:model-value`; the plain edge-clean / always-finish
  switches (no `value`) correctly keep `:checked`.

## [0.7.1] - 2026-07-26

### Changed

- Visual polish on the 0.7.0 revamp: the battery ring now shows the percentage
  in its centre (a ⚡ while calibrating); the status pill's dot gently pulses
  while cleaning; pill/stage state colours fully use the shared tokens; and the
  dashboard's remaining ad-hoc corner radii were migrated to the radius scale.
- Added a consistent keyboard **focus-visible** ring to the app's custom
  interactive surfaces (history rows, achievement tiles, chip buttons).

## [0.7.0] - 2026-07-26

### Added

- **Dashboard data-visualization.** The hero's flat facts are now compact
  gauges driven by real telemetry: an SVG **battery ring** (level-coloured,
  charge-aware), **Wi-Fi signal bars** from the RSSI buckets, and a **bin fill
  glyph**. LifetimeStats gains a **mission success-rate donut**. Pure helpers
  `signalBars()` / `batteryLevel()` in `format.js`, unit-tested.
- **Iconified controls.** ControlPad buttons now carry leading glyphs
  (Clean/Spot/Pause/Resume/Dock/Find/Stop) via `NcIconSvgWrapper` + inline
  MDI-style paths — no new dependency.

### Changed

- **Design-token layer + elevation.** Added systematic radius, spacing,
  elevation (`--nc-litter-shadow-sm/md/lg` + a brass `--…-glow`), state-colour
  and motion tokens; panels and cards now have real depth and a hairline
  highlight, interactive rows/tiles lift on hover, and the mission stage gets a
  brass glow while cleaning. Hardcoded pause/dock/fault colours were promoted to
  tokens so light theme holds up.
- **Gentle entrance motion** for dashboard panels (staggered rise), all
  `prefers-reduced-motion` aware.

### Fixed

- Removed a dead teal `:root` accent override in `nc-litter-theme.css` that was
  always shadowed by the brass accent — the butler palette is now unambiguous.

## [0.6.0] - 2026-07-25

### Added

- **Achievements.** A butler-themed, purely-derived achievement wall (no new DB)
  computed live from the robot's own counters via `src/utils/achievements.js` —
  mission, run-hour, area, reliability and streak tiers with progress bars on the
  ones still locked, plus a "New!" tag for freshly-earned badges. Shown on
  History with an unlocked/total teaser on the Dashboard. Unit-tested against a
  veteran unit's counters.
- **Dashboard "at a glance" hero (`StatusHero.vue`).** A single card up top with
  a Ready / Cleaning / Charging / Returning / Attention status pill plus battery,
  bin, Wi-Fi and next-scheduled-clean — answering "is the robot OK and what is it
  doing" without scanning five widgets.
- **History lifetime band + inviting empty state.** History now leads with the
  robot's lifetime totals (missions, run time, area, success rate) so it is
  informative before any mission is recorded, and the empty state explains what
  will appear and offers a **Clean now** button instead of a dead end.

### Changed

- **Dashboard reorganized into three zones** — at-a-glance hero → controls + live
  theater (with the error alert folded in beside the controls) → activity +
  lifetime/health — so the page reads glance → act → review. Maintenance
  advisories are demoted to the bottom and only render when there are hints.
- Lifetime stats + the model/firmware identity card were extracted into a shared
  `LifetimeStats.vue` reused by both the Dashboard and History (previously buried
  in the Maintenance panel).
- **History mission rows are now visual cards** — outcome badge (Complete /
  Error / In progress), relative date ("Today 14:20"), duration and coverage —
  instead of a plain `#id · cycle` line.

## [0.5.2] - 2026-07-25

### Changed

- **Operator Settings tab no longer carries admin-only tools.** Robot Auto-
  discover, hold-HOME onboarding and data-retention prune were duplicated in the
  operator Settings view; they now live solely in Administration → NC Litter
  (where they already existed). Settings keeps the schedule and cleaning
  preferences and points admins to the admin page for the rest.

### Fixed

- More clipping hardening: list titles/meta (long BLID/IP strings) now wrap
  with `overflow-wrap` instead of overflowing their row.

## [0.5.1] - 2026-07-25

### Fixed

- **UI felt frozen and needed a manual browser refresh.** SSE can stay "open"
  behind a buffering proxy while no frames actually arrive. The store now runs a
  slow background poll (6 s) alongside SSE as a safety net, drops to a faster
  3 s poll the moment SSE errors (was: only after 2 failures), and refreshes
  immediately when the tab regains focus/visibility. Data stays live without a
  reload.
- **Labels and values clipped out of their boxes.** Status-strip chips forced a
  single line with no overflow handling, and stat / mission-metric values had no
  wrapping. Chips now cap to the row width and wrap long labels; stat and metric
  values wrap (`overflow-wrap` + `min-width:0`) instead of spilling past their
  borders (e.g. firmware strings, large coverage numbers).

## [0.5.0] - 2026-07-25

### Fixed

- **App tabs could not be scrolled** — the main content region was a flex child
  with no `overflow-y`, so anything below the fold was unreachable. The shell now
  bounds its height and `.nc-litter-main` owns a real scroll region
  (`overflow-y:auto; min-height:0`); the status strip and nav stay pinned above.
- **Battery showed a red 0% while charging.** A freshly power-cycled robot
  reports `batPct: 0` until the BMS recalibrates over the first charge cycle.
  `batteryLabel`/`batteryClass` are now phase-aware: 0% during `charge` renders
  as a neutral "Charging…" instead of a critical reading (normal buckets
  unchanged when a real percentage is reported).

### Added

- **Dock / ready chip** in the status strip decoding phase + `not_ready` into
  "On dock / Off dock / Not ready" instead of a raw bitfield.
- **Charging-calibration hint** on the mission stage explaining the 0%-while-
  charging behavior so it does not read as a fault.
- **"About the robot" identity card** and richer lifetime stats in Maintenance:
  model (`sku`), firmware (`software_version`), mission success rate, average
  mission length, and cliff-pick / panic counts — all from data the robot
  already reports.

### Changed

- **Onboarding hardened for repeatable setup.** `onboard()` now names the robot
  from the value it reports (`robotname`) instead of hardcoding "Alfred", stores
  its model `sku`, and maps get-password failures to actionable messages
  (not-in-onboarding → hold HOME; ECONNREFUSED → close the iRobot app;
  unreachable → check Wi-Fi/IP) rather than a generic `get_password_failed`.

## [0.4.0] - 2026-07-25

### Changed

- **Onboarding guidance reordered around what actually works.** Live onboarding
  of a Litter-Robot 960 (Alfred) showed the Soft-AP factory path is unreliable on the
  960 — it associates at Wi-Fi L2 but often serves no setup service (no DHCP /
  MQTT at `192.168.10.1`), even after factory reset. The reliable, app-free
  route is: get the robot on Wi-Fi (iRobot app once, if needed), then
  **hold HOME → Retrieve credentials** over the LAN. The admin page now opens
  and leads with Auto-discover + hold-HOME (a success NoteCard), and the Soft-AP
  wizard is labelled a fallback.
- `docs/OPERATOR.md` leads with the hold-HOME LAN takeover; Soft-AP is the
  fallback with the 960 setup-service caveat and full-minute battery-pull
  recovery. Added a troubleshooting row for `battery 0 / not_ready 15` = robot
  off the dock (not a connection fault) and for the 960 "gateway never
  responded" Soft-AP case.

### Added

- `docs/REVIEW.md` — a plain-language tour of every improvement (0.1.0 → 0.4.0),
  Alfred's current live state, and how to review the app yourself.
- SetupWizard shows the 960 Soft-AP caveat + battery-pull tip and points to the
  hold-HOME path when the robot is already on Wi-Fi.
- Documented `.env` `BLID` / `PASSWORD` / `ROBOT_IP` as the headless
  auto-reconnect persistence for the bridge (verified across a container
  recreate).

## [0.3.2] - 2026-07-25

### Fixed

- Soft-AP join reported success without actually associating: `iw connect` returns
  as soon as the request is queued, so the link could stay `NO-CARRIER` while the
  helper went on to assign the static IP and ping the gateway. `joinSoftAp` now
  calls a new `waitAssociated()` that polls `iw dev <iface> link` for the real
  "Connected to" carrier before proceeding, and disconnects + retries on failure.
  Verified live against a Litter-Robot 960 Soft-AP: association is now solid (was 0%).
- Host Wi-Fi regulatory domain was left at the unset `country 00`, which throttles
  channel-1 TX power enough that association to a weak open Soft-AP can silently
  fail. `ensureRadioUp()` now runs `iw reg set US` (override via
  `LITTER_WIFI_REGDOM`) and disables power-save for the short-lived Soft-AP session.

### Added

- Request-level logging in the Wi-Fi helper (`--> METHOD /path` / `<-- ... status
  ms`, body never logged since it carries the home Wi-Fi password) so live Soft-AP
  attempts are debuggable from `journalctl -u nc-litter-wifi-helper`.

## [0.3.1] - 2026-07-25

### Fixed

- Wi-Fi scanning returned zero networks after any Soft-AP session: `leaveSoftAp`
  handed the radio back to NetworkManager while the link was down, and every later
  `nmcli device wifi list` came back empty. The helper now restores managed + up on
  leave, and `ensureRadioUp()` runs before every scan and join with a 3-pass retry.
- `joinSoftAp` only computed a centre frequency for channel 1, so a Soft-AP on any
  other channel was passed to `iw` with no frequency hint. Added `channelToFreq`.
- Soft-AP discovery only matched `Litter-Robot-<BLID>`; Braava and some newer units
  advertise `iRobot-<BLID>`. Both prefixes are now accepted for scan and BLID parse.
- `authExchangePacket` wrote the MQTT remaining-length as a single byte, which
  corrupts the packet above 127 bytes. Now uses proper varint encoding.
- Post-provision LAN discovery fell back to `candidates[0]` when no BLID matched,
  which could target a different robot. It now requires a BLID match or a lone
  candidate, and polls 4 times (robots take 15-60 s to join home Wi-Fi).

### Added

- `occ nc_litter:home-wifi` to show or seed the home Wi-Fi SSID / passphrase /
  timezone / country headlessly; the passphrase is encrypted at rest as the admin
  UI would store it.
- `home_wifi_password` listed in `AdminSecretCrypto::SECRET_KEYS`.

## [0.3.0] - 2026-07-25

### Added

- **Factory Soft-AP setup wizard** in Administration → NC Litter (name, home Wi‑Fi,
  Soft-AP scan, provision, LAN connect)
- Host service `nc-litter-wifi-helper` (`wifi-helper/`) for Soft-AP join + kumy
  MQTT `wlcfg` provisioning without the iRobot app
- Bridge APIs `/onboard/softap-scan`, `/onboard/softap-provision`, `/onboard/softap-status`
- PHP admin routes `/api/admin/setup/softap*`; encrypted `home_wifi_password` appconfig
- Dogfood script `scripts/softap-dogfood.js` and plan
  `.cursor/plans/nc-litter-softap-wizard-v0.3.md`

### Fixed

- Admin UI now recognizes `has_password` (was looking only for `password_set`)

### Changed

- Operator guide leads with Soft-AP factory path; hold-HOME is advanced fallback

## [0.2.0] - 2026-07-25

### Added

- Butler visual system (charcoal / brass / cream) and unique Litter-Robot app icon
- **Mission stage** on the Dashboard — live phase animation, coverage / duration /
  battery / cycle metrics, optional pose mini-map
- Advanced Location map (dock origin, fading trail, heading cone) when pose exists;
  mission-theater fallback when it does not (e.g. Litter-Robot 960)
- Production docs: `docs/ARCHITECTURE.md`, `CONTRIBUTING.md`, GitHub issue templates
- Checked-in plan `.cursor/plans/nc-litter-butler-ui-v0.2.md`

### Changed

- Operator-facing copy uses the live robot display name (not hardcoded Alfred)
- App summary/description are multi-robot; Alfred remains the worked example in docs
- Dashboard is a Controls + MissionStage split; shell gains a cleaning atmosphere

## [0.1.2] - 2026-07-25

### Fixed

- **Bridge was unreachable from Nextcloud.** The default bridge URL used
  `nc-litter-bridge` (hyphens) but the container/service resolves as
  `nc_litter_bridge` (underscores), so every PHP → bridge call failed with
  `Could not resolve host`. Default fixed everywhere and a `nc-litter-bridge`
  network alias added so previously-saved URLs keep working.
- **Live connection to fw2 robots (e.g. Litter-Robot 960) failed silently.** Their
  old TLS stack lacks RFC 5746 secure renegotiation, which OpenSSL 3 (Node
  ≥ 17) refuses (`ERR_SSL_UNSAFE_LEGACY_RENEGOTIATION_DISABLED`). Added a
  `tls.connect` shim (`bridge/lib/tlsLegacy.js`) that enables
  `SSL_OP_LEGACY_SERVER_CONNECT` + a workable cipher/`SECLEVEL` for the
  robot's `:8883` endpoint only.
- **Bridge crash-loop on connect errors.** dorita980 v2 attaches an `error`
  listener that rethrows, turning a benign auth/reconnect error into an
  uncaught exception that restarted the container. The manager now strips that
  listener and handles errors itself.
- Bad-credentials (MQTT CONNACK code 5) now surfaces a clear message pointing
  the operator at **Retrieve credentials (hold HOME)** instead of being
  mislabeled as a session conflict.

### Changed

- `docker-compose.bridge.yml` reads a `.env` for `LITTER_MOCK` / `ROBOT_IP` /
  `LITTER_DISCOVER_SUBNETS` (real mode requires `LITTER_MOCK=0`).

## [0.1.1] - 2026-07-25

### Added

- Working **Auto discover** on Settings + Admin (UDP + :8883 LAN scan)
- Discover finds Alfred even when UDP broadcast is silent

### Changed

- Settings: cleaning preferences are real controls (carpet boost, pass count,
  edge clean, always finish) instead of a raw JSON dump, and the admin-only
  retention panel can preview *and* apply a prune


## [0.1.0] - 2026-07-25

### Added

- Initial NC Litter Nextcloud app (`nc_litter`) for Alfred (Litter-Robot 960)
- Local MQTT bridge sidecar (`nc-litter-bridge`) using dorita980
- Dashboard controls: clean, spot, pause, resume, stop, dock, find
- Status strip, error decoder, mission timeline, schedule week grid
- Maintenance hints, connection health drawer, NC theme inheritance
- Mission history from install, CSV/JSON export, retention prune
- `litter-operators` group ACL, encrypted robot password at rest
- Notifications + Activity for complete / error / bin / battery
- Gate suite: `gate-preflight`, `gate-live`, `gate-gui`
