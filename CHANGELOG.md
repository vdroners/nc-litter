# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This app began as a clone of the `nc_roomba` shell (same controllers → services →
bridge → DB pattern, same live-refresh pipeline, same design tokens) with the
device layer replaced. Its history starts here; the vacuum app's changelog was
inherited by the clone and is not this app's history, so it has been removed.

## [0.3.1] - 2026-08-03

App Store readiness — uninstall cleanup, public OCP paths, packaging, stranger install.

### Added

- `UninstallCleanupListener` drops `nc_litter_*` tables and appconfig on uninstall
- Makefile `appstore` / `appstore-sign` (excludes bridge, tests, src, `.env*`, `.github`)
- GitHub workflows: `release.yml`, `docker-bridge.yml` (GHCR `nc-litter-bridge`), `ci.yml`
- `docs/INSTALL.md` — stranger install via GHCR + Docker network attach (incl. `cloud_cron`)
- Nested `documentation` (user / admin / developer) + screenshot URL placeholders
- `scripts/file_from_env.php` for CI app signing

### Changed

- Alert-log confinement uses `IConfig` / `ITempManager` / `IAppData` (no `\OC::$configDir`)
- Default device display name fallbacks are `Litter-Robot` (not Alfred)
- `bridge-up` / `bridge-net-check` attach and health-check `cloud_cron` on `nc-litter-net`
- Bridge Dockerfile no longer copies `test/` into the runtime image
- Privacy note in `info.xml`: Whisker cloud dependency + encrypted credential handling

## [0.3.0] - 2026-08-03

Alfred / household-ops honesty pass — Talk monitors, docs, and UI catch up to
what the LR4 and Whisker bridge can actually do.

### Added

- Bridge-unreachable banner when Nextcloud cannot reach `nc_litter_bridge`
- Admin field for Alfred `alfred_alert_log` path (confined under `nc_litter/`)
- History “Showing N of total” when the cycle page is truncated
- Plan: `docs/plans/nc-litter-v0.3-alfred-ops.md`
- `ConfinedFileReader` for Alfred alert JSONL reads

### Changed

- Cycle history default page size **50 → 500** (API + UI)
- Settings save copy: confirmed on unit vs waiting for Whisker echo (~30s)
- `docs/OPERATOR.md` / `docs/ARCHITECTURE.md` rewritten for Whisker cloud + Python bridge
- `appinfo/info.xml` description no longer claims empty-drawer or sleep writes
- Notifier fallback device name is `Litter-Robot` (not Alfred)
- Preflight notes `alfred-cron-litter-monitor.timer` for Talk alerts

## [0.2.0] - 2026-07-30

An adversarial audit of the whole app — backend, bridge and GUI — against the
real Litter-Robot 4 and the *installed* `pylitterbot`. It found three blockers
and nine majors, most of them cases where the app reported something confidently
untrue. All are fixed below.

### Fixed — the app was lying to operators

- **A 1% level was reported as 100%.** `_pct()` treated any value in `0..1` as a
  fraction and multiplied by 100. Both LR4 level fields are already percentages
  (`DFILevelPercent`, and `litterLevelPercentage * 100`), so a nearly-empty waste
  drawer read as **full** — firing a drawer-full notification — and, in the other
  direction, critically-low litter read as **100% remaining**, suppressing the
  low-litter warning at exactly the moment it mattered.
- **Every fault decoded to "Something needs a look".** The DTO carried no
  `status_code`, and the normalizer collapsed BR/CSF/SCF/PD/OTF/DHF/DPF/HPF/SPF
  to a bare `fault`, discarding the code it had already computed. Eleven of the
  catalog's fourteen error entries were unreachable. The raw code is now carried
  through and resolved, so a removed bonnet says so.
- **Sleep on/off could never work, and failed silently.** `set_sleep_mode` is
  `raise NotImplementedError()` for the LR4 and `LitterRobot4Command` has no
  sleep verb — yet `capabilities.sleep` was hardcoded `true`, so the UI offered
  the control. Because `str(NotImplementedError())` is the empty string, pressing
  it produced a failure with **no reason given**. The actions are removed, the
  capability reports `false`, and the sleep window is presented read-only
  (it is changed in the Whisker app).
- **Saving settings always claimed success.** `set_settings` discarded every
  per-key result and the HTTP layer hardcoded `ok: true`, so the app showed
  "Saved" for writes that had failed. It now returns `{ok, settings, errors}`
  with 200/207/502, the PHP layer forwards it, and the GUI diffs the readback
  against the patch and reports per-key failure.
- **"Empty globe" did not empty anything.** `empty` and `reset_drawer` were two
  buttons, two confirm dialogs and two audit names for **one** command:
  `reset()`, a short reset press that clears errors and may turn the globe once.
  The confirm text claimed it "tips everything into the waste drawer" and
  "clears the cycles-since-empty count"; neither is possible. Collapsed to one
  honest **Reset / clear error** control with accurate copy.
