# NC-Litter Agent Guide

| Path | Use |
|------|-----|
| `/media/4TB/nc-litter` | This app (standalone) |
| `/media/4TB/nc-print` | Skeleton reference |
| `/media/4TB/nc-gcs` | Theme / ACL / crypto patterns (vendor, do not hard-depend) |

## Key services

| Service | Container / path |
|---------|------------------|
| Nextcloud | `cloud_app` → `custom_apps/nc_litter` |
| Bridge | `nc_litter_bridge` on Docker network `nc-litter-net` |

## Common commands

```bash
cd /media/4TB/nc-litter
make build
make bridge-up
make deploy RESTART=1
make gate-preflight
curl -s http://127.0.0.1:18791/health   # only if bridge published for debug — production binds Docker DNS only
```

Do not stop `openclaw-gateway`. Do not expose the bridge publicly.
