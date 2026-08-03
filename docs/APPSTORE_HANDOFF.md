# Nextcloud App Store — operator handoff

Status after the five-app readiness pass (2026-08-03). Agent work is pushed to GitHub. **Your remaining steps are below.**

## Current versions (on `origin/main`)

| App ID | Version | Repo (public) | Notes |
|--------|---------|---------------|-------|
| `nc_print` | **1.60.13** | https://github.com/vdroners/nc-print | CSRF hardened; slicer GHCR workflow stub; PHP max dropped for NC34 |
| `nc_wireguard` | **2.3.2** | https://github.com/vdroners/nc-wireguard | External wg-easy; CSRF on settings save |
| `nc_roomba` | **0.12.1** | https://github.com/vdroners/nc-roomba | Uninstall cleanup; bridge GHCR workflow |
| `nc_litter` | **0.3.1** | https://github.com/vdroners/nc-litter | Whisker privacy note; bridge GHCR workflow |
| `nc_tower` | **1.12.1** | https://github.com/vdroners/nc-tower | OCP rewrite; configurable endpoints; NC Tower branding |

All five `appinfo/info.xml` files validate against https://apps.nextcloud.com/schema/apps/info.xsd.

## Agent-prepared artifacts (on this server)

| Item | Location |
|------|----------|
| Private keys | `~/.nextcloud/certificates/<app_id>.key` (mode 600, **never commit**) |
| CSRs + README drafts | `~/.nextcloud/app-certificate-requests/<app_id>/` |
| Registration signatures | `~/.nextcloud/app-registration-signatures.txt` |

## Clean-room verification (done)

On `nextcloud:34-apache` (PHP 8.5):

- Enabled: `nc_print` 1.60.13, `nc_wireguard` 2.3.2, `nc_roomba` 0.12.1, `nc_litter` 0.3.1, `nc_tower` 1.12.1
- `occ app:remove` exercised for roomba/litter (uninstall listeners ran)
- Blocker fixed mid-pass: PHP `max-version="8.4"` prevented enable → dropped (min-only)

Tarball dry-runs under `/tmp/nc_<id>-<ver>.tar.gz` exclude `node_modules`, `.git`, `src`, bridges/engines as designed.

## Your steps (cannot be automated)

### 1. Public GitHub email
https://github.com/settings/emails — show email on profile (required for cert review).

### 2. Submit CSRs
PR against https://github.com/nextcloud/app-certificate-requests — one directory per app id with `.csr` + `README` from `~/.nextcloud/app-certificate-requests/`. Save returned `.crt` next to the `.key`.

### 3. Register apps
https://apps.nextcloud.com/developer — register each app id with public cert + signature from `~/.nextcloud/app-registration-signatures.txt`. Create `APPSTORE_TOKEN`.

### 4. GitHub `release` environment secrets (each repo)
- `APP_PRIVATE_KEY`
- `APP_PUBLIC_CRT`
- `APPSTORE_TOKEN`

### 5. First signed release
Tag **without** `v` prefix matching `info.xml` (e.g. `1.60.13`), or:

```bash
cd /media/4TB/<repo>
make appstore
export NC_OCC=... APP_PRIVATE_KEY=~/.nextcloud/certificates/<id>.key APP_PUBLIC_CRT=~/.nextcloud/certificates/<id>.crt
make appstore-sign
```

### 6. Sidecar images
| App | Action |
|-----|--------|
| Roomba / Litter | Run/publish `docker-bridge.yml` → `ghcr.io/vdroners/nc-*-bridge` |
| Print | Stage engine + publish `nc-print-slicer` (amd64) + AGPL source offer |
| WireGuard | Point installers at `ghcr.io/wg-easy/wg-easy` |
| Tower | Optional advanced sidecar; app works without it |

### 7. Post-upload
- Confirm screenshots resolve over public HTTPS
- `occ integrity:check-app <id>` on a test instance

## Deferred (follow-up)

- nc-print full Vue l10n (~700 strings)
- nc-print-slicer arm64
- nc-tower sidecar GHCR publication
- wg-sync production cutover
- Full Psalm CI beyond baseline stubs

## Checklist

- [x] Repos public (incl. nc-print)
- [x] Screenshots + XSD-valid info.xml
- [x] `make appstore` + release workflows
- [x] CSRF / uninstall / OCP (tower) / PHP 8.5 enable
- [x] Keys/CSRs generated locally
- [ ] CSR PRs submitted (operator)
- [ ] Apps registered + first signed upload (operator)
- [ ] GHCR bridge/slicer images published (operator / CI)
- [ ] GitHub `release` env secrets set (operator)