- **`cycles_since_empty` was the number of local database rows** and grew
  forever, so its maintenance hint would latch permanently while the drawer sat
  at 7%. The device reports this itself as `cycles_after_drawer_full`, which was
  in `pylitterbot` all along and read by nothing. Now surfaced and used.
- **The app manufactured cycles it never observed.** The reaper closed an
  over-age cycle and reopened one on the same tick, producing an unbounded chain
  of fake `interrupted` rows (seven found on the live install, chained
  end-to-start). Cycle detection now keys off `cycle_count` deltas, will not
  reopen on a reap tick, and **stores no duration when neither boundary was
  seen** — the old rows reported the telemetry poll gap (900s) as a cycle
  duration and notified it as fact, for cycles that actually take ~90s. A repair
  step purges the fabricated rows.
- **A dead Whisker cloud looked perfectly healthy.** `updated_at` was re-stamped
  on every *read*, so the 90-second staleness rule could never fire, and a failing
  refresh left `connected: true` while serving hours-old numbers. Added
  `last_poll_ok_at` / `poll_error`, and `connected` now goes false after three
  consecutive failed polls. (`last_seen` is exposed but deliberately unused for
  freshness — it reads 3 days stale on a healthy unit.)
- **Two panels contradicted each other on the same screen.** The Dashboard showed
  a 100% "Fault-free cycles — 8 of 8" donut while History badged 7 of those same
  8 rows as INTERRUPTED. `interrupted` now counts as not-cleanly-completed, and
  the metric is titled for what it measures.
- **History presented telemetry poll gaps as cycle durations** ("· 30m", "· 24m").
  Unobserved durations render as "not observed".
- **A failed command left no trace.** The 3-second poll cleared the error banner,
  and a later successful tap wiped the previous failure. Action failures are now
  sticky until dismissed, and carry the server's real reason.

### Fixed — GUI

- **Labels were hard-clipped; "Litter" rendered as nothing at all.** Nextcloud's
  `core/css/server.css` styles a bare `dt` as `display:inline-block; width:130px;
  white-space:nowrap; text-align:end`. That fixed 130px width overflowed ~120px
  tiles, and `overflow: hidden` cut the result: "Waste drawer" → "Waste",
  "Litter" → invisible, plus nine stats labels shaved. Fifteen clipped elements
  measured at 1600px; now zero. Fixed with one app-wide `dt` reset rather than
  per-component patches.
- **SSE never worked in a browser and armed a state-wipe bug.** The `/stream`
  route's `Content-Type` stayed `text/html` (the bridge proxy echoed to output,
  escaping the `ob_start()` wrapper and sending the body before the headers), so
  `EventSource` refused it and logged a dozen "headers already sent" warnings per
  request; the enriched frame also arrived *last*, ~25s after a **raw** frame of a
  different shape. Since `applyState` did a full replace, fixing the MIME type
  alone would have made the UI flicker between two shapes every 15s. The route is
  now a single enriched frame plus a `retry` hint with correct headers, the store
  merges instead of replacing, and one natural close no longer abandons SSE
  forever (`SSE_MAX_FAILURES` was 1).
- **All Wi-Fi UI was dead** — a permanent "Wi-Fi —" chip, a hero tile with four
  never-lit signal bars, and three helpers with two dedicated tests. An LR4 has
  no RSSI and no SSID. Removed; the freed tile now shows the real wait time.
- **Tabby amber failed contrast on light theme**, which is this instance's active
  theme — including both primary ring gauges at 2.05:1 (needs 3:1) and the "New!"
  pill at 2.24:1 (needs 4.5:1). Added light-theme-only ink tokens; the two
  gauges now measure 5.16:1 and the pill 7.77:1. The dark path was already
  passing and is unchanged.
- **A stylesheet was injected into every Nextcloud page.** `css/nc-litter-theme.css`
  was a byte-for-byte copy of the NC-GCS theme — declaring `--nc-gcs-*` tokens
  and `.nc-gcs-app-shell` component classes globally, re-declaring `:root` after
  nc_gcs's own copy — loaded from `boot()` on Files, Talk, Settings and
  everything else, for an app that renders on one route. nc_litter read none of
  it. Deleted.
- Nine odometer achievements unlocked on install because progress was scored
  against the unit's 1,684-cycle lifetime counter — including "First Flush — the
  very first clean cycle is in the books". Three drawer achievements were
  unreachable because they keyed off a field that is null in practice. Both fixed.
- Connection-health drawer sat behind the Nextcloud header; `prefers-reduced-motion`
  killed animations but not transitions; timeline band labels clipped; several
  dead selectors and store members removed.

### Fixed — security & robustness

- **`/api/alfred/alerts` had no permission check** — the only such route in the
  app. Any of the 126 authenticated users on this instance could read the alert
  tail. Now operator-gated.
