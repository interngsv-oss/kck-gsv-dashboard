"""
Excel report parsing - Posist "Payment Report" / "Bill Item Detailed Report" /
"Discount and Voucher Report" exports -> plain dict rows.

Copied from build_dashboard_data.py unchanged (same column layout, same
cleaning rules) so the API produces byte-identical rows to the old CLI
pipeline. If Posist ever changes a report's column layout, fix it here.
"""

import os
import re
import glob
from datetime import datetime, date
from collections import defaultdict

import openpyxl


def find_branch_file(report_dir, branch_hint):
    """Find the xlsx for a branch inside a report folder, tolerant of typos
    in filenames (e.g. 'OTher Details chennai.xlsx')."""
    for fp in glob.glob(os.path.join(report_dir, "*.xlsx")):
        if branch_hint.lower() in os.path.basename(fp).lower():
            return fp
    raise FileNotFoundError(f"No file matching '{branch_hint}' in {report_dir}")


def find_dir_ci(parent, name):
    """Case-insensitive lookup of a subfolder named `name` inside `parent`.

    Real-world Posist/Google-Drive exports aren't always consistent about
    capitalization (e.g. a folder actually named "Discount and voucher
    Report" instead of "Discount and Voucher Report"), and unlike Windows,
    directory lookups are case-SENSITIVE on the Linux servers this runs on
    in production - a folder name that looks fine to a human eye can
    silently fail to be found, making real uploaded data show up as
    "not available" even though it's genuinely in the file. Falls back to
    the exact-case path if no case-insensitive match exists either (which
    then correctly reports as actually missing)."""
    exact = os.path.join(parent, name)
    if os.path.isdir(exact):
        return exact
    if not os.path.isdir(parent):
        return exact
    target = name.lower()
    for entry in os.listdir(parent):
        if entry.lower() == target and os.path.isdir(os.path.join(parent, entry)):
            return os.path.join(parent, entry)
    return exact


