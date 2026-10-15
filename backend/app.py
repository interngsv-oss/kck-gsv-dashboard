"""
KCK Sales Dashboard backend.

Run with:
    uvicorn app:app --host 0.0.0.0 --port 8000

Endpoints:
    GET  /api/meta
    GET  /api/{dataset}?branch=&from=&to=      dataset: bills | sales | discounts | cancellations
    POST /api/{dataset}                         body: one row (dict) -> create (409 if key exists)
    PUT  /api/{dataset}                         body: one row (dict) -> update (404 if key missing)
    DELETE /api/{dataset}                       body: {key fields only} -> delete (404 if missing)
    POST /api/upload                            multipart upload of a whole "Posist Report" folder
                                                 (e.g. from a <input webkitdirectory> picker) -
                                                 -> parses it and upserts bills/sales/discounts
    GET  /                                       the dashboard itself (kck_sales_dashboard_V11.html)
"""

import base64
import hashlib
import hmac
import json
import logging
import os
import secrets
import time
import shutil
import tempfile
import traceback
import re
import zipfile
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

from fastapi import Depends, FastAPI, HTTPException, Request, UploadFile, File
from fastapi.responses import FileResponse, JSONResponse, PlainTextResponse
from fastapi.staticfiles import StaticFiles

import parsers

# storage.py's JSON-file store doesn't survive a redeploy/restart on a host
# with no persistent disk (e.g. Render's free tier) - when DATABASE_URL is
# set (production), use the Postgres-backed storage_db.py instead, which has
# the exact same function signatures. Local development is untouched: with
# no DATABASE_URL, this keeps using the original file-based storage.py.
if os.environ.get("DATABASE_URL"):
    import storage_db as storage
else:
    import storage

# A copy of the dashboard HTML lives right next to this file so it deploys
# as part of this repo (the original also still lives one level up from
# kck_dashboard_pipeline/, for local editing convenience) - prefer the local
# copy when both exist, since that's the one that actually ships.
_HERE = os.path.dirname(__file__)
DASHBOARD_HTML_PATH = os.path.join(_HERE, "kck_sales_dashboard_V11.html")
LOGIN_HTML_PATH = os.path.join(_HERE, "login.html")
STATIC_DIR = os.path.join(_HERE, "static")

# No docs/openapi pages (they'd list every route to an anonymous visitor), and
# no CORS middleware: the dashboard and its API are same-origin, and a wildcard
# CORS policy would only let other sites' scripts talk to the API.
app = FastAPI(title="KCK Sales Dashboard API", docs_url=None, redoc_url=None, openapi_url=None)
app.mount("/static", StaticFiles(directory=STATIC_DIR), name="static")
audit_log = logging.getLogger("kck.audit")
audit_log.setLevel(logging.INFO)
if not audit_log.handlers:
    _h = logging.StreamHandler()
    _h.setFormatter(logging.Formatter("%(asctime)s AUDIT %(message)s"))
    audit_log.addHandler(_h)


# Real server-side sign-in. POST /api/login checks the email/password HERE (no
# credentials ever ship to the browser) and sets an HttpOnly, Secure,
# SameSite=Strict cookie holding a signed, expiring session token. "/" only
# serves the dashboard when that cookie is valid (otherwise just the login
# page), and every /api/ route below requires it. The passwords are real env
# vars on the server (Render > this service > Environment), never in the repo.
ADMIN_EMAIL = os.environ.get("ADMIN_EMAIL", "admin@gsvassociates.com").strip().lower()
VIEWER_EMAIL = os.environ.get("VIEWER_EMAIL", "user@gsvassociates.com").strip().lower()
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD")
VIEWER_PASSWORD = os.environ.get("VIEWER_PASSWORD")

COOKIE_NAME = "kck_session"
SESSION_TTL_SECONDS = 8 * 60 * 60          # short-lived: sign in again after 8 hours
MAX_FAILED_LOGINS = 5                       # per email AND per client IP...
LOCKOUT_SECONDS = 15 * 60                   # ...then locked out for this long
MAX_UPLOAD_BYTES = 200 * 1024 * 1024        # per request body
MAX_UNZIPPED_BYTES = 1024 * 1024 * 1024     # zip-bomb guard
ALLOWED_UPLOAD_EXTENSIONS = (".xlsx", ".xls", ".zip")


