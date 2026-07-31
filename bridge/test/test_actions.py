"""Action-dispatch + mock-mode tests -- GATE G1 (part 2).

Covers:
  * mock-mode manager: state advances over refresh ticks, actions mutate the
    canned state, health shape.
  * action name -> the right pylitterbot call, via a fake robot that records
    the method invoked (no real pylitterbot needed).
  * unsupported action -> error envelope.

All async work is driven with asyncio.run so no pytest-asyncio dependency is
required.
"""

import asyncio
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))

import litter_manager  # noqa: E402
from litter_manager import ALLOWED_ACTIONS, LitterManager  # noqa: E402


def _mock_env(**overrides):
    env = {"LITTER_MOCK": "1", "LITTER_REFRESH_S": "30"}
    env.update(overrides)
    return env


# ---------------------------------------------------------------------------
# Mock-mode state
# ---------------------------------------------------------------------------
def test_mock_health_shape():
    m = LitterManager(_mock_env(), version="0.1.0")
    h = m.health()
    assert set(h.keys()) == {"ok", "connected", "mock", "version", "error", "device_present"}
    assert h["ok"] is True
    assert h["mock"] is True
    assert h["connected"] is True
    assert h["device_present"] is True
    assert h["version"] == "0.1.0"
    assert h["error"] is None


def test_mock_initial_state_is_full_dto():
    m = LitterManager(_mock_env(), version="0.1.0")
    dto = m.get_state()
    assert dto["mock"] is True
    assert dto["status"] in {"ready", "cleaning", "emptying", "sleeping", "drawer_full"}
    assert isinstance(dto["drawer_level_pct"], int)
    assert isinstance(dto["litter_level_pct"], int)
    assert dto["cat_weight"] is not None


def test_mock_state_advances_over_ticks():
    m = LitterManager(_mock_env(), version="0.1.0")

    async def run():
        seen_status = set()
        drawer_start = m.get_state()["drawer_level_pct"]
        cycles_start = m.get_state()["cycle_count"]
        for _ in range(12):
            await m._refresh_once()
            seen_status.add(m.get_state()["status"])
        dto = m.get_state()
        return seen_status, drawer_start, dto, cycles_start

    seen_status, drawer_start, dto, cycles_start = asyncio.run(run())
    # Over enough ticks we should observe a cleaning phase and a ready phase.
    assert "cleaning" in seen_status
    assert "ready" in seen_status
    # Drawer fills, cycle count climbs.
    assert dto["drawer_level_pct"] >= drawer_start
    assert dto["cycle_count"] >= cycles_start


def test_mock_action_mutates_state():
    m = LitterManager(_mock_env(), version="0.1.0")

    async def run():
        await m.run_action("clean")
        cleaning = m.get_state()["status"]
        res_nl_off = await m.run_action("night_light_off")
        nl = m.get_state()["night_light"]
        res_lock = await m.run_action("panel_lock_on")
        lock = m.get_state()["panel_lock"]
        return cleaning, res_nl_off, nl, res_lock, lock

    cleaning, res_nl_off, nl, res_lock, lock = asyncio.run(run())
    assert cleaning == "cleaning"
    assert res_nl_off["ok"] is True
    assert nl is False
    assert res_lock["ok"] is True
    assert lock is True


def test_reset_does_not_empty_the_drawer():
    """``reset`` clears the status; it does NOT empty the drawer.

    The mock used to zero the drawer level and the cycle count on ``empty``,
    which made the mock pass a behaviour the real device does not have:
    ``LitterRobot4.reset()`` dispatches SHORT_RESET_PRESS, documented as "clears
    errors and may trigger a cycle". Emptying the waste drawer is a manual job,
    so the mock must not fake it.
    """
    m = LitterManager(_mock_env(), version="0.1.0")

    async def run():
        await m.run_action("clean")
        before = m.get_state()
        res = await m.run_action("reset")
        return before, res, m.get_state()

    before, res, after = asyncio.run(run())
    assert res["ok"] is True
    assert after["drawer_level_pct"] == before["drawer_level_pct"]
    assert after["cycle_count"] == before["cycle_count"]
    assert after["cycles_since_full"] == before["cycles_since_full"]
    assert after["status"] == "ready"


