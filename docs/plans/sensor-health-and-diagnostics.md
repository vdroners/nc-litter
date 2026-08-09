# nc-litter 0.4.0 — sensor health, diagnostics capture, and honest alerting

## Why

The unit's laser board failed on 2026-08-07. This app had been sampling it every
five minutes throughout and **recorded four days of clear warning signs without
raising one of them**:

- From 08-04 the waste-drawer ToF began swinging 0 % → 100 % → 0 % inside single
  five-minute intervals. Physically impossible. Recorded faithfully, never flagged.
- On 08-06 the litter level read 60 → 86 in a day. Litter does not rise on its own.
- On 08-07 05:20 the litter sensor stopped answering (`0xFFFF`) and the app
  announced **"litter critically low"** — sending the owner to refill a full box.
- Four **drawer-full notifications** fired during the flapping window, most of them
  false. Measured, not estimated: `notifyLevelEdges` is rising-edge-only, which is
  correct as far as it goes, but a flapping sensor produces genuine rising edges.
- The odometer has been frozen at 1718 since 08-07 and nothing noticed.

The false low-litter alarm is already fixed (`f33df2a`) by treating a level outside
0..100 as a malfunction. That was the narrowest possible fix. This release addresses
the class of problem behind it: **the app has no notion of whether a reading is
believable**, so it relays whatever the device says and draws confident conclusions
from nonsense.

There is also a diagnosis-time lesson. When the board failed it was impossible to
say whether `pinchStatus: SWITCH_1_SET` was normal, because the app has never
recorded that field — it took physically reseating the bonnet to find out. The
bridge already *receives* all 100 fields from Whisker and keeps 30.

Background and full hardware evidence: `docs/lr4-laser-board-failure-2026-08.md`.

## What changes

Six features. Ordered by dependency, not value — (2) has to land first because
everything else reads what it captures.

### 2. Capture the diagnostic registers

`source` is the live `pylitterbot` `LitterRobot4`, and most of these are **not**
exposed as properties — they exist only in `robot._data`. So the normalizer gains a
`_raw(source, key)` accessor that reads `_data` when present and falls back to plain
dict access (mock and tests pass dicts). Documented as reaching past the library on
purpose.

New `diagnostics` block in the DTO:

| field | raw key | why it matters |
|---|---|---|
| `display_code` | `displayCode` | `DCX_LAMP_TEST` stuck = hung self-test |
| `pinch_status` | `pinchStatus` | had no baseline when it mattered |
| `weight_sensor` | `weightSensor` | froze at exactly `-1.5` |
| `dfi_level_mm` | `DFILevelMM` | froze at `77`; the drawer ToF in millimetres |
| `globe_motor_fault` | `globeMotorFaultStatus` | exonerates/implicates the motor |
| `globe_motor_retract_fault` | `globeMotorRetractFaultStatus` | as above |
| `usb_fault` | `USBFaultStatus` | power path |
| `laser_dirty` | `isLaserDirty` | distinguishes a dirty lens from a dead board |
| `firmware.{esp,pic,laser_board}` | `espFirmware`, `picFirmwareVersion`, `laserBoardFirmwareVersion` | per-board health |
| `laser_board_ok` | derived | false when the version is absent or `0.0.0.0` |

These ride into `oc_nc_litter_telemetry_samples.payload_json` via the existing
allowlist in `CycleService::toDto()` — no migration, and the history becomes the
baseline the next fault will need.

### 1. Sensor plausibility layer

Single-reading sanity stays in the bridge, where it already is for litter.
Cross-sample plausibility needs history, so it goes in PHP as a new
`SensorHealthService`. Detectors:

- **`drawer_jump`** — drawer moves ≥ 60 points between consecutive samples taken
  ≤ 15 minutes apart. Caught the 08-04 onset.
- **`litter_rise`** — litter level rises ≥ 10 points with no empty or reset cycle
  in between. Litter is monotonically non-increasing between refills.
- **`frozen_register`** — `weight_sensor` or `dfi_level_mm` bit-identical across
  ≥ 6 hours **and** ≥ 24 samples **and** at least one cycle completed in the
  window. All three conditions matter: a genuinely idle box legitimately holds a
  steady reading, so requiring a completed cycle is what separates "quiet" from
  "not being read". Real load cells jitter; an exact repeat is the tell.