def _to_iso_date(value):
    """Payment/Bill-Item date-times come as 'DD-MM-YYYY hh:mm:ss pm' or
    'DD-Mon-YYYY hh:mm:ss pm' strings. Return 'YYYY-MM-DD'."""
    if value is None:
        return None
    if isinstance(value, (datetime, date)):
        return value.strftime("%Y-%m-%d")
    s = str(value).strip()
    for fmt in ("%d-%m-%Y %I:%M:%S %p", "%d-%b-%Y %I:%M:%S %p"):
        try:
            return datetime.strptime(s, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
    return None


def _to_hour(value):
    if value is None:
        return None
    if isinstance(value, datetime):
        return value.hour
    s = str(value).strip()
    for fmt in ("%d-%m-%Y %I:%M:%S %p", "%d-%b-%Y %I:%M:%S %p"):
        try:
            return datetime.strptime(s, fmt).hour
        except ValueError:
            continue
    return None


SERVICE_TYPE_BANQUET_NAMES = {"BANQUETS", "ODC"}


def _service_type(tab_name, tab_type):
    tab_name = (tab_name or "").strip()
    tab_type = (tab_type or "").strip().lower()
    if tab_name.upper() in SERVICE_TYPE_BANQUET_NAMES:
        return "Banquets & Catering"
    if tab_type == "delivery":
        return "Home Delivery"
    if tab_type == "takeout":
        return "Takeaway"
    return "Dine-In"


def parse_payment_report(fp, branch_label):
    """-> (allBills rows, {bill_no: total_amount}) for discount join.

    The same bill number can appear on more than one row here (e.g. a group
    split across two physical tables under one bill) - if that weren't
    handled, only whichever row happened to survive storage's per-bill dedup
    would count, silently under-reporting that bill's covers/sales and
    throwing off Sales By Service Type, Sales By Hour and Guests By Hour.
    So every row for the same bill number is combined into a single output
    row here: covers and net sales are summed, and total_amount (used for
    the discount join) is summed too, before any per-bill dedup happens
    downstream. The first-seen row's date/hour/service type is kept, since
    a split bill is opened once and all its rows are on the same ticket."""
    wb = openpyxl.load_workbook(fp, data_only=True, read_only=True)
    ws = wb["Sheet1"]
    agg = {}
    order = []
    bill_totals = {}
    for row in ws.iter_rows(min_row=7, values_only=True):
        bill_no = row[1]
        if bill_no is None:
            continue  # date-section marker row
        void_flag = str(row[45]).strip().upper() if row[45] is not None else "NO"
        if void_flag == "YES":
            continue
        open_time = row[4]
        row_date = _to_iso_date(open_time)
        hour = _to_hour(open_time)
        covers = row[8] or 0
        net_sales = row[13] if row[13] is not None else 0.0
        total_amount = row[11] if row[11] is not None else 0.0
        tab_name = row[6]
        tab_type = row[7]
        bill_key = str(bill_no).strip()
        bill_totals[bill_key] = bill_totals.get(bill_key, 0.0) + float(total_amount)
        if row_date is None or hour is None:
            continue
        if net_sales == 0:
            # A zero Net Sales row isn't a real bill for counting/analysis
            # purposes (e.g. Number Of Bills, Guests By Hour) - only
            # bill_totals above still records it, so a discount referencing
            # this bill number can still be matched to it.
            continue
        covers = int(covers) if isinstance(covers, (int, float)) else 0
        if bill_key in agg:
            entry = agg[bill_key]
            entry["covers"] += covers
            entry["salesValue"] += float(net_sales)
        else:
            agg[bill_key] = {
                "date": row_date,
                "hour": hour,
                "branch": branch_label,
                "bill": bill_no,
                "covers": covers,
                "salesValue": float(net_sales),
                "serviceType": _service_type(tab_name, tab_type),
            }
            order.append(bill_key)
    wb.close()
    bills = [agg[k] for k in order]
    return bills, bill_totals


def _clean_name(value):
    """Posist's own exports are inconsistent about internal whitespace in
    item/category names (e.g. 'Erachi      curry' vs 'Erachi curry'), which
    would otherwise split one dish into multiple chart rows."""
    return " ".join(str(value).split()) if value else value


def parse_bill_item_report(fp, branch_label):
    """-> aggregated salesData rows (per date+branch+category+item)."""
    wb = openpyxl.load_workbook(fp, data_only=True, read_only=True)
    ws = wb["Sheet1"]
    agg = defaultdict(lambda: {"qty": 0.0, "value": 0.0})
    for row in ws.iter_rows(min_row=9, values_only=True):
        qty = row[16]
        if not isinstance(qty, (int, float)) or qty <= 0:
            continue  # header repeats / BILL TOTAL rows / combo-constituent sub-lines
        category = _clean_name(row[10])
        item = _clean_name(row[11])
        amount = row[18] if isinstance(row[18], (int, float)) else 0.0
        row_date = _to_iso_date(row[7])  # Open Time
        if row_date is None or not item:
            continue
        key = (row_date, category, item)
        agg[key]["qty"] += qty
        agg[key]["value"] += amount
    wb.close()

    sales = []
    for (row_date, category, item), v in agg.items():
        rate = round(v["value"] / v["qty"], 2) if v["qty"] else 0.0
        sales.append({
            "date": row_date,
            "branch": branch_label,
            "category": category,
            "item": item,
            "qty": v["qty"] if v["qty"] % 1 else int(v["qty"]),
            "rate": rate,
            "value": round(v["value"], 2),
        })
    return sales


def discount_reason_from_remark(remarks):
    """The "Reasons For Discount" chart groups by the discount's own
    free-text Remarks cell (who authorized it / for whom, e.g. 'Augustine
    Sir') rather than the system's fixed Discount Type field, since that's
    what actually explains the discount here. Remarks are typed by hand, so
    grouping is case/whitespace-insensitive (via _clean_name + uppercasing)
    so the same person/reason typed inconsistently doesn't fragment into
    separate bars."""
    r = _clean_name(remarks) if remarks else None
    if not r or r == "-":
        return "Not Specified"
    return r.upper()


def _discount_date(value):
    """The Discount and Voucher Report's date column is already plain text
    ('YYYY-MM-DD' or similar), unlike the datetime-stamped Payment/Bill-Item
    reports - so no _to_iso_date parsing here, just a datetime/date -> string
    safety net in case openpyxl hands back a real date object."""
    if isinstance(value, (datetime, date)):
        return value.strftime("%Y-%m-%d")
    return value


def parse_discount_report(fp, branch_label, bill_totals):
    """The same bill number can have more than one discount line (e.g. a
    separate discount against each of several items on one bill) - storage's
    dedup key needs something beyond just (branch, bill) or all but the last
    line for that bill would be silently discarded on every upload, under-
    reporting the bill's total discount and losing whichever reasons weren't
    on that last line. `lineSeq` (this line's 0-based position among this
    bill's own discount lines, in the order they appear in the report) makes
    each line's key unique while staying stable across re-uploads of the same
    file, so re-uploading doesn't create duplicates."""
    wb = openpyxl.load_workbook(fp, data_only=True, read_only=True)
    ws = wb["Sheet1"]
    discounts = []
    line_seq = {}
    for row in ws.iter_rows(min_row=7, values_only=True):
        trxno = row[4]
        if not trxno:
            continue
        row_date = _discount_date(row[3])
        disc_amt = row[7] if isinstance(row[7], (int, float)) else 0.0
        is_foc = str(row[11]).strip().upper() == "YES" if row[11] is not None else False
        bill_key = str(trxno).strip()
        item_value = bill_totals.get(bill_key)
        if item_value is None:
            # bill not found in this month's Payment Report (edge case); fall
            # back to treating the discount amount as the item value.
            item_value = disc_amt
        net = round(item_value - disc_amt, 2)
        seq = line_seq.get(bill_key, 0)
        line_seq[bill_key] = seq + 1
        discounts.append({
            "date": row_date,
            "branch": branch_label,
            "bill": trxno,
            "lineSeq": seq,
            "itemValue": round(item_value, 2),
            "discountValue": round(disc_amt, 2),
            "net": net,
            "isFull": is_foc,
            "reason": discount_reason_from_remark(row[9]),
            "type": row[6],
            "remarks": row[9],
        })
    wb.close()
    return discounts


_KOT_DATE_RE = re.compile(r"^\d{4}-\d{2}-\d{2}$")


def _classify_cancel_reason(comment):
    """Map a cancelled item's own free-text 'Comment' cell to one of the
    dashboard's fixed reason buckets (storage.DEFAULT reasons list)."""
    c = (comment or "").strip()
    if c in ("", "-"):
        return "Not Specified"
    cl = c.lower()
    if cl == "guest cancellation":
        return "Guest Cancellation"
    if cl == "change of item":
        return "Change Of Item"
    return "Other"


def parse_kot_tracking_report(fp, branch_label):
    """-> cancellation rows, one per cancelled ("Deleted") item line, from a
    Posist "KOT Tracking Report" export.

    The sheet is a nested, stateful layout, not a flat table: a date-marker
    row, then repeating blocks of [instance label -> KOT header row -> item
    sub-header -> item rows -> "KOT Total" row]. The KOT header row's own
    column layout is NOT stable - e.g. the Bangalore file has an extra "Edit
    Bill Comment" column the Chennai file doesn't, shifting every column
    after it - so rows are classified by their VALUE SHAPE instead of a
    fixed column index: the bill number cell (index 2) is always text on a
    KOT header row and always a number (the item's rate) on an item row,
    which reliably tells the two apart regardless of branch-specific column
    drift. An item's own "Status" column ("Billed" vs "Deleted") is the
    per-item cancellation signal - it reads "Deleted" both for an item voided
    individually inside an otherwise-billed KOT, and for every item inside a
    fully "KOT Voided"/"Bill Voided" KOT, so this one check catches both
    cases. The item's own "Comment" column carries the cancellation reason
    ("Guest Cancellation", "Change of item", or freeform text) - the KOT
    Tracking Report's real column layout (verified directly against Sept
    2026 production Posist exports) does carry item-level detail, unlike an
    earlier, now-known-incorrect assumption that it only had bill-level
    status.

    The same dish can be cancelled more than once within one bill (e.g. two
    separate KOTs for the same table both had a "Vattayappam" voided), which
    would collide under storage.KEY_FIELDS["cancellations"]'s
    (branch, bill, date, item) key and silently overwrite one occurrence with
    another - so, like parse_bill_item_report's aggregation, occurrences that
    share that key are summed here into a single row instead of losing all
    but the last one. But when those repeat occurrences were cancelled for
    DIFFERENT reasons, clubbing them into one row - as an earlier version of
    this function did - hid the fact that more than one reason applied and
    threw off the "Reasons For Cancellation" chart (it would show only the
    single surviving reason, undercounting every other reason involved). So
    the aggregation key includes the reason: occurrences of the same dish in
    the same bill are only summed together when they share a reason, and
    kept as separate rows (each with its own qty/value) when the reasons
    differ, so the chart accounts for all of them.
    """
    wb = openpyxl.load_workbook(fp, data_only=True, read_only=True)
    # Unlike the Payment/Bill-Item/Discount reports, real-world KOT Tracking
    # Report exports don't reliably name their one data tab "Sheet1" (seen in
    # production: a KeyError crashing the whole upload) - since this report
    # always has exactly one sheet, take whichever one is actually there.
    ws = wb["Sheet1"] if "Sheet1" in wb.sheetnames else wb[wb.sheetnames[0]]
    agg = {}
    cur_date = None
    cur_bill = None
    for row in ws.iter_rows(min_row=9, values_only=True):
        c0 = row[0]
        if c0 is None:
            continue
        s0 = str(c0).strip()
        if row[1] is None and _KOT_DATE_RE.match(s0):
            cur_date = s0
            continue
        if s0.startswith("Instance") or s0 in ("Item Name", "KOT Total"):
            continue
        if isinstance(row[2], str):
            # KOT header row - only the bill number (needed for the output
            # row) is read; KOT-level status/comment are ignored since the
            # item-level fields below are the reliable signal.
            cur_bill = row[2].strip()
            continue
        if not (isinstance(row[1], (int, float)) and isinstance(row[2], (int, float))):
            continue  # unrecognised row shape - skip rather than guess
        status = str(row[4]).strip() if row[4] is not None else ""
        if status != "Deleted" or cur_date is None or cur_bill is None:
            continue
        item = _clean_name(c0)
        if not item:
            continue
        qty = row[1]
        value = float(row[3]) if isinstance(row[3], (int, float)) else 0.0
        reason = _classify_cancel_reason(row[5] if len(row) > 5 else None)
        key = (cur_date, cur_bill, item, reason)
        if key in agg:
            entry = agg[key]
            entry["qty"] += qty
            entry["value"] += value
        else:
            agg[key] = {"qty": qty, "value": value, "reason": reason}
    wb.close()

    cancellations = []
    for (row_date, bill, item, reason), v in agg.items():
        qty = v["qty"]
        cancellations.append({
            "date": row_date,
            "branch": branch_label,
            "bill": bill,
            "item": item,
            "qty": qty if qty % 1 else int(qty),
            "rate": round(v["value"] / qty, 2) if qty else 0.0,
            "value": round(v["value"], 2),
            "reason": reason,
        })
    return cancellations


BRANCH_MAP = {
    "Bangalore": "Bengaluru",
    "Chennai": "Chennai",
}


def parse_posist_export(posist_root):
    """Parse a whole Posist export folder -> (bills, sales, discounts,
    cancellations, missing) combined across both branches.

    A missing report type or branch file (e.g. no "Discount and Voucher
    Report" this month, or one branch didn't send its Bill Item file) is
    tolerated: that section just comes back empty and its description is
    added to `missing`, instead of failing the whole upload - the dashboard
    then shows "no data" for whatever wasn't included rather than rejecting
    bills/sales that WERE there. Only raises if there isn't a single bill
    anywhere (Payment Report for both branches missing), since without any
    bills there's no date range to anchor the upload to."""
    payment_dir = find_dir_ci(posist_root, "Payment Report")
    bill_item_dir = find_dir_ci(posist_root, "Bill Item Detailed Report")
    discount_dir = find_dir_ci(posist_root, "Discount and Voucher Report")
    kot_dir = find_dir_ci(posist_root, "KOT Tracking Report")

    all_bills, sales_data, discount_data, cancellation_data = [], [], [], []
    bill_totals_by_branch = {}
    missing = []

    for file_hint, branch_label in BRANCH_MAP.items():
        try:
            fp = find_branch_file(payment_dir, file_hint)
        except FileNotFoundError:
            missing.append(f"Payment Report ({branch_label})")
            bill_totals_by_branch[branch_label] = {}
            continue
        bills, bill_totals = parse_payment_report(fp, branch_label)
        all_bills.extend(bills)
        bill_totals_by_branch[branch_label] = bill_totals

    if not all_bills:
        raise FileNotFoundError(
            "No 'Payment Report' found for either branch - can't tell which dates this upload covers."
        )

    for file_hint, branch_label in BRANCH_MAP.items():
        try:
            fp = find_branch_file(bill_item_dir, file_hint)
        except FileNotFoundError:
            missing.append(f"Bill Item Detailed Report ({branch_label})")
            continue
        sales_data.extend(parse_bill_item_report(fp, branch_label))

    for file_hint, branch_label in BRANCH_MAP.items():
        try:
            fp = find_branch_file(discount_dir, file_hint)
        except FileNotFoundError:
            missing.append(f"Discount and Voucher Report ({branch_label})")
            continue
        discount_data.extend(parse_discount_report(fp, branch_label, bill_totals_by_branch[branch_label]))

    for file_hint, branch_label in BRANCH_MAP.items():
        try:
            fp = find_branch_file(kot_dir, file_hint)
        except FileNotFoundError:
            missing.append(f"KOT Tracking Report ({branch_label})")
            continue
        cancellation_data.extend(parse_kot_tracking_report(fp, branch_label))

    return all_bills, sales_data, discount_data, cancellation_data, missing