def test_sleep_actions_are_rejected_not_attempted():
    """No sleep write path exists on an LR4 -- refuse rather than 502.

    ``LitterRobot4.set_sleep_mode`` is ``raise NotImplementedError()`` and
    ``LitterRobot4Command`` has no sleep verb. These used to be advertised
    actions that always failed, and because ``str(NotImplementedError())`` is the
    empty string the operator got a failure with no reason at all.
    """
    m = LitterManager(_mock_env(), version="0.1.0")
    for name in ("sleep_on", "sleep_off"):
        res = asyncio.run(m.run_action(name))
        assert res["ok"] is False, name
        assert res["error"] == "unsupported_action", name


def test_mock_set_wait_time_action():
    m = LitterManager(_mock_env(), version="0.1.0")

    async def run():
        res = await m.run_action("set_wait_time", wait_time=15)
        return res, m.get_settings()["wait_time"]

    res, wait = asyncio.run(run())
    assert res["ok"] is True
    assert wait == 15


def test_unsupported_action_returns_error():
    m = LitterManager(_mock_env(), version="0.1.0")
    res = asyncio.run(m.run_action("self_destruct"))
    assert res["ok"] is False
    assert res["error"] == "unsupported_action"


def test_mock_settings_roundtrip():
    m = LitterManager(_mock_env(), version="0.1.0")

    async def run():
        return await m.set_settings(
            {"night_light": False, "panel_lock": True, "wait_time": 3})

    out = asyncio.run(run())
    assert out["ok"] is True
    assert out["errors"] == {}
    s = out["settings"]
    assert s["night_light"] is False
    assert s["panel_lock"] is True
    assert s["wait_time"] == 3
    assert s["wait_time_values"] == [3, 7, 15, 25, 30]
    assert s["sleep_writable"] is False


def test_settings_reports_per_key_failures_instead_of_claiming_success():
    """A failed write must not be reported as saved.

    ``set_settings`` used to discard every ``run_action`` result and the HTTP
    layer hard-coded ``ok: True``, so the app showed "Saved" for writes that had
    failed -- and for sleep, which can never succeed at all.
    """
    m = LitterManager(_mock_env(), version="0.1.0")
    out = asyncio.run(m.set_settings({"night_light": True, "wait_time": 5}))
    assert out["ok"] is False
    assert "wait_time" in out["errors"]
    assert "3,7,15,25,30" in out["errors"]["wait_time"]
    assert "night_light" not in out["errors"]  # the valid key still applied
    assert out["settings"]["night_light"] is True


def test_settings_sleep_is_reported_read_only():
    m = LitterManager(_mock_env(), version="0.1.0")
    out = asyncio.run(m.set_settings({"sleep": {"enabled": True}}))
    assert out["ok"] is False
    assert "sleep_read_only" in out["errors"]["sleep"]


def test_wait_time_is_validated_against_the_device_enum():
    """The device rejects anything outside [3,7,15,25,30] -- note: no 5.

    Validated before dispatch so a bad value is a clean caller error instead of
    an upstream exception, and a missing value is refused rather than silently
    becoming 0.
    """
    m = LitterManager(_mock_env(), version="0.1.0")
    for bad, expected in (
        (5, "wait_time_invalid"),
        (9, "wait_time_invalid"),
        (60, "wait_time_invalid"),
        ("abc", "wait_time_not_a_number"),
        (None, "wait_time_required"),
    ):
        res = asyncio.run(m.run_action("set_wait_time", wait_time=bad))
        assert res["ok"] is False, bad
        assert res["error"].startswith(expected), (bad, res["error"])
    res = asyncio.run(m.run_action("set_wait_time"))
    assert res["error"] == "wait_time_required"
    for good in (3, 7, 15, 25, 30):
        assert asyncio.run(m.run_action("set_wait_time", wait_time=good))["ok"] is True


def test_poll_failures_eventually_clear_connected():
    """A dead cloud must stop looking healthy.

    ``updated_at`` is stamped on every read, so it can never detect staleness;
    ``last_poll_ok_at`` / ``poll_error`` carry that instead, and after
    MAX_POLL_FAILURES consecutive failures the bridge stops claiming to be
    connected rather than serving hours-old numbers as live.
    """
    from litter_manager import MAX_POLL_FAILURES

    m = LitterManager(_mock_env(LITTER_MOCK="0"), version="0.1.0")
    m.mock = False
    m._robot = _FakeRobot()
    m._note_poll_success()
    ok_at = m.last_poll_ok_at
    assert m.connected is True and m.poll_error is None

    for i in range(1, MAX_POLL_FAILURES + 1):
        m._note_poll_failure("refresh_failed: boom")
        assert m.poll_error == "refresh_failed: boom"
        # The successful-poll timestamp must NOT advance on a failure.
        assert m.last_poll_ok_at == ok_at
        assert m.connected is (i < MAX_POLL_FAILURES)

    dto = m.get_state()
    assert dto["connected"] is False
    assert dto["poll_error"] == "refresh_failed: boom"
    assert dto["last_poll_ok_at"] == ok_at

    m._note_poll_success()
    assert m.connected is True and m.poll_error is None


