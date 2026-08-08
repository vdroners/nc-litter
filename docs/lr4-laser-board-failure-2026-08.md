# Litter-Robot 4 `LR4C839073` — laser (ToF) board failure

Evidence pack for a warranty claim. Unit registered **2025-09-02**, failed
**2026-08-07**. Every reading below was taken from Whisker's own API against the
live unit; the timeline comes from telemetry this app has been recording every
five minutes since 2026-07-27.

## Verdict

The **laser board** — the daughterboard carrying the time-of-flight (ToF)
sensors — has failed. It is not the main control board.

The unit reports firmware versions for each of its three boards. Two are current;
the third reports nothing at all:

| board | reported | latest available |
|---|---|---|
| ESP (Wi-Fi) | `1.1.84` | `1.1.84` ✅ |
| PIC (main controller) | `10512.3072.2.93` | `10512.3072.2.93` ✅ |
| **Laser board (ToF)** | **`0.0.0.0`** | **`5.0.2.1`** ❌ |

`0.0.0.0` is not a version — it is the absence of one. A board that is talking
reports what it is running. Both sensors that board serves are consistent with
that:

- `litterLevel: 65535` — `0xFFFF`, the out-of-range/no-measurement sentinel.
  This field is a millimetre distance from the top-centre ToF sensor to the
  litter (~441 full, ~451 nominal, ~471 very low). 65535 mm is 65 metres.
- `DFILevelMM: 77` — the waste-drawer ToF, frozen on one value since the failure
  and no longer tracking.

## The main control board is provably fine

This is the part that contradicts what support told you. A dead main board cannot
do what this one just did — accept a setting over the network, write it, apply it,
and report the new value back:

```
set_panel_brightness(MEDIUM) -> True     panelBrightnessHigh  0 -> 50   (10s)
set_wait_time(15)            -> True     cleanCycleWaitTime   7 -> 15   (10s)
set_wait_time(7)             -> True     restored
```

Also healthy: `globeMotorFaultStatus: FAULT_CLEAR`,
`globeMotorRetractFaultStatus: FAULT_CLEAR`, `USBFaultStatus: CLEAR`,
`isUSBPowerOn: true`, `isBonnetRemoved: false`, `isOnline: true`,
`wifiRssi: -49`, `isKeypadLockout: false`.

The motors report no fault, the bonnet is seated, the panel is unlocked, and the
board answers every command. What it will not do is run a cycle: `start_cleaning()`
returns `True` (the cloud accepts it) and then nothing happens — 60 seconds later
every register is unchanged and the odometer is still on 1718. The next section
explains why.

## The unit never finishes booting

This is the mechanism that ties every symptom together. Observed after a bonnet
reseat and a fresh power-up.

```
t+10s  ROBOT_POWER_OFF   DCX_REFRESH
t+20s  ROBOT_IDLE        DCX_LAMP_TEST
t+30s  ROBOT_CAT_DETECT  DCX_LAMP_TEST     catDetect: CAT_DETECT_RESET_HOME
```

And then nothing. Watched for a further **11 minutes at 15-second intervals: not
one register changed.** Same status, same display code, same `CYCLE_STATE_WAIT_ON`,
`isCatDetectPending: false`, odometer still 1718. That is well past the unit's
7-minute clean-cycle wait timer, so it is not waiting on a timer — it is stuck.

`DCX_LAMP_TEST` is part of the power-up self-test and `CAT_DETECT_RESET_HOME` is
the startup globe-homing routine. **The unit is hanging inside its own power-up
self-test and never reaches an operational state.** That accounts for the whole
symptom set at once:

- alternating red/blue flash — self-test failure indication, not a normal state
- litter and drawer both reading "empty" — sensor registers never initialised
- will not cycle *even from the buttons* — it never got far enough to accept one
- `reset()` appears to work but changes nothing, and a power cycle boots straight
  back into the same hang

The most likely reason the self-test cannot complete is the laser board: the
firmware waits on a ToF initialisation that never arrives from a board reporting
`0.0.0.0`.

A `reset()` issued from this hung state is revealing. It does something, and what
it does is give up:

```
reset() -> True
t+ 0s   ROBOT_CAT_DETECT   DCX_LAMP_TEST   catDetect: CAT_DETECT_RESET_HOME
t+15s   ROBOT_IDLE         DCX_LAMP_TEST   catDetect: CAT_DETECT_RESET_CANCELLED
        ... then unchanged for the remaining 6.75 minutes
```

`CAT_DETECT_RESET_CANCELLED` — the globe-homing routine was **cancelled, not
completed**, while `globeMotorFaultStatus` stayed `FAULT_CLEAR` throughout. The
motor driver reports nothing wrong; the unit simply cannot confirm the globe
reached its home position, so it abandons the attempt, drops to idle, and stays in
`DCX_LAMP_TEST` forever. It never leaves the self-test and the odometer never
moves off 1718.

One further observation. `weightSensor` held **exactly** `-1.5` across all 11
minutes. Real load cells jitter; a perfectly constant value means that register is
frozen too, alongside `litterLevel` (sentinel) and `DFILevelMM` (pinned at 77).
Three independent sensor registers are all stale, which points at the sensor
read path rather than three separate transducers failing at once.

