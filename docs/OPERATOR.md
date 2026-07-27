# NC Litter — Operator guide

Control your Litter-Robot from Nextcloud over the local LAN MQTT API.
The Node bridge (`nc_litter_bridge`) never binds a public port; browsers talk
only to Nextcloud. The UI uses the robot’s **display name** everywhere
(worked example below: **Alfred**, Litter-Robot 960).

## Prerequisites

- Robot on the same LAN / VLAN as the Nextcloud host (after Soft-AP provision)
- Nextcloud admins configure the app; operators must be in the `litter-operators` group
- Bridge container on Docker network `nc-litter-net`, reachable from `cloud_app` as
  `http://nc_litter_bridge:8080` (underscores; `nc-litter-bridge` alias also resolves)
- Host Soft-AP helper: `nc-litter-wifi-helper` on `:8091` (Wi‑Fi radio on the GCS host)
- For a **real** robot: bridge must run with `LITTER_MOCK=0` (see `.env`)

## 1. Recommended: hold-HOME LAN takeover (robot already on Wi‑Fi)

This is the reliable, app-free path once the robot is on your home Wi‑Fi. If a
brand-new robot isn't on Wi‑Fi yet, get it on **once** with the iRobot app
(that's the only time the app is needed — it also updates firmware), then take
over here. NC Litter pulls the robot's **local** MQTT password straight from the
robot; you never store or use your iRobot cloud login.

1. Give the robot a **DHCP reservation** so its LAN IP is stable, and confirm
   it's on the same `10.0.0.x` LAN the bridge can reach.
2. Open **Administration → NC Litter → Advanced**.
3. Click **Auto discover** — it lists `robotname`, IP, and BLID from a LAN
   `:8883` scan. Pick the robot (fills IP + BLID).
4. On the robot, **press and hold HOME (~2 s) until it beeps / the Wi‑Fi ring
   pulses**, then within ~60 s click **Retrieve credentials (hold HOME)**.
5. **Save** (password is stored encrypted) and **Test connection**.
6. Force-quit the iRobot app (single MQTT client — the robot allows one).

CLI equivalent (host → bridge): `POST /onboard/get-password {"ip":"<robot-ip>"}`.

**Persistence:** the app stores the encrypted creds in Nextcloud. For a headless
guarantee that the bridge reconnects on restart, also set `BLID` / `PASSWORD` /
`ROBOT_IP` in `.env` (gitignored). Verified: recreating the bridge container
then reconnects with no hold-HOME and no app.

## 2. Fallback: factory Soft-AP setup (robot not yet on any Wi‑Fi)

Only needed for a robot that can't be app-provisioned at all. Supported on
Soft-AP fw2 robots (Litter-Robot 960 / 980 class); newer BLE-only models are out of
scope.

> **Litter-Robot 960 caveat (learned the hard way):** the 960's Soft-AP often
> *associates but serves no setup service* — it beacons `Litter-Robot-<BLID>` and
> lets you join, but answers nothing at `192.168.10.1` (no DHCP / MQTT), so
> provisioning stalls at "gateway never responded". A button-only factory reset
> may not fix it. The documented recovery is a **full-minute battery pull**
> (turn the robot over, remove the battery, wait a full minute, reinstall),
> then re-enter Soft-AP. If it still won't serve setup, use path 1 via the
> iRobot app instead.

1. Open **Administration → NC Litter**.
2. **Wizard → Name** — set the display name (e.g. Alfred).
3. **Home Wi‑Fi** — 2.4&nbsp;GHz SSID + password (same LAN as `10.0.0.x` / the bridge).
   Password is stored encrypted for re-provision.
4. Put the robot in Soft-AP mode:
   - **CLEAN** until all lights flash (factory path), release
   - **HOME + SPOT** until melody + green Wi‑Fi blink
5. **Scan Soft-AP**, select `Litter-Robot-<BLID>`, then **Start Soft-AP provision**.
   Wait for the spoken “connected to Litter-Robot” after the host joins.
6. When provision finishes, reserve the discovered LAN IP on your router
   (DHCP reservation), then **Test connection**.
7. Force-quit the iRobot app if it is installed (single MQTT client).

CLI dogfood (host): `node scripts/softap-dogfood.js` with
`HOME_SSID` / `HOME_PASS` / `LITTER_WIFI_HELPER_TOKEN`.

## 2. DHCP reservation

1. Find the robot’s MAC on your router (or use **Auto discover** — it lists
   `robotname`, IP, and BLID from a LAN `:8883` scan).
2. Create a **DHCP reservation** so the robot always gets the same IPv4 address.
3. Confirm port **8883/tcp** is reachable from the host running the bridge:
   `nc -zv <robot-ip> 8883`

After Soft-AP (or phone) Wi‑Fi join we use **local MQTT only**. Close the iRobot
app when using NC Litter (single MQTT connection).

## 3. Rotating the local password

The hold-HOME flow in §1 is also how you rotate the local MQTT password on an
already-connected robot: Advanced → hold HOME until tones → **Retrieve
credentials (hold HOME)** → Save → Test connection.

### Local password vs account password

MQTT `Not authorized` almost always means the stored password is wrong.
Use Soft-AP provision (new password) or **Retrieve credentials (hold HOME)** —
never paste the iRobot cloud login.

## 4. Operators

1. Create / use the Nextcloud group `litter-operators` (configurable in admin).
2. Add users who may start/pause/dock the robot.
3. Open **NC Litter** from the app menu (admins always have access).

## 5. Day-to-day

- **Clean / Spot / Pause / Resume / Dock / Find** from the control pad
- **Stop** asks for confirmation (destructive)
- **Dashboard** shows Controls + live **mission stage** (phase animation, coverage, duration)
- **Location** shows a pose map + trail when the robot publishes pose; otherwise
  the same mission stage so cleaning still feels live
- Status strip shows battery, bin, RSSI, phase, last-seen
- If MQTT conflicts, open the connection health drawer and follow the checklist
- Schedule editor uses robot-local week times (`setWeek`)
- Mission history accumulates locally; admins set retention days (default 365)

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| Soft-AP scan empty | Robot not in HOME+SPOT Soft-AP | Re-enter Soft-AP; helper Wi‑Fi radio up |
| Soft-AP joins but `gateway never responded` | 960 Soft-AP serving no setup service (associates, no DHCP/MQTT at `192.168.10.1`) | Full-minute battery pull, re-enter Soft-AP; if still dead, provision via iRobot app then use §1 hold-HOME |
| Soft-AP join but provision times out | IP stack not up / no spoken prompt | Wait for voice; retry; stay near robot |
| `battery 0` / `not_ready 15`, but connected | Robot off the dock / not reporting charge | Dock the robot; values populate — this is not a connection fault |
| Helper unauthorized / 502 | Helper down or bad token | `systemctl status nc-litter-wifi-helper`; check `.env` token |
| `Could not resolve host: nc-litter-bridge` | Wrong DNS spelling | Use `http://nc_litter_bridge:8080` (underscores) |
| Discover / onboard / test all fail | Bridge URL wrong or bridge down | Check admin Bridge URL; `docker ps` for `nc_litter_bridge` |
| `Not authorized` | Wrong local password | Soft-AP re-provision or hold HOME → Retrieve |
| Conflict / cannot connect | iRobot app or second MQTT client | Close app; wait 30s; retry |
| Discover empty | Robot asleep / wrong subnet | Wake robot; set `LITTER_DISCOVER_SUBNETS` |
| get-password: not in onboarding mode | HOME not held / window expired | Re-hold HOME until tones; retry within ~60s |
| Connected=false, silent errors | Old bridge without TLS shim | Rebuild bridge (v0.1.2+ includes `tlsLegacy.js`) |
| Mock always “connected” | `LITTER_MOCK=1` | Set `LITTER_MOCK=0` and `make bridge-up` |
| Stale state | Bridge down | `curl` `/health` on Docker DNS |
| 403 on actions | Not in `litter-operators` | Admin adds user to group |

## Security notes

- Robot password is stored only as `enc:v1:…` in the Nextcloud DB
- Bridge has no public publish; do not map host ports in production
- Do not stop unrelated services (e.g. `openclaw-gateway`)
