"""LitterManager: owns the Whisker/pylitterbot session and the live state.

Responsibilities
----------------
* Authenticate to the Whisker cloud via ``pylitterbot.Account`` and bind one
  ``LitterRobot4`` (auth + token refresh handled by pylitterbot's session).
* Run a background refresh loop (poll every ``LITTER_REFRESH_S`` seconds) that
  refreshes the robot, re-normalizes state, and pushes the DTO to every SSE
  subscriber on change.
* Expose the operations the HTTP layer needs: ``get_state``, ``run_action``,
  ``get_settings`` / ``set_settings``, ``login`` (list devices on an account)
  and ``connect`` (bind the active device).
* Provide a fully self-contained MOCK implementation (``LITTER_MOCK=1``) that
  never imports or contacts pylitterbot -- the app, the gates (G1) and the
  first deploy (G6) run against it with no Whisker account.

The manager is deliberately import-safe: ``pylitterbot`` is only imported
lazily inside the live code path, so ``import litter_manager`` (and the unit
tests) work even when the library is not installed.

Confirmed against pylitterbot 2025.6.2 (see method notes inline).
"""

from __future__ import annotations

import asyncio
import contextlib
import os
import time
from datetime import datetime, time as dtime, timezone
from typing import Any, Awaitable, Callable

import normalizer


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _env_bool(name: str, default: bool) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


