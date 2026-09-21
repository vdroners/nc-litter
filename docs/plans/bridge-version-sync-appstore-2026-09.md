# Bridge version sync + App Store prep (2026-09)

## Why

`/health` reported bridge `0.1.0` while the Nextcloud app was `0.4.1`. Preflight
G0e accepted a missing literal as "env-driven" and never checked the stale
`.get(..., "0.1.0")` default or compose. App Store signing is still blocked on
an unreturned public cert (CSR + key exist locally).

## What changes

1. Pin `BRIDGE_VERSION` default in `bridge/app.py`, compose, and Dockerfile to
   the app version; `_bump` keeps all three in sync with `info.xml`.
2. Tighten G0e to require the Python default **and** compose default match
   `info.xml`.
3. Push to `main` so `.github/workflows/docker-bridge.yml` publishes GHCR
   `nc-litter-bridge:latest` with the new build-arg.
4. Build an unsigned App Store tarball; document cert/CSR blocker in
   `APPSTORE_HANDOFF.md`.

## Verify

```bash
make bump-patch   # or land as 0.4.2
make gate-preflight
LITTER_MOCK=0 make gate-live
curl -s http://127.0.0.1:18793/health   # version == info.xml
make appstore
```
