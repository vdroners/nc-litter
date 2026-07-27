"""Unit tests for normalizer.normalize -- GATE G1 (part 1).

These run with plain ``pytest`` and never import or require ``pylitterbot``:
normalize() is exercised with plain dicts whose keys mirror the LitterRobot4
attribute names.
"""

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import normalizer  # noqa: E402


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
        "night_light_mode_enabled": True,
        "panel_lock_enabled": False,
        "rssi": -55,
        "wifi_ssid": "home-wifi",
    }
    raw.update(overrides)
    return raw


def test_normalize_maps_all_contract_fields():
    dto = normalizer.normalize(
        _sample_raw(),
        {"connected": True, "mock": False, "device_id": 1,
         "bridge_version": "0.1.0", "uptime_s": 12},
    )

    # Exact contract keys must all be present.
    expected_keys = {
        "device_id", "name", "connected", "mock", "updated_at", "status",
        "status_label", "drawer_level_pct", "litter_level_pct", "cat_weight",
        "cycle_count", "cycles_total", "sleeping", "sleep_schedule",
        "night_light", "panel_lock", "rssi", "wifi_ssid", "error",
        "error_label", "capabilities", "bridge",
    }
    assert expected_keys.issubset(dto.keys())

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
    assert dto["sleeping"] is False
    assert dto["night_light"] is True
    assert dto["panel_lock"] is False
    assert dto["rssi"] == -55
    assert dto["wifi_ssid"] == "home-wifi"
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


def test_fraction_level_is_scaled_to_percent():
    # A 0..1 fraction is scaled to 0..100.
    dto = normalizer.normalize(_sample_raw(waste_drawer_level=0.5))
    assert dto["drawer_level_pct"] == 50


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
