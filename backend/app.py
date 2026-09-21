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

import os
import shutil
import tempfile
import traceback
import zipfile
from datetime import datetime, timezone
from typing import Any, Dict, List, Optional

from fastapi import FastAPI, HTTPException, Request, UploadFile, File
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import FileResponse, JSONResponse

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
_LOCAL_HTML = os.path.join(os.path.dirname(__file__), "kck_sales_dashboard_V11.html")
_DEV_HTML = os.path.join(os.path.dirname(__file__), "..", "..", "kck_sales_dashboard_V11.html")
DASHBOARD_HTML_PATH = _LOCAL_HTML if os.path.exists(_LOCAL_HTML) else _DEV_HTML

app = FastAPI(title="KCK Sales Dashboard API")

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_methods=["*"],
    allow_headers=["*"],
)


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
def serve_dashboard():
    # No-cache so every deploy is visible immediately - without this, browsers
    # can keep showing a stale cached copy of the page after we ship a fix,
    # which looks exactly like the fix never went live.
    return FileResponse(DASHBOARD_HTML_PATH, headers={"Cache-Control": "no-cache, no-store, must-revalidate"})


@app.api_route("/api/meta", methods=["GET", "HEAD"])
def get_meta():
    # HEAD too - uptime-monitor pings (UptimeRobot etc.) default to HEAD
    # requests, which a GET-only route rejects with 405.
    return storage.read_meta()


@app.get("/api/upload-history")
def get_upload_history():
    """Most-recent-first log of every /api/upload call: when it happened,
    which files were sent, and how many rows landed in each month/dataset.
    NOTE: like /api/upload below, this must stay registered before
    GET /api/{dataset} - Starlette matches routes in registration order, and
    "/api/upload-history" would otherwise match "/api/{dataset}" first (with
    dataset="upload-history") and 400 as an unknown dataset."""
    return list(reversed(storage.read_upload_history()))


@app.get("/api/{dataset}")
def list_rows(dataset: str, branch: Optional[str] = None,
              from_: Optional[str] = None, to: Optional[str] = None):
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
async def upload_export(files: List[UploadFile] = File(...)):
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

    # replace_months (not upsert_rows) so re-uploading a month's report
    # cleanly supersedes whatever was previously stored for that month,
    # instead of merging with stale rows the new report no longer contains.
    # Months not present in this upload are left untouched.
    bills_result = storage.replace_months("bills", bills)
    sales_result = storage.replace_months("sales", sales)
    discounts_result = storage.replace_months("discounts", discounts)
    cancellations_result = storage.replace_months("cancellations", cancellations)

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
    # "Data Available" tracks exactly what THIS upload's own rows span (e.g. if
    # the report only has rows for 1-25 June, this shows "1 Jun to 25 Jun") -
    # not the full history still sitting in storage for other months, so it
    # always reflects what was just uploaded rather than everything ever kept.
    if bills:
        upload_dates = [b["date"] for b in bills]
        meta["dataStart"] = min(upload_dates)
        meta["dataEnd"] = max(upload_dates)
    storage.write_meta(meta)

    months_touched = sorted(
        set(bills_result) | set(sales_result) | set(discounts_result) | set(cancellations_result)
    )
    storage.append_upload_history({
        "timestamp": meta["lastRefreshed"],
        "files": [f.filename for f in files if f.filename],
        "months": months_touched,
        "bills": bills_result,
        "sales": sales_result,
        "discounts": discounts_result,
        "cancellations": cancellations_result,
        "missing": missing_sections,
    })

    return {
        "bills": bills_result,
        "sales": sales_result,
        "discounts": discounts_result,
        "cancellations": cancellations_result,
        "missing": missing_sections,
    }


@app.post("/api/clear-all")
def clear_all_data():
    """Wipes every uploaded row and the upload history log entirely. NOTE:
    like the rest of this app's login gate, there's no real server-side
    auth - this just isn't wired into the admin-only UI for a viewer
    session. Must stay registered before POST /api/{dataset} below, same
    reasoning as POST /api/upload."""
    storage.clear_all()
    return {"cleared": True}


@app.post("/api/delete-month")
def delete_month_data(payload: Dict[str, Any]):
    """Wipes one month's data from just the chosen dataset(s) (e.g. only
    Cancelled KOTs for 2026-04), so a client can drop a bad month before
    re-uploading a corrected report without wiping everything else. NOTE:
    like the rest of this app's login gate, there's no real server-side
    auth - this just isn't wired into the admin-only UI for a viewer session.
    Must stay registered before POST /api/{dataset} below, same reasoning as
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
    return {"month": month, "removed": removed}


@app.post("/api/migrate-discount-reasons")
def migrate_discount_reasons():
    """One-time fix-up for discount rows uploaded before "reason" switched
    from a Discount-Type classification to parsers.discount_reason_from_remark
    (Remarks-based): recomputes "reason" for every already-stored discount
    row from its own already-stored "remarks" field (no original Excel
    files needed) and replaces meta.discountReasons with the fresh set, so
    existing months' charts catch up without anyone re-uploading anything.
    Idempotent - safe to call more than once. NOTE: like the rest of this
    app's login gate, there's no real server-side auth - this just isn't
    wired into the admin-only UI for a viewer session. Must stay registered
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
def migrate_zero_sales_bills():
    """One-time fix-up for bills uploaded before parse_payment_report started
    excluding zero-Net-Sales rows from counting as a bill at all (Number Of
    Bills, Guests By Hour, etc. all derive from this same stored list):
    drops every already-stored bill with salesValue == 0. Explicitly clears
    every month first (not just the ones with rows left over) so a month
    that turns out to have had ONLY zero-sales rows is actually emptied,
    not left with its stale zero-sales rows untouched. Idempotent - safe to
    call more than once. NOTE: like the rest of this app's login gate,
    there's no real server-side auth - this just isn't wired into the
    admin-only UI for a viewer session. Must stay registered before POST
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
def create_row(dataset: str, row: Dict[str, Any]):
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
def update_row(dataset: str, row: Dict[str, Any]):
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
def delete_row(dataset: str, key: Dict[str, Any]):
    _require_dataset(dataset)
    _require_key_fields(dataset, key)
    if not storage.delete_row(dataset, key):
        raise HTTPException(404, "No row with this key exists.")
    if dataset == "bills":
        storage.recompute_meta_dates()
    return {"deleted": True}


