#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0
need=(
  appinfo/info.xml
  appinfo/routes.php
  lib/AppInfo/Application.php
  src/main.js
  src/App.vue
  bridge/index.js
  docs/plans/nc-litter-v0.1.0.md
  knowledge/error_codes.json
)
for f in "${need[@]}"; do
  if [[ ! -e "$ROOT/$f" ]]; then
    echo "FAIL G00 missing $f"
    fail=1
  fi
done
v_xml=$(grep -oE '<version>[0-9]+\.[0-9]+\.[0-9]+</version>' "$ROOT/appinfo/info.xml" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+')
v_pkg=$(node -p "require('$ROOT/package.json').version")
v_br=$(node -p "require('$ROOT/bridge/package.json').version")
if [[ "$v_xml" != "$v_pkg" || "$v_xml" != "$v_br" ]]; then
  echo "FAIL G01 version sync xml=$v_xml pkg=$v_pkg bridge=$v_br"
  fail=1
else
  echo "PASS G01 version $v_xml"
fi
[[ $fail -eq 0 ]] && echo "PASS G00 repo layout"
exit $fail