def _session_key() -> bytes:
    # SESSION_SECRET is preferred; otherwise derive from the two passwords, so
    # changing a password also invalidates every existing session.
    secret = os.environ.get("SESSION_SECRET") or f"{ADMIN_PASSWORD}|{VIEWER_PASSWORD}"
    return hashlib.sha256(("kck-session|" + secret).encode()).digest()


def _b64(b: bytes) -> str:
    return base64.urlsafe_b64encode(b).decode().rstrip("=")


def _unb64(s: str) -> bytes:
    return base64.urlsafe_b64decode(s + "=" * (-len(s) % 4))


_revoked_sessions: Dict[str, float] = {}    # jti -> expiry; logout adds here
_failed_logins: Dict[str, List[float]] = {}  # "ip:..."/"email:..." -> failure times


def _make_session(role: str, email: str) -> str:
    payload = _b64(json.dumps({"r": role, "e": email, "x": int(time.time()) + SESSION_TTL_SECONDS,
                               "j": secrets.token_hex(8)}).encode())
    sig = _b64(hmac.new(_session_key(), payload.encode(), hashlib.sha256).digest())
    return f"{payload}.{sig}"


def _read_session(token: Optional[str]) -> Optional[Dict[str, Any]]:
    if not token or "." not in token or not ADMIN_PASSWORD or not VIEWER_PASSWORD:
        return None
    payload, _, sig = token.partition(".")
    expected = _b64(hmac.new(_session_key(), payload.encode(), hashlib.sha256).digest())
    if not hmac.compare_digest(sig, expected):
        return None
    try:
        data = json.loads(_unb64(payload))
    except ValueError:
        return None
    now = time.time()
    if data.get("x", 0) < now or data.get("j") in _revoked_sessions:
        return None
    for jti in [j for j, exp in _revoked_sessions.items() if exp < now]:
        del _revoked_sessions[jti]
    return data


def _client_ip(request: Request) -> str:
    # Render puts the real client first in X-Forwarded-For.
    fwd = request.headers.get("x-forwarded-for")
    return fwd.split(",")[0].strip() if fwd else (request.client.host if request.client else "?")


def _lockout_remaining(keys: List[str]) -> int:
    now = time.time()
    worst = 0
    for k in keys:
        recent = [t for t in _failed_logins.get(k, []) if t > now - LOCKOUT_SECONDS]
        _failed_logins[k] = recent
        if len(recent) >= MAX_FAILED_LOGINS:
            worst = max(worst, int(recent[0] + LOCKOUT_SECONDS - now))
    return worst


def current_session(request: Request) -> Optional[Dict[str, Any]]:
    return _read_session(request.cookies.get(COOKIE_NAME))


def require_viewer(request: Request) -> str:
    """Accepts either role; returns which one the session has. Used as the
    Depends() for every read endpoint - both admin and viewer can view data."""
    if not ADMIN_PASSWORD or not VIEWER_PASSWORD:
        raise HTTPException(503, "Server auth isn't configured - set ADMIN_PASSWORD and VIEWER_PASSWORD "
                                  "in the server's environment variables.")
    session = current_session(request)
    if not session:
        raise HTTPException(401, "Not authenticated")
    request.state.actor = f"{session['e']} ({session['r']}) from {_client_ip(request)}"
    return session["r"]


def require_admin(request: Request, role: str = Depends(require_viewer)) -> str:
    """Used as the Depends() for every data-mutating endpoint (upload, edit,
    delete, the one-time migrate-* fix-ups) - viewer sessions authenticate
    but aren't allowed to change anything. Every admin action is written to
    the server log (who, what, when) as an audit trail."""
    if role != "admin":
        raise HTTPException(403, "This action requires an admin account.")
    audit_log.info("ADMIN ACTION %s %s by %s", request.method, request.url.path, request.state.actor)
    return role


