#!/usr/bin/env bash
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

check_file_contains G31 src/components/StatusStrip.vue 'data-field="battery"'
check_file_contains G32 src/components/ControlPad.vue 'stop-confirm'
check_file_contains G33 src/components/ErrorDecoderPanel.vue 'error-decoder'
check_file_contains G34 src/components/MissionTimeline.vue 'mission-timeline'
check_file_contains G35 src/components/ScheduleWeekGrid.vue 'schedule-week'
check_file_contains G35b src/views/SettingsView.vue 'Auto discover'
check_file_contains G35c src/components/MissionStage.vue 'data-testid="mission-stage"'
check_file_contains G35d src/views/DashboardView.vue 'MissionStage'
check_file_contains G35e css/style.scss 'nc-litter-stage'
check_file_contains G35f img/app.svg 'NC Litter'
check_file_contains G36 src/components/MaintenanceHints.vue 'maintenance-hints'
check_file_contains G37 src/components/ConnectionHealthDrawer.vue 'Recovery checklist'
check_file_contains G38 css/style.scss 'color-main-background'
check_file_contains G39 css/style.scss '--nc-app-accent: #c4a574'

# Catalog + thresholds for decoder/maintenance
if jq -e '[.errors // {} | to_entries[] | select(.value.title|tostring|test("bin full";"i"))] | length > 0' "$ROOT/knowledge/error_codes.json" >/dev/null \
  && jq -e '.errors["18"]' "$ROOT/knowledge/error_codes.json" >/dev/null; then
  echo "PASS G33 catalog bin full (code 18)"
else
  echo "FAIL G33 catalog"; fail=1
fi
if jq -e '.thresholds[0].id // .stuck_per_100h.warn' "$ROOT/knowledge/maintenance_thresholds.json" >/dev/null; then
  echo "PASS G36 thresholds"
else
  echo "FAIL G36 thresholds"; fail=1
fi

exit $fail
