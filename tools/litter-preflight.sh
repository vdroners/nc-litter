#!/usr/bin/env bash
#
# Repo-layout + version-sync preflight for nc-litter (G0).
#
# Cheap, offline, no containers -- run it before anything else.
#
# Inherited from nc-roomba, where the bridge was Node. It listed
# `bridge/index.js` and read a version out of `bridge/package.json`; the litter
# bridge is Python (FastAPI + pylitterbot), so both were guaranteed failures and
# the version gate could never run. Rewritten against the real tree.
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0

pass() { printf 'PASS %s\n' "$*"; }
bad()  { printf 'FAIL %s\n' "$*"; fail=1; }

# ---------------------------------------------------------------------------
# G0a  the files the app cannot start without
# ---------------------------------------------------------------------------
need=(
	appinfo/info.xml
	appinfo/routes.php
	lib/AppInfo/Application.php
	lib/Controller/DeviceController.php
	lib/Service/DeviceService.php
	lib/Service/BridgeClient.php
	src/main.js
	src/App.vue
	src/store/device.js
	css/style.scss
	bridge/app.py
	bridge/litter_manager.py
	bridge/normalizer.py
	bridge/requirements.txt
	bridge/Dockerfile
	docker-compose.bridge.yml
	knowledge/error_codes.json
	knowledge/maintenance_thresholds.json
	docs/plans/nc-litter-v0.1-plan.md
)
missing=()
for f in "${need[@]}"; do
	[[ -e "$ROOT/$f" ]] || missing+=("$f")