@app.middleware("http")
async def no_store_api_responses(request, call_next):
    # Every /api/* response reflects live, frequently-changing data (bills,
    # meta, upload history, ...) - without an explicit no-store, a browser
    # can still serve a cached copy of a GET response instead of re-fetching
    # it, which would show stale data (e.g. "Data Available" missing a
    # just-uploaded month) even on a route that itself works correctly.
    response = await call_next(request)
    if request.url.path.startswith("/api/"):
        response.headers["Cache-Control"] = "no-store"
    response.headers["X-Robots-Tag"] = "noindex, nofollow"
    response.headers["X-Frame-Options"] = "DENY"
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Referrer-Policy"] = "no-referrer"
    response.headers["Strict-Transport-Security"] = "max-age=31536000; includeSubDomains"
    # Only the pages' own inline script/styles and the three cdnjs libraries
    # may run; nothing can frame the page.
    response.headers["Content-Security-Policy"] = (
        "default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; "
        "worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'"
    )
    return response


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    # Without this, an unhandled exception (e.g. a Posist export with a
    # layout the parser doesn't expect) falls through to Starlette's default
    # 500 response, which is a PLAIN TEXT "Internal Server Error" body, not
    # JSON. The frontend's `await res.json()` then throws its own confusing
    # "Unexpected token 'I', "Internal S"... is not valid JSON" error,
    # burying the real cause.
    #
    # The real exception (with file paths, class names, etc.) is only ever
    # printed to the server's own log (visible in Render's logs) - it must
    # NOT be put in the response body, since that's sent straight to
    # whoever's browser made the request and would leak internals of this
    # server to them. The client just gets a generic message plus enough of
    # a fix (retry) to be useful.
    traceback.print_exc()
    return JSONResponse(
        status_code=500,
        content={"detail": "Something went wrong processing that upload. Please try again or contact support."},
    )

EDITABLE_DATASETS = ("bills", "sales", "discounts", "cancellations")


def _require_dataset(dataset: str):
    if dataset not in EDITABLE_DATASETS:
        raise HTTPException(400, f"Unknown dataset '{dataset}'. Must be one of {EDITABLE_DATASETS}.")


def _require_key_fields(dataset: str, row: Dict[str, Any]):
    missing = [f for f in storage.KEY_FIELDS[dataset] if not row.get(f)]
    if missing:
        raise HTTPException(400, f"Missing required field(s) for '{dataset}': {missing}")


@app.api_route("/", methods=["GET", "HEAD"])
def serve_dashboard(request: Request):
    # The server, not the browser, decides what an anonymous visitor gets:
    # without a valid session they receive ONLY the small login page - none of
    # the dashboard's markup, report names or admin controls. No-store so
    # every deploy is visible immediately and the dashboard is never cached.
    path = DASHBOARD_HTML_PATH if current_session(request) else LOGIN_HTML_PATH
    return FileResponse(path, headers={"Cache-Control": "no-store"})


@app.get("/robots.txt")
def robots_txt():
    return PlainTextResponse("User-agent: *\nDisallow: /\n")


@app.api_route("/healthz", methods=["GET", "HEAD"])
def healthz():
    """Public, data-free liveness check for the keep-alive workflow (it still
    touches the database so Neon stays warm too)."""
    storage.read_meta()
    return {"ok": True}


@app.post("/api/login")
async def login(request: Request):
    if not ADMIN_PASSWORD or not VIEWER_PASSWORD:
        raise HTTPException(503, "Server auth isn't configured.")
    try:
        body = await request.json()
    except ValueError:
        body = None
    if not isinstance(body, dict):
        raise HTTPException(400, "Invalid request.")
    email = str(body.get("email", "")).strip().lower()
    password = str(body.get("password", ""))
    keys = [f"ip:{_client_ip(request)}", f"email:{email}"]
    wait = _lockout_remaining(keys)
    if wait:
        raise HTTPException(429, f"Too many failed attempts. Try again in {wait // 60 + 1} minute(s).")
    is_admin = secrets.compare_digest(email, ADMIN_EMAIL) & secrets.compare_digest(password, ADMIN_PASSWORD)
    is_viewer = secrets.compare_digest(email, VIEWER_EMAIL) & secrets.compare_digest(password, VIEWER_PASSWORD)
    if not (is_admin or is_viewer):
        now = time.time()
        for k in keys:
            _failed_logins.setdefault(k, []).append(now)
        audit_log.warning("FAILED LOGIN for %r from %s", email, _client_ip(request))
        # Same generic message for a wrong email and a wrong password.
        raise HTTPException(401, "Invalid email or password.")
    for k in keys:
        _failed_logins.pop(k, None)
    role = "admin" if is_admin else "viewer"
    audit_log.info("LOGIN %s (%s) from %s", email, role, _client_ip(request))
    response = JSONResponse({"role": role})
    response.set_cookie(COOKIE_NAME, _make_session(role, email), max_age=SESSION_TTL_SECONDS,
                        httponly=True, secure=request.url.hostname not in ("localhost", "127.0.0.1"),
                        samesite="strict", path="/")
    return response


