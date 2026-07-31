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
from datetime import datetime, timezone
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
        # Freshness bookkeeping. ``updated_at`` is stamped on every *read*, so it
        # can never detect staleness on its own; these track the last *successful
        # upstream poll* instead. After MAX_POLL_FAILURES consecutive failures we
        # stop claiming to be connected, so a dead Whisker cloud can no longer
        # present hours-old numbers as live.
        self.last_poll_ok_at: str | None = None
        self.poll_error: str | None = None
        self._poll_failures: int = 0

        # In-memory normalized DTO + change notification.
        self._state: dict[str, Any] = {}
        self._subscribers: set[Callable[[dict[str, Any]], None]] = set()
        self._lock = asyncio.Lock()
        self._refresh_task: asyncio.Task | None = None
        # Post-write convergence polls (see _schedule_converge_polls). Held so
        # they are not garbage-collected mid-flight and are cancelled on stop().
        self._converge_tasks: set[asyncio.Task] = set()

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
        for task in list(self._converge_tasks):
            task.cancel()
        self._converge_tasks.clear()
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
                self._note_poll_failure(f"refresh_failed: {_exc_text(exc)}")
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
        self._note_poll_success()
        self._set_state(normalizer.normalize(self._robot, self._meta()))

    def _note_poll_success(self) -> None:
        self.error = None
        self.poll_error = None
        self._poll_failures = 0
        self.last_poll_ok_at = _now_iso()
        self.connected = True

    def _note_poll_failure(self, message: str) -> None:
        self.error = message
        self.poll_error = message
        self._poll_failures += 1
        if self._poll_failures >= MAX_POLL_FAILURES:
            self.connected = False
        # Re-stamp the DTO so /state carries the failure without waiting for the
        # next successful poll (which may never come).
        if self._state:
            self._set_state({**self._state, **self._freshness_meta()})

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
            **self._freshness_meta(),
        }

    def _freshness_meta(self) -> dict[str, Any]:
        return {
            "last_poll_ok_at": self.last_poll_ok_at,
            "poll_error": self.poll_error,
            "connected": self.connected,
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
        self.poll_error = None
        self.last_poll_ok_at = _now_iso()
        self._poll_failures = 0
        self._set_state(normalizer.normalize(self._mock.as_source(), self._meta()))

    def get_state(self) -> dict[str, Any]:
        # Refresh bridge-side bookkeeping (uptime) on read without renotifying.
        # ``updated_at`` is a *read* stamp and must never be mistaken for
        # freshness -- ``last_poll_ok_at`` / ``poll_error`` carry that, and they
        # are re-projected here so a read always reflects current poll health.
        self._state = {**self._state, "updated_at": _now_iso(), **self._freshness_meta()}
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
    # pylitterbot method mapping (verified against 2025.6.2 by introspecting the
    # installed LitterRobot4 class -- not assumed):
    #   clean                 -> robot.start_cleaning()
    #   reset                 -> robot.reset()            [see note below]
    #   night_light_on/off    -> robot.set_night_light(True/False)
    #   panel_lock_on/off     -> robot.set_panel_lockout(True/False)
    #   power_on/off          -> robot.set_power_status(True/False)
    #   set_wait_time (n)     -> robot.set_wait_time(n), n in VALID_WAIT_TIMES
    #
    # NOTE (reset): ``LitterRobot4.reset()`` dispatches SHORT_RESET_PRESS, whose
    # own docstring reads "Clears errors and may trigger a cycle. Make sure the
    # globe is clear before proceeding." It does NOT empty the waste drawer and
    # does NOT reset the drawer-full cycle counter -- emptying is a manual job.
    # An earlier revision exposed this as both ``empty`` and ``reset_drawer``,
    # two names for one command that did neither thing. ``empty`` is kept only as
    # a deprecated alias so existing callers do not break.
    #
    # NOTE (sleep): there is deliberately NO sleep action.
    # ``LitterRobot4.set_sleep_mode`` is ``raise NotImplementedError()`` and
    # ``LitterRobot4Command`` has no sleep verb, so the library offers no write
    # path at all. The sleep *window* is still read and surfaced
    # (``sleep_schedule``); it is changed in the Whisker app. Advertising a
    # control we cannot honour produced a 502 with an empty error message.
    RESET_ALIASES = ("reset", "empty", "reset_drawer")

    async def run_action(self, name: str, **kwargs: Any) -> dict[str, Any]:
        name = (name or "").strip().lower()
        if name not in ALLOWED_ACTIONS:
            return {"ok": False, "result": {}, "error": "unsupported_action"}

        # Validate before touching the device so a bad request is a clean 400
        # rather than an upstream exception (or, worse, a silent default of 0).
        invalid = self._validate_action(name, kwargs)
        if invalid is not None:
            return {"ok": False, "result": {"action": name}, "error": invalid}

        if self.mock:
            self._mock.apply_action(name, **kwargs)
            self._recompute_from_mock()
            return {"ok": True, "result": {"action": name, "mock": True}, "error": None}

        if self._robot is None:
            return {"ok": False, "result": {}, "error": "not_connected"}

        try:
            result = await self._dispatch_live(name, **kwargs)
        except Exception as exc:  # noqa: BLE001
            # NotImplementedError and friends stringify to '', which surfaced to
            # operators as a failure with no reason at all.
            return {"ok": False, "result": {"action": name}, "error": _exc_text(exc)}

        # Converge on the new state instead of polling once, immediately.
        #
        # Measured on the real unit: the Whisker cloud takes *tens of seconds* to
        # report a write back. A single refresh fired right after the command
        # reliably captured the pre-change value, so the UI showed the old state
        # and the command looked like it had failed (night-light on/off appeared
        # inert for ~40s, then flipped on its own). ``toggle_hopper``'s own
        # docstring in pylitterbot notes the same ~5s+ lag.
        with contextlib.suppress(Exception):
            await self._refresh_once()
        self._schedule_converge_polls()
        return {"ok": True, "result": {"action": name, "returned": result}, "error": None}

    def _schedule_converge_polls(self) -> None:
        """Re-poll a few times after a write so the UI converges on the truth.

        Fire-and-forget; each poll pushes an SSE frame if anything changed.
        """
        if self.mock:
            return

        async def _converge() -> None:
            for delay in CONVERGE_POLL_DELAYS_S:
                await asyncio.sleep(delay)
                with contextlib.suppress(Exception):
                    await self._refresh_once()

        task = asyncio.create_task(_converge())
        self._converge_tasks.add(task)
        task.add_done_callback(self._converge_tasks.discard)

    def valid_wait_times(self) -> list[int]:
        """The wait times this device accepts (the device rejects all others)."""
        candidate = getattr(self._robot, "VALID_WAIT_TIMES", None) if self._robot else None
        if not candidate:
            candidate = DEFAULT_VALID_WAIT_TIMES
        return [int(w) for w in candidate]

    def _validate_action(self, name: str, kwargs: dict[str, Any]) -> str | None:
        """Return an error slug when the request is not answerable, else None."""
        if name != "set_wait_time":
            return None
        raw = kwargs.get("wait_time", kwargs.get("minutes"))
        if raw is None or raw == "":
            return "wait_time_required"
        try:
            minutes = int(raw)
        except (TypeError, ValueError):
            return "wait_time_not_a_number"
        allowed = self.valid_wait_times()
        if minutes not in allowed:
            return "wait_time_invalid: must be one of " + ",".join(
                str(w) for w in allowed
            )
        return None

    async def _dispatch_live(self, name: str, **kwargs: Any) -> Any:
        r = self._robot
        if name == "clean":
            return await r.start_cleaning()
        if name in self.RESET_ALIASES:
            return await r.reset()  # clears errors, may spin the globe (see NOTE)
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
            # Already validated against VALID_WAIT_TIMES by _validate_action.
            minutes = int(kwargs.get("wait_time", kwargs.get("minutes")))
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
            # The set the device will accept, so the UI can offer exactly those
            # and nothing else (it rejects anything outside them, e.g. 5).
            "wait_time_values": self.valid_wait_times(),
            "sleep": s.get("sleep_schedule"),
            "sleeping": bool(s.get("sleeping")),
            # LR4 sleep is readable but not writable through pylitterbot.
            "sleep_writable": False,
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
        """Apply a settings patch. Only present keys are changed.

        Returns ``{ok, settings, errors}``. Every per-key outcome is collected
        and reported: an earlier revision discarded the ``run_action`` results
        and the HTTP layer hard-coded ``ok: True``, so the app told operators
        "Saved" for writes that had actually failed (and, for sleep, could
        never succeed).
        """
        payload = payload or {}
        errors: dict[str, str] = {}

        async def _apply(key: str, action: str, **kwargs: Any) -> None:
            outcome = await self.run_action(action, **kwargs)
            if not outcome.get("ok"):
                errors[key] = str(outcome.get("error") or "action_failed")

        if "night_light" in payload:
            await _apply(
                "night_light",
                "night_light_on" if payload["night_light"] else "night_light_off",
            )
        if "panel_lock" in payload:
            await _apply(
                "panel_lock",
                "panel_lock_on" if payload["panel_lock"] else "panel_lock_off",
            )
        if "wait_time" in payload and payload["wait_time"] is not None:
            await _apply("wait_time", "set_wait_time", wait_time=payload["wait_time"])
        if "sleep" in payload and isinstance(payload["sleep"], dict):
            # Read-only on an LR4 (see the sleep NOTE above). Say so plainly
            # instead of dispatching a command that cannot exist.
            errors["sleep"] = (
                "sleep_read_only: the LR4 sleep schedule can only be changed in "
                "the Whisker app"
            )

        return {"ok": not errors, "settings": self.get_settings(), "errors": errors}


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
        self.cycles_since_full = 3
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
            self.cycles_since_full += 1
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
            self.cycles_since_full += 1
            self.drawer_level = min(100.0, self.drawer_level + 1.3)
        elif name in ("reset", "empty", "reset_drawer"):
            # Mirrors the real command: clears the fault/status, does NOT empty
            # the drawer and does NOT reset any counter. The mock used to zero
            # the drawer and the cycle count, which made the mock pass tests the
            # real device would fail.
            self.status_code = "RDY"
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
        """Shape matching the attribute names normalize() reads.

        Kept deliberately close to the real ``LitterRobot4`` property surface --
        no ``rssi``/``wifi_ssid`` (the device has neither), and
        ``cycles_after_drawer_full`` present because the real one has it.
        """
        return {
            "name": self.device()["name"],
            "status_code": self.status_code,
            "is_sleeping": self.sleeping,
            "is_online": self.online,
            "is_on": self.online,
            "is_waste_drawer_full": self.drawer_level >= 99.0,
            "waste_drawer_level": self.drawer_level,
            "litter_level": self.litter_level,
            "litter_level_state": "OPTIMAL" if self.litter_level > 20 else "LOW",
            "pet_weight": self.cat_weight,
            "cycle_count": self.cycle_count,
            "cycles_total": self.cycles_total,
            "cycles_after_drawer_full": self.cycles_since_full,
            "cycle_capacity": 46,
            "scoops_saved_count": self.cycles_total * 3,
            "clean_cycle_wait_time_minutes": self.wait_time,
            "night_light_mode_enabled": self.night_light,
            "night_light_mode": "ON" if self.night_light else "OFF",
            "night_light_brightness": 128 if self.night_light else 0,
            "panel_lock_enabled": self.panel_lock,
            "panel_brightness": "MEDIUM",
            "power_type": "AC",
            "hopper_status": None,
            "is_hopper_removed": False,
            "wifi_mode_status": "ROUTER_CONNECTED" if self.online else "OFFLINE",
            "last_seen": _now_iso(),
            "sleep_schedule": {
                "enabled": self.sleeping,
                "start_time": "22:00",
                "end_time": "06:00",
                "writable": False,
            } if self.sleeping else None,
            "VALID_WAIT_TIMES": list(DEFAULT_VALID_WAIT_TIMES),
        }


# ---------------------------------------------------------------------------
# Allowed action set (the contract; PHP rejects anything not here too).
#
# Every entry here is backed by a pylitterbot method that is actually
# implemented -- verified by introspecting LitterRobot4 rather than reading the
# docs. ``sleep_on``/``sleep_off`` used to be listed and were removed: the
# library raises NotImplementedError for LR4 sleep, so they were guaranteed
# 502s. ``reset`` is the honest name for the short-reset-press command;
# ``empty``/``reset_drawer`` remain as deprecated aliases for the same thing.
# ---------------------------------------------------------------------------
ALLOWED_ACTIONS: frozenset[str] = frozenset(
    {
        "clean",
        "reset",
        "empty",          # deprecated alias of reset
        "reset_drawer",   # deprecated alias of reset
        "night_light_on",
        "night_light_off",
        "panel_lock_on",
        "panel_lock_off",
        "power_on",
        "power_off",
        "set_wait_time",
    }
)

# Wait times an LR4 accepts. Read off the bound robot when available (see
# LitterManager.valid_wait_times); this is only the offline default. Note there
# is no 5 -- the device rejects it outright.
DEFAULT_VALID_WAIT_TIMES: tuple[int, ...] = (3, 7, 15, 25, 30)

# Consecutive failed polls before the bridge stops reporting itself connected.
MAX_POLL_FAILURES = 3

# Delays (seconds, cumulative) for the re-polls fired after a write. The Whisker
# cloud does not reflect a command immediately -- measured at well over 30s on a
# real LR4 for a night-light toggle -- so one eager refresh is worse than none.
CONVERGE_POLL_DELAYS_S: tuple[float, ...] = (5.0, 10.0, 20.0)


def _exc_text(exc: BaseException) -> str:
    """Never return an empty error string.

    ``str(NotImplementedError())`` is ``''``, which reached operators as a
    failed command with no reason given. Fall back to the exception class name.
    """
    return str(exc) or exc.__class__.__name__


# Fields that change every tick and must NOT by themselves trigger an SSE push.
_VOLATILE_KEYS = {"updated_at", "bridge", "last_poll_ok_at"}


def _significant_change(prev: dict[str, Any], cur: dict[str, Any]) -> bool:
    if not prev:
        return True

    def _stripped(d: dict[str, Any]) -> dict[str, Any]:
        return {k: v for k, v in d.items() if k not in _VOLATILE_KEYS}

    return _stripped(prev) != _stripped(cur)
