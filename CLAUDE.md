# NC-Litter Post-Change Workflow

Standalone Nextcloud app (Vue 2 + Pinia + PHP) plus Node MQTT bridge sidecar.
After every change, run this workflow before reporting completion.

## 0. Plan first (large changes)

Multi-file features / behavior changes: write a plan to `docs/plans/<slug>.md`
and commit it with (or just before) the implementation.

## 1. Version bump (if warranted)

```bash
make bump-patch   # bug fixes
make bump-minor   # new features
```

Syncs `appinfo/info.xml`, `package.json`, `package-lock.json`, README badge,
CHANGELOG stub, and `bridge/package.json`.

## 2. Build

```bash
make build
```

## 3. Ship

```bash
make ship            # build + bridge-up + deploy + gate-preflight
make ship RESTART=1  # when a NEW route or PHP class was added
```

## 4. Verify

```bash
make gate-preflight
make gate-live LITTER_MOCK=1
make gate-gui
```

## 5. Commit

Stage only files for this task. Use sanitized env commit recipe so the last
line is:

```
Co-developed-by: Claude (model: claude-opus-4.7, context: 200k)
```

Ask before pushing to the public GitHub remote.
