#!/usr/bin/env bash
#
# Live bridge gates for nc-litter (G2 in docs/plans/nc-litter-v0.1-plan.md).
#
# Exercises the running bridge against its real HTTP contract. Works in mock
# mode and against a real Litter-Robot 4; the only write it performs is a
# night-light toggle, which is reversible and does not move the globe.
#
#   tools/litter-live-gates.sh                    # default loopback bridge
#   BRIDGE_URL=http://127.0.0.1:18793 tools/...   # explicit
#
# This file was originally copied from nc-roomba and still asserted
# battery_pct / has_pose / pause|resume|dock / a /schedule endpoint -- none of
# which exist on a litter box. Every assertion below is checked against the
# contract in bridge/normalizer.py and bridge/litter_manager.py.
set -uo pipefail

BASE="${BRIDGE_URL:-http://127.0.0.1:18793}"
CONTAINER="${LITTER_BRIDGE_CONTAINER:-nc_litter_bridge}"
# Repo root, resolved from this script rather than the caller's cwd -- an earlier
# gate run failed purely because it was invoked from the wrong directory.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

fail=0
pass() { printf 'PASS %s\n' "$*"; }
bad()  { printf 'FAIL %s\n' "$*"; fail=1; }

# jq is not guaranteed on this host; python3 is (the bridge is Python).
jget() { python3 -c '
import json,sys
data = json.load(sys.stdin)
for key in sys.argv[1].split("."):
    if isinstance(data, list):
        data = data[int(key)]
    else:
        data = data.get(key) if isinstance(data, dict) else None
    if data is None:
        break
print("" if data is None else json.dumps(data) if isinstance(data,(dict,list)) else data)
' "$1" 2>/dev/null; }

echo "== nc-litter live bridge gates =="
echo "bridge: $BASE"

# ---------------------------------------------------------------------------
# G2a  health
# ---------------------------------------------------------------------------
health="$(curl -sS -m 8 "$BASE/health" 2>/dev/null || true)"
if [[ "$(printf '%s' "$health" | jget ok)" == "True" || "$(printf '%s' "$health" | jget ok)" == "true" ]]; then
	pass "G2a bridge health ok"
else
	bad "G2a bridge health: ${health:-<no response>}"
fi
mock="$(printf '%s' "$health" | jget mock)"
echo "     mock=$mock connected=$(printf '%s' "$health" | jget connected)"

# ---------------------------------------------------------------------------
# G2b  the bridge must not be reachable off-host
# ---------------------------------------------------------------------------
if docker port "$CONTAINER" 2>/dev/null | grep -q '0\.0\.0\.0'; then
	bad "G2b bridge published on 0.0.0.0 -- must be loopback + docker network only"
else
	pass "G2b bridge bind is loopback/docker-network only"
fi

# ---------------------------------------------------------------------------
# G2c  /state carries the full DTO contract, and none of the fields an LR4
#      does not have (rssi/wifi_ssid were permanent nulls inherited from the
#      vacuum app and invited a signal-bars widget that could never light up)
# ---------------------------------------------------------------------------
curl -sS -m 10 "$BASE/state" -o "$TMP/state.json" 2>/dev/null || true
if ! python3 - "$TMP/state.json" <<'PY'
import json, sys

REQUIRED = {
    "device_id", "name", "connected", "mock", "updated_at",
    "last_poll_ok_at", "poll_error", "last_seen",
    "status", "status_label", "status_code",
    "drawer_level_pct", "litter_level_pct", "litter_level_state",
    "cat_weight", "cycle_count", "cycles_total", "cycles_since_full",
    "cycle_capacity", "scoops_saved",
    "sleeping", "sleep_schedule",
    "night_light", "night_light_mode", "night_light_brightness",
    "panel_lock", "panel_brightness",
    "power_on", "power_type", "wait_time",
    "hopper_status", "hopper_removed", "wifi_mode",
    "error", "error_label", "capabilities", "bridge",
}
FORBIDDEN = {"rssi", "wifi_ssid", "battery_pct", "phase", "has_pose", "pose",
             "mission", "coverage", "bin_full"}

state = json.load(open(sys.argv[1]))["state"]
missing = sorted(REQUIRED - set(state))
present = sorted(FORBIDDEN & set(state))
if missing:
    print(f"     missing DTO keys: {missing}")
if present:
    print(f"     vacuum-era keys still present: {present}")
sys.exit(1 if (missing or present) else 0)
PY
then
	bad "G2c /state DTO contract"
else
	pass "G2c /state DTO contract (all keys present, no vacuum-era keys)"
fi

status="$(jget status <"$TMP/state.json" || true)"
[[ -z "$status" ]] && status="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["state"]["status"])' "$TMP/state.json" 2>/dev/null || true)"
read -r st code drawer litter cyc <<<"$(python3 -c '
import json,sys
s=json.load(open(sys.argv[1]))["state"]
print(s["status"], s["status_code"], s["drawer_level_pct"], s["litter_level_pct"], s["cycle_count"])
' "$TMP/state.json" 2>/dev/null || echo "? ? ? ? ?")"
echo "     status=$st code=$code drawer=${drawer}% litter=${litter}% cycles=$cyc"

case "$st" in
	ready|cleaning|emptying|drawer_full|sleeping|paused|fault|offline)
		pass "G2d status is in the normalized vocabulary ($st)" ;;
	*) bad "G2d unexpected status: $st" ;;
