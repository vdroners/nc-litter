"""Contract tests against the REAL installed pylitterbot -- GATE G1 (part 3).

Every other test in this suite exercises the bridge through a fake robot, which
is exactly how a broken action shipped: the fake implemented
``set_sleep_mode``, the real ``LitterRobot4`` raises ``NotImplementedError``, and
the mismatch was invisible until an operator pressed the button and got a 502
with an empty error message.

So these tests bind to the installed library instead of a double, and assert:

* every property the normalizer reads actually exists on ``LitterRobot4``;
* every method the dispatcher calls exists *and is implemented* (no
  ``NotImplementedError`` in its body);
* the actions we advertise are exactly the ones that can work -- and the ones we
  refuse are still genuinely unavailable. If a future pylitterbot implements LR4
  sleep, ``test_sleep_is_still_unimplemented_upstream`` fails on purpose to say
  "you can re-enable sleep now".

They skip cleanly when pylitterbot is absent (it is only installed in the bridge
image, not on the host), so ``pytest`` stays green in both places. Run them
where it matters with:

    docker exec nc_litter_bridge python3 -m pytest /app/test -q
"""

import inspect
import os
import sys

import pytest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

from litter_manager import ALLOWED_ACTIONS, DEFAULT_VALID_WAIT_TIMES  # noqa: E402
from normalizer import _STATUS_CODE_MAP  # noqa: E402

pytest.importorskip("pylitterbot", reason="only installed in the bridge image")

from pylitterbot.enums import LitterBoxStatus  # noqa: E402
from pylitterbot.robot.litterrobot4 import LitterRobot4  # noqa: E402


# Properties the normalizer reads off the robot object. If pylitterbot renames
# or drops one of these, the DTO silently starts reporting null -- which is how
# ``rssi``/``wifi_ssid`` sat in the contract as permanent nulls.
READ_PROPERTIES = (
    "name",
    "status",
    "status_code",
    "is_sleeping",
    "is_online",
    "is_on",
    "is_waste_drawer_full",
    "waste_drawer_level",
    "litter_level",
    "litter_level_state",
    "pet_weight",
    "cycle_count",
    "cycles_after_drawer_full",
    "cycle_capacity",
    "scoops_saved_count",
    "clean_cycle_wait_time_minutes",
    "night_light_mode_enabled",
    "night_light_mode",
    "night_light_brightness",
    "panel_lock_enabled",
    "panel_brightness",
    "power_type",
    "hopper_status",
    "is_hopper_removed",
    "wifi_mode_status",
    "sleep_schedule",
    "sleep_mode_enabled",
    "sleep_mode_start_time",
    "sleep_mode_end_time",
    "last_seen",
)

# Methods the live dispatcher calls, keyed by the action(s) that reach them.
DISPATCH_METHODS = {
    "start_cleaning": ("clean",),
    "reset": ("reset", "empty", "reset_drawer"),
    "set_night_light": ("night_light_on", "night_light_off"),
    "set_panel_lockout": ("panel_lock_on", "panel_lock_off"),
    "set_power_status": ("power_on", "power_off"),
    "set_wait_time": ("set_wait_time",),
}


def _is_unimplemented(func) -> bool:
    """True when the body just raises NotImplementedError."""
    try:
        return "NotImplementedError" in inspect.getsource(func)
    except (OSError, TypeError):  # pragma: no cover - source always available here
        return False


def test_every_property_the_normalizer_reads_exists():
    missing = [p for p in READ_PROPERTIES if not hasattr(LitterRobot4, p)]
    assert missing == [], f"pylitterbot no longer exposes: {missing}"


def test_no_property_we_dropped_has_quietly_reappeared():
    """``rssi``/``wifi_ssid`` never existed -- if they appear, surface them."""
    for gone in ("rssi", "wifi_ssid", "cycles_total", "odometer_clean_cycles"):
        assert not hasattr(LitterRobot4, gone), (
            f"LitterRobot4 now has {gone!r} -- add it to the DTO instead of "
            "reporting a permanent null or a mirrored value"
        )


def test_every_dispatched_method_exists_and_is_implemented():
    for method, actions in DISPATCH_METHODS.items():
        func = getattr(LitterRobot4, method, None)
        assert func is not None, f"{method} missing (needed by {actions})"
        assert not _is_unimplemented(func), (
            f"{method} raises NotImplementedError but actions {actions} still "
            "dispatch to it -- remove them from ALLOWED_ACTIONS"
        )


def test_sleep_is_still_unimplemented_upstream():
    """Justifies refusing sleep_on/sleep_off. Fails when upstream fixes it.

    That failure is the signal to re-enable the sleep actions, flip
    ``capabilities.sleep`` back to True and drop the read-only settings error.
    """
    assert _is_unimplemented(LitterRobot4.set_sleep_mode), (
        "pylitterbot now implements LR4 set_sleep_mode -- re-enable the sleep "
        "actions (see the sleep NOTE in litter_manager.py)"
    )
    commands = {
        name for name in dir(LitterRobot4) if name.startswith("_command_")
    }
    assert not any("sleep" in c for c in commands)


def test_advertised_actions_have_no_orphans():
    """Every allowed action maps to a method this test verified."""
    covered = {a for actions in DISPATCH_METHODS.values() for a in actions}
    assert ALLOWED_ACTIONS == covered


def test_valid_wait_times_match_the_device():
    assert tuple(LitterRobot4.VALID_WAIT_TIMES) == DEFAULT_VALID_WAIT_TIMES
    # The set is not a range -- 5 and 60 are rejected by the device.
    assert 5 not in LitterRobot4.VALID_WAIT_TIMES
    assert 60 not in LitterRobot4.VALID_WAIT_TIMES


def test_status_code_map_covers_every_upstream_status():
    """No LitterBoxStatus may fall through the map unmapped.

    An unmapped code lands in the heuristic fallback, which historically meant a
    fault code could be reported as "ready".
    """
    upstream = {s.value for s in LitterBoxStatus if s.value is not None}
    unmapped = sorted(upstream - set(_STATUS_CODE_MAP))
    assert unmapped == [], f"unmapped LitterBoxStatus codes: {unmapped}"


def test_status_code_map_has_no_invented_codes():
    upstream = {s.value for s in LitterBoxStatus if s.value is not None}
    invented = sorted(set(_STATUS_CODE_MAP) - upstream)
    assert invented == [], f"codes not in pylitterbot: {invented}"
