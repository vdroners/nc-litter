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
        # Fill the drawer a bit, then reset it via empty.
        await m.run_action("clean")
        before = m.get_state()["drawer_level_pct"]
        res_empty = await m.run_action("empty")
        after = m.get_state()["drawer_level_pct"]

        res_nl_off = await m.run_action("night_light_off")
        nl = m.get_state()["night_light"]

        res_sleep = await m.run_action("sleep_on")
        sleeping = m.get_state()["sleeping"]
        status_when_sleeping = m.get_state()["status"]
        return before, after, res_empty, res_nl_off, nl, res_sleep, sleeping, status_when_sleeping

    before, after, res_empty, res_nl_off, nl, res_sleep, sleeping, status = asyncio.run(run())
    assert res_empty["ok"] is True
    assert after == 0  # drawer reset
    assert res_nl_off["ok"] is True
    assert nl is False
    assert res_sleep["ok"] is True
    assert sleeping is True
    assert status == "sleeping"


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
        await m.set_settings({"night_light": False, "panel_lock": True, "wait_time": 3})
        return m.get_settings()

    s = asyncio.run(run())
    assert s["night_light"] is False
    assert s["panel_lock"] is True
    assert s["wait_time"] == 3


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

    async def set_sleep_mode(self, value, sleep_time=None):
        return await self._rec("set_sleep_mode", value, sleep_time)

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
        await m.run_action("empty")
        await m.run_action("reset_drawer")
        await m.run_action("sleep_on")
        await m.run_action("sleep_off")
        await m.run_action("night_light_on")
        await m.run_action("night_light_off")
        await m.run_action("panel_lock_on")
        await m.run_action("panel_lock_off")
        await m.run_action("power_on")
        await m.run_action("power_off")
        await m.run_action("set_wait_time", wait_time=9)
        return m._robot.calls

    calls = asyncio.run(run())
    names = [c[0] for c in calls]

    assert names.count("start_cleaning") == 1
    # empty AND reset_drawer both map to reset().
    assert names.count("reset") == 2
    # sleep_on carries a default start time; sleep_off carries False.
    sleep_calls = [c for c in calls if c[0] == "set_sleep_mode"]
    assert sleep_calls[0][1][0] is True and sleep_calls[0][1][1] is not None
    assert sleep_calls[1][1][0] is False
    # night light + panel lock + power on/off booleans.
    assert ("set_night_light", (True,)) in calls
    assert ("set_night_light", (False,)) in calls
    assert ("set_panel_lockout", (True,)) in calls
    assert ("set_panel_lockout", (False,)) in calls
    assert ("set_power_status", (True,)) in calls
    assert ("set_power_status", (False,)) in calls
    assert ("set_wait_time", (9,)) in calls


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
        "clean", "empty", "reset_drawer", "sleep_on", "sleep_off",
        "night_light_on", "night_light_off", "panel_lock_on", "panel_lock_off",
        "power_on", "power_off", "set_wait_time",
    })