done
if [[ ${#missing[@]} -eq 0 ]]; then
	pass "G0a repo layout (${#need[@]} required paths present)"
else
	bad "G0a missing: ${missing[*]}"
fi

# ---------------------------------------------------------------------------
# G0b  nothing from the vacuum app may survive in shipped code
# ---------------------------------------------------------------------------
strays=$(grep -rniE 'roomba|dorita980|irobot' \
	--include='*.php' --include='*.vue' --include='*.js' --include='*.py' \
	--include='*.scss' --include='*.json' --include='*.xml' \
	"$ROOT/lib" "$ROOT/src" "$ROOT/bridge" "$ROOT/css" "$ROOT/appinfo" \
	"$ROOT/knowledge" "$ROOT/templates" 2>/dev/null \
	| grep -vE 'vacuum-era|nc-roomba \(the sibling|sibling vacuum' || true)
if [[ -z "$strays" ]]; then
	pass "G0b no vacuum-app leftovers in shipped code"
else
	bad "G0b vacuum-app references remain:"
	printf '     %s\n' "$strays"
fi

# ---------------------------------------------------------------------------
# G0b2 the sibling vacuum's robot name must not leak into operator-facing copy.
#      The knowledge catalogs were cloned wholesale and addressed the litter box
#      as "Alfred" in 37 places -- that is the Roomba's name; this device is
#      whatever the owner called it in the Whisker app. Catalog copy must stay
#      device-neutral ("the box", "the unit") so it reads correctly either way.
#      Scoped to knowledge/ on purpose: elsewhere "Alfred" legitimately names the
#      OpenClaw Talk assistant.
# ---------------------------------------------------------------------------
sibling_catalog=$(grep -rn 'Alfred' "$ROOT/knowledge" 2>/dev/null || true)
if [[ -z "$sibling_catalog" ]]; then
	pass "G0b2 catalog copy is device-neutral (no sibling robot name)"
else
	bad "G0b2 sibling robot name in operator-facing catalog copy:"
	printf '     %s\n' "$sibling_catalog"
fi

# ---------------------------------------------------------------------------
# G0c  the deleted global theme must not be referenced anywhere
#      (it was a byte-copy of the NC-GCS theme injected into every NC page)
# ---------------------------------------------------------------------------
theme_refs=$(grep -rn "nc-litter-theme" "$ROOT/lib" "$ROOT/src" "$ROOT/css" \
	"$ROOT/templates" "$ROOT/appinfo" 2>/dev/null | grep -v 'used to' || true)
if [[ -z "$theme_refs" ]]; then
	pass "G0c no reference to the removed global theme stylesheet"
else
	bad "G0c dangling nc-litter-theme reference (would 404):"
	printf '     %s\n' "$theme_refs"
fi

# ---------------------------------------------------------------------------
# G0d  version sync. Two files only: the Python bridge has no package.json.
# ---------------------------------------------------------------------------
v_xml=$(grep -oE '<version>[0-9]+\.[0-9]+\.[0-9]+</version>' "$ROOT/appinfo/info.xml" \
	| grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
v_pkg=$(python3 -c "import json;print(json.load(open('$ROOT/package.json'))['version'])" 2>/dev/null || echo '?')
v_lock=$(python3 -c "
import json
try:
    d = json.load(open('$ROOT/package-lock.json'))
    print(d.get('version', '?'))
except Exception:
    print('-')
" 2>/dev/null || echo '-')
if [[ "$v_xml" == "$v_pkg" ]] && [[ "$v_lock" == "-" || "$v_lock" == "$v_xml" ]]; then
	pass "G0d version sync $v_xml (info.xml = package.json = package-lock.json)"
else
	bad "G0d version drift: info.xml=$v_xml package.json=$v_pkg package-lock.json=$v_lock"
fi

# The bridge reports its own version over /health; keep it pinned to the app.
v_bridge=$(grep -oE 'BRIDGE_VERSION[[:space:]]*=[[:space:]]*"[0-9.]+"' "$ROOT/bridge/app.py" 2>/dev/null \
	| grep -oE '[0-9]+\.[0-9]+\.[0-9]+' | head -1)
if [[ -z "$v_bridge" ]]; then
	pass "G0e bridge takes its version from the environment (no hardcoded literal)"
elif [[ "$v_bridge" == "$v_xml" ]]; then
	pass "G0e bridge version literal matches ($v_bridge)"
else
	bad "G0e bridge version literal $v_bridge != app $v_xml"
fi

# ---------------------------------------------------------------------------
# G0f  secrets must never be committed
# ---------------------------------------------------------------------------
if git -C "$ROOT" ls-files --error-unmatch .env >/dev/null 2>&1; then
	bad "G0f .env is TRACKED BY GIT -- it holds the Whisker password"
else
	pass "G0f .env is not tracked"
fi
if [[ -f "$ROOT/.env" ]]; then
	perms=$(stat -c '%a' "$ROOT/.env")
	if [[ "$perms" == "600" ]]; then
		pass "G0g .env permissions 600"
	else
		bad "G0g .env permissions are $perms, expected 600"
	fi
fi
leaked=$(grep -rnE 'WHISKER_PASSWORD[[:space:]]*=[[:space:]]*[^$"'"'"'[:space:]]' \
	--include='*.yml' --include='*.yaml' --include='*.php' --include='*.py' \
	--include='*.js' --include='*.sh' "$ROOT" 2>/dev/null \
	| grep -v '/node_modules/' | grep -vE '=[[:space:]]*$|\$\{|getenv|environ|os\.env' || true)
if [[ -z "$leaked" ]]; then
	pass "G0h no hardcoded Whisker password in tracked files"
else
	bad "G0h possible credential literal:"
	printf '     %s\n' "$leaked"
fi

# The owner's real Whisker address and the unit's 64-char device id are not
# secrets, but they are personal identifiers and have no business in source
# control. A test fixture captured from the live device carried both; fixtures
# exist to pin the DTO's *shape*, so identity values are anonymised.
pii=$(git -C "$ROOT" grep -nIE '[A-Za-z0-9._%+-]+@(gmail|outlook|yahoo|hotmail|icloud)\.[A-Za-z]{2,}' \
	-- ':!*.lock' ':!package-lock.json' 2>/dev/null || true)
if [[ -z "$pii" ]]; then
	pass "G0i no personal email address in tracked files"
else
	bad "G0i personal email address in tracked files:"
	printf '     %s\n' "$pii"
fi

echo
if [[ $fail -eq 0 ]]; then
	echo "PREFLIGHT PASS"
else
	echo "PREFLIGHT FAILED"
fi
exit $fail
