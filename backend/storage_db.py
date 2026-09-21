"""
Postgres-backed drop-in replacement for storage.py's JSON-file store, used
only in production (Render) where the local filesystem doesn't persist
across restarts. Same public function signatures as storage.py, so app.py
just imports whichever one applies - see the top of app.py.

Activated only when the DATABASE_URL environment variable is set (e.g. a
free Neon Postgres connection string). Local development is untouched:
without DATABASE_URL, app.py keeps using the original storage.py.
"""

import os
import json
import time
from collections import defaultdict
from contextlib import contextmanager

import psycopg2
from psycopg2.extras import execute_values

DATABASE_URL = os.environ["DATABASE_URL"]
_CONNECT_RETRIES = 3
_CONNECT_RETRY_DELAY = 2  # seconds

KEY_FIELDS = {
    "bills": ("branch", "bill", "date"),
    "sales": ("branch", "date", "category", "item"),
    "discounts": ("branch", "bill"),
    "cancellations": ("branch", "bill", "date", "item"),
}

DEFAULT_META = {
    "dataStart": None, "dataEnd": None, "lastRefreshed": None,
    "reasons": ["Guest Cancellation", "Change Of Item", "Not Specified", "Other"],
    "discountReasons": [], "serviceTypes": ["Dine-In", "Home Delivery", "Takeaway", "Banquets & Catering"],
}


@contextmanager
def _conn():
    """A free-tier/serverless Postgres (e.g. Neon) suspends when idle and
    can take a few seconds to wake up on the first connection after a
    while - which previously surfaced as a raw connection error on whatever
    request happened to hit it first (including uploads), caught by app.py's
    catch-all handler and shown as a generic "Something went wrong".
    Retrying just the CONNECT a few times with a short delay absorbs that
    wake-up window instead of failing the caller's request over it."""
    conn = None
    for attempt in range(_CONNECT_RETRIES):
        try:
            conn = psycopg2.connect(DATABASE_URL)
            break
        except psycopg2.OperationalError:
            if attempt == _CONNECT_RETRIES - 1:
                raise
            time.sleep(_CONNECT_RETRY_DELAY)
    try:
        yield conn
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


def _ensure_schema():
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("CREATE TABLE IF NOT EXISTS meta (id INT PRIMARY KEY DEFAULT 1, data JSONB NOT NULL);")
        cur.execute("""
            CREATE TABLE IF NOT EXISTS rows (
                dataset TEXT NOT NULL,
                month TEXT NOT NULL,
                row_key TEXT NOT NULL,
                data JSONB NOT NULL,
                PRIMARY KEY (dataset, row_key)
            );
        """)
        cur.execute("CREATE INDEX IF NOT EXISTS rows_dataset_month_idx ON rows (dataset, month);")
        cur.execute("CREATE TABLE IF NOT EXISTS upload_history (id SERIAL PRIMARY KEY, entry JSONB NOT NULL);")


_ensure_schema()


def _row_key(dataset, row):
    return "|".join(str(row.get(f)) for f in KEY_FIELDS[dataset])


def _month_key(date_str):
    return date_str[:7]


def read_meta():
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("SELECT data FROM meta WHERE id = 1;")
        r = cur.fetchone()
    meta = dict(DEFAULT_META)
    if r is not None:
        meta.update(r[0])
    return meta


def write_meta(meta):
    with _conn() as conn, conn.cursor() as cur:
        cur.execute(
            "INSERT INTO meta (id, data) VALUES (1, %s) "
            "ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data;",
            (json.dumps(meta),),
        )


def read_range(dataset, from_month=None, to_month=None):
    query = "SELECT data FROM rows WHERE dataset = %s"
    params = [dataset]
    if from_month:
        query += " AND month >= %s"
        params.append(from_month)
    if to_month:
        query += " AND month <= %s"
        params.append(to_month)
    with _conn() as conn, conn.cursor() as cur:
        cur.execute(query, params)
        return [r[0] for r in cur.fetchall()]


def _replace_months_with_cursor(cur, dataset, rows):
    """Core of replace_months, run against an already-open cursor so a
    caller juggling several datasets in one request (bulk_write_upload) can
    do it all in a single connection/transaction instead of reconnecting
    once per dataset."""
    by_month = defaultdict(list)
    for row in rows:
        by_month[_month_key(row["date"])].append(row)

    result = {}
    for month, new_rows in by_month.items():
        cur.execute("DELETE FROM rows WHERE dataset = %s AND month = %s;", (dataset, month))
        deduped = {}
        for row in new_rows:
            deduped[_row_key(dataset, row)] = row
        if deduped:
            values = [(dataset, month, key, json.dumps(row)) for key, row in deduped.items()]
            # page_size = the whole batch: one round trip total instead of
            # execute_values' default of one round trip per 100 rows -
            # network latency to Postgres, not row count, is what
            # dominates upload time here.
            execute_values(
                cur,
                "INSERT INTO rows (dataset, month, row_key, data) VALUES %s",
                values,
                page_size=len(values),
            )
        result[month] = {"added": len(deduped), "updated": 0}
    return result


def replace_months(dataset, rows):
    """See storage.py's replace_months docstring - same replace-the-whole-
    month semantics, just against Postgres instead of a JSON file."""
    with _conn() as conn, conn.cursor() as cur:
        return _replace_months_with_cursor(cur, dataset, rows)