@app.post("/api/logout")
def logout(request: Request):
    session = current_session(request)
    if session:
        _revoked_sessions[session["j"]] = session["x"]   # dead server-side, not just cleared in the browser
        audit_log.info("LOGOUT %s", session["e"])
    response = JSONResponse({"ok": True})
    response.delete_cookie(COOKIE_NAME, path="/")
    return response


@app.get("/api/me")
def me(role: str = Depends(require_viewer)):
    return {"role": role}


@app.api_route("/api/meta", methods=["GET", "HEAD"])
def get_meta(role: str = Depends(require_viewer)):
    # HEAD too - uptime-monitor pings (UptimeRobot etc.) default to HEAD
    # requests, which a GET-only route rejects with 405.
    return storage.read_meta()


@app.get("/api/upload-history")
def get_upload_history(role: str = Depends(require_admin)):
    """Most-recent-first log of every /api/upload call: when it happened,
    which files were sent, and how many rows landed in each month/dataset.
    Admin-only, same as the UI section that shows it. NOTE: like /api/upload
    below, this must stay registered before GET /api/{dataset} - Starlette
    matches routes in registration order, and "/api/upload-history" would
    otherwise match "/api/{dataset}" first (with dataset="upload-history")
    and 400 as an unknown dataset."""
    return list(reversed(storage.read_upload_history()))


@app.get("/api/{dataset}")
def list_rows(dataset: str, branch: Optional[str] = None,
              from_: Optional[str] = None, to: Optional[str] = None,
              role: str = Depends(require_viewer)):
    _require_dataset(dataset)
    rows = storage.read_range(dataset, from_month=from_[:7] if from_ else None,
                               to_month=to[:7] if to else None)
    if from_:
        rows = [r for r in rows if r["date"] >= from_]
    if to:
        rows = [r for r in rows if r["date"] <= to]
    if branch:
        rows = [r for r in rows if r.get("branch") == branch]
    return rows


def _safe_relpath(filename: str) -> str:
    """A browser folder-picker sends each file's name as its path relative to
    the selected folder (e.g. 'Posist Report/Payment Report/Bangalore.xlsx').
    Strip any '.'/'..'/empty segments so a crafted filename can't write outside
    the temp extraction directory."""
    parts = [p for p in filename.replace("\\", "/").split("/") if p not in ("", ".", "..")]
    if not parts:
        raise HTTPException(400, f"Invalid file name: {filename!r}")
    return os.path.join(*parts)


IGNORED_DIR_PREFIXES = ("__MACOSX", ".")


def _find_posist_root(extract_dir, max_depth=4):
    """A folder picker's selected folder (or an uploaded zip of it) is usually
    named something like 'Posist Report' and wraps the report subfolders one
    level down, sometimes more when a zip re-wraps it or adds junk folders
    (e.g. macOS's '__MACOSX') - search down a few levels for the first folder
    that actually contains 'Payment Report', ignoring junk folders."""
    def search(d, depth):
        if os.path.isdir(parsers.find_dir_ci(d, "Payment Report")):
            return d
        if depth <= 0:
            return None
        try:
            subdirs = [e for e in os.listdir(d)
                       if os.path.isdir(os.path.join(d, e)) and not e.startswith(IGNORED_DIR_PREFIXES)]
        except FileNotFoundError:
            return None
        for sub in subdirs:
            found = search(os.path.join(d, sub), depth - 1)
            if found:
                return found
        return None

    root = search(extract_dir, max_depth)
    if root is None:
        raise HTTPException(400, "Could not find a 'Payment Report' folder in what was uploaded.")
    return root


def _safe_extract_zip(zf: zipfile.ZipFile, dest_dir: str):
    """Extract every entry of an uploaded zip into dest_dir, rejecting any
    path that would escape it (zip-slip) the same way _safe_relpath does for
    individually-uploaded files."""
    if sum(m.file_size for m in zf.infolist()) > MAX_UNZIPPED_BYTES:
        raise HTTPException(400, "That zip expands to too much data.")
    for member in zf.infolist():
        parts = [p for p in member.filename.replace("\\", "/").split("/") if p not in ("", ".", "..")]
        if not parts:
            continue
        target = os.path.join(dest_dir, *parts)
        if member.is_dir():
            os.makedirs(target, exist_ok=True)
            continue
        os.makedirs(os.path.dirname(target), exist_ok=True)
        with zf.open(member) as src, open(target, "wb") as out:
            shutil.copyfileobj(src, out)