esac

# status_code must be a raw LR4 code, not the normalized status echoed back --
# PHP's error decoder resolves the specific fault entry from it.
if [[ -n "$code" && "$code" != "$st" && "$code" != "None" ]]; then
	pass "G2e status_code present and distinct from status ($code)"
else
	bad "G2e status_code missing/degenerate (got '$code') -- faults would all decode generically"
fi

# ---------------------------------------------------------------------------
# G2f  levels read the right way round: drawer = fullness, litter = remaining
# ---------------------------------------------------------------------------
if python3 - "$TMP/state.json" <<'PY'
import json, sys
s = json.load(open(sys.argv[1]))["state"]
ok = True
for key in ("drawer_level_pct", "litter_level_pct"):
    v = s[key]
    if v is not None and not (isinstance(v, int) and 0 <= v <= 100):
        print(f"     {key} is not an int 0..100: {v!r}")
        ok = False
if s["status"] == "drawer_full" and (s["drawer_level_pct"] or 0) < 50:
    print("     status=drawer_full but drawer_level_pct is low -- inverted?")
    ok = False
sys.exit(0 if ok else 1)
PY
then
	pass "G2f level fields are sane percentages"
else
	bad "G2f level fields"
fi

# ---------------------------------------------------------------------------
# G2g  freshness signals. updated_at is stamped on every read and can never
#      detect staleness; last_poll_ok_at is the honest signal.
# ---------------------------------------------------------------------------
# Three fast reads, not two reads two seconds apart. The old form demanded that
# last_poll_ok_at be *identical* across a 2 s gap -- something a genuine upstream
# poll is entitled to break. With LITTER_REFRESH_S=30 that is roughly a 7% spurious
# failure rate, and a gate that cries wolf is a gate nobody believes. Across three
# reads a few hundred milliseconds apart at most one poll can intervene, so the
# invariant still holds: updated_at moves every time, last_poll_ok_at does not.
for i in 1 2 3; do
	curl -sS -m 10 "$BASE/state" -o "$TMP/fresh$i.json" 2>/dev/null || true
	[[ $i -lt 3 ]] && sleep 0.3
done
if python3 - "$TMP/fresh1.json" "$TMP/fresh2.json" "$TMP/fresh3.json" <<'PY'
import json, sys

reads = []
for path in sys.argv[1:]:
    s = json.load(open(path))["state"]
    reads.append((s["updated_at"], s["last_poll_ok_at"]))

