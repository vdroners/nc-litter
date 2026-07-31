#!/usr/bin/env bash
# GUI gates for the Litter-Robot 4 front end: assert the surfaces the plan
# promises actually exist in the sources, and that the knowledge catalogs behind
# the decoder / maintenance panels are LR4-shaped.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
fail=0

check_file_contains() {
  local g="$1" file="$2" needle="$3"
  if [[ -f "$ROOT/$file" ]] && grep -Fq -- "$needle" "$ROOT/$file"; then
    echo "PASS $g $file"
  else
    echo "FAIL $g missing $needle in $file"
    fail=1
  fi
}

check_file_absent() {
  local g="$1" file="$2"
  if [[ -e "$ROOT/$file" ]]; then
    echo "FAIL $g $file should be gone (vacuum-era surface)"
    fail=1
  else
    echo "PASS $g $file removed"
  fi
}

# ── Status strip + hero: the two level gauges are the headline readings ──
check_file_contains G31 src/components/StatusStrip.vue 'data-field="drawer"'
check_file_contains G31b src/components/StatusStrip.vue 'data-field="litter"'
check_file_contains G31c src/components/StatusHero.vue 'data-field="drawer-gauge"'
check_file_contains G31d src/components/StatusHero.vue 'data-field="litter-gauge"'
check_file_contains G31e src/components/StatusHero.vue 'data-field="cat-weight"'
check_file_contains G31f src/components/RingGauge.vue 'nc-litter-ring__pct'

# ── Control pad: the LR4 command surface, and only that ─────────────────
# `reset` replaced `empty`/`reset_drawer` (one command under three names, none of
# which emptied the drawer), and sleep is gone entirely: pylitterbot raises
# NotImplementedError for LR4 sleep, so a sleep button could only ever fail.
check_file_contains G32 src/components/ControlPad.vue 'empty-confirm'
check_file_contains G32b src/components/ControlPad.vue 'data-action="set_wait_time"'
for action in clean reset night_light_on night_light_off panel_lock_on panel_lock_off; do
  check_file_contains "G32-${action}" src/components/ControlPad.vue "name: '${action}'"
done
for gone in sleep_on sleep_off; do
  if grep -Fq -- "name: '${gone}'" "$ROOT/src/components/ControlPad.vue"; then
    echo "FAIL G32z ControlPad offers ${gone}, which the LR4 cannot honour"
    fail=1
  else
    echo "PASS G32z ${gone} not offered"
  fi
done

# ── Condition decoder + cycle theater / timeline ────────────────────────
check_file_contains G33 src/components/ErrorDecoderPanel.vue 'error-decoder'
check_file_contains G34 src/components/CycleTimeline.vue 'cycle-timeline'
check_file_contains G35 src/views/SettingsView.vue 'sleep-schedule'
check_file_contains G35b src/views/AdminSettingsView.vue 'WhiskerSetup'
check_file_contains G35c src/components/CycleStage.vue 'data-testid="cycle-stage"'
check_file_contains G35d src/views/DashboardView.vue 'CycleStage'
check_file_contains G35e css/style.scss 'nc-litter-stage__globe'
check_file_contains G35f img/app.svg 'NC Litter'
check_file_contains G35g src/components/WhiskerSetup.vue 'whisker-setup'
check_file_contains G35h src/components/DrawerTrend.vue 'drawer-trend'
check_file_contains G35i src/views/DashboardView.vue 'DrawerTrend'

# ── Vacuum-era surfaces must not come back ──────────────────────────────
check_file_absent G35j src/views/LocationView.vue
check_file_absent G35k src/components/MissionStage.vue
check_file_absent G35l src/components/SetupWizard.vue
check_file_absent G35m src/store/robot.js

# ── Maintenance + connection health ─────────────────────────────────────
check_file_contains G36 src/components/MaintenanceHints.vue 'maintenance-hints'
check_file_contains G37 src/components/ConnectionHealthDrawer.vue 'Recovery checklist'
check_file_contains G37b src/components/ConnectionHealthDrawer.vue 'data-field="cloud"'

# ── Theme: NC light/dark inheritance + the tabby accent ─────────────────
check_file_contains G38 css/style.scss 'color-main-background'
check_file_contains G39 css/style.scss '--nc-app-accent: #d8a45e'
check_file_contains G39b css/style.scss 'prefers-reduced-motion'

# ── Store wiring: the device store and the real routes ──────────────────
check_file_contains G40 src/store/device.js "defineStore('device'"
check_file_contains G40b src/services/api.js '/api/devices/${deviceId}/state'
check_file_contains G40c src/services/api.js '/api/admin/onboard/login'
check_file_contains G40d src/services/api.js '/api/cycles'

# Nothing may still point at the vacuum API or store.
if grep -rqE 'api/robots|useRobotStore' "$ROOT/src"; then
  echo "FAIL G40e src/ still references the vacuum API or store"
  fail=1
else
  echo "PASS G40e no vacuum API/store references in src/"
fi

# ── Catalogs behind the decoder + maintenance panels ────────────────────
if jq -e '.errors["DFS"] and .errors["BR"] and .errors["PD"] and .not_ready["CCP"]' \
    "$ROOT/knowledge/error_codes.json" >/dev/null; then
  echo "PASS G33b catalog has the LR4 conditions (DFS/BR/PD/CCP)"
else
  echo "FAIL G33b catalog missing LR4 condition codes"; fail=1
fi
if jq -e '[.thresholds[] | select(.metric_state=="drawer_level_pct")] | length > 0' \
    "$ROOT/knowledge/maintenance_thresholds.json" >/dev/null \
  && jq -e '[.thresholds[] | select(.metric_state=="litter_level_pct")] | length > 0' \
    "$ROOT/knowledge/maintenance_thresholds.json" >/dev/null; then
  echo "PASS G36b thresholds cover drawer + litter levels"
else
  echo "FAIL G36b thresholds"; fail=1
fi

exit $fail
