#!/usr/bin/env python3
"""
Pre-parse a Posist Report export locally (on your own PC) into one JSON
file, so the slow/heavy part (reading the Excel files) never has to happen
on the shared-hosting server at all - only a small JSON upload does.

Why this exists: a real month's export (especially the Bill Item Detailed
Report) can be tens of MB and take a long time to parse with PHP on
Hostinger's shared hosting, which has much less memory/CPU than your own
PC. This script runs the exact same parsing logic already used in
production (parsers.py - the same code backend/app.py imports) right here,
so the dashboard's "Upload" dialog only ever has to send the small,
already-parsed result.

Usage:
    python local_prepare_upload.py "<path to Posist Report folder or .zip>" [output.json]

If you omit the output path, it writes "<input name>.upload.json" next to
the input. Then, in the dashboard's "Upload New Data" dialog, use
"Choose JSON File..." and pick that file instead of the folder/zip.
"""

import sys
import os
import json
import zipfile
import tempfile
import shutil

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import parsers


IGNORED_DIR_PREFIXES = ("__MACOSX", ".")


def find_posist_root(extract_dir, max_depth=4):
    """Same search backend/app.py's _find_posist_root does: the folder
    picked (or zip) is usually named something like 'Posist Report' and
    wraps the report subfolders one level down - search down a few levels
    for the first folder that actually contains 'Payment Report'."""
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
        raise FileNotFoundError("Could not find a 'Payment Report' folder in what was given.")
    return root


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        sys.exit(1)

    input_path = sys.argv[1]
    if not os.path.exists(input_path):
        print(f"Not found: {input_path}")
        sys.exit(1)

    if len(sys.argv) >= 3:
        output_path = sys.argv[2]
    else:
        base = input_path[:-4] if input_path.lower().endswith(".zip") else input_path.rstrip("\\/")
        output_path = base + ".upload.json"

    tmp_dir = None
    try:
        if os.path.isdir(input_path):
            posist_root = find_posist_root(input_path)
        elif input_path.lower().endswith(".zip"):
            tmp_dir = tempfile.mkdtemp(prefix="kck_local_upload_")
            print(f"Extracting {input_path} ...")
            with zipfile.ZipFile(input_path) as zf:
                zf.extractall(tmp_dir)
            posist_root = find_posist_root(tmp_dir)
        else:
            print("Input must be a folder or a .zip file.")
            sys.exit(1)

        print(f"Parsing reports from: {posist_root}")
        bills, sales, discounts, cancellations, missing = parsers.parse_posist_export(posist_root)

        print(f"  bills: {len(bills)}")
        print(f"  sales: {len(sales)}")
        print(f"  discounts: {len(discounts)}")
        print(f"  cancellations: {len(cancellations)}")
        if missing:
            print(f"  missing: {missing}")

        payload = {
            "bills": bills,
            "sales": sales,
            "discounts": discounts,
            "cancellations": cancellations,
            "missing": missing,
            "sourceFiles": [os.path.basename(input_path)],
        }
        with open(output_path, "w", encoding="utf-8") as f:
            json.dump(payload, f, ensure_ascii=False)

        size_kb = os.path.getsize(output_path) / 1024
        print(f"\nWrote {output_path} ({size_kb:.0f} KB).")
        print("In the dashboard's Upload dialog, use \"Choose JSON File...\" and pick this file.")
    finally:
        if tmp_dir:
            shutil.rmtree(tmp_dir, ignore_errors=True)


if __name__ == "__main__":
    main()
