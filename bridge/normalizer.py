"""Normalized state DTO for NC-Litter.

The raw shape produced by ``pylitterbot`` (a :class:`LitterRobot4` object) and
the canned mock state are both firmware/library-specific and can change between
versions. Everything downstream -- the PHP ``BridgeClient``/``RobotService``,
the Pinia store, every GUI surface -- reads only the shape produced *here*, so
unsupported fields are ``null`` rather than absent.

``normalize()`` is a pure function. It accepts either:
  * a duck-typed object exposing the ``pylitterbot`` LitterRobot4 attributes
    (``waste_drawer_level``, ``litter_level``, ``pet_weight``, ``status`` ...),
    or
  * a plain ``dict`` whose keys are those same attribute names.

This lets the unit tests exercise the mapping with plain dicts and no real
``pylitterbot`` object (see ``test/test_normalizer.py``).
"""

from __future__ import annotations

import math
from datetime import datetime, timezone
from typing import Any


# ---------------------------------------------------------------------------
# Normalized status vocabulary the contract fixes.
# ---------------------------------------------------------------------------
STATUS_READY = "ready"
STATUS_CLEANING = "cleaning"
STATUS_EMPTYING = "emptying"
STATUS_DRAWER_FULL = "drawer_full"
STATUS_SLEEPING = "sleeping"
STATUS_PAUSED = "paused"
STATUS_FAULT = "fault"
STATUS_OFFLINE = "offline"

# Map pylitterbot ``LitterBoxStatus`` *codes* (the enum ``.value``) to our
# normalized status vocabulary. Codes are taken verbatim from
# pylitterbot/enums.py::LitterBoxStatus (confirmed against pylitterbot 2025.6.2).
_STATUS_CODE_MAP: dict[str, str] = {
    "RDY": STATUS_READY,
    "CCP": STATUS_CLEANING,          # Clean Cycle In Progress
    "CCC": STATUS_READY,             # Clean Cycle Complete -> back to ready
    "EC": STATUS_EMPTYING,           # Empty Cycle
    "P": STATUS_PAUSED,              # Clean Cycle Paused
    "DFS": STATUS_DRAWER_FULL,       # Drawer Full
    "SDF": STATUS_DRAWER_FULL,       # Drawer Full At Startup
    "DF1": STATUS_READY,             # Almost full - 2 cycles left (still usable)
    "DF2": STATUS_READY,             # Almost full - 1 cycle left (still usable)
    "OFF": STATUS_OFFLINE,           # unit powered off
    "OFFLINE": STATUS_OFFLINE,
    "PWRD": STATUS_OFFLINE,          # Powering Down
    "PWRU": STATUS_READY,            # Powering Up
    "CD": STATUS_READY,              # Cat Detected (waiting to cycle)
    "CSI": STATUS_READY,             # Cat Sensor Interrupted
    "CST": STATUS_READY,             # Cat Sensor Timing
    # Every remaining *_FAULT / sensor-fault / pinch / bonnet code -> fault.
    "BR": STATUS_FAULT,              # Bonnet Removed
    "CSF": STATUS_FAULT,
    "SCF": STATUS_FAULT,
    "DHF": STATUS_FAULT,
    "DPF": STATUS_FAULT,
    "HPF": STATUS_FAULT,
    "OTF": STATUS_FAULT,
    "PD": STATUS_FAULT,
    "SPF": STATUS_FAULT,
}

# Human labels for the *normalized* status (used when the raw source gives no
# label text of its own).
_STATUS_LABELS: dict[str, str] = {
    STATUS_READY: "Ready",
    STATUS_CLEANING: "Cleaning",
    STATUS_EMPTYING: "Emptying",
    STATUS_DRAWER_FULL: "Drawer full",
    STATUS_SLEEPING: "Sleeping",
    STATUS_PAUSED: "Paused",
    STATUS_FAULT: "Fault",
    STATUS_OFFLINE: "Offline",
}

# Status codes that indicate a fault condition -> surface as a non-zero error.
_FAULT_CODES = {c for c, s in _STATUS_CODE_MAP.items() if s == STATUS_FAULT}