class LitterManager:
    """Single-device manager. One process binds one Litter-Robot."""

    def __init__(self, env: dict[str, str] | None = None, version: str = "0.0.0") -> None:
        env = dict(env if env is not None else os.environ)
        self.version = version
        self._start_monotonic = time.monotonic()

        self.mock: bool = _env_bool("LITTER_MOCK", True) if env is os.environ else (
            str(env.get("LITTER_MOCK", "1")).strip().lower() in ("1", "true", "yes", "on")
        )
        self.refresh_s: float = float(env.get("LITTER_REFRESH_S", "30") or 30)
        self._email: str | None = env.get("WHISKER_EMAIL") or None
        self._password: str | None = env.get("WHISKER_PASSWORD") or None
        self._device_id: str | None = env.get("LITTER_DEVICE_ID") or None

        # Live-session objects (populated only in non-mock mode).
        self._account: Any = None
        self._robot: Any = None

        # Health / status bookkeeping.
        self.connected: bool = False
        self.error: str | None = None

        # In-memory normalized DTO + change notification.
        self._state: dict[str, Any] = {}
        self._subscribers: set[Callable[[dict[str, Any]], None]] = set()
        self._lock = asyncio.Lock()
        self._refresh_task: asyncio.Task | None = None

        # Mock scratch state (only used when self.mock).
        self._mock = _MockState()

        # Seed an initial DTO so /state and /health work before the first poll.
        self._recompute_from_mock() if self.mock else self._set_state(
            normalizer.normalize({}, self._meta())
        )

    # ------------------------------------------------------------------
    # Lifecycle
    # ------------------------------------------------------------------
    @property
    def configured(self) -> bool:
        return self.mock or bool(self._email and self._password)

    async def start(self) -> None:
        """Called on FastAPI startup. Auto-connect when configured."""
        if self.mock:
            self._start_refresh_loop()
            return
        if self._email and self._password:
            try:
                await self._connect_account(self._email, self._password, self._device_id)
            except Exception as exc:  # noqa: BLE001 - surface into health, never crash
                self.error = f"connect_failed: {exc}"
                self.connected = False
        self._start_refresh_loop()

    async def stop(self) -> None:
        if self._refresh_task is not None:
            self._refresh_task.cancel()
            with contextlib.suppress(asyncio.CancelledError):
                await self._refresh_task
            self._refresh_task = None
        if self._account is not None and not self.mock:
            disconnect = getattr(self._account, "disconnect", None)
            if callable(disconnect):
                with contextlib.suppress(Exception):
                    await disconnect()

    def _start_refresh_loop(self) -> None:
        if self._refresh_task is None or self._refresh_task.done():
            self._refresh_task = asyncio.create_task(self._refresh_loop())

    async def _refresh_loop(self) -> None:
        while True:
            try:
                await self._refresh_once()
            except asyncio.CancelledError:
                raise
            except Exception as exc:  # noqa: BLE001
                self.error = f"refresh_failed: {exc}"
            await asyncio.sleep(max(1.0, self.refresh_s))

    async def _refresh_once(self) -> None:
        if self.mock:
            self._mock.advance()
            self._recompute_from_mock()
            return
        if self._robot is None:
            return
        # pylitterbot: LitterRobot4.refresh() re-pulls the unit from the API.
        refresh = getattr(self._robot, "refresh", None)
        if callable(refresh):
            await refresh()
        self.error = None
        self._set_state(normalizer.normalize(self._robot, self._meta()))

    # ------------------------------------------------------------------
    # State + SSE subscription
    # ------------------------------------------------------------------
    def _meta(self) -> dict[str, Any]:
        return {
            "connected": self.connected,
            "mock": self.mock,
            "device_id": 1,
            "bridge_version": self.version,
            "uptime_s": int(time.monotonic() - self._start_monotonic),
            "updated_at": _now_iso(),
        }

    def _set_state(self, dto: dict[str, Any]) -> None:
        prev = self._state
        self._state = dto
        # Notify only when something operators care about actually changed
        # (ignore the always-moving updated_at / uptime fields).
        if _significant_change(prev, dto):
            self._notify(dto)

    def _recompute_from_mock(self) -> None:
        self.connected = True  # mock is always "connected"
        self.error = None
        self._set_state(normalizer.normalize(self._mock.as_source(), self._meta()))

    def get_state(self) -> dict[str, Any]:
        # Refresh bridge-side bookkeeping (uptime) on read without renotifying.
        self._state = {**self._state, "updated_at": _now_iso()}
        self._state["bridge"] = {
            **self._state.get("bridge", {}),
            "uptime_s": int(time.monotonic() - self._start_monotonic),
        }
        return self._state

    def subscribe(self, callback: Callable[[dict[str, Any]], None]) -> Callable[[], None]:
        self._subscribers.add(callback)

        def _unsub() -> None:
            self._subscribers.discard(callback)

        return _unsub

    def _notify(self, dto: dict[str, Any]) -> None:
        for cb in list(self._subscribers):
            with contextlib.suppress(Exception):
                cb(dto)

    def health(self) -> dict[str, Any]:
        return {
            "ok": True,
            "connected": bool(self.connected),
            "mock": bool(self.mock),
            "version": self.version,
            "error": self.error,
            "device_present": bool(self.mock or self._robot is not None),
        }

    # ------------------------------------------------------------------
    # Whisker account (live mode only)
    # ------------------------------------------------------------------
    async def _new_account(self) -> Any:
        # Lazy import so the module (and the mock path / tests) never require
        # pylitterbot to be installed.
        from pylitterbot import Account  # type: ignore

        return Account()

    async def _connect_account(
        self, email: str, password: str, device_id: str | None
    ) -> list[dict[str, Any]]:
        """Authenticate, load robots, and (optionally) bind ``device_id``.

        Returns the list of discovered LR4 devices.
        """
        from pylitterbot.robot.litterrobot4 import LitterRobot4  # type: ignore

        account = await self._new_account()
        # Account.connect(username, password, load_robots=True[, subscribe_for_updates])
        # confirmed signature (pylitterbot 2025.6.2). We keep the poll loop as
        # the source of truth rather than relying on push subscriptions.
        await account.connect(username=email, password=password, load_robots=True)
        self._account = account

        robots = list(getattr(account, "robots", []) or [])
        # Only Litter-Robot 4 units are in scope for this bridge.
        lr4s = [r for r in robots if isinstance(r, LitterRobot4)]

        devices = [
            {
                "id": str(getattr(r, "id", "")),
                "name": getattr(r, "name", "") or "Litter-Robot",
                "model": getattr(r, "model", "Litter-Robot 4"),
                "serial": getattr(r, "serial", "") or "",
            }
            for r in lr4s
        ]

        # Bind the active robot: explicit device_id (matches id or serial), else
        # the first LR4 on the account.
        chosen = None
        if device_id:
            for r in lr4s:
                if str(getattr(r, "id", "")) == device_id or getattr(r, "serial", "") == device_id:
                    chosen = r
                    break
        if chosen is None and lr4s:
            chosen = lr4s[0]

        self._robot = chosen
        self._email, self._password, self._device_id = email, password, device_id
        self.connected = chosen is not None
        self.error = None if chosen is not None else "no_lr4_on_account"
        if chosen is not None:
            self._set_state(normalizer.normalize(chosen, self._meta()))
        return devices

    async def login(self, email: str, password: str) -> list[dict[str, Any]]:
        """Authenticate only to enumerate LR4s; does NOT bind or persist creds.

        In mock mode returns a single canned device.
        """
        if self.mock:
            return [self._mock.device()]
        from pylitterbot import Account  # type: ignore
        from pylitterbot.robot.litterrobot4 import LitterRobot4  # type: ignore

        account = Account()
        try:
            await account.connect(username=email, password=password, load_robots=True)
            robots = list(getattr(account, "robots", []) or [])
            lr4s = [r for r in robots if isinstance(r, LitterRobot4)]
            return [
                {
                    "id": str(getattr(r, "id", "")),
                    "name": getattr(r, "name", "") or "Litter-Robot",
                    "model": getattr(r, "model", "Litter-Robot 4"),
                    "serial": getattr(r, "serial", "") or "",
                }
                for r in lr4s
            ]
        finally:
            disconnect = getattr(account, "disconnect", None)
            if callable(disconnect):
                with contextlib.suppress(Exception):
                    await disconnect()

    async def connect(
        self, email: str | None = None, password: str | None = None, device_id: str | None = None
    ) -> dict[str, Any]:
        """Bind the active device for this bridge process. Returns health."""
        if self.mock:
            self.connected = True
            self.error = None
            self._recompute_from_mock()
            return self.health()
        email = email or self._email
        password = password or self._password
        if not email or not password:
            self.error = "missing_credentials"
            self.connected = False
            return self.health()
        try:
            await self._connect_account(email, password, device_id or self._device_id)
        except Exception as exc:  # noqa: BLE001
            self.error = f"connect_failed: {exc}"
            self.connected = False
        return self.health()

    # ------------------------------------------------------------------
    # Actions
    # ------------------------------------------------------------------
    # ALLOWED_ACTIONS -> (mock handler, live coroutine factory). The live
    # factory takes the bound robot and returns an awaitable.
    #
    # pylitterbot method mapping (confirmed against 2025.6.2 unless noted):
    #   clean                 -> robot.start_cleaning()
    #   empty / reset_drawer  -> robot.reset()            [LR4; see note below]
    #   sleep_on              -> robot.set_sleep_mode(True, <default start>)
    #   sleep_off             -> robot.set_sleep_mode(False)
    #   night_light_on/off    -> robot.set_night_light(True/False)
    #   panel_lock_on/off     -> robot.set_panel_lockout(True/False)
    #   power_on/off          -> robot.set_power_status(True/False)
    #   set_wait_time (n)     -> robot.set_wait_time(n)
    #
    # NOTE (empty/reset_drawer): pylitterbot 2025.6.2 does NOT expose a
    # ``reset_waste_drawer()``/``reset_waste_drawer_level()`` method. The LR4
    # object provides ``reset()`` ("perform reset") which is the closest
    # documented reset primitive, so we use that. If a future pylitterbot adds a
    # dedicated waste-drawer reset, prefer it here.
    DEFAULT_SLEEP_START = dtime(hour=22, minute=0)  # 10pm local default

    async def run_action(self, name: str, **kwargs: Any) -> dict[str, Any]:
        name = (name or "").strip().lower()
        if name not in ALLOWED_ACTIONS:
            return {"ok": False, "result": {}, "error": "unsupported_action"}

        if self.mock:
            self._mock.apply_action(name, **kwargs)
            self._recompute_from_mock()
            return {"ok": True, "result": {"action": name, "mock": True}, "error": None}

        if self._robot is None:
            return {"ok": False, "result": {}, "error": "not_connected"}

        try:
            result = await self._dispatch_live(name, **kwargs)
        except Exception as exc:  # noqa: BLE001
            return {"ok": False, "result": {"action": name}, "error": str(exc)}

        # Reflect the change quickly rather than waiting for the next poll.
        with contextlib.suppress(Exception):
            await self._refresh_once()
        return {"ok": True, "result": {"action": name, "returned": result}, "error": None}

    async def _dispatch_live(self, name: str, **kwargs: Any) -> Any:
        r = self._robot
        if name == "clean":
            return await r.start_cleaning()
        if name in ("empty", "reset_drawer"):
            return await r.reset()  # assumed: closest reset primitive (see NOTE)
        if name == "sleep_on":
            return await r.set_sleep_mode(True, self.DEFAULT_SLEEP_START)
        if name == "sleep_off":
            return await r.set_sleep_mode(False)
        if name == "night_light_on":
            return await r.set_night_light(True)
        if name == "night_light_off":
            return await r.set_night_light(False)
        if name == "panel_lock_on":
            return await r.set_panel_lockout(True)
        if name == "panel_lock_off":
            return await r.set_panel_lockout(False)
        if name == "power_on":
            return await r.set_power_status(True)
        if name == "power_off":
            return await r.set_power_status(False)
        if name == "set_wait_time":
            minutes = int(kwargs.get("wait_time", kwargs.get("minutes", 0)) or 0)
            return await r.set_wait_time(minutes)
        raise ValueError("unsupported_action")

    # ------------------------------------------------------------------
    # Settings
    # ------------------------------------------------------------------
    def get_settings(self) -> dict[str, Any]:
        """Return the current device settings subset the app manages."""
        s = self._state
        return {
            "night_light": bool(s.get("night_light")),
            "panel_lock": bool(s.get("panel_lock")),
            "wait_time": self._current_wait_time(),
            "sleep": s.get("sleep_schedule"),
            "sleeping": bool(s.get("sleeping")),
        }

    def _current_wait_time(self) -> int | None:
        if self.mock:
            return self._mock.wait_time
        if self._robot is not None:
            wt = getattr(self._robot, "clean_cycle_wait_time_minutes", None)
            try:
                return int(wt) if wt is not None else None
            except (TypeError, ValueError):
                return None
        return None

    async def set_settings(self, payload: dict[str, Any]) -> dict[str, Any]:
        """Apply a settings patch. Only present keys are changed."""
        payload = payload or {}
        if "night_light" in payload:
            await self.run_action(
                "night_light_on" if payload["night_light"] else "night_light_off"
            )
        if "panel_lock" in payload:
            await self.run_action(
                "panel_lock_on" if payload["panel_lock"] else "panel_lock_off"
            )
        if "wait_time" in payload and payload["wait_time"] is not None:
            await self.run_action("set_wait_time", wait_time=int(payload["wait_time"]))
        if "sleep" in payload and isinstance(payload["sleep"], dict):
            enabled = bool(payload["sleep"].get("enabled"))
            await self.run_action("sleep_on" if enabled else "sleep_off")
        return self.get_settings()