stamps = [r[0] for r in reads]
polls = [r[1] for r in reads]

# updated_at is stamped on every read, so it must differ every time. That is the
# claim that matters: it can never be used to detect staleness.
if len(set(stamps)) != len(stamps):
    print(f"     updated_at repeated across reads: {stamps}")
    sys.exit(1)

# last_poll_ok_at tracks successful upstream polls, so it must not move merely
# because we read. One real poll may land mid-run; two cannot in ~600 ms, so at
# least one consecutive pair has to match.
if not any(polls[i] == polls[i + 1] for i in range(len(polls) - 1)):
    print(f"     last_poll_ok_at moved on every read: {polls}")
    sys.exit(1)

# And a poll can never be stamped later than the read that reported it.
if any(p > u for u, p in reads):
    print(f"     last_poll_ok_at is ahead of updated_at: {reads}")
    sys.exit(1)
sys.exit(0)
PY
then
	pass "G2g updated_at advances per read while last_poll_ok_at tracks polls (real staleness signal)"
else
	bad "G2g freshness signals did not behave as expected"
fi

# ---------------------------------------------------------------------------
# G2h  capabilities tell the truth about what the device can do
# ---------------------------------------------------------------------------
if python3 - "$TMP/state.json" <<'PY'
import json, sys
caps = json.load(open(sys.argv[1]))["state"]["capabilities"]
ok = True
# pylitterbot raises NotImplementedError for LR4 sleep: never advertise it.
if caps.get("sleep") is not False:
    print(f"     capabilities.sleep should be False, got {caps.get('sleep')!r}")
    ok = False
if caps.get("reset") is not True:
    print("     capabilities.reset missing")
    ok = False
if caps.get("wait_time_values") != [3, 7, 15, 25, 30]:
    print(f"     wait_time_values wrong: {caps.get('wait_time_values')!r}")
    ok = False
sys.exit(0 if ok else 1)
PY
then
	pass "G2h capabilities honest (sleep=false, reset present, wait_time enum exact)"
else
	bad "G2h capabilities"
fi

# ---------------------------------------------------------------------------
# G2i  action allow-list + input validation
# ---------------------------------------------------------------------------
check_code() { # name expected-http body...
	local name="$1" want="$2"; shift 2
	local got
	got=$(curl -sS -m 15 -o "$TMP/act.json" -w '%{http_code}' -X POST \
		-H 'Content-Type: application/json' "$@" "$BASE/action/$name" 2>/dev/null || echo 000)
	if [[ "$got" == "$want" ]]; then
		return 0
	fi
	echo "     $name expected HTTP $want, got $got: $(cat "$TMP/act.json" 2>/dev/null)"
	return 1
}

# Sleep is not a supported action any more -- it could only ever 502.
if check_code sleep_on 400 && check_code sleep_off 400; then
	pass "G2i sleep_on/sleep_off correctly rejected (no LR4 write path exists)"
else
	bad "G2i sleep actions should be rejected as unsupported"
fi

# Vacuum-era verbs must not be reachable.
if check_code dock 400 && check_code pause 400; then
	pass "G2j vacuum-era actions (dock/pause) rejected"
else
	bad "G2j vacuum-era actions are still reachable"
fi

# wait_time is an enum, not a range: 5 and 9 are invalid, 60 is invalid.
if check_code set_wait_time 400 -d '{"wait_time":5}' \
	&& check_code set_wait_time 400 -d '{"wait_time":9}' \
	&& check_code set_wait_time 400 -d '{}'; then
	pass "G2k set_wait_time validates against the device enum and requires a value"
else
	bad "G2k set_wait_time validation"
fi

