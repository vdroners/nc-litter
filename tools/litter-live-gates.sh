#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MOCK="${LITTER_MOCK:-1}"
BASE="${BRIDGE_URL:-http://127.0.0.1:18791}"
fail=0

echo "Using bridge $BASE LITTER_MOCK=$MOCK"

health=$(curl -sS -m 5 "$BASE/health" || true)
if ! echo "$health" | grep -q '"ok":true'; then
  echo "FAIL G06 bridge health: $health"
  fail=1
else
  echo "PASS G06 bridge health"
fi

# G07: compose should bind loopback only
if docker port nc_litter_bridge 2>/dev/null | grep -q '0.0.0.0'; then
  echo "FAIL G07 public bind detected"
  fail=1
else
  echo "PASS G07 loopback/docker bind"
fi

state=$(curl -sS -m 5 "$BASE/state" || true)
if ! echo "$state" | grep -q 'battery_pct\|phase'; then
  echo "FAIL G17 state: $state"
  fail=1
else
  echo "PASS G17 state payload"
fi

for action in clean pause resume dock; do
  code=$(curl -sS -m 5 -o /tmp/rr.json -w '%{http_code}' -X POST "$BASE/action/$action" || echo 000)
  if [[ "$code" != "200" ]]; then
    echo "FAIL G16 action $action http $code $(cat /tmp/rr.json 2>/dev/null || true)"
    fail=1
  fi
done
[[ $fail -eq 0 ]] && echo "PASS G16 actions round-trip (mock/live)"

sched=$(curl -sS -m 5 "$BASE/schedule" || true)
if ! echo "$sched" | grep -q 'cycle'; then
  echo "FAIL G20 schedule get"
  fail=1
else
  echo "PASS G20 schedule get"
fi

week='{"cycle":["none","start","none","none","none","none","none"],"h":[9,15,9,9,9,9,9],"m":[0,0,0,0,0,0,0]}'
curl -sS -m 5 -X POST -H 'Content-Type: application/json' -d "$week" "$BASE/schedule" >/tmp/ws.json || true
if ! grep -q 'start' /tmp/ws.json; then
  echo "FAIL G20 schedule set"
  fail=1
else
  echo "PASS G20 schedule set"
fi

if echo "$state" | grep -q '"has_pose"'; then
  echo "PASS G18 pose capability field present"
else
  echo "FAIL G18 has_pose missing"
  fail=1
fi

if docker ps --format '{{.Names}}' | grep -q '^nc_litter_bridge$'; then
  echo "PASS G28 bridge container running"
else
  echo "FAIL G28 bridge container missing"
  fail=1
fi

if systemctl --user is-active openclaw-gateway >/dev/null 2>&1 || pgrep -af openclaw-gateway >/dev/null 2>&1; then
  echo "PASS G28 openclaw-gateway untouched/active-or-present"
fi

exit $fail
