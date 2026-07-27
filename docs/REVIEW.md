# NC Litter — Improvements Review

A plain-language tour of everything built so far (v0.1.0 → v0.4.0), what each
piece does, and the current live state of **Alfred** (the Litter-Robot 960 on this
install). For the machine-readable version see [CHANGELOG.md](../CHANGELOG.md);
for design see [ARCHITECTURE.md](ARCHITECTURE.md); for day-to-day use see
[OPERATOR.md](OPERATOR.md).

## How to review it yourself

```bash
cd /media/4TB/nc-litter
git log --oneline            # the milestone story (few commits)
cat CHANGELOG.md             # human summary per version
git show <hash>              # full diff of any one commit
# live robot state through the bridge:
docker exec cloud_app curl -s http://nc_litter_bridge:8080/state
```

Then open **NC Litter** in Nextcloud (Dashboard, Location, and
Administration → NC Litter).

## What the app is

A Nextcloud app that controls a Litter-Robot over the **local LAN MQTT API** — no
iRobot cloud in the control path. Browsers talk only to Nextcloud; a private
Node **bridge** (`nc_litter_bridge`, using `dorita980`) holds the single MQTT
session to the robot. A host **Wi‑Fi helper** exists for the optional Soft-AP
factory path. The UI brands itself around the robot's display name (Alfred).

```
Browser → Nextcloud (nc_litter PHP + Vue) → bridge (Node+dorita980) → Litter-Robot :8883
                                              └→ wifi-helper (Soft-AP, optional)
```

## The improvements, version by version

### v0.1.0 — Initial app
The foundation: Vue 2.7 + Pinia SPA, PHP backend, the Node MQTT bridge, and
the core controls (Clean / Pause / Resume / Stop / Dock / Spot / Find), a live
status strip (battery, bin, Wi‑Fi, phase), mission history, a schedule week
editor, preferences/retention, an error decoder and maintenance hints, plus
Nextcloud Notifications + Activity. RBAC via a `litter-operators` group.

### v0.1.2 — "Alfred can actually connect"
Two real bugs that blocked any connection to a live robot:
- **Bridge was unreachable from Nextcloud** — the default bridge URL used
  hyphens (`nc-litter-bridge`) but Docker DNS resolves the service with
  underscores (`nc_litter_bridge`), so every PHP→bridge call failed with
  "Could not resolve host". Fixed the default and added a hyphen alias.
- **fw2 TLS** — the 900-series speaks a legacy TLS dialect that OpenSSL 3
  rejects by default; added `tlsLegacy.js` (legacy renegotiation + low
  seclevel ciphers) so the handshake succeeds.

### v0.2.0 — Butler UI + mission stage
The visual system (charcoal / brass / cream), a unique app icon, and the
**Mission stage** on the Dashboard: live phase animation with coverage /
duration / battery / cycle metrics. The **Location** view shows a pose map with
dock origin, fading trail, and heading cone when the robot publishes pose; when
it doesn't (e.g. a 960 with no pose), it falls back to the mission stage so
cleaning still feels live. Copy uses the robot's display name everywhere.

### v0.3.0 / v0.3.1 — Factory Soft-AP setup wizard
An attempt to onboard a factory-reset 960/980 **without the iRobot app**: the
host Wi‑Fi helper joins the robot's `Litter-Robot-<BLID>` Soft-AP and pushes home
Wi‑Fi credentials over the "kumy" MQTT `wlcfg` sequence. Included the admin
stepper (`SetupWizard.vue`), bridge Soft-AP proxy routes, encrypted
`home_wifi_password` storage, an `occ nc_litter:home-wifi` command, and a batch
of helper hardening (radio-up-before-scan, dual `Litter-Robot-`/`iRobot-` prefixes,
MQTT varint length fix, safer post-provision LAN discovery).

### v0.3.2 — Soft-AP join hardening (proven on real hardware)
Live testing against Alfred's Soft-AP exposed that the join *reported success
without associating*: `iw connect` returns before the Wi‑Fi carrier is up, so
the helper went on to assign an IP and ping a gateway it was never linked to.
Fixes:
- `waitAssociated()` polls `iw dev <iface> link` for the real "Connected to"
  carrier before proceeding; disconnect + retry on failure.
- Set the host **regulatory domain** (`iw reg set US`) — an unset `country 00`
  throttles channel-1 TX enough to silently break association; also disable
  power-save for the short Soft-AP session.
- **Request logging** in the helper for live debuggability.
Result: association went from 0% to solid (strong link, thousands of RX
packets).

### v0.4.0 — Onboarding findings baked in (this review)
What we learned finishing Alfred's onboarding, turned into guidance so the next
robot is easy. See the next section.

## The onboarding reality (important)

The Soft-AP factory path is **unreliable on the Litter-Robot 960**. Alfred associated
cleanly at Wi‑Fi L2 but its Soft-AP setup service served **no IP stack** — no
DHCP offer, no ARP or MQTT at `192.168.10.1` — even after a 3-button factory
reset *and* a battery pull. A 25-second packet capture showed the robot sending
**zero** IP-layer packets. This matches a documented 960 quirk ("won't set up…
for whatever reason"); the community recovery is a **full-minute battery pull**,
not a button reset. Our code and target IP are correct (verified against the
upstream reference) — the robot's setup service simply wasn't running.

**The path that worked — and is now the recommended one:**
1. Get the robot onto home Wi‑Fi (the iRobot app once, if needed — that's the
   *only* time the app is used, and it also updates firmware).
2. **Hold HOME until it beeps**, then Administration → NC Litter → Advanced →
   **Retrieve credentials (hold HOME)** (or `POST
   /onboard/get-password {"ip":"<robot-ip>"}` on the bridge). This pulls the
   BLID + local MQTT password straight from the robot — **no iRobot app**.
3. Save (creds stored encrypted) and Test connection.

Once connected, the robot refuses other MQTT clients (single-slot), which is
why you close the iRobot app.

## Alfred — current live state

- **Model:** Litter-Robot 960 (SKU `R960020`), name **Alfred**
- **LAN:** `10.0.0.242:8883` (DHCP-reserved)
- **BLID:** `3165811C32410750`
- **Connection:** live over local MQTT, `connected: true`, RSSI ≈ −37 dBm,
  `error: 0`, pose available.
- **Persistence:** credentials written to `.env` (gitignored) so the bridge
  **auto-connects on restart** — verified by fully recreating the container; it
  came up connected with no hold-HOME and no app. For belt-and-suspenders, also
  onboard once through the admin UI so the encrypted row lives in the Nextcloud
  DB.
- **Telemetry note:** right after a battery pull, `battery_pct: 0` /
  `not_ready: 15` means the robot isn't reporting charge — usually it's simply
  **off the dock**. Dock it and the values populate. Not a connection fault.

## Recommended follow-ups

- Onboard Alfred once via the admin UI (hold HOME) so the encrypted creds row
  is in the DB, not only in `.env`.
- Dock Alfred and confirm battery / not_ready clear.
- Future robots: follow the hold-HOME LAN path above; only reach for Soft-AP if
  a robot can't be app-provisioned at all.
