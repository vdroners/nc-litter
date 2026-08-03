#!/usr/bin/env bash
# Scan full git history for likely secrets / internal hostnames before making repos public.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

PATTERNS=(
  'APP_PRIVATE_KEY'
  'APPSTORE_TOKEN'
  'wg_easy_password'
  'BEGIN (RSA |OPENSSH |EC )?PRIVATE KEY'
  'vpn-vdroners'
  'cloud-vdroners'
  '10\.0\.0\.(84|210)'
  'password\s*=\s*["\047][^"\047]{8,}'
  '\.env'
)

echo "Scanning $(basename "$ROOT") git history…"
for pat in "${PATTERNS[@]}"; do
  if git log --all -p -i --extended-regexp -e "$pat" 2>/dev/null | head -1 | grep -q .; then
    echo "MATCH: $pat"
    git log --all -p -i --extended-regexp -e "$pat" 2>/dev/null | head -40
    echo "---"
  fi
done
echo "Scan complete (review MATCH lines manually)."
