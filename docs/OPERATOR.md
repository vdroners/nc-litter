# NC Litter — Operator guide

Control a **Whisker Litter-Robot 4** from Nextcloud via the Whisker cloud.
The Python bridge (`nc_litter_bridge`) never binds a public port; browsers talk
only to Nextcloud. The UI uses the unit’s **Whisker display name** everywhere
(worked example: **Poop Roller**).

This is **not** a local MQTT / Soft-AP product. There is no wifi-helper and no
`:8883` LAN session — credentials are a Whisker account, encrypted in Nextcloud.

## Prerequisites

- Whisker account that already owns the LR4 (set up once in the Whisker app)
- Nextcloud admins configure the app; operators must be in the `litter-operators` group
- Bridge container on Docker network `nc-litter-net`, reachable from `cloud_app`
  **and** `cloud_cron` (when used) as `http://nc_litter_bridge:8080`
  (underscores; `nc-litter-bridge` alias also resolves). See [INSTALL.md](INSTALL.md).
- For a **real** unit: bridge must run with `LITTER_MOCK=0` (see `.env`)

See also [README — What the LR4 genuinely cannot do](../README.md#what-the-lr4-genuinely-cannot-do)
(no remote empty-drawer, no sleep write, no power-off that sticks, etc.).

## 1. Whisker account onboarding

1. Open **Administration → NC Litter**.
2. Enter Whisker **email + password**, then **Sign in / list robots**.
3. Pick the LR4, set a display name if you want, and **Save** (password stored
   encrypted as `enc:v1:`).
4. **Test connection** — bridge should show Ready / live status within ~30 s.

CLI / bridge health (host):

```bash
docker exec cloud_app curl -sS http://nc_litter_bridge:8080/health
```

**Persistence:** encrypted creds live in Nextcloud. For headless reconnect after
bridge recreate, also set account fields in `.env` (gitignored) if your deploy
uses them — otherwise re-save from Admin is enough.

## 2. Operator day-to-day

| Task | Where |
|---|---|
| Start a clean cycle | Dashboard → Clean |
| Clear a fault | Dashboard → Reset |
| Night light / panel lock / wait time | Settings |
| Cycle history | History |
| Talk commands | `@alfred litter status \| clean \| reset \| light-on \| light-off \| lock \| unlock \| help` |

Sleep window is **read-only** in NC Litter — change it in the Whisker app.

## 3. Alfred (OpenClaw) Talk alerts

Optional. Enable under **Administration → NC Litter → Alfred assistant**:

- Talk room token
- Alert log path — must sit under an allowed root:
  `{datadirectory}/nc_litter/`,
  `{datadirectory}/appdata_{instanceid}/nc_litter/`,
  or `{temp}/nc_litter/` (see DeviceService confinement)

The host monitor is typically a systemd user timer (e.g.
`alfred-cron-litter-monitor.timer`). Without it the Dashboard Alfred card stays
empty.

## 4. Networking note

`make bridge-up` attaches `cloud_app` **and** `cloud_cron` to `nc-litter-net`,
then health-checks both. Re-run after recreating either container unless the
cloud compose declares the network permanently.

## 5. Troubleshooting

| Symptom | Check |
|---|---|
| Blank gauges / “Can’t reach the bridge” | `make bridge-up`; `curl` bridge `/health` from `cloud_app` |
| History never grows | `cloud_cron` on `nc-litter-net` (`make bridge-net-check`) |
| Commands fail | Whisker creds, unit online in Whisker app, bridge logs |
| False Talk “fault” spam (pre-0.3 monitors) | Upgrade monitor — only real `decoded_error` / non-zero codes alert |
| Sleep save fails | Expected — sleep is not writable on LR4 via pylitterbot |
