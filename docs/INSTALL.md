# Install NC Litter

NC Litter is a Nextcloud app plus a companion **`nc-litter-bridge`** Docker
container. The bridge owns one Whisker cloud session (via `pylitterbot`); the
Nextcloud app never talks to Whisker directly. Both must be able to reach each
other on a shared Docker network.

This guide is for a stranger install — no lab-specific paths.

## Prerequisites

- Nextcloud **28–34** (Docker or bare metal with Docker available for the bridge)
- Docker + `docker compose`
- A **Whisker** account with at least one Litter-Robot 4
- Outbound HTTPS from the bridge host to Whisker's cloud

## 1. Run the bridge

Pull the published image (preferred) or build from this repository.

### Option A — GHCR image

```bash
docker network create nc-litter-net 2>/dev/null || true

docker run -d --name nc_litter_bridge --restart unless-stopped \
  --network nc-litter-net \
  --network-alias nc_litter_bridge \
  --network-alias nc-litter-bridge \
  -e PORT=8080 \
  -e LITTER_MOCK=0 \
  -e WHISKER_EMAIL='you@example.com' \
  -e WHISKER_PASSWORD='your-whisker-password' \
  -e LITTER_DEVICE_ID='' \
  -p 127.0.0.1:18793:8080 \
  ghcr.io/vdroners/nc-litter-bridge:latest
```

Leave `LITTER_DEVICE_ID` blank to bind the first LR4 on the account. Set
`LITTER_MOCK=1` only for UI/gates without a real robot.

### Option B — compose from this repo

```bash
cp .env.example .env   # set WHISKER_* and LITTER_MOCK=0
docker compose -f docker-compose.bridge.yml up -d --build
```

Verify:

```bash
curl -sS http://127.0.0.1:18793/health
# → {"ok":true,...}
```

## 2. Attach Nextcloud containers to the bridge network

The PHP app (`cloud_app` or your Nextcloud container name) **and** the cron
worker (`cloud_cron` when you use the AIO / separate-cron layout) must resolve
`nc_litter_bridge`:

```bash
docker network connect nc-litter-net cloud_app
docker network connect nc-litter-net cloud_cron   # if you have a cron container
```

Health-check from both:

```bash
docker exec cloud_app curl -sS -m 5 http://nc_litter_bridge:8080/health
docker exec cloud_cron curl -sS -m 5 http://nc_litter_bridge:8080/health
```

Background jobs (telemetry / cycle history) run in the cron container. If only
`cloud_app` is attached, the UI may work while history stays empty.

Network attachments do **not** survive container recreate — reconnect after
upgrades, or declare the network in your Nextcloud compose file permanently.

## 3. Install the Nextcloud app

From the App Store (once published), or manually:

1. Place the app under `custom_apps/nc_litter` (or enable from the store).
2. `occ app:enable nc_litter`
3. `occ upgrade`

Default bridge URL (from inside Nextcloud): `http://nc_litter_bridge:8080`.
Override under **Administration → NC Litter** if your DNS name differs.

## 4. Onboard the Whisker account

In **Administration → NC Litter**:

1. Confirm bridge URL.
2. Enter Whisker email + password → list devices → select your LR4.
3. Credentials are stored encrypted (`enc:v1:`) in the Nextcloud database.
4. Add operators to the `litter-operators` group (or your configured group).

## 5. Verify

- Open **NC Litter** in the navigation — Dashboard should show Ready / live levels.
- Trigger a Reset or clean cycle from an operator account.
- Confirm Notifications fire on drawer-full / fault when those conditions appear.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| Bridge unreachable | Nextcloud container not on `nc-litter-net` |
| History never grows | `cloud_cron` not on `nc-litter-net` |
| Login fails | Wrong Whisker password, or bridge has no outbound HTTPS |
| Credentials undecryptable | Instance secret rotated — re-enter password in Admin |
| Mock state forever | `LITTER_MOCK=1` still set on the bridge |

See [OPERATOR.md](OPERATOR.md) for day-to-day use and [ARCHITECTURE.md](ARCHITECTURE.md)
for the PHP ↔ bridge contract.