@app.post("/api/upload")
async def upload_export(request: Request, files: List[UploadFile] = File(...), role: str = Depends(require_admin)):
    """Accepts every file of a selected 'Posist Report' folder (each file's
    name carries its path within that folder) and upserts its bills/sales/
    discounts into the store.

    NOTE: this must stay registered before POST /api/{dataset} below - Starlette
    matches routes in registration order, and "/api/upload" would otherwise
    match the "/api/{dataset}" pattern first (with dataset="upload") and get
    routed into create_row() instead, which expects a JSON body, not a
    multipart upload."""
    if not files:
        raise HTTPException(400, "No files received.")
    if int(request.headers.get("content-length") or 0) > MAX_UPLOAD_BYTES:
        raise HTTPException(413, "Upload is too large.")
    # A picked folder can carry stray files (.DS_Store, notes, ...); only Excel
    # files and zips are ever kept - anything else is dropped, never written to disk.
    files = [f for f in files if (f.filename or "").lower().endswith(ALLOWED_UPLOAD_EXTENSIONS)]
    if not files:
        raise HTTPException(400, "No Excel (.xlsx/.xls) or .zip files in that upload.")

    with tempfile.TemporaryDirectory() as tmp:
        extract_dir = os.path.join(tmp, "extracted")
        os.makedirs(extract_dir, exist_ok=True)
        for f in files:
            filename = f.filename or ""
            if filename.lower().endswith(".zip"):
                zip_path = os.path.join(tmp, "_upload_" + _safe_relpath(filename).replace(os.sep, "_"))
                with open(zip_path, "wb") as out:
                    shutil.copyfileobj(f.file, out)
                try:
                    with zipfile.ZipFile(zip_path) as zf:
                        _safe_extract_zip(zf, extract_dir)
                except zipfile.BadZipFile:
                    raise HTTPException(400, f"'{filename}' is not a valid zip file.")
            else:
                dest = os.path.join(extract_dir, _safe_relpath(filename))
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                with open(dest, "wb") as out:
                    shutil.copyfileobj(f.file, out)

        posist_root = _find_posist_root(extract_dir)
        try:
            bills, sales, discounts, cancellations, missing_sections = parsers.parse_posist_export(posist_root)
        except FileNotFoundError as e:
            raise HTTPException(400, str(e))

    return _write_parsed_upload(
        bills, sales, discounts, cancellations, missing_sections,
        [f.filename for f in files if f.filename],
    )


def _write_parsed_upload(bills, sales, discounts, cancellations, missing_sections, file_names):
    """Shared by both upload routes: the write phase is identical whether
    the rows came from parsing real Excel files here (upload_export) or
    were already parsed locally and sent as JSON (upload_export_json)."""
    discount_reasons = sorted({d["reason"] for d in discounts}) if discounts else None
    meta = storage.read_meta()
    if discount_reasons:
        meta["discountReasons"] = sorted(set(meta.get("discountReasons", [])) | set(discount_reasons))
    # Explicit UTC + "Z" suffix - Render's server clock is UTC, and without a
    # timezone marker the browser's `new Date(...)` would wrongly treat this
    # as already being in the VIEWER's local time (per the ES spec's handling
    # of timezone-less datetime strings), showing a time up to many hours off
    # for anyone not in UTC. With the "Z", the browser correctly converts it
    # to whatever timezone the viewer is actually in.
    meta["lastRefreshed"] = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    # bulk_write_upload (not 4x replace_months + write_meta + append_upload_
    # history separately) so this only takes 2 database connections total
    # (the read_meta above, plus this) instead of up to 6 - each fresh
    # connection to a serverless/free-tier Postgres instance adds real,
    # avoidable latency, and that was a meaningful chunk of why uploads were
    # slow. replace_months (not upsert_rows) semantics still apply: re-
    # uploading a month's report cleanly supersedes whatever was previously
    # stored for that month rather than merging with stale rows the new
    # report no longer contains; months not present in this upload are left
    # untouched.
    results = storage.bulk_write_upload(
        {"bills": bills, "sales": sales, "discounts": discounts, "cancellations": cancellations},
        meta,
        {"files": file_names, "missing": missing_sections},
    )

    # "Data Available" spans every month EVER uploaded, not just this one -
    # e.g. upload June, then later May, then later July -> "May 2026 to Jul
    # 2026", not just whichever month was uploaded most recently. Recomputed
    # from what's now actually in storage (this upload's rows plus every
    # earlier one still there), after the write above so it sees the result.
    storage.recompute_meta_dates()

    return {
        "bills": results["bills"],
        "sales": results["sales"],
        "discounts": results["discounts"],
        "cancellations": results["cancellations"],
        "missing": missing_sections,
    }