Each detector returns a finding `{id, severity, detail, evidence}` rather than a
bare boolean, so the hint text can quote the actual numbers.

### 3. Gate notifications on plausibility

Two changes to `notifyLevelEdges`:

- **Trust gate** — suppress a drawer-full or litter-low notification when that
  sensor is currently untrusted. Telling someone to empty a drawer that is not full
  is worse than silence: it teaches them to ignore the alerts.
- **Two-sample confirmation** — require the triggering condition to hold across two
  consecutive samples. A single flapped reading no longer alerts.

The suppressed condition is not swallowed: it becomes a *sensor* finding instead, so
the user is told the sensor is unreliable rather than told nothing.

### 4. Board firmware health

- **`laser_board_unprogrammed`** — `laser_board_ok === false`, severity `error`.
  A one-line check the app could have made on day one.
- **Version history** — the firmware triple is in `payload_json` from (2), so a
  failed update becomes visible after the fact instead of being unrecoverable.
- **`firmware_backend_contradiction`** — the reported version differs from the
  target while Whisker's backend reports no update needed. That is a bug on their
  side (it compares against `0.0.0.0` and concludes nothing is needed, which is why
  a forced reflash is refused). The app should name it, not let it hide. Requires
  the bridge to surface the target and the `isLaserboardFirmwareUpdateNeeded` flag
  from `get_firmware_details()`.

### 5. Stalled-unit watchdog

- **`no_cycles`** — odometer unchanged for ≥ 24 h, severity `warn`; ≥ 48 h → `error`.
  This is the symptom the owner actually experienced and the one thing the app never
  said. Suppressed while the unit is offline or asleep, so a quiet night is not a
  fault.
- **`self_test_stuck`** — `display_code` unchanged for ≥ 1 h while it is a
  self-test/startup code (`DCX_LAMP_TEST`, `DCX_REFRESH`). This is the actual
  failure state of the real unit and deserves to be named precisely.

### 6. Verify commands executed

`start_cleaning()` returns `True` and the robot does nothing — the app currently
treats the API's acceptance as success. Same bug class as the nc-roomba preferences
echo: **acknowledgement is not execution.**

On a commanded cycle, record `{odometer, ts}` to appconfig. The telemetry job
settles it: odometer moved → audit `executed`; grace elapsed (10 min) without
movement → audit `not_executed` plus a `command_not_executed` finding. The
pending record is device-scoped and cleared either way, so it cannot accumulate.

## Rules

All eight new findings become rules in `knowledge/maintenance_thresholds.json`
(with the `.yaml` mirror regenerated — G14 gates the drift). Wording rule, learned
from the litter-sensor fix: a sensor fault must say plainly that it is a sensor
fault and that the obvious remedy will not clear it.

## Verification

The decisive test, and the reason this is worth doing carefully: **the recorded
telemetry is now a labelled dataset.** 2536 samples spanning 07-27 → 08-08, with a
known-good week (07-27 → 08-03) and a known-bad one (08-04 → 08-08), and a known
onset date.

A backtest harness replays those samples through `SensorHealthService` and asserts:

- **zero findings** across 07-27 → 08-03 — no false positives on a healthy week
- `drawer_jump` fires on **08-04**, the real onset, three days before the owner
  noticed anything
- `litter_rise` fires on 08-06
- `litter_sensor_no_response` fires from 08-07 05:20
- `frozen_register` fires for `weight_sensor` and `dfi_level_mm`
- `no_cycles` fires after 08-08
- the four historical drawer-full notifications drop to at most one under the new
  gate

A detector that cannot survive its own history is not worth shipping, and the false
positive rate matters as much as the catch — an app that cries wolf on a healthy
week is how the real signal got missed in the first place.

Plus: bridge pytest (host + in-image against real `pylitterbot`), `vitest`,
`litter-preflight.sh`, `litter-api-gates.php`, `litter-live-gates.sh`, `npm run
build`, deploy, and a browser check that the new sensor-health surface renders.

## Notes

- Minor bump 0.3.2 → 0.4.0; CHANGELOG entry; this plan checked in with the work.
- The DTO key-set contract test in `bridge/test/test_normalizer.py` will fail until
  `diagnostics` is added to `EXPECTED_DTO_KEYS`. That is the test doing its job.
- The real unit is currently faulted, so the live gates run against a genuinely
  broken device — which makes it a better test target than a healthy one for once.
