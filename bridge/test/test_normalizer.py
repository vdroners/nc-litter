"""Unit tests for normalizer.normalize -- GATE G1 (part 1).

These run with plain ``pytest`` and never import or require ``pylitterbot``:
normalize() is exercised with plain dicts whose keys mirror the LitterRobot4
attribute names.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import normalizer  # noqa: E402


# The exact DTO contract. Every consumer (PHP BridgeClient/DeviceService, the
# Pinia store, the GUI) reads only these keys, so adding or removing one here is
# a deliberate contract change that must be made in step with those consumers.
# Notably absent: ``rssi`` and ``wifi_ssid`` -- an LR4 exposes neither, and
# carrying them as permanent nulls invited a signal-strength widget that could
# never light up.
EXPECTED_DTO_KEYS = {
    "device_id", "name", "connected", "mock", "updated_at",
    "last_poll_ok_at", "poll_error", "last_seen",
    "status", "status_label", "status_code",
    "drawer_level_pct", "litter_level_pct", "litter_level_state",
    # Litter-sensor health. `litter_level_pct`/`litter_level_state` are suppressed
    # when the time-of-flight sensor stops answering, so consumers need a way to
    # tell "unknown because the sensor is dead" from "genuinely empty".
    "litter_sensor_ok", "litter_level_raw",
    "cat_weight", "cycle_count", "cycles_total", "cycles_since_full",
    "cycle_capacity", "scoops_saved",
    "sleeping", "sleep_schedule",
    "night_light", "night_light_mode", "night_light_brightness",
    "panel_lock", "panel_brightness",
    "power_on", "power_type", "wait_time",
    "hopper_status", "hopper_removed", "wifi_mode",
    "error", "error_label", "capabilities", "bridge",
}


def _sample_raw(**overrides):
    raw = {
        "name": "Alfred",
        "status_code": "RDY",
        "is_sleeping": False,
        "is_online": True,
        "is_waste_drawer_full": False,
        "waste_drawer_level": 42.0,
        "litter_level": 73.0,
        "pet_weight": 9.2,
        "cycle_count": 5,
        "cycles_total": 220,
        "cycles_after_drawer_full": 4,
        "night_light_mode_enabled": True,
        "panel_lock_enabled": False,
        "wifi_mode_status": "ROUTER_CONNECTED",
    }
    raw.update(overrides)
    return raw


def test_normalize_maps_all_contract_fields():
    dto = normalizer.normalize(
        _sample_raw(),
        {"connected": True, "mock": False, "device_id": 1,
         "bridge_version": "0.1.0", "uptime_s": 12},
    )

    # The DTO key set is a CONTRACT: PHP, the Pinia store and every GUI surface
    # read only these keys. Asserted as an exact equality, not a subset -- a
    # subset check is what let the missing ``status_code`` ship, which silently
    # made every specific fault decode to the generic "something needs a look".
    assert set(dto.keys()) == EXPECTED_DTO_KEYS

    assert dto["device_id"] == 1
    assert dto["name"] == "Alfred"
    assert dto["connected"] is True
    assert dto["mock"] is False
    assert dto["status"] == "ready"
    assert dto["status_label"]  # non-empty label
    assert dto["drawer_level_pct"] == 42
    assert dto["litter_level_pct"] == 73
    assert dto["cat_weight"] == 9.2
    assert dto["cycle_count"] == 5
    assert dto["cycles_total"] == 220
    assert dto["cycles_since_full"] == 4
    assert dto["sleeping"] is False
    assert dto["night_light"] is True
    assert dto["panel_lock"] is False
    assert dto["wifi_mode"] == "ROUTER_CONNECTED"
    assert dto["error"] == 0
    assert dto["error_label"] is None
    assert dto["bridge"]["version"] == "0.1.0"
    assert dto["bridge"]["uptime_s"] == 12
    assert dto["bridge"]["mock"] is False


def test_status_cleaning_and_paused_and_emptying():
    assert normalizer.normalize(_sample_raw(status_code="CCP"))["status"] == "cleaning"
    assert normalizer.normalize(_sample_raw(status_code="P"))["status"] == "paused"
    assert normalizer.normalize(_sample_raw(status_code="EC"))["status"] == "emptying"


def test_drawer_full_overrides_ready():
    dto = normalizer.normalize(
        _sample_raw(status_code="DFS", is_waste_drawer_full=True, waste_drawer_level=100)
    )
    assert dto["status"] == "drawer_full"
    assert dto["drawer_level_pct"] == 100


def test_sleeping_flag_when_status_ready():
    dto = normalizer.normalize(_sample_raw(status_code="RDY", is_sleeping=True))
    assert dto["status"] == "sleeping"
    assert dto["sleeping"] is True


def test_offline_status():
    dto = normalizer.normalize(_sample_raw(is_online=False, status_code="OFFLINE"))
    assert dto["status"] == "offline"


def test_fault_status_sets_error():
    dto = normalizer.normalize(_sample_raw(status_code="HPF"))
    assert dto["status"] == "fault"
    assert dto["error"] == 1
    assert dto["error_label"]


def test_null_levels_when_missing():
    dto = normalizer.normalize({"status_code": "RDY"})
    assert dto["drawer_level_pct"] is None
    assert dto["litter_level_pct"] is None
    assert dto["cat_weight"] is None
    assert dto["cycle_count"] is None


def test_percent_values_are_never_rescaled():
    """A percentage in is the same percentage out -- especially 1%.

    Regression guard. An earlier ``_pct`` treated ``0 <= n <= 1`` as a fraction
    and multiplied by 100, so a genuine 1% became 100%. Both LR4 level fields
    are already percentages (``DFILevelPercent`` and
    ``litterLevelPercentage * 100``), so that turned an almost-empty drawer into
    "full" (firing a drawer-full notification) and, in the other direction,
    reported critically-low litter as completely full -- suppressing the warning
    at exactly the moment it mattered.
    """
    assert normalizer.normalize(_sample_raw(waste_drawer_level=1))["drawer_level_pct"] == 1
    assert normalizer.normalize(_sample_raw(waste_drawer_level=1.0))["drawer_level_pct"] == 1
    assert normalizer.normalize(_sample_raw(litter_level=1))["litter_level_pct"] == 1
    assert normalizer.normalize(_sample_raw(litter_level=0.4))["litter_level_pct"] == 0
    assert normalizer.normalize(_sample_raw(waste_drawer_level=42.6))["drawer_level_pct"] == 43
    assert normalizer.normalize(_sample_raw(waste_drawer_level=140))["drawer_level_pct"] == 100
    assert normalizer.normalize(_sample_raw(waste_drawer_level=-5))["drawer_level_pct"] == 0


def test_status_code_is_preserved_for_every_fault():
    """The raw code must survive even though ``status`` collapses to "fault".

    PHP's error decoder resolves the specific catalog entry from
    ``status_code``; without it, BR/CSF/SCF/PD/... were all indistinguishable.
    """
    for code in ("BR", "CSF", "SCF", "DHF", "DPF", "HPF", "OTF", "PD", "SPF"):
        dto = normalizer.normalize(_sample_raw(status_code=code))
        assert dto["status"] == "fault", code
        assert dto["status_code"] == code, code
        assert dto["error"] == 1, code


def test_unknown_source_reports_offline_not_ready():
    """With nothing known, say offline. Never claim a box is Ready.

    This is the seed DTO the bridge publishes before its first successful poll.
    Reporting "ready" there meant a never-contacted device looked healthy.
    """
    dto = normalizer.normalize({})
    assert dto["status"] == "offline"
    assert dto["status_code"] is None


def test_sleep_and_capabilities_report_what_the_device_can_actually_do():
    """``set_sleep_mode`` is NotImplementedError on LR4 -- never advertise it."""
    caps = normalizer.normalize(_sample_raw())["capabilities"]
    assert caps["sleep"] is False
    assert caps["reset"] is True
    assert "empty" not in caps  # renamed: reset does not empty the drawer
    assert caps["wait_time_values"] == [3, 7, 15, 25, 30]
    assert 5 not in caps["wait_time_values"]


def test_sleep_schedule_is_marked_read_only():
    dto = normalizer.normalize(_sample_raw(
        sleep_mode_enabled=True, sleep_mode_start_time="22:00", sleep_mode_end_time="06:00"))
    assert dto["sleep_schedule"]["writable"] is False
    assert dto["sleep_schedule"]["enabled"] is True


def test_poll_health_passes_through_from_meta():
    dto = normalizer.normalize(_sample_raw(), {
        "last_poll_ok_at": "2026-07-30T14:00:00+00:00",
        "poll_error": "refresh_failed: boom",
    })
    assert dto["last_poll_ok_at"] == "2026-07-30T14:00:00+00:00"
    assert dto["poll_error"] == "refresh_failed: boom"


def test_enum_valued_fields_flatten_to_strings():
    class Enumish:
        def __init__(self, value):
            self.value = value

    dto = normalizer.normalize(_sample_raw(
        litter_level_state=Enumish("LOW"),
        night_light_mode=Enumish("AUTO"),
        panel_brightness=Enumish("MEDIUM"),
        power_type=Enumish("AC"),
        wifi_mode_status=Enumish("ROUTER_CONNECTED"),
    ))
    assert dto["litter_level_state"] == "LOW"
    assert dto["night_light_mode"] == "AUTO"
    assert dto["panel_brightness"] == "MEDIUM"
    assert dto["power_type"] == "AC"
    assert dto["wifi_mode"] == "ROUTER_CONNECTED"


def test_duck_typed_object_source():
    """normalize accepts an object with attributes, not just a dict."""

    class FakeStatus:
        value = "CCP"
        text = "Clean Cycle In Progress"

    class FakeRobot:
        name = "Duck"
        status = FakeStatus()
        is_sleeping = False
        is_online = True
        is_waste_drawer_full = False
        waste_drawer_level = 30.0
        litter_level = 60.0
        pet_weight = 8.8
        cycle_count = 2
        night_light_mode_enabled = False
        panel_lock_enabled = True

    dto = normalizer.normalize(FakeRobot(), {"connected": True})
    assert dto["name"] == "Duck"
    assert dto["status"] == "cleaning"
    assert dto["status_label"] == "Clean Cycle In Progress"  # from enum .text
    assert dto["panel_lock"] is True
    assert dto["cat_weight"] == 8.8


def test_meta_name_override_wins():
    dto = normalizer.normalize(_sample_raw(name="Raw"), {"name": "Override"})
    assert dto["name"] == "Override"


def test_a_dead_litter_sensor_is_not_reported_as_an_empty_box():
    """The failure mode observed on the real unit on 2026-08-07.

    The LR4 litter level is a time-of-flight distance. When that sensor stops
    answering, the firmware publishes 0xFFFF (65535) and the derived percentage
    goes wildly negative; the cloud then labels the state EMPTY. Taking that at
    face value made the app announce "litter critically low" and raise a refill
    hint for a box that had just been filled -- sending the owner to top up
    litter when the real fault was a dead sensor.
    """
    dead = normalizer.normalize(_sample_raw(litter_level=65535, litter_level_state="EMPTY"))
    assert dead["litter_sensor_ok"] is False
    assert dead["litter_level_pct"] is None, "must be unknown, not 0 -- 0 reads as empty"
    assert dead["litter_level_state"] is None, "the cloud's EMPTY label must be suppressed"
    assert dead["litter_level_raw"] == 65535
    assert dead["capabilities"]["litter_level"] is False


def test_the_negative_derived_percentage_is_also_treated_as_a_failure():
    # The same fault seen through the other field: litterLevelPercentage went to
    # -1300.7 on the live unit. A percentage outside 0..100 is a malfunction.
    for bad in (-1300.7, -1, 101, 65535):
        dto = normalizer.normalize(_sample_raw(litter_level=bad))
        assert dto["litter_sensor_ok"] is False, bad
        assert dto["litter_level_pct"] is None, bad


def test_a_believable_level_is_still_reported_normally():
    ok = normalizer.normalize(_sample_raw(litter_level=60, litter_level_state="OPTIMAL"))
    assert ok["litter_sensor_ok"] is True
    assert ok["litter_level_pct"] == 60
    assert ok["litter_level_state"] == "OPTIMAL"
    assert ok["capabilities"]["litter_level"] is True

    # The boundaries are legitimate readings, not failures: a genuinely empty box
    # reports 0 and a brimming one reports 100.
    for edge in (0, 100):
        assert normalizer.normalize(_sample_raw(litter_level=edge))["litter_sensor_ok"] is True, edge