# ---------------------------------------------------------------------------
# G2l  a real write round-trips. Night light is the safe one: reversible and
#      it does not move the globe. NOTE: the Whisker cloud takes tens of
#      seconds to report a write back, so this asserts acceptance, not
#      immediate reflection (see CONVERGE_POLL_DELAYS_S).
# ---------------------------------------------------------------------------
before="$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["state"]["night_light"])' "$TMP/state.json" 2>/dev/null || echo unknown)"
if [[ "$before" == "True" ]]; then first_cmd=night_light_off; restore=night_light_on
else first_cmd=night_light_on; restore=night_light_off; fi
if check_code "$first_cmd" 200; then
	pass "G2l write path accepted ($first_cmd)"
else
	bad "G2l write path ($first_cmd)"
fi
check_code "$restore" 200 >/dev/null || true   # restore prior state, best effort
echo "     night_light restored toward '$before' (cloud reflects writes with a delay)"

# ---------------------------------------------------------------------------
# G2m  /settings reports per-key truth instead of a blanket ok
# ---------------------------------------------------------------------------
got=$(curl -sS -m 15 -o "$TMP/set.json" -w '%{http_code}' -X POST \
	-H 'Content-Type: application/json' -d '{"wait_time":"abc"}' "$BASE/settings" 2>/dev/null || echo 000)
if [[ "$got" != "200" ]] && grep -q 'wait_time_not_a_number' "$TMP/set.json" 2>/dev/null; then
	pass "G2m /settings reports a failed key instead of claiming success (HTTP $got)"
else
	bad "G2m /settings still reports success for a failed write (HTTP $got: $(cat "$TMP/set.json" 2>/dev/null))"
fi

if curl -sS -m 10 "$BASE/settings" 2>/dev/null | grep -q '"sleep_writable": *false'; then
	pass "G2n /settings marks sleep read-only"
else
	bad "G2n /settings should mark sleep read-only"
fi

# ---------------------------------------------------------------------------
# G2o  SSE stream emits a state frame
# ---------------------------------------------------------------------------
curl -sS -N -m 6 -H 'Accept: text/event-stream' "$BASE/stream" -o "$TMP/sse.txt" 2>/dev/null || true
if grep -q '^event: state' "$TMP/sse.txt" 2>/dev/null && grep -q '^data: {' "$TMP/sse.txt" 2>/dev/null; then
	pass "G2o /stream emits an 'event: state' frame with a JSON payload"
else
	bad "G2o /stream frame: $(head -c 200 "$TMP/sse.txt" 2>/dev/null)"
fi

# ---------------------------------------------------------------------------
# G2p  container + the bridge's own unit tests against real pylitterbot
# ---------------------------------------------------------------------------
if docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
	pass "G2p bridge container running"

	# Run the repo's tests against the image's real pylitterbot.
	#
	# This used to `docker exec … pytest /app/test`, which only ever worked when
	# somebody had hand-copied the tests into the running container -- the
	# Dockerfile ships app code only, on purpose. So the gate passed or failed
	# depending on an undocumented manual step, and reported "no tests ran" as a
	# failure of the app rather than of itself. Copy them in for the run and take
	# them back out, so the gate is self-sufficient either way.
	if docker cp "$ROOT/bridge/test/." "$CONTAINER:/app/test/" >/dev/null 2>&1; then
		if docker exec "$CONTAINER" python3 -m pytest /app/test -q >"$TMP/pytest.txt" 2>&1; then
			pass "G2q in-image pytest green ($(tail -1 "$TMP/pytest.txt"))"
		else
			bad "G2q in-image pytest: $(tail -3 "$TMP/pytest.txt")"
		fi
		# Leave the runtime container as we found it: app code only.
		docker exec "$CONTAINER" python3 -c \
			"import shutil,pathlib; p=pathlib.Path('/app/test'); shutil.rmtree(p, ignore_errors=True)" \
			>/dev/null 2>&1 || true
	else
		bad "G2q could not stage the bridge tests into '$CONTAINER'"
	fi
else
	bad "G2p bridge container '$CONTAINER' not running"
fi

echo
if [[ $fail -eq 0 ]]; then
	echo "ALL LIVE BRIDGE GATES PASS"
else
	echo "LIVE BRIDGE GATES FAILED"
fi
exit $fail
