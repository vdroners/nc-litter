"""nc-litter-bridge -- HTTP/SSE front end for one Whisker Litter-Robot 4.

Reachable only from the ``nc-litter-net`` Docker network (the compose file
publishes no public host port -- optionally a loopback debug port). The
Nextcloud PHP app (``BridgeClient``) proxies every call, so the browser never
talks to the Whisker cloud or this process directly. There is no auth on the
bridge itself; Docker-network isolation + the PHP layer handle access.

Routes and JSON envelope shapes are fixed by ``BridgeClient``'s expectations
(``/health``, ``/state``, ``/stream``, ``/action/{name}``, ``/settings``), so the
PHP side needs no per-backend branching.

Env:
  PORT=8080                bind port
  LITTER_MOCK=1            mock mode (default) -- no pylitterbot import/connect
  WHISKER_EMAIL / WHISKER_PASSWORD   Whisker cloud creds (live mode)
  LITTER_DEVICE_ID         which LR4 to bind (id or serial; blank = first)
  LITTER_REFRESH_S=30      cloud poll cadence
"""

from __future__ import annotations

import asyncio
import json
import os
from contextlib import asynccontextmanager
from typing import Any

from fastapi import FastAPI, Request, Response
from fastapi.responses import JSONResponse
from sse_starlette.sse import EventSourceResponse

from litter_manager import ALLOWED_ACTIONS, LitterManager

BRIDGE_VERSION = os.environ.get("BRIDGE_VERSION", "0.1.0")

manager = LitterManager(os.environ, version=BRIDGE_VERSION)


@asynccontextmanager
async def lifespan(app: FastAPI):
    await manager.start()
    try:
        yield
    finally:
        await manager.stop()


app = FastAPI(title="nc-litter-bridge", version=BRIDGE_VERSION, lifespan=lifespan)


# ---------------------------------------------------------------------------
# Health / state
# ---------------------------------------------------------------------------
@app.get("/health")
async def health() -> JSONResponse:
    return JSONResponse(manager.health())


@app.get("/state")
async def state() -> JSONResponse:
    return JSONResponse({"ok": True, "state": manager.get_state()})


# ---------------------------------------------------------------------------
# SSE stream
# ---------------------------------------------------------------------------
@app.get("/stream")
async def stream(request: Request) -> EventSourceResponse:
    """text/event-stream: ``event: state`` frames on change + 15s keepalive.

    A change pushed by the manager is delivered onto an asyncio queue that this
    per-connection generator drains; a 15s timeout emits a ``:keepalive``
    comment so intermediaries do not time the connection out.
    """
    queue: asyncio.Queue[dict[str, Any]] = asyncio.Queue()
    loop = asyncio.get_running_loop()

    def _on_change(dto: dict[str, Any]) -> None:
        loop.call_soon_threadsafe(queue.put_nowait, dto)

    unsubscribe = manager.subscribe(_on_change)

    async def _gen():
        try:
            # Prime with the current state so a fresh subscriber is never blank.
            yield {"event": "state", "data": json.dumps(manager.get_state())}
            while True:
                if await request.is_disconnected():
                    break
                try:
                    dto = await asyncio.wait_for(queue.get(), timeout=15.0)
                    yield {"event": "state", "data": json.dumps(dto)}
                except asyncio.TimeoutError:
                    # sse-starlette renders a comment-only ping from this.
                    yield {"comment": "keepalive"}
        finally:
            unsubscribe()

    return EventSourceResponse(
        _gen(),
        ping=15,  # library-level keepalive backstop
        headers={"Cache-Control": "no-cache, no-transform", "X-Accel-Buffering": "no"},
    )


# ---------------------------------------------------------------------------
# Actions
# ---------------------------------------------------------------------------
@app.post("/action/{name}")
async def action(name: str, request: Request) -> Response:
    if name.strip().lower() not in ALLOWED_ACTIONS:
        return JSONResponse(
            {"ok": False, "result": {}, "error": "unsupported_action"}, status_code=400
        )
    body = await _json_body(request)
    kwargs = {k: v for k, v in body.items() if k != "robot_id"}
    result = await manager.run_action(name, **kwargs)
    if result.get("ok"):
        return JSONResponse(result, status_code=200)
    # A rejected *request* is the caller's fault (400); a failure reaching or
    # commanding the device is upstream (502). Lumping both into 502 hid bad
    # input behind what looked like a cloud outage.
    error = str(result.get("error") or "")
    caller_fault = error.startswith(("wait_time_", "unsupported_action"))
    return JSONResponse(result, status_code=400 if caller_fault else 502)


# ---------------------------------------------------------------------------
# Settings
# ---------------------------------------------------------------------------
@app.get("/settings")
async def get_settings() -> JSONResponse:
    return JSONResponse({"ok": True, "settings": manager.get_settings()})


@app.post("/settings")
async def set_settings(request: Request) -> JSONResponse:
    """Apply a settings patch and report the truth about each key.

    ``manager.set_settings`` returns ``{ok, settings, errors}``; the response
    used to hard-code ``ok: True`` and drop ``errors`` on the floor, so the app
    showed "Saved" for writes that had failed. A partial failure is a 207 so a
    caller can tell "nothing applied" from "some keys applied".
    """
    body = await _json_body(request)
    result = await manager.set_settings(body)
    if result.get("ok"):
        return JSONResponse(result, status_code=200)
    applied_none = len(result.get("errors") or {}) >= len(
        [k for k in body if k in ("night_light", "panel_lock", "wait_time", "sleep")]
    )
    return JSONResponse(result, status_code=502 if applied_none else 207)


# ---------------------------------------------------------------------------
# Onboarding / connect
# ---------------------------------------------------------------------------
@app.post("/onboard/login")
async def onboard_login(request: Request) -> JSONResponse:
    body = await _json_body(request)
    email = str(body.get("email", ""))
    password = str(body.get("password", ""))
    if not email or not password:
        return JSONResponse(
            {"ok": False, "error": "missing_credentials"}, status_code=400
        )
    try:
        devices = await manager.login(email, password)
    except Exception as exc:  # noqa: BLE001
        return JSONResponse({"ok": False, "error": str(exc)}, status_code=502)
    return JSONResponse({"ok": True, "devices": devices})


@app.post("/connect")
async def connect(request: Request) -> JSONResponse:
    body = await _json_body(request)
    email = body.get("email") or None
    password = body.get("password") or None
    device_id = body.get("device_id") or None
    health = await manager.connect(email, password, device_id)
    status = 200 if (health.get("connected") or health.get("mock")) else 202
    return JSONResponse({"ok": True, **health}, status_code=status)


# ---------------------------------------------------------------------------
# Helpers / fallthrough
# ---------------------------------------------------------------------------
async def _json_body(request: Request) -> dict[str, Any]:
    try:
        data = await request.json()
    except Exception:  # noqa: BLE001 - empty or malformed body
        return {}
    return data if isinstance(data, dict) else {}


if __name__ == "__main__":  # pragma: no cover - manual/dev entry point
    import uvicorn

    uvicorn.run(
        "app:app",
        host="0.0.0.0",
        port=int(os.environ.get("PORT", "8080")),
        log_level="info",
    )
