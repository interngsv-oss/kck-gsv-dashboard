"""
Read/write for the per-dataset JSON store under backend/data/.

Layout:
    data/meta.json
    data/cancellations.json
    data/bills/YYYY-MM.json
    data/sales/YYYY-MM.json
    data/discounts/YYYY-MM.json

Each dataset has a "key" that identifies "the same row" across uploads, used
to upsert (replace-if-exists, else append) instead of blindly appending
duplicates every time the same Excel is uploaded again.
"""

import os
import json
from collections import defaultdict

DATA_DIR = os.path.join(os.path.dirname(__file__), "data")

KEY_FIELDS = {
    "bills": ("branch", "bill", "date"),
    "sales": ("branch", "date", "category", "item"),
    "discounts": ("branch", "bill"),
    "cancellations": ("branch", "bill", "date", "item"),
}


def _month_key(date_str):
    return date_str[:7]  # "2026-07-15" -> "2026-07"


def _row_key(dataset, row):
    return tuple(row.get(f) for f in KEY_FIELDS[dataset])


def _month_file(dataset, month):
    return os.path.join(DATA_DIR, dataset, f"{month}.json")


def _read_json(path, default):
    if not os.path.exists(path):
        return default
    with open(path, encoding="utf-8") as f:
        return json.load(f)


def _write_json(path, obj):
    os.makedirs(os.path.dirname(path), exist_ok=True)
    tmp_path = path + ".tmp"
    with open(tmp_path, "w", encoding="utf-8") as f:
        json.dump(obj, f, ensure_ascii=False, indent=2)
    os.replace(tmp_path, path)  # atomic on both Windows and POSIX


def read_meta():
    return _read_json(os.path.join(DATA_DIR, "meta.json"), {
        "dataStart": None, "dataEnd": None, "lastRefreshed": None,
        "reasons": ["Guest Cancellation", "Change Of Item", "Not Specified", "Other"],
        "discountReasons": [], "serviceTypes": ["Dine-In", "Home Delivery", "Takeaway", "Banquets & Catering"],
    })


def write_meta(meta):
    _write_json(os.path.join(DATA_DIR, "meta.json"), meta)


def list_months(dataset):
    d = os.path.join(DATA_DIR, dataset)
    if not os.path.isdir(d):
        return []
    return sorted(fn[:-5] for fn in os.listdir(d) if fn.endswith(".json"))


def read_month(dataset, month):
    return _read_json(_month_file(dataset, month), [])


def read_range(dataset, from_month=None, to_month=None):
    """Read and concatenate every month file in [from_month, to_month]
    (inclusive, 'YYYY-MM' strings). Omit either bound to leave it open."""
    rows = []
    for month in list_months(dataset):
        if from_month and month < from_month:
            continue
        if to_month and month > to_month:
            continue
        rows.extend(read_month(dataset, month))
    return rows


def upsert_rows(dataset, rows):
    """Upsert `rows` into the month files they belong to (grouped by each
    row's own date), keyed by KEY_FIELDS[dataset]. Returns {month: (added, updated)}."""
    by_month = defaultdict(list)
    for row in rows:
        by_month[_month_key(row["date"])].append(row)

    result = {}
    for month, new_rows in by_month.items():
        existing = read_month(dataset, month)
        index = {_row_key(dataset, r): i for i, r in enumerate(existing)}

        added = updated = 0
        for row in new_rows:
            k = _row_key(dataset, row)
            if k in index:
                existing[index[k]] = row
                updated += 1
            else:
                index[k] = len(existing)
                existing.append(row)
                added += 1

        _write_json(_month_file(dataset, month), existing)
        result[month] = {"added": added, "updated": updated}
    return result


