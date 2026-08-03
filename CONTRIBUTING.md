# Contributing to NC Litter

## Workflow

1. Make a focused change (UI, bridge, or PHP — avoid mixing unrelated scopes).
2. Run the gates that match what you touched.
3. Commit with an imperative subject + short body.
4. Primarily AI-authored commits end with this trailer as the **last** line:

```
Co-developed-by: Claude (model: claude-opus-4.7, context: 200k)
```

Do not include a Cursor `Co-authored-by` trailer. Use a sanitized env for
`git commit` if your agent injects one (see repo Makefile / operator notes).

## Gates

```bash
make gate-preflight   # file layout + PHP API smoke in cloud_app
make gate-gui         # Vue surface / catalog / butler tokens
make gate-live LITTER_MOCK=1   # bridge HTTP with mock robot
make bridge-test      # Node unit tests
npm test              # vitest (format helpers, store, MissionStage, …)
make build            # sass + webpack production
```

For a full ship on the lab host:

```bash
LITTER_MOCK=0 make ship RESTART=1
```

`RESTART=1` when you add PHP routes/classes (opcache).

## Version bumps

```bash
make bump-patch   # fixes
make bump-minor   # features / visible UI
```

Keep `appinfo/info.xml`, `package.json`, `package-lock.json`, README badge,
and `CHANGELOG.md` in sync (the bump target updates the first set; fill the
changelog stub by hand).

## UI conventions

- Product name: **NC Litter**. Operator-facing copy uses the **live robot
  display name**, not a hardcoded “Alfred”.
- Brand tokens: charcoal / brass / cream in `css/style.scss`
  (`--nc-app-accent: #c4a574`).
- Respect `prefers-reduced-motion` for mission-stage animations.
- Never invent pose when `has_pose` is false — use the mission stage fallback.
