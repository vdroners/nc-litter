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
    """Clamp an already-percentage value (0..100) to an integer percentage.

    Deliberately does **not** rescale small values. Both LR4 level properties
    are percentages already: ``waste_drawer_level`` is ``DFILevelPercent`` and
    ``litter_level`` is ``litterLevelPercentage * 100``. An earlier revision
    treated ``0 <= n <= 1`` as a fraction and multiplied by 100, which turned a
    genuine 1% into 100% -- reporting an almost-empty drawer as full and, worse,
    reporting critically-low litter as completely full. Percent in, percent out.
    """
    n = _num(value)
    if n is None:
        return None
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
        # Unknown code: fall back to drawer-full/sleeping. With *nothing* known
        # (no code, no sensors -- e.g. the seed DTO before the first successful
        # poll) report ``offline``, never ``ready``: claiming a box is Ready when
        # we have never heard from it is the one wrong answer here.
        if is_drawer_full:
            status = STATUS_DRAWER_FULL
        elif is_sleeping:
            status = STATUS_SLEEPING
        elif code is None:
            status = STATUS_OFFLINE
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
    # ``cycle_count`` on an LR4 *is* the lifetime odometer (verified on a real
    # unit: 1684). There is no separate lifetime counter, so ``cycles_total``
    # mirrors it rather than inventing one. The genuinely useful "how many
    # cycles since the drawer filled" number is its own device property.
    cycle_count = _int_or_none(_get(source, "cycle_count"))
    cycles_total = _int_or_none(_get(source, "cycles_total"))
    if cycles_total is None:
        cycles_total = cycle_count
    cycles_since_full = _int_or_none(_get(source, "cycles_after_drawer_full"))
    cycle_capacity = _int_or_none(_get(source, "cycle_capacity"))
    scoops_saved = _int_or_none(_get(source, "scoops_saved_count"))

    # --- sleep schedule -------------------------------------------------
    sleep_schedule = _sleep_schedule(source)

    # --- wifi -----------------------------------------------------------
    # An LR4 exposes no RSSI and no SSID -- only ``wifi_mode_status``
    # (ROUTER_CONNECTED / OFFLINE / ...). Reporting a null ``rssi`` invited a
    # signal-bars widget that could never light up, so the DTO carries the mode
    # string that actually exists instead.
    wifi_mode = _enum_value(_get(source, "wifi_mode_status"))

    name = meta.get("name") or _get(source, "name") or "Litter-Robot"

    return {
        "device_id": _int_or_none(meta.get("device_id")) or 1,
        "name": str(name),
        "connected": _bool(meta.get("connected")),
        "mock": _bool(meta.get("mock")),
        "updated_at": updated_at,
        # When the last upstream poll succeeded, and why it failed if it did.
        # ``updated_at`` is a read timestamp; these two are the honest freshness
        # signals, so a dead Whisker cloud can no longer look healthy.
        "last_poll_ok_at": meta.get("last_poll_ok_at"),
        "poll_error": meta.get("poll_error") or None,
        "last_seen": _iso_or_none(_get(source, "last_seen")),
        "status": status,
        "status_label": status_label,
        # The raw LR4 status code (RDY / CCP / DFS / BR / PD / ...). The
        # normalized ``status`` above collapses every fault code to "fault", so
        # without this the error decoder could only ever say "something needs a
        # look" -- the specific catalog entries were unreachable.
        "status_code": code,
        "drawer_level_pct": drawer_level,
        "litter_level_pct": litter_level,
        "litter_level_state": _enum_value(_get(source, "litter_level_state")),
        "cat_weight": cat_weight,
        "cycle_count": cycle_count,
        "cycles_total": cycles_total,
        "cycles_since_full": cycles_since_full,
        "cycle_capacity": cycle_capacity,
        "scoops_saved": scoops_saved,
        "sleeping": is_sleeping,
        "sleep_schedule": sleep_schedule,
        "night_light": _bool(_get(source, "night_light_mode_enabled",
                                  _get(source, "night_light"))),
        "night_light_mode": _enum_value(_get(source, "night_light_mode")),
        "night_light_brightness": _int_or_none(
            _get(source, "night_light_brightness")),
        "panel_lock": _bool(_get(source, "panel_lock_enabled",
                                 _get(source, "panel_lock"))),
        "panel_brightness": _enum_value(_get(source, "panel_brightness")),
        "power_on": _bool(_get(source, "is_on", True)),
        "power_type": _enum_value(_get(source, "power_type")),
        "wait_time": _int_or_none(
            _get(source, "clean_cycle_wait_time_minutes",
                 _get(source, "wait_time"))),
        "hopper_status": _enum_value(_get(source, "hopper_status")),
        "hopper_removed": _bool(_get(source, "is_hopper_removed")),
        "wifi_mode": wifi_mode,
        "error": error,
        "error_label": error_label,
        "capabilities": _capabilities(source),
        "bridge": {
            "version": str(meta.get("bridge_version", "0.0.0")),
            "uptime_s": _int_or_none(meta.get("uptime_s")) or 0,
            "mock": _bool(meta.get("mock")),
        },
    }


def _enum_value(value: Any) -> str | None:
    """Flatten a pylitterbot enum (or plain string) to a JSON-safe string."""
    if value is None:
        return None
    val = getattr(value, "value", value)
    if val is None:
        return None
    text = str(val)
    return text or None


def _iso_or_none(dt: Any) -> str | None:
    """ISO-8601 string for a datetime-ish value, else ``None``."""
    if dt is None:
        return None
    if isinstance(dt, str):
        return dt or None
    iso = getattr(dt, "isoformat", None)
    return iso() if callable(iso) else str(dt)


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

    return {
        "enabled": _bool(enabled),
        "start_time": _iso_or_none(start),
        "end_time": _iso_or_none(end),
        # Read-only: see ``_capabilities`` -- the LR4 sleep window can be read
        # but not written through pylitterbot.
        "writable": False,
    }


def _capabilities(source: Any) -> dict[str, Any]:
    """Capability matrix the UI uses to show/hide controls.

    Reports only what a *real* LR4 can actually do through pylitterbot, checked
    against the library rather than assumed:

    * ``sleep`` is ``False``. ``LitterRobot4.set_sleep_mode`` is
      ``raise NotImplementedError()`` and ``LitterRobot4Command`` has no sleep
      verb, so there is no write path. The window is still *readable* via
      ``sleep_schedule`` -- surfaced as ``sleep_schedule_read``.
    * ``empty`` maps to ``LitterRobot4.reset()`` (a short reset press). It clears
      errors and may spin the globe; it does **not** empty the drawer, which is
      a manual job. Named ``reset`` here so the UI stops promising otherwise.
    * ``wait_time_values`` is read off the class rather than hard-coded, because
      the device rejects anything outside the set (note: 5 is *not* valid).
    """
    caps = _get(source, "capabilities")
    if isinstance(caps, dict):
        return dict(caps)
    wait_times = _get(source, "VALID_WAIT_TIMES") or [3, 7, 15, 25, 30]
    return {
        "clean": True,
        "reset": True,
        "sleep": False,
        "sleep_schedule_read": _get(source, "sleep_schedule") is not None,
        "night_light": True,
        "panel_lock": True,
        "power": True,
        "wait_time": True,
        "wait_time_values": [int(w) for w in wait_times],
        "litter_level": _get(source, "litter_level") is not None,
    }