def replace_months(dataset, rows):
    """Like upsert_rows, but fully replaces every month file touched by
    `rows` (grouped by each row's own date) with exactly the given rows,
    discarding whatever existed there before instead of merging with it - so
    re-uploading a month's report cleanly supersedes the old one instead of
    leaving stale rows the new report no longer contains. Months not present
    in `rows` are left untouched. Rows are deduped by KEY_FIELDS[dataset]
    (last one wins) in case the same key appears twice in one upload.
    Returns {month: {"added": n, "updated": 0}} (same shape as upsert_rows,
    so existing callers/formatting keep working)."""
    by_month = defaultdict(list)
    for row in rows:
        by_month[_month_key(row["date"])].append(row)

    result = {}
    for month, new_rows in by_month.items():
        deduped = {}
        for row in new_rows:
            deduped[_row_key(dataset, row)] = row
        final_rows = list(deduped.values())
        _write_json(_month_file(dataset, month), final_rows)
        result[month] = {"added": len(final_rows), "updated": 0}
    return result


def read_upload_history():
    return _read_json(os.path.join(DATA_DIR, "upload_history.json"), [])


def append_upload_history(entry):
    """Append an upload-history entry, keeping only the most recent 500 so
    the log file can't grow unbounded."""
    history = read_upload_history()
    history.append(entry)
    history = history[-500:]
    _write_json(os.path.join(DATA_DIR, "upload_history.json"), history)
    return history


def bulk_write_upload(datasets_rows, meta, history_extra):
    """Same interface as storage_db.py's version (which batches everything
    into one Postgres connection for real connection-count reasons) - here
    it's just a thin wrapper, since local file I/O has no per-call
    connection overhead worth batching away.

    datasets_rows: {"bills": [...], "sales": [...], "discounts": [...], "cancellations": [...]}
    meta: the full meta dict to write.
    history_extra: {"files": [...], "missing": [...]}.
    Returns {dataset: {month: {"added": n, "updated": 0}}}."""
    results = {ds: replace_months(ds, rows) for ds, rows in datasets_rows.items()}
    write_meta(meta)
    months_touched = sorted({m for r in results.values() for m in r})
    append_upload_history({
        "timestamp": meta["lastRefreshed"],
        "files": history_extra["files"],
        "months": months_touched,
        "bills": results["bills"],
        "sales": results["sales"],
        "discounts": results["discounts"],
        "cancellations": results["cancellations"],
        "missing": history_extra["missing"],
    })
    return results


def delete_row(dataset, key_row):
    """Delete the row matching key_row's key fields, wherever its month file
    is. Returns True if a row was deleted, False if no match was found."""
    target = _row_key(dataset, key_row)
    for month in list_months(dataset):
        rows = read_month(dataset, month)
        kept = [r for r in rows if _row_key(dataset, r) != target]
        if len(kept) != len(rows):
            _write_json(_month_file(dataset, month), kept)
            return True
    return False


def delete_month(dataset, month):
    """Wipe every row of `dataset` for exactly `month` ('YYYY-MM'), e.g. so a
    client can drop a bad month before re-uploading a corrected report,
    without touching any other month or dataset. Returns the number of rows
    removed (0 if that month had none)."""
    path = _month_file(dataset, month)
    removed = len(read_month(dataset, month))
    if os.path.exists(path):
        os.remove(path)
    return removed


def clear_all():
    """Wipe every uploaded row (all datasets, all months), the upload history
    log, and reset meta.json back to its empty defaults. Irreversible -
    callers must confirm with the user before calling this."""
    for dataset in KEY_FIELDS:
        for month in list_months(dataset):
            os.remove(_month_file(dataset, month))
    _write_json(os.path.join(DATA_DIR, "upload_history.json"), [])
    write_meta({
        "dataStart": None, "dataEnd": None, "lastRefreshed": None,
        "reasons": ["Guest Cancellation", "Change Of Item", "Not Specified", "Other"],
        "discountReasons": [], "serviceTypes": ["Dine-In", "Home Delivery", "Takeaway", "Banquets & Catering"],
    })


def recompute_meta_dates():
    """Refresh meta.json's dataStart/dataEnd from whatever bill months exist
    on disk right now. Call after every upload."""
    months = list_months("bills")
    meta = read_meta()
    if not months:
        meta["dataStart"] = meta["dataEnd"] = None
        write_meta(meta)
        return meta

    all_dates = [r["date"] for r in read_month("bills", months[0])] + \
                [r["date"] for r in read_month("bills", months[-1])]
    meta["dataStart"] = min(all_dates)
    meta["dataEnd"] = max(all_dates)
    write_meta(meta)
    return meta