def _get(src: Any, key: str, default: Any = None) -> Any:
    """Read ``key`` from a dict or an attribute from a duck-typed object."""
    if isinstance(src, dict):
        return src.get(key, default)
    return getattr(src, key, default)


def _num(value: Any) -> float | None:
    """Return a finite number or ``None``."""
    try:
        if value is None or isinstance(value, bool):
            return None
        n = float(value)
    except (TypeError, ValueError):
        return None
    return n if math.isfinite(n) else None


def _int_or_none(value: Any) -> int | None:
    n = _num(value)
    return int(round(n)) if n is not None else None


def _pct(value: Any) -> int | None:
    """Clamp a 0..100 (or 0..1) fraction to an integer percentage."""
    n = _num(value)
    if n is None:
        return None
    if 0.0 <= n <= 1.0:
        n *= 100.0
    return max(0, min(100, int(round(n))))


def _bool(value: Any) -> bool:
    return bool(value)


def _status_code(status: Any) -> str | None:
    """Extract the string status *code* from a pylitterbot status.

    ``status`` may be a ``LitterBoxStatus`` enum (has ``.value``), or a plain
    string code, or ``None``.
    """
    if status is None:
        return None
    # Enum -> its .value (the short code, e.g. "RDY").
    val = getattr(status, "value", status)
    if val is None:
        return None
    return str(val)


def _status_text(status: Any, code: str | None) -> str | None:
    """Human label straight from pylitterbot when present.

    ``LitterBoxStatus`` exposes ``.text`` ("Clean Cycle In Progress", ...).
    """
    text = getattr(status, "text", None)
    if isinstance(text, str) and text:
        return text
    return None


def normalize(source: Any, meta: dict[str, Any] | None = None) -> dict[str, Any]:
    """Map a LitterRobot4 (or duck-typed dict) to the fixed DTO.

    ``meta`` carries bridge-side context that the robot object does not know
    about: ``connected``, ``mock``, ``device_id``, ``name`` override,
    ``bridge_version``, ``uptime_s``, and an ``updated_at`` override.
    """
    meta = meta or {}
    updated_at = meta.get("updated_at") or datetime.now(timezone.utc).isoformat()

    # --- status ---------------------------------------------------------
    raw_status = _get(source, "status")
    code = _status_code(raw_status)
    # A plain string status code may also arrive directly (mock uses this).
    if code is None and isinstance(_get(source, "status_code"), str):
        code = _get(source, "status_code")

    is_sleeping = _bool(_get(source, "is_sleeping"))
    is_online = _get(source, "is_online")
    is_online = True if is_online is None else _bool(is_online)
    is_drawer_full = _bool(
        _get(source, "is_waste_drawer_full", _get(source, "is_drawer_full"))
    )

    status = _STATUS_CODE_MAP.get(code or "", None)
    # Sleeping/offline are cross-cutting overrides on top of the code map.
    if not is_online:
        status = STATUS_OFFLINE
    elif status is None:
        # Unknown code: fall back to sleeping/drawer-full/ready heuristics.
        if is_drawer_full:
            status = STATUS_DRAWER_FULL
        elif is_sleeping:
            status = STATUS_SLEEPING
        else:
            status = STATUS_READY
    else:
        # A "ready" unit that is asleep should read as sleeping; a full drawer
        # dominates a nominally-ready code.
        if is_drawer_full and status == STATUS_READY:
            status = STATUS_DRAWER_FULL
        elif is_sleeping and status == STATUS_READY:
            status = STATUS_SLEEPING

    status_label = _status_text(raw_status, code) or _STATUS_LABELS.get(status, "Unknown")

    # --- error ----------------------------------------------------------
    # pylitterbot has no numeric error register; a fault status *is* the error.
    # Represent it as a non-zero int (1) with the code as the label so the PHP
    # error-decoder path (which reads int error + string label) still works.
    error = 1 if (code in _FAULT_CODES or status == STATUS_FAULT) else 0
    error_label = (status_label if error else None)

    # --- levels / weight / cycles --------------------------------------
    drawer_level = _pct(_get(source, "waste_drawer_level"))
    litter_level = _pct(_get(source, "litter_level"))
    cat_weight = _num(_get(source, "pet_weight", _get(source, "cat_weight")))
    cycle_count = _int_or_none(_get(source, "cycle_count"))
    # LR4 tracks total lifetime cycles under a distinct odometer; ``cycles_total``
    # is best-effort: try an explicit override, else fall back to cycle_count.
    cycles_total = _int_or_none(
        _get(source, "cycles_total", _get(source, "odometer_clean_cycles"))
    )
    if cycles_total is None:
        cycles_total = cycle_count

    # --- sleep schedule -------------------------------------------------
    sleep_schedule = _sleep_schedule(source)

    # --- wifi -----------------------------------------------------------
    # LR4 does not expose RSSI/SSID as first-class properties (only
    # ``wifi_mode_status``); probe the raw ``_data`` defensively, else null.
    rssi = _int_or_none(_get(source, "rssi"))
    wifi_ssid = _get(source, "wifi_ssid")
    wifi_ssid = wifi_ssid if isinstance(wifi_ssid, str) and wifi_ssid else None

    name = meta.get("name") or _get(source, "name") or "Litter-Robot"

    return {
        "device_id": _int_or_none(meta.get("device_id")) or 1,
        "name": str(name),
        "connected": _bool(meta.get("connected")),
        "mock": _bool(meta.get("mock")),
        "updated_at": updated_at,
        "status": status,
        "status_label": status_label,
        "drawer_level_pct": drawer_level,
        "litter_level_pct": litter_level,
        "cat_weight": cat_weight,
        "cycle_count": cycle_count,
        "cycles_total": cycles_total,
        "sleeping": is_sleeping,
        "sleep_schedule": sleep_schedule,
        "night_light": _bool(_get(source, "night_light_mode_enabled",
                                  _get(source, "night_light"))),
        "panel_lock": _bool(_get(source, "panel_lock_enabled",
                                 _get(source, "panel_lock"))),
        "rssi": rssi,
        "wifi_ssid": wifi_ssid,
        "error": error,
        "error_label": error_label,
        "capabilities": _capabilities(source),
        "bridge": {
            "version": str(meta.get("bridge_version", "0.0.0")),
            "uptime_s": _int_or_none(meta.get("uptime_s")) or 0,
            "mock": _bool(meta.get("mock")),
        },
    }


