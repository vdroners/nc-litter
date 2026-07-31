# nc-litter v0.2.0 — objective audit and remediation

Plan-First record for the 0.2.0 change (19labs AI-assist guideline #5). Written
after v0.1.0 shipped and before the remediation work, then kept as the record of
what actually changed. The v0.1.0 build plan is
[`nc-litter-v0.1-plan.md`](nc-litter-v0.1-plan.md).

## Why

v0.1.0 was built fast by cloning the `nc_roomba` shell and swapping the device
layer. That got a working app quickly, but a clone inherits its parent's
assumptions, and a litter box is not a vacuum. The brief for this change was to
**objectively review the whole program — GUI and backend — and make sure the code
is logical, with continued testing.**

So the work started with two independent adversarial audits, told to find what is
wrong rather than to confirm what is right, and required to *demonstrate* every
claim against the real Litter-Robot 4 rather than reason about the source:

- **Backend/bridge audit** — read `lib/**`, `bridge/**`, `appinfo/**`,
  `knowledge/**`; verified against the live device, the MariaDB tables, PHP
  harnesses run inside `cloud_app`, and introspection of the installed
  `pylitterbot`.
- **GUI audit** — read `src/**`, `css/**`, `templates/**`; verified against the
  live DTO, a real authenticated browser session, and throwaway harnesses driving
  the actual Pinia store.

Between them they found **3 blockers and 9 majors**. The dominant failure mode
was not crashes — it was the app stating things that were **confidently untrue**:
a full drawer that was nearly empty, a "Saved" that saved nothing, a Sleep button
that could never work, an "Empty" that emptied nothing, a 100% fault-free donut
next to seven interrupted rows.

## The root cause worth naming

**Every one of those bugs was protected by a test that passed.**

The tests asserted the clone's assumptions instead of the device's behaviour:

- `_FakeRobot` in the bridge suite implemented `set_sleep_mode`. The real
  `LitterRobot4.set_sleep_mode` is `raise NotImplementedError()`. The fake made a
  permanently broken button look tested.
- The GUI fixture asserted `rssi: -52`, `wifi_ssid: 'Sheela 6'` and an enabled
  sleep schedule — three things this device cannot produce. That fixture is
  precisely why dead Wi-Fi UI and a false "Saved" shipped.
- `test_fraction_level_is_scaled_to_percent` exercised `0.5` only, cementing the
  ambiguity that turned a genuine 1% into 100%.
- A DTO key-set assertion used `issubset`, so a *missing* key (`status_code`) was
  invisible — and its absence made 11 of 14 error-catalog entries unreachable.

The structural fix is therefore not "more tests" but **tests bound to reality**:

1. `bridge/test/test_pylitterbot_contract.py` binds to the *installed* library,
   not a double. It asserts every property the normalizer reads exists, every
   dispatched method exists **and is implemented**, no allowed action is an
   orphan, the wait-time enum matches, and every upstream `LitterBoxStatus` code
   is mapped with none invented. One test asserts sleep is *still* unimplemented
   upstream, so it fails on purpose the day that changes.
2. The DTO key set is asserted as **exact equality**, in the bridge suite, the
   GUI fixtures, a PHP contract test, and the live gate script.
3. GUI fixtures are built from a **captured real DTO**.
4. 23 GUI component tests, where there were none — every GUI blocker and major
   was component-level.

## What changed

Full detail is in [`../../CHANGELOG.md`](../../CHANGELOG.md) under `[0.2.0]`.
Summary by layer:

**Bridge (Python)** — `_pct` no longer rescales percentages; `status_code`,
`cycles_since_full`, `last_poll_ok_at`/`poll_error` and a dozen real LR4 fields
added to the DTO; `rssi`/`wifi_ssid` removed (the device has neither); sleep
actions removed and `capabilities.sleep` reports false; `/settings` returns
per-key `errors` instead of a blanket `ok`; wait time validated against the
device enum `[3,7,15,25,30]`; `connected` goes false after 3 failed polls;
post-write convergence polls at +5/+10/+20s because the Whisker cloud takes tens
of seconds to reflect a write.

**PHP** — operator gate added to the one route that lacked it; cycle detection
keyed off `cycle_count` deltas and no longer reopens on a reap tick, with no
duration stored when neither boundary was observed (plus a repair step that
purged 7 fabricated rows); `cycles_since_empty` reads the device's own counter;
retention cutoff floored and batched with cycle-aware telemetry protection; 404
on unknown devices; true pagination totals; settings read through to the device;
`AdminSecretCrypto` throws instead of returning ciphertext; SSE route rewritten
as a single enriched frame with correct headers; dead code removed.

**GUI** — one app-wide `dt` reset fixed 15 clipped labels (Nextcloud's
`core/css/server.css` gives a bare `dt` a fixed 130px width, so "Litter" rendered
as nothing at all); the store merges frames instead of replacing state and no
longer abandons SSE after one natural close; controls gate on `capabilities`;
Sleep removed and the sleep window is read-only; two lying confirm dialogs
collapsed into one honest "Reset / clear error"; all dead Wi-Fi UI deleted;
light-theme contrast fixed (both primary gauges were at 2.05:1, needing 3:1);
action failures are sticky instead of erased by the 3s poll; a global stylesheet
that was a byte-copy of the NC-GCS theme and injected into **every** Nextcloud
page was deleted.

**Copy** — the knowledge catalogs addressed the litter box as "Alfred" in 37
places. That is the sibling Roomba's name; this unit is called whatever its owner
chose in the Whisker app. All catalog copy is now device-neutral, with a preflight
gate to keep it that way.

**Gates** — `litter-preflight.sh` and `litter-live-gates.sh` were still
nc-roomba's: they asserted `battery_pct`, `has_pose`, `pause`/`resume`/`dock`, a
`/schedule` endpoint and `bridge/index.js`, and read a version from a
`bridge/package.json` that a Python bridge does not have. Both rewritten against
the real contract.

## Verification

| Suite | Result |
|---|---|
| `npx vitest run` | 106 passed (5 files) |
| `pytest bridge/test` (host) | 33 passed, 1 skipped (contract test needs pylitterbot) |
| `pytest /app/test` (in bridge image) | 41 passed, incl. the pylitterbot contract |
| `make run-phpunit` | 101 tests, 287 assertions, OK |
| `tools/litter-preflight.sh` | 9/9 PASS |
| `tools/litter-gui-gates.sh` | 44/44 PASS |
| `tools/litter-api-gates.php` | 9/9 PASS |
| `tools/litter-live-gates.sh` (real device) | 17/17 PASS |
| `npm run build` | exit 0 (asset-size warnings only) |
| Deploy | `cloud_app` reports 0.2.0; migration + repair step ran |

Live confirmations on the real unit ("Poop Roller"): `status_code` resolves
(`RDY`, and `BR` → the bonnet entry), `cycles_since_empty` is the device's 0 and
not the old row count of 8, `/devices/999/state` → 404, `sleep_on` → 400 with an
honest reason, `set_wait_time 5` → 400 naming the valid set, `/settings` with a
bad key → non-200 with the failing key named, `/stream` → correct
`text/event-stream` with the enriched frame first, and `@alfred litter status`
posts a real line to Talk with no spurious warning marker.

## Known limitations, stated rather than hidden

- **Sleep cannot be written.** Read-only until `pylitterbot` implements LR4
  sleep; the contract test will announce the day it does.
- **Emptying the drawer is manual.** No command exists. `reset` clears errors and
  may turn the globe once.
- **Data freshness is capped at the 30s upstream poll**, and writes take tens of
  seconds to be reflected by the Whisker cloud. Nothing in the app can improve
  on that; the UI converges rather than pretending to be instant.
- **`last_seen` and `wifi_mode` are exposed but unused for health.** Both read
  degenerately on a healthy unit (3 days stale, and `OFF` respectively), so no UI
  is built on them.
- **A real cycle duration is only recorded when both boundaries are observed.**
  With Nextcloud cron at 5 minutes and a cycle lasting ~90 seconds, that is rare;
  the app says "not observed" rather than printing the poll gap as fact.
- **`nc_litter_telemetry_samples.rssi`** remains in the schema, unwritten and
  documented as reserved. Dropping a column was judged more risk than value.
- **G8 (GUI end-to-end by the operator)** still wants the owner's own eyes in a
  browser. The clipping fix, the absent Wi-Fi tile, the absent Sleep button and
  live refresh were all confirmed in an authenticated session, but a human sign-off
  on the feel of it is not something a gate can give.