def test_mock_login_returns_a_device():
    m = LitterManager(_mock_env(), version="0.1.0")
    devices = asyncio.run(m.login("someone@example.com", "pw"))
    assert isinstance(devices, list) and len(devices) == 1
    d = devices[0]
    assert set(d.keys()) == {"id", "name", "model", "serial"}


# ---------------------------------------------------------------------------
# Live dispatch mapping (fake robot, no pylitterbot)
# ---------------------------------------------------------------------------
class _FakeRobot:
    """Records which method was called with which args."""

    # Mirrors the real LitterRobot4 surface. Deliberately has NO
    # ``set_sleep_mode``: the real class raises NotImplementedError, and a fake
    # that implements it is how the broken sleep action passed its tests.
    VALID_WAIT_TIMES = [3, 7, 15, 25, 30]

    def __init__(self):
        self.calls = []
        self.clean_cycle_wait_time_minutes = 7

    def _rec(self, name, *args):
        self.calls.append((name, args))

        async def _coro():
            return True

        return _coro()

    async def start_cleaning(self):
        return await self._rec("start_cleaning")

    async def reset(self):
        return await self._rec("reset")

    async def set_night_light(self, value):
        return await self._rec("set_night_light", value)

    async def set_panel_lockout(self, value):
        return await self._rec("set_panel_lockout", value)

    async def set_power_status(self, value):
        return await self._rec("set_power_status", value)

    async def set_wait_time(self, wait_time):
        return await self._rec("set_wait_time", wait_time)

    async def refresh(self):
        return None


def _live_manager_with_fake():
    m = LitterManager(_mock_env(LITTER_MOCK="0"), version="0.1.0")
    # Force live path with an injected fake robot; no pylitterbot import occurs.
    m.mock = False
    m._robot = _FakeRobot()
    m.connected = True
    return m


def test_live_dispatch_maps_names_to_methods():
    m = _live_manager_with_fake()

    async def run():
        await m.run_action("clean")
        await m.run_action("reset")
        await m.run_action("empty")
        await m.run_action("reset_drawer")
        await m.run_action("night_light_on")
        await m.run_action("night_light_off")
        await m.run_action("panel_lock_on")
        await m.run_action("panel_lock_off")
        await m.run_action("power_on")
        await m.run_action("power_off")
        await m.run_action("set_wait_time", wait_time=15)
        return m._robot.calls

    calls = asyncio.run(run())
    names = [c[0] for c in calls]

    assert names.count("start_cleaning") == 1
    # reset + its two deprecated aliases (empty, reset_drawer) are one command.
    assert names.count("reset") == 3
    assert "set_sleep_mode" not in names
    # night light + panel lock + power on/off booleans.
    assert ("set_night_light", (True,)) in calls
    assert ("set_night_light", (False,)) in calls
    assert ("set_panel_lockout", (True,)) in calls
    assert ("set_panel_lockout", (False,)) in calls
    assert ("set_power_status", (True,)) in calls
    assert ("set_power_status", (False,)) in calls
    assert ("set_wait_time", (15,)) in calls


def test_live_action_when_not_connected():
    m = LitterManager(_mock_env(LITTER_MOCK="0"), version="0.1.0")
    m.mock = False
    m._robot = None
    res = asyncio.run(m.run_action("clean"))
    assert res["ok"] is False
    assert res["error"] == "not_connected"


def test_allowed_actions_contract_set():
    # Guard the exact contract set so a rename can't silently drift.
    assert ALLOWED_ACTIONS == frozenset({
        "clean", "reset", "empty", "reset_drawer",
        "night_light_on", "night_light_off", "panel_lock_on", "panel_lock_off",
        "power_on", "power_off", "set_wait_time",
    })