def _sleep_schedule(source: Any) -> dict[str, Any] | None:
    """Best-effort normalization of the sleep window.

    pylitterbot exposes ``sleep_mode_enabled`` plus ``sleep_mode_start_time`` /
    ``sleep_mode_end_time`` (datetimes) and a richer ``sleep_schedule`` object.
    We surface a small, JSON-safe summary; when nothing is known we return
    ``None`` so the UI hides the schedule panel.
    """
    enabled = _get(source, "sleep_mode_enabled")
    start = _get(source, "sleep_mode_start_time")
    end = _get(source, "sleep_mode_end_time")

    # Mock/dict path: an already-shaped dict passed straight through.
    raw = _get(source, "sleep_schedule")
    if isinstance(raw, dict):
        return raw

    if enabled is None and start is None and end is None:
        return None

    def _iso(dt: Any) -> str | None:
        if dt is None:
            return None
        if isinstance(dt, str):
            return dt
        iso = getattr(dt, "isoformat", None)
        return iso() if callable(iso) else str(dt)

    return {
        "enabled": _bool(enabled),
        "start_time": _iso(start),
        "end_time": _iso(end),
    }


def _capabilities(source: Any) -> dict[str, bool]:
    """Capability matrix the UI uses to show/hide controls.

    An LR4 supports everything in the action set; a dict source may override
    individual flags. We report booleans, defaulting to ``True`` for the
    LR4-standard set.
    """
    caps = _get(source, "capabilities")
    if isinstance(caps, dict):
        return {k: _bool(v) for k, v in caps.items()}
    return {
        "clean": True,
        "empty": True,
        "sleep": True,
        "night_light": True,
        "panel_lock": True,
        "power": True,
        "wait_time": True,
        "litter_level": _get(source, "litter_level") is not None,
    }
