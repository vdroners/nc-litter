# nc-litter: Nextcloud app for the Whisker Litter-Robot 4

## Context

Build a sibling to nc-roomba that monitors and controls a **Whisker
Litter-Robot 4** (LR4). Same product shape (Nextcloud Vue+PHP app → private
bridge sidecar → the device), same butler visual system and live-refresh UX,
but a **different device and transport**.

Key facts established:
- **No official API.** The community options are:
  - **Cloud** — `pylitterbot` (Python), reverse-engineered: Whisker account
    (email/password) → AWS Cognito token → **GraphQL at
    `https://lr4.iothings.site/graphql`** + a websocket/MQTT subscription for
    live push. Commands: `start_cleaning`, `set_panel_lockout`,
    `set_night_light`, `set_power_status`, `set_sleep_mode`, `set_wait_time`,
    `reset_settings`, plus activity/insight history.
  - **Local** — `whiskerless` (Python), fully local MQTT/BLE, no cloud account.
- **The LR4 is on the `Sheela 6` LAN** (via the `Sheela 6R` repeater) — same
  network as the host, so a local transport is viable.
- LR4 telemetry (vs Roomba's pose/coverage): unit **status** (Ready / Clean
  Cycle / Emptying / Drawer Full / Fault / Offline / Sleeping), **waste-drawer
  level %**, **litter level %**, **weight / last cat weight**, **cycle count**,
  **sleep-mode + schedule**, **night-light**, **panel lock**, Wi-Fi/RSSI, error
  states. No pose, no floor map, no "mission coverage".

Reference implementations (confirm the ecosystem + auth/command shapes):
- **`natekspencer/pylitterbot`** (Python) — the authoritative, actively
  maintained LR4 client (Cognito auth + `lr4.iothings.site` GraphQL + live
  push; full status + command set). Best library to build the bridge on.
- **`natekspencer/LitterRobotManager`** — same author; HA/manager patterns.
- **`joshjcarrier/homeassistant-litter-robot`** — HA integration (older LR2/3
  era) for the auth/status pattern.
- **`rylee-s/Homebridge-Litter-Robot-4`** (TypeScript/Node) — proves an LR4
  cloud client (email/password) is doable in **Node**, but it's thin (drawer
  sensor + light toggle only). So: Python reuses the richest lib; Node keeps
  language parity with nc-roomba's bridge but means wrapping/porting the
  Cognito+GraphQL client ourselves.

nc-roomba is a clean, battle-tested skeleton to clone: the generic shell
(controllers→services→BridgeClient→DB, RBAC, crypto, notifications, Pinia store
+ SSE/poll live pipeline, butler theme, achievements, Makefile/deploy) is
device-agnostic; only the bridge internals, the device state/action vocabulary,
the knowledge catalogs, and a few components are Roomba-specific.

## Decisions (locked in review)

- **Transport = cloud** via `pylitterbot`. The local (`whiskerless`) path was
  ruled out because it requires running a TLS MQTT broker + a one-time BLE
  re-provision that takes the robot off the Whisker app — the operator opted not
  to. Cloud onboarding is just a Whisker email+password (stored encrypted).
- **Bridge language = Python.** `pylitterbot` is the maintained, full-featured
  LR4 client (Cognito auth + `lr4.iothings.site` GraphQL + live push), so the
  bridge is a small Python (FastAPI) service wrapping it. Not language-parity
  with nc-roomba's Node bridge, but that's cosmetic — the PHP `BridgeClient`
  only cares about the HTTP+SSE contract, which stays identical.
- **Scope = full app + Alfred skill** in one build.

## Architecture — clone nc-roomba, swap the device layer

### Keep (generic, copy near-verbatim; rename nc_roomba→nc_litter)
- PHP: Controllers→Services→`BridgeClient`→Db pattern, `AdminSecretCrypto`
  (encrypt the Whisker creds/token), `PermissionService`/RBAC
  (`litter-operators` group), Activity/Notifications, background telemetry +
  retention jobs, appconfig getters/setters, `getEnrichedState`/`runAction`
  shape, command audit.