@app.post("/api/upload-json")
async def upload_export_json(request: Request, payload: Dict[str, Any], role: str = Depends(require_admin)):
    """Accepts a Posist export already parsed LOCALLY (see
    backend/local_prepare_upload.py, which runs this exact same parsers.py
    logic on the user's own PC) as a plain JSON body, instead of raw Excel
    files - skips Excel parsing on the server entirely. Added after a
    Hostinger shared-hosting deployment of this same API repeatedly
    exhausted its resources parsing large real exports server-side; doing
    that part locally instead sidesteps the whole class of problem, and
    this route is just as useful here on Render for the same reason (a
    large upload parsing faster locally than waiting on a free-tier
    instance never hurts)."""
    if int(request.headers.get("content-length") or 0) > MAX_UPLOAD_BYTES:
        raise HTTPException(413, "Upload is too large.")
    for key in ("bills", "sales", "discounts", "cancellations"):
        if key not in payload or not isinstance(payload[key], list):
            raise HTTPException(400, f"Missing or invalid '{key}' in the uploaded JSON.")
        if not all(isinstance(r, dict) for r in payload[key]):
            raise HTTPException(400, f"'{key}' must be a list of row objects.")
        # Schema check: every row needs the fields the DB keys on, in the
        # right shape, so a hand-crafted file can't inject junk rows.
        for r in payload[key]:
            if not isinstance(r.get("branch"), str) or not r["branch"] or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", str(r.get("date", ""))):
                raise HTTPException(400, f"'{key}' has a row with a missing branch or a date that isn't YYYY-MM-DD.")
    return _write_parsed_upload(
        payload["bills"], payload["sales"], payload["discounts"], payload["cancellations"],
        payload.get("missing") or [],
        payload.get("sourceFiles") or [],
    )


@app.post("/api/clear-all")
def clear_all_data(payload: Dict[str, Any], role: str = Depends(require_admin)):
    """Wipes every uploaded row and the upload history log entirely. Admin
    only, and the admin must re-enter their password (irreversible action).
    Must stay registered before POST /api/{dataset} below, same reasoning as
    POST /api/upload."""
    if not secrets.compare_digest(str(payload.get("password", "")), ADMIN_PASSWORD):
        raise HTTPException(403, "Password incorrect - nothing was deleted.")
    storage.clear_all()
    return {"cleared": True}


@app.post("/api/delete-month")
def delete_month_data(payload: Dict[str, Any], role: str = Depends(require_admin)):
    """Wipes one month's data from just the chosen dataset(s) (e.g. only
    Cancelled KOTs for 2026-04), so a client can drop a bad month before
    re-uploading a corrected report without wiping everything else. NOTE:
    Admin-only (server-enforced). Must stay registered before POST /api/{dataset} below, same reasoning as
    POST /api/upload."""
    month = payload.get("month")
    datasets = payload.get("datasets") or []
    if not month:
        raise HTTPException(400, "Missing 'month' (expected 'YYYY-MM').")
    unknown = [d for d in datasets if d not in EDITABLE_DATASETS]
    if unknown:
        raise HTTPException(400, f"Unknown dataset(s): {unknown}. Must be one of {EDITABLE_DATASETS}.")
    if not datasets:
        raise HTTPException(400, "No datasets selected to delete.")

    removed = {d: storage.delete_month(d, month) for d in datasets}
    if "bills" in datasets:
        storage.recompute_meta_dates()
    # Only purge this month's Upload History rows when EVERY dataset for it
    # was just deleted (a full wipe of the month) - a partial delete (e.g.
    # just Cancelled KOTs) still has real data behind it, so the history
    # entries describing that upload are still meaningful and shouldn't
    # disappear. A full wipe leaves nothing behind them, so keeping the
    # history rows would just show a month with no data anywhere.
    history_removed = 0
    if set(datasets) >= set(EDITABLE_DATASETS):
        history_removed = storage.delete_upload_history_month(month)
        if history_removed:
            # The upload that most recently set "Last Refreshed" might be
            # exactly the entry just removed above - reset it to whichever
            # upload is now actually the most recent one on record (or
            # None if history is now empty), instead of leaving it pointing
            # at data that no longer exists.
            storage.recompute_last_refreshed()
    return {"month": month, "removed": removed, "historyRemoved": history_removed}


