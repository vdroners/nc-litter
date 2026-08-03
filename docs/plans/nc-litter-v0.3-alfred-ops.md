# NC Litter v0.3.0 — Alfred ops honesty

## Why

Bring litter to the post-0.10/0.11 roomba ops bar without inventing LR4
capabilities the device cannot perform. OpenClaw Talk false-fault spam and
clone-era Soft-AP/MQTT docs made the app dishonest.

## What changes

1. **Docs** — `docs/OPERATOR.md` / `docs/ARCHITECTURE.md` rewritten for Whisker
   cloud + Python bridge (no Soft-AP / dorita980 fiction).
2. **Marketing honesty** — `appinfo/info.xml` description; Notifier default name
   is `Litter-Robot` (not Alfred).
3. **Bridge-unreachable banner** — `isBridgeUnreachable` + AppShell banner + tests.
4. **Settings confirmed UX** — success = “confirmed on unit”; empty rejection =
   “waiting for Whisker echo (~30s)”.
5. **History** — `getCycles` default limit **500**; UI shows “Showing N of total”.
6. **Admin Alfred** — `alfred_alert_log` path field + monitor-timer preflight note.
7. **Hardening** — `ConfinedFileReader` for alert log reads (parity with roomba).

## Verify

```bash
cd /media/4TB/nc-litter
make bump-minor   # 0.2.0 → 0.3.0
make ship         # or build + deploy + gate-preflight
npm test
```

Talk: `@alfred litter help`; confirm no false fault titles during a normal cycle.
Browser: Admin Alfred alert path; History truncation line when total > page;
Dashboard bridge banner when bridge network is detached.