- **A 0-day retention deleted everything, including the sample written a second
  ago** (`cutoff(0)` returned `time() + 1`). The cutoff is floored an hour in the
  past, telemetry belonging to retained or open cycles is protected, and
  retention deletes cycles, events and samples in matching batches instead of
  capping one at 10,000 and leaving the rest orphaned.
- **`/api/devices/999/state` answered 200 with the real unit's live sensors**, and
  an action on device 999 would have commanded the real robot and filed the audit
  row under 999. Every device-scoped route now 404s on an unknown device.
- **`AdminSecretCrypto::decrypt` returned the ciphertext on failure**, so after a
  key rotation the app would send `enc:v1:…` to Whisker as the password and
  report a credential error. It now throws, and the UI says the stored
  credentials need re-entering.
- A bridge *failure* body was being merged in as if it were state, putting a
  string into the integer `error` field — which the GUI read as a mechanical
  fault.
- Wait time was clamped to 1..60 when the device accepts only `[3, 7, 15, 25, 30]`
  (note: **no 5**). Both layers now validate against the enum and reject rather
  than clamp. A non-numeric value returned HTTP 500 with a traceback; it is a 400.
- Pagination reported the page size as the total (`?limit=2` → `total: 2` with 8
  rows).
- Overlapping maintenance rules double-reported (drawer 98% fired both warn and
  danger).

### Changed

- **DTO contract.** Added `status_code`, `last_poll_ok_at`, `poll_error`,
  `last_seen`, `litter_level_state`, `cycles_since_full`, `cycle_capacity`,
  `scoops_saved`, `night_light_mode`, `night_light_brightness`,
  `panel_brightness`, `power_on`, `power_type`, `wait_time`, `hopper_status`,
  `hopper_removed`, `wifi_mode`. **Removed `rssi` and `wifi_ssid`** — the device
  has neither. `capabilities` gained `reset`, `sleep_schedule_read` and
  `wait_time_values`, and `sleep` is now `false`.
- **Action set.** `sleep_on` / `sleep_off` removed. `reset` added as the honest
  name; `empty` and `reset_drawer` kept as deprecated aliases.
- Device settings are read through to the unit rather than mirrored in
  `settings_json`, which had gone stale (the row said wait time 3; the device
  said 7).
- Post-write behaviour: instead of one immediate refresh that always captured the
  pre-change value, the bridge re-polls at +5/+10/+20s. Measured on the real
  unit, a night-light toggle takes well over 30 seconds to be reported back.

### Added

- **Contract tests against the installed `pylitterbot`** (not a test double).
  They assert every property the normalizer reads exists, every dispatched method
  exists *and is implemented*, no allowed action is an orphan, the wait-time enum
  matches, and every upstream `LitterBoxStatus` code is mapped with none invented.
  One test asserts sleep is *still* unimplemented upstream, so it fails on purpose
  the day that changes. A fake robot that implemented `set_sleep_mode` is exactly
  how the broken Sleep button shipped.
- 23 GUI component tests (there were none) and a fixture built from the captured
  real DTO — the old fixture asserted `rssi: -52` and a sleep schedule this
  device cannot produce, which is *why* the dead Wi-Fi UI and the false "Saved"
  shipped.
- PHP tests for the cycle state machine, the reaper, rising-edge notifications,
  the retention floor, the DTO key set, and degraded-cloud responses.
- Rewrote the preflight and live-bridge gate scripts, which were still
  nc-roomba's: they asserted `battery_pct`, `has_pose`, `pause`/`resume`/`dock`,
  a `/schedule` endpoint and `bridge/index.js`, none of which exist here, and
  read a version from a `bridge/package.json` that a Python bridge does not have.
  The live gates now check the DTO contract, that vacuum-era verbs are rejected,
  and that the in-image contract tests pass.
- Alfred (OpenClaw) Talk skill: `@alfred litter status | clean | reset |
  light-on | light-off | lock | unlock | help`, plus a monitor that posts
  drawer-full / litter-low / fault / offline transitions to a Talk room.
- A `Power` control in Settings (the capability was reported but had no UI).

## [0.1.0] - 2026-07-29

Initial release: a Nextcloud app for the Whisker Litter-Robot 4 via the Whisker
cloud.

### Added

- Python bridge (FastAPI + `pylitterbot`) exposing `/health`, `/state`,
  `/stream`, `/action/{name}`, `/settings`, `/onboard/login`, `/connect`, with a
  self-contained mock mode so the app runs with no Whisker account.
- PHP app: controllers → services → `BridgeClient` → DB, RBAC via the
  `litter-operators` group, encrypted credentials (`enc:v1:`), command audit,
  Activity + Notifications, telemetry sampling and retention jobs.
- Five tables: devices, cycles, cycle events, telemetry samples, command audit.
- Vue 2.7 + Pinia SPA: Dashboard (status hero, two ring gauges, control pad,
  animated globe, drawer trend), History (cycle log, detail, timeline, export,
  achievements), Settings, and Admin (Whisker onboarding).
- Cat-themed charcoal / tabby-amber theme, LR4 error catalog and maintenance
  thresholds.