# ---------------------------------------------------------------------------
# Mock device -- realistic, self-contained, no pylitterbot.
# ---------------------------------------------------------------------------
class _MockState:
    """A canned LR4 that cycles ready->cleaning->ready over successive
    refreshes, slowly fills the drawer, slowly empties the litter, and keeps a
    steady ~9.2 lb cat weight while incrementing the cycle counter."""

    def __init__(self) -> None:
        self.tick = 0
        self.status_code = "RDY"
        self.drawer_level = 12.0     # percent full
        self.litter_level = 88.0     # percent remaining
        self.cat_weight = 9.2
        self.cycle_count = 3
        self.cycles_total = 214
        self.sleeping = False
        self.night_light = True
        self.panel_lock = False
        self.wait_time = 7
        self.online = True

    def device(self) -> dict[str, Any]:
        return {
            "id": "mock-lr4-0001",
            "name": "Alfred (mock)",
            "model": "Litter-Robot 4",
            "serial": "LR4MOCK0001",
        }

    def advance(self) -> None:
        """Advance the simulation one refresh tick."""
        if not self.online:
            self.status_code = "OFFLINE"
            return
        self.tick += 1
        # Cycle status: two ticks cleaning every ~5 ticks, else ready.
        phase = self.tick % 5
        if self.sleeping:
            self.status_code = "RDY"  # sleeping is a cross-cutting flag
        elif phase == 1:
            self.status_code = "CCP"  # cleaning
        elif phase == 2:
            self.status_code = "CCP"
            self.cycle_count += 1
            self.cycles_total += 1
            self.drawer_level = min(100.0, self.drawer_level + 1.3)
            self.litter_level = max(0.0, self.litter_level - 0.7)
        else:
            self.status_code = "RDY"
        # Small weight jitter so the UI shows life without being noisy.
        self.cat_weight = round(9.2 + 0.1 * ((self.tick % 3) - 1), 2)

    def apply_action(self, name: str, **kwargs: Any) -> None:
        if name == "clean":
            self.status_code = "CCP"
            self.cycle_count += 1
            self.cycles_total += 1
            self.drawer_level = min(100.0, self.drawer_level + 1.3)
        elif name in ("empty", "reset_drawer"):
            self.drawer_level = 0.0
            self.cycle_count = 0
            self.status_code = "RDY"
        elif name == "sleep_on":
            self.sleeping = True
        elif name == "sleep_off":
            self.sleeping = False
        elif name == "night_light_on":
            self.night_light = True
        elif name == "night_light_off":
            self.night_light = False
        elif name == "panel_lock_on":
            self.panel_lock = True
        elif name == "panel_lock_off":
            self.panel_lock = False
        elif name == "power_on":
            self.online = True
            self.status_code = "RDY"
        elif name == "power_off":
            self.online = False
            self.status_code = "OFFLINE"
        elif name == "set_wait_time":
            self.wait_time = int(kwargs.get("wait_time", kwargs.get("minutes", self.wait_time)) or self.wait_time)

    def as_source(self) -> dict[str, Any]:
        """Shape matching the attribute names normalize() reads."""
        return {
            "name": self.device()["name"],
            "status_code": self.status_code,
            "is_sleeping": self.sleeping,
            "is_online": self.online,
            "is_waste_drawer_full": self.drawer_level >= 99.0,
            "waste_drawer_level": self.drawer_level,
            "litter_level": self.litter_level,
            "pet_weight": self.cat_weight,
            "cycle_count": self.cycle_count,
            "cycles_total": self.cycles_total,
            "night_light_mode_enabled": self.night_light,
            "panel_lock_enabled": self.panel_lock,
            "sleep_schedule": {
                "enabled": self.sleeping,
                "start_time": "22:00",
                "end_time": "06:00",
            } if self.sleeping else None,
            "rssi": -52,
            "wifi_ssid": "mock-wifi",
        }


# ---------------------------------------------------------------------------
# Allowed action set (the contract; PHP rejects anything not here too).
# ---------------------------------------------------------------------------
ALLOWED_ACTIONS: frozenset[str] = frozenset(
    {
        "clean",
        "empty",
        "reset_drawer",
        "sleep_on",
        "sleep_off",
        "night_light_on",
        "night_light_off",
        "panel_lock_on",
        "panel_lock_off",
        "power_on",
        "power_off",
        "set_wait_time",
    }
)


# Fields that change every tick and must NOT by themselves trigger an SSE push.
_VOLATILE_KEYS = {"updated_at", "bridge"}


def _significant_change(prev: dict[str, Any], cur: dict[str, Any]) -> bool:
    if not prev:
        return True

    def _stripped(d: dict[str, Any]) -> dict[str, Any]:
        return {k: v for k, v in d.items() if k not in _VOLATILE_KEYS}

    return _stripped(prev) != _stripped(cur)
