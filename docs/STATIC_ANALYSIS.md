# Static analysis / l10n (first App Store cut)

- **Psalm:** root `psalm.xml` targets `lib/` at `errorLevel="8"` (informational). `vimeo/psalm` is listed in `composer.json` `require-dev` but not required for CI yet — run locally after `composer install`. A follow-up pass can tighten to errorLevel 3–5 with an OCP stub + baseline that fails on new issues only.
- **Frontend l10n:** `l10n/en.json` is scaffolded and `@nextcloud/l10n` is a package dependency. Full Vue `t()` wrapping of UI strings is a follow-up (English-only listing is acceptable for the initial App Store release).
