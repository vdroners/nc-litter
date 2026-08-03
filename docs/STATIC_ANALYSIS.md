# Static analysis / l10n (first App Store cut)

- **Psalm / PHPStan / php-cs-fixer:** deferred for the first store submission; local gates use phpunit/vitest/`make gate-*` instead. A follow-up pass will add a psalm baseline that fails on new issues only.
- **Frontend l10n:** `l10n/en.json` is scaffolded. Full Vue `t()` wrapping is a follow-up (English-only listing is acceptable for the initial App Store release).