def bulk_write_upload(datasets_rows, meta, history_extra):
    """Everything POST /api/upload needs to write, in ONE database
    connection/transaction instead of one connection per call - previously
    4x replace_months + read_meta + write_meta + append_upload_history meant
    up to 7 separate connections per upload, and each fresh connection to a
    serverless/free-tier Postgres instance adds real, avoidable latency
    (connect + TLS handshake + possible cold-start) on top of the query
    itself. That was a meaningful chunk of why uploads were slow.

    datasets_rows: {"bills": [...], "sales": [...], "discounts": [...], "cancellations": [...]}
    meta: the full meta dict to write (caller has already merged in
        discountReasons/lastRefreshed/dataStart/dataEnd)
    history_extra: {"files": [...], "missing": [...]} - the upload-history
        entry's fields besides "timestamp" and the per-dataset month
        results, both computed here.
    Returns {dataset: {month: {"added": n, "updated": 0}}}."""
    with _conn() as conn, conn.cursor() as cur:
        results = {ds: _replace_months_with_cursor(cur, ds, rows) for ds, rows in datasets_rows.items()}

        cur.execute(
            "INSERT INTO meta (id, data) VALUES (1, %s) "
            "ON CONFLICT (id) DO UPDATE SET data = EXCLUDED.data;",
            (json.dumps(meta),),
        )

        months_touched = sorted({m for r in results.values() for m in r})
        history_entry = {
            "timestamp": meta["lastRefreshed"],
            "files": history_extra["files"],
            "months": months_touched,
            "bills": results["bills"],
            "sales": results["sales"],
            "discounts": results["discounts"],
            "cancellations": results["cancellations"],
            "missing": history_extra["missing"],
        }
        cur.execute("INSERT INTO upload_history (entry) VALUES (%s);", (json.dumps(history_entry),))
        cur.execute(
            "DELETE FROM upload_history WHERE id NOT IN "
            "(SELECT id FROM upload_history ORDER BY id DESC LIMIT 500);"
        )
    return results


def upsert_rows(dataset, rows):
    by_month = defaultdict(list)
    for row in rows:
        by_month[_month_key(row["date"])].append(row)

    result = {}
    with _conn() as conn, conn.cursor() as cur:
        for month, new_rows in by_month.items():
            deduped = {}
            for row in new_rows:
                deduped[_row_key(dataset, row)] = row
            values = [(dataset, month, key, json.dumps(row)) for key, row in deduped.items()]
            # xmax = 0 on the returned row means this INSERT created it fresh;
            # a nonzero xmax means ON CONFLICT UPDATE touched an existing row
            # - lets us report added/updated without a separate SELECT per row.
            # page_size = the whole batch, same reasoning as replace_months.
            rows_out = execute_values(
                cur,
                "INSERT INTO rows (dataset, month, row_key, data) VALUES %s "
                "ON CONFLICT (dataset, row_key) DO UPDATE SET data = EXCLUDED.data, month = EXCLUDED.month "
                "RETURNING (xmax = 0) AS inserted;",
                values,
                page_size=len(values),
                fetch=True,
            )
            added = sum(1 for (inserted,) in rows_out if inserted)
            result[month] = {"added": added, "updated": len(rows_out) - added}
    return result


def delete_month(dataset, month):
    """Wipe every row of `dataset` for exactly `month` ('YYYY-MM'), e.g. so a
    client can drop a bad month before re-uploading a corrected report,
    without touching any other month or dataset. Returns the number of rows
    removed (0 if that month had none)."""
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("DELETE FROM rows WHERE dataset = %s AND month = %s;", (dataset, month))
        return cur.rowcount


def delete_row(dataset, key_row):
    key = _row_key(dataset, key_row)
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("DELETE FROM rows WHERE dataset = %s AND row_key = %s;", (dataset, key))
        return cur.rowcount > 0


def clear_all():
    """Wipe every uploaded row (all datasets, all months), the upload history
    log, and reset meta back to its empty defaults. Irreversible - callers
    must confirm with the user before calling this."""
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("DELETE FROM rows;")
        cur.execute("DELETE FROM upload_history;")
    write_meta(dict(DEFAULT_META))


def recompute_meta_dates():
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("SELECT MIN(data->>'date'), MAX(data->>'date') FROM rows WHERE dataset = 'bills';")
        row_min, row_max = cur.fetchone()
    meta = read_meta()
    meta["dataStart"] = row_min
    meta["dataEnd"] = row_max
    write_meta(meta)
    return meta


def read_upload_history():
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("SELECT entry FROM upload_history ORDER BY id ASC;")
        return [r[0] for r in cur.fetchall()]


def append_upload_history(entry):
    """Keeps only the most recent 500, same as storage.py."""
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("INSERT INTO upload_history (entry) VALUES (%s);", (json.dumps(entry),))
        cur.execute(
            "DELETE FROM upload_history WHERE id NOT IN "
            "(SELECT id FROM upload_history ORDER BY id DESC LIMIT 500);"
        )
    return read_upload_history()


def delete_upload_history_month(month):
    """Remove every upload-history entry that touched `month` - e.g. after
    a full delete_month() wipe of that month across every dataset, so the
    History table doesn't keep showing a month that no longer has any data
    behind it. Returns how many entries were removed."""
    with _conn() as conn, conn.cursor() as cur:
        cur.execute("DELETE FROM upload_history WHERE entry->'months' ? %s;", (month,))
        return cur.rowcount


def recompute_last_refreshed():
    """Reset meta.lastRefreshed to whatever the most recent Upload History
    entry's own timestamp actually is, rather than trusting whatever an
    upload most recently set it to - important after
    delete_upload_history_month() removes entries, since the timestamp an
    upload set could belong to an entry that no longer exists (e.g. a test
    upload that was later deleted), leaving "Last Refreshed" pointing at an
    upload that's gone instead of the real most recent one still on record."""
    history = read_upload_history()
    meta = read_meta()
    meta["lastRefreshed"] = history[-1]["timestamp"] if history else None
    write_meta(meta)
    return meta