@app.post("/api/migrate-discount-reasons")
def migrate_discount_reasons(role: str = Depends(require_admin)):
    """One-time fix-up for discount rows uploaded before "reason" switched
    from a Discount-Type classification to parsers.discount_reason_from_remark
    (Remarks-based): recomputes "reason" for every already-stored discount
    row from its own already-stored "remarks" field (no original Excel
    files needed) and replaces meta.discountReasons with the fresh set, so
    existing months' charts catch up without anyone re-uploading anything.
    Idempotent - safe to call more than once. Admin-only (server-enforced). Must stay registered
    before POST /api/{dataset} below, same reasoning as POST /api/upload."""
    rows = storage.read_range("discounts")
    for row in rows:
        row["reason"] = parsers.discount_reason_from_remark(row.get("remarks"))
    storage.replace_months("discounts", rows)

    reasons = sorted({row["reason"] for row in rows})
    meta = storage.read_meta()
    meta["discountReasons"] = reasons
    storage.write_meta(meta)
    return {"updated": len(rows), "reasons": reasons}


@app.post("/api/migrate-zero-sales-bills")
def migrate_zero_sales_bills(role: str = Depends(require_admin)):
    """One-time fix-up for bills uploaded before parse_payment_report started
    excluding zero-Net-Sales rows from counting as a bill at all (Number Of
    Bills, Guests By Hour, etc. all derive from this same stored list):
    drops every already-stored bill with salesValue == 0. Explicitly clears
    every month first (not just the ones with rows left over) so a month
    that turns out to have had ONLY zero-sales rows is actually emptied,
    not left with its stale zero-sales rows untouched. Idempotent - safe to
    call more than once. Admin-only (server-enforced). Must stay registered before POST
    /api/{dataset} below, same reasoning as POST /api/upload."""
    all_rows = storage.read_range("bills")
    kept = [r for r in all_rows if r.get("salesValue") != 0]

    months = sorted({r["date"][:7] for r in all_rows})
    for month in months:
        storage.delete_month("bills", month)
    storage.replace_months("bills", kept)
    storage.recompute_meta_dates()
    return {"kept": len(kept), "removed": len(all_rows) - len(kept)}


@app.post("/api/{dataset}")
def create_row(dataset: str, row: Dict[str, Any], role: str = Depends(require_admin)):
    _require_dataset(dataset)
    _require_key_fields(dataset, row)
    existing = storage.read_range(dataset)
    key = tuple(row.get(f) for f in storage.KEY_FIELDS[dataset])
    if any(tuple(r.get(f) for f in storage.KEY_FIELDS[dataset]) == key for r in existing):
        raise HTTPException(409, "A row with this key already exists - use PUT to update it.")
    storage.upsert_rows(dataset, [row])
    if dataset == "bills":
        storage.recompute_meta_dates()
    return row


@app.put("/api/{dataset}")
def update_row(dataset: str, row: Dict[str, Any], role: str = Depends(require_admin)):
    _require_dataset(dataset)
    _require_key_fields(dataset, row)
    existing = storage.read_range(dataset)
    key = tuple(row.get(f) for f in storage.KEY_FIELDS[dataset])
    if not any(tuple(r.get(f) for f in storage.KEY_FIELDS[dataset]) == key for r in existing):
        raise HTTPException(404, "No row with this key exists - use POST to create it.")
    storage.upsert_rows(dataset, [row])
    if dataset == "bills":
        storage.recompute_meta_dates()
    return row


@app.delete("/api/{dataset}")
def delete_row(dataset: str, key: Dict[str, Any], role: str = Depends(require_admin)):
    _require_dataset(dataset)
    _require_key_fields(dataset, key)
    if not storage.delete_row(dataset, key):
        raise HTTPException(404, "No row with this key exists.")
    if dataset == "bills":
        storage.recompute_meta_dates()
    return {"deleted": True}