## Timeline — the board degraded over four days before it failed

From this app's five-minute telemetry. "Swings" counts physically impossible
drawer readings: a jump of 60+ percentage points inside 15 minutes.

| day | drawer range | swings | litter level |
|---|---|---|---|
| 07-27 | 14–18 | 0 | 76–90 |
| 07-28 | 4–15 | 0 | 68–90 |
| 07-29 | 3–4 | 0 | 74–90 |
| 07-30 | 4–12 | 0 | 90 |
| 07-31 | 12–21 | 0 | 84–90 |
| 08-01 | 21–28 | 0 | 82–90 |
| 08-02 | 28–35 | 0 | 74–82 |
| 08-03 | 2–40 | 0 | 74–76 |
| **08-04** | **0–100** | **3** | 70–74 |
| **08-05** | **0–100** | **2** | 62–72 |
| **08-06** | **0–100** | **3** | 60–86 |
| **08-07** | 0 | 0 | **60 → dead at 05:20** |
| 08-08 | 0 | 0 | dead |

Through 08-03 the drawer sensor traces a clean monotonic fill ramp and the litter
level walks steadily downward — both sensors healthy. On **08-04** the drawer
sensor starts slamming between 0 % and 100 %. On **08-06** the litter reading
begins wandering upward (60→86 in a day, which litter cannot do on its own). At
**08-07 05:20** the litter sensor stops answering entirely and the drawer pins to
zero.

Both failed sensors sit on the same board, and they failed in sequence. That is a
progressive board failure, not two coincidences.

The unit completed one more cycle at 08-07 20:00 (odometer 1717 → 1718), raised a
fault at 21:10, and has not cycled since.

**The failure began three days before the box was cleaned on 08-07.** Cleaning it
did not cause this and could not have.

## Why the remote fix is blocked — a bug at Whisker's end

The backend's own update check says the laser board is up to date:

```
isLaserboardFirmwareUpdateNeeded: false
latestFirmware.laserBoardFirmwareVersion: "5.0.2.1"
laserBoardFirmwareVersion (actual):       "0.0.0.0"
```

It is comparing against `0.0.0.0` and concluding no action is needed, so
`update_firmware()` is refused (`isUpdateTriggered: false`) and `has_firmware_update`
returns `false`. The one repair that might work cannot be requested through the
normal path.

This matters because `0.0.0.0` has two possible causes and they need different fixes:

1. **The board's flash is blank** — a programming attempt erased it and did not
   complete. A forced reflash fixes it outright.
2. **The board is electrically dead** — it cannot answer, so it cannot report a
   version. Reflash will fail and the board needs replacing.

Both are the laser board. Only a forced reflash distinguishes them, and the
backend currently refuses to attempt one.

## What has been tried

Remotely (all via Whisker's API, all exhausted):

| lever | result |
|---|---|
| `reset()` (short reset press) | accepted; globe-homing **cancels**, self-test never completes |
| `set_power_status` off → on | boots straight back into the stuck state |
| `update_firmware()` | **refused by the backend** — see above |
| `start_cleaning()` | accepted by the cloud, robot never moves, odometer frozen |
| `reset_settings()` | not implemented for LR4 (Litter-Robot 3 only) |
| config writes | **work** — proves the main board is alive |

By the owner: power cycle, firmware update, manual reboot, extended rest with
power removed, manual button presses.

## The ask

Have Whisker **force a laser-board firmware reflash on serial `LR4C839073`**,
overriding `isLaserboardFirmwareUpdateNeeded: false`. If the reflash fails or the
board still reports `0.0.0.0` afterwards, the laser board is dead and needs
replacing. Either way the main control board is not the failed part.

## Not faults — do not chase these

- **"Dirty bin is empty" is correct.** `DFILevelPercent: 0` right after emptying
  the drawer is accurate. Only the litter reading is wrong.
- **`isLaserDirty: false`** — the unit has a dedicated dirty-laser flag and it is
  clear. A dirty window also cannot make a board forget its firmware version.
- `isHopperRemoved: true` — correct, there is no LitterHopper fitted.
- `pinchStatus: SWITCH_1_SET` — **ruled out.** The bonnet was removed and reseated;
  this field did not change, and the owner's bonnet-removal notification fired
  correctly. An engaged pinch switch would have changed state. This is the normal
  resting value for this unit.
- **Bonnet reseat has already been done** and did not help. The bonnet switch and
  the notification path both work.

## Owner checks still outstanding

Worth doing before the unit is sent back, and cheap:

- With the power off, lift the globe and confirm nothing is wedged beneath it and
  that it seats squarely — the Smart Weight load cells live under there, and the
  startup routine that hangs (`CAT_DETECT_RESET_HOME`) is the globe-homing step.
  A globe that cannot reach its home position would stall the same self-test.
- Reseat the laser board's ribbon connector at both ends, power removed. This is
  the only user-serviceable cause of a board that will not enumerate.

Neither will restore a laser board whose flash is blank; both are worth ruling
out so the warranty conversation is about the board and nothing else.