- Frontend: Pinia store + **SSE/poll live pipeline** (verbatim), AppShell,
  ConnectionHealthDrawer, ErrorDecoderPanel, MaintenanceHints, Achievements,
  AlfredPanel, format.js/errorDecoder.js, the butler SCSS + design tokens +
  gauges (battery-ring style reused for level gauges), dashboard grid.
- Tooling: Makefile (build/bump/ship/deploy into `cloud_app`),
  docker-compose.bridge.yml, gate scripts, version-sync, CHANGELOG flow.

### Replace (device-specific)
- **bridge/** internals — this is the core work. Since the best LR4 libraries
  are **Python** (`pylitterbot` / `whiskerless`), the litter bridge is a **small
  Python service** (FastAPI/Flask) exposing the same HTTP+SSE contract the PHP
  `BridgeClient` already speaks (`/health`, `/state`, `/stream`, `/action/{name}`,
  `/settings`), backed by `pylitterbot`. (Node is possible but would mean
  reimplementing the Cognito/GraphQL client from scratch — Python reuses a
  maintained lib.) Remove dorita980, wifi-helper, Soft-AP entirely.
- **State normalizer** → LR4 DTO: `status`, `drawer_level_pct`,
  `litter_level_pct`, `weight`, `last_cat_weight`, `cycle_count`, `sleeping`,
  `night_light`, `panel_lock`, `rssi`, `error`, plus derived
  `connection_health`.
- **Actions** (`ALLOWED_ACTIONS`): `clean` (start cycle), `empty` (empty/reset
  drawer), `reset_drawer` (mark emptied), `sleep_on`/`sleep_off`,
  `night_light_on`/`off`, `panel_lock_on`/`off`, `set_wait_time`.
- **Onboarding** (SetupWizard → LitterSetup): replace Soft-AP/hold-HOME with a
  simple **Whisker account login** (email + password, stored encrypted) → list
  the account's LR4 devices → pick one. (Local path: BLE/MQTT pair instead.)
- **Knowledge catalogs**: LR4 `error_codes.json` (Drawer Full, Bonnet Removed,
  Cat Sensor Fault, Pinch Detect, Motor Fault, Offline, etc.) +
  `maintenance_thresholds.json` (drawer nearing full, litter low, cycles since
  last empty).
- **DB schema**: robot row → device (account_id, device_id/serial,
  creds_enc, model, timezone); telemetry samples → drawer/litter/weight/status;
  "missions" → **cycles** (a clean cycle: started/ended, duration, result,
  drawer level before/after, cat weight). Command audit stays.

## User interface & experience (full spec)

### Visual identity — butler theme, cat-tailored
Keep nc-roomba's design-token system verbatim (elevation/shadow scale, radius,
spacing, motion easing, gauges, hover-lift, entrance motion, focus rings) but
re-skin the accent to a distinct **litter/cat** identity so it doesn't read as a
clone:
- **Palette:** swap Roomba's brass → a warm **"tabby" accent** (soft amber/ginger
  `#d8a45e`-ish) with the same charcoal/cream base; keep light/dark inheritance.
  Status colours reuse the shared state tokens (ok/warn/danger/dock) retinted.
- **Icon + name:** new `img/app.svg` (a cat / litter-box glyph, not a vacuum),
  app display name "**NC Litter**" (device shown by its Whisker name, e.g.
  "Litter-Robot" or a user nickname). Butler voice kept ("your litter valet").
- **Emoji vocabulary** for achievements/hints: 🐈 🧻 🗑️ 🌙 🔒 ⚖️ ✨ (cat/litter),
  replacing the roomba 🧹 set.
- **Cat motif:** the mission-stage glyph becomes a stylised **globe/litter-unit**
  that animates during a clean cycle (rotates) — the theatrical centrepiece,
  same role as Roomba's animated puck.

### Global shell (AppShell)
- Sticky **StatusStrip** (top): device name chip, status pill, drawer %, litter %,
  Wi-Fi, last-seen, connection chip (opens health drawer). Same layout, litter data.
- **Tab nav:** Dashboard · History · Settings (Location tab removed — no pose).
- **ConnectionHealthDrawer:** cloud reachability, last poll age, Whisker
  auth/token state, recovery hints ("re-enter Whisker login", "check the unit's
  Wi-Fi") — replaces the MQTT-conflict recovery copy.

### Dashboard (the primary screen) — dense responsive grid
Reuse nc-roomba's 12-col wide-screen grid (single column on phones). Zones:

1. **StatusHero (at-a-glance card):**
   - **Status pill** with a breathing dot when active — states: Ready · Cleaning
     · Emptying/Cycling · Drawer Full · Sleeping · Paused · Fault · Offline
     (each with its own tone + one-line detail, reusing the hero pill styling).
   - **Two ring gauges** (reuse the battery-ring SVG primitive):
     **Waste drawer %** (fills toward full; amber→red as it approaches full) and
     **Litter level %** (drops as litter is used; warns when low).
   - **Fact tiles:** Last cat weight (⚖️ lbs), Cycles today / total, Wi-Fi (bars
     from RSSI, reusing the signal-bars component), Next scheduled/next-empty
     reminder, Sleep window (if set).
2. **ControlPad (actions):** icon buttons via `NcIconSvgWrapper` (inline SVG
   paths, no new dep) — **Clean cycle**, **Empty / reset drawer** (confirm dialog,
   like Roomba's Stop), **Sleep now / Wake**, **Night-light on/off** (toggle),
   **Panel lock/unlock** (toggle), **Set wait time** (the LR4 post-use delay:
   a small select — 3/7/15/30 min). Buttons disabled off `litter-operators`
   group; pending state shows "…" like Roomba. Toggles reflect live state.
3. **CycleStage (theater):** the animated litter-unit glyph, mood-coloured by
   status (cleaning=amber glow, emptying=blue, fault=red, sleeping=dim/moon),
   with metric tiles beside it: current status, cycles today, drawer %, litter %.
   Replaces MissionStage; **no map** — honest, no fabricated pose.
4. **Health / lifetime rail:** a live **drawer-fill trend** sparkline (drawer %
   over the last N samples, from telemetry — the honest reuse of the old
   Location slot) + **LifetimeStats** card (total cycles, total empties, avg cat
   weight, days since litter change, uptime) + an **Achievements** teaser
   (unlocked/total).
5. **MaintenanceHints (bottom, only when tripped):** "Drawer nearly full — empty
   soon", "Litter low — top up", "N cycles since last empty", "Cat sensor fault
   — clear the globe". Server-computed from `maintenance_thresholds.json`.
6. **AlfredPanel (optional):** "Ask Alfred" card (deep-link to Talk + example
   `@alfred litter …` commands + recent alert mirror), shown only when enabled.
- **ErrorDecoderPanel** folds in above the controls when a fault is active.

### History tab
- **Lifetime band** up top (from the device's own counters) so it's informative
  immediately, even before locally-recorded cycles: total cycles, total empties,
  avg cat weight, litter-change interval.
- **Achievements wall** (cat-themed, tiered bronze/silver/gold with progress bars
  + "New!" tags), reusing the Achievements component + dwell of real counters.
- **Cycle list:** each row a visual card — outcome badge (Complete / Interrupted
  / Fault), relative time ("Today 14:20"), duration, cat weight recorded,
  drawer level after. Rich empty state ("No cycles recorded yet — when the unit
  runs, they'll appear here", with a **Clean now** button).
- **Cycle detail:** stats grid + a phase timeline (Ready→Cycling→Dumping→
  Ready) reusing MissionTimeline. CSV/JSON export kept.

### Settings tab (operator)
- **Sleep schedule editor** (start/end window — the LR4 quiet hours), reusing
  the week-grid pattern where it maps; otherwise a simple start/end time.
- **Device preferences** (real LR4 controls): night-light brightness/mode,
  panel lockout, wait-time default, clean-cycle behaviour toggles the API
  exposes. Same "edit → Save (dirty-guarded)" pattern; **fix applied from
  nc-roomba 0.7.x**: bind `NcCheckboxRadioSwitch` radios with `:model-value`,
  and the watcher only syncs when not dirty (so selections don't snap back).
- Admin-only tools live in the Admin panel (below), not here.

### Admin settings (AdminSettingsView)
- **Onboarding = Whisker login** (replaces Soft-AP wizard): email + password
  fields → "Connect account" → the bridge authenticates and lists the account's
  LR4 devices → pick one → stored (creds encrypted `enc:v1:`). A clear note that
  this uses Whisker's cloud and the unit stays on the Whisker app.
- Device config: display nickname, operator group, refresh interval, retention
  days, bridge URL. "Test connection" + "Re-authenticate" buttons.
- **Alfred assistant** toggle + Talk room token (same as nc-roomba).

### Functionality checklist (must all work)
Monitor: live status, drawer %, litter %, cat weight, cycle count, sleep state,
night-light state, panel-lock state, Wi-Fi/RSSI, faults, last-seen — all live
via SSE/poll without refresh. Control: start clean cycle; empty/reset drawer
(confirmed); sleep on/off; night-light on/off; panel lock/unlock; set wait time;
edit sleep schedule. History: per-cycle log + detail + export + lifetime stats +
achievements. Admin: Whisker onboarding, device pick, re-auth, retention, RBAC.
Assistant: `@alfred litter status|clean|empty|sleep|night-light|help` in Talk +
in-app Alfred card. All gated by `litter-operators` RBAC + command audit.

### Alfred integration (IN SCOPE)
An OpenClaw `litter` skill + `litter-talk-fast-path.sh` + `litter-dispatch-exec.sh`
+ relay wiring (`is_litter_command` / `_litter_fast_path` in
`nc-webhook-relay.py`, defined at module scope — the fix learned on the roomba
skill), mirroring the roomba skill: `@alfred litter status | clean | empty |
sleep | night-light | help`, routed through the app's PHP API as the `alfred`
operator. Gated by `LITTER_ENABLED` + an in-app `alfred_enabled` toggle; alerts
(drawer full, fault) posted to a Talk room by a `litter-monitor` cron.

## Files (clone from nc-roomba → nc-litter, then edit)
- `appinfo/info.xml`, `routes.php`; `lib/AppInfo/Application.php` (APP_ID
  `nc_litter`, group `litter-operators`, bridge URL `http://nc_litter_bridge:8080`).
- `lib/Service/{RobotService,BridgeClient,MissionService,ErrorDecoderService,
  MaintenanceHintService,AdminSecretCrypto,...}` → renamed Device/Cycle services,
  new action list + state enrich.
- `lib/Db/*` + `Migration/Version0001` → device + cycle + telemetry + audit
  tables.
- `bridge/` → new Python service (`app.py` + `litter_manager.py` +
  `normalizer.py` + `Dockerfile` + `requirements.txt` with `pylitterbot`), same
  HTTP/SSE contract; delete `wifi-helper/`.
- `src/store/robot.js` (keep pipeline; rename), `src/services/api.js`,
  `src/components/{StatusHero,ControlPad,LifetimeStats,Achievements,...}.vue`,
  `src/views/{Dashboard,History,Settings,AdminSettings}.vue` (drop Location),
  `src/utils/{format,errorDecoder,achievements}.js`.
- `knowledge/error_codes.json` + `maintenance_thresholds.json` (LR4).
- `css/style.scss` (reuse tokens/gauges; retheme copy), `Makefile`,
  `docker-compose.bridge.yml`.
- New repo at `/media/4TB/nc-litter`, remote to be decided.

## Verification — pass/fail gates

Each gate is objective (green = observable evidence). Later phases don't start
until their prerequisite gates pass.

**G0 Scaffold** — tree copied; `grep -rniE 'roomba|dorita980'` (excl.
node_modules/CHANGELOG history) returns **0**; git repo initialised; plan
checked into `docs/plans/`. PASS = clean rename + `git status` sane.

**G1 Bridge unit** — `cd bridge && pytest` (or the chosen runner) green with
`pylitterbot` mocked: normalizer maps a sample LR4 payload → DTO
(status/drawer%/litter%/weight/cycles/sleep/nightlight/lock); each action maps
to the right `pylitterbot` call; auth/token refresh path covered; SSE emitter
fires on change. PASS = 100% of bridge tests pass.

**G2 Bridge live** — with real Whisker creds in the bridge env:
`curl :PORT/health` → `{connected:true}`; `curl :PORT/state` → real drawer%,
litter%, cycle_count, status; `curl -N :PORT/stream` pushes a frame on change;
`POST /action/night_light_on` flips the live state (safest live action to prove
write path — NOT a physical cycle unless you approve). PASS = real values + a
confirmed round-trip write.

**G3 PHP unit** — `phpunit` green: `DeviceService::getEnrichedState` shape,
`runAction` ACL + audit, `AdminSecretCrypto` round-trip, error decode of the LR4
catalog, maintenance thresholds. PASS = all PHP tests pass + `php -l` clean on
changed files.

**G4 Frontend unit** — `npx vitest run` green: format helpers (drawer/litter
labels, gauge buckets), achievements catalogue against sample counters,
store/live-pipeline specs. PASS = all vitest pass.

**G5 Build** — `make build` exits 0 (scss + webpack, no errors; size-warnings
OK). PASS = exit 0.

**G6 Deploy + migrate** — `make deploy RESTART=1`; container reports the app
version; `occ upgrade` runs the migration (tables created); both `cloud_app` +
`nc_litter_bridge` healthy. PASS = version match + tables exist + containers up.

**G7 API smoke (as an operator)** — authenticated `GET
/api/devices/1/state` returns enriched LR4 state; `GET /api/cycles` returns a
list (may be empty); a command audit row is written on an action. PASS =
2xx + expected JSON shape.

**G8 GUI E2E** — browser (hard refresh): Dashboard shows the two ring gauges at
real %, status pill correct, ControlPad renders icons + toggles reflect live
state; History lists (or shows the inviting empty state); Admin shows the
Whisker-login onboarding; **nothing clips**; **data updates live without a
refresh** (watch a value change). Light-theme + reduced-motion sane. PASS =
all observed.

**G9 Onboarding E2E** — in Admin, enter Whisker email+password → Connect →
account's LR4 device(s) listed → select → creds persisted `enc:v1:` in DB →
"Test connection" green. PASS = device onboarded end-to-end via the UI.

**G10 Alfred E2E** — `LITTER_ENABLED=1` + relay wired; a `@alfred litter status`
webhook to the relay returns `{via:"litter-fast-path"}` and posts a real status;
`is_litter_command`/`_litter_fast_path` at module scope (no NameError). PASS =
dispatched 200 + status line.

**G11 Regression** — `git status` scoped (no `.env`, no secrets committed);
commit trailer is the Claude line, no Cursor; nc-roomba untouched. PASS = clean
commit on the CLAUDE.md recipe.

## Guardrails / non-goals
- v1 = cloud transport (pylitterbot), one LR4 device on one Whisker account.
- Whisker creds/token stored **encrypted** (`enc:v1:`), never in the repo or logs.
- No pose/map/coverage (device has none) — don't fabricate; reuse that UI space
  for real litter/drawer telemetry.
- Reuse nc-roomba's generic shell; don't re-architect what already works.
