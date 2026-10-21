<?php
// Direct port of backend/parsers.py to PHP (PhpSpreadsheet instead of
// openpyxl). Function-for-function, same column indices, same edge-case
// handling - see parsers.py's own docstrings for the *why* behind each
// quirk; comments here only flag where the PHP port had to do something
// differently. NOT verified against a real Posist export file (none were
// available in this repo) - test carefully against a real upload before
// trusting the numbers this produces.

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/** Restricts PhpSpreadsheet's loader to only materialize the handful of
 * columns a given report actually reads (see each kck_sheet_rows() call
 * site below) - observed in production: a real Bill Item Detailed Report
 * can be 40+ columns wide, but kck_parse_bill_item_report only reads 5 of
 * them. Without this, PhpSpreadsheet builds a full Cell object for every
 * column of every row regardless of whether anything ever looks at it,
 * which is most of this reader's actual memory cost for a wide real sheet -
 * skipping the ones we never use cuts that roughly in proportion to columns
 * used/columns total. toArray()'s row length is unaffected (it reflects the
 * sheet's real dimensions either way) - a filtered-out cell just reads back
 * as null, same as kck_col() already treats a legitimately empty one. */
class KckColumnFilter implements IReadFilter {
    private array $allowedLetters = [];
    public function __construct(array $zeroIndexedCols) {
        foreach ($zeroIndexedCols as $c) {
            $this->allowedLetters[Coordinate::stringFromColumnIndex($c + 1)] = true;
        }
    }
    public function readCell($column, $row, $worksheetName = ''): bool {
        return isset($this->allowedLetters[$column]);
    }
}

/** Python's bool(x) truthiness for the scalar types cell values take. */
function kck_py_truthy($v): bool {
    if ($v === null || $v === false) return false;
    if (is_string($v)) return $v !== '';
    if (is_int($v) || is_float($v)) return $v != 0;
    return (bool) $v;
}

function kck_is_number($v): bool {
    return is_int($v) || is_float($v);
}

/** Case-insensitive-tolerant xlsx -> array-of-rows reader. Mirrors
 * openpyxl.load_workbook(fp, data_only=True, read_only=True).iter_rows(...,
 * values_only=True): 0-indexed columns, raw (unformatted) values, formulas
 * resolved to their value not their text. $minRow is 1-indexed, same as
 * openpyxl's min_row. */
function kck_sheet_rows(string $fp, ?string $sheetName, int $minRow, ?array $neededCols = null): array {
    $reader = IOFactory::createReaderForFile($fp);
    $reader->setReadDataOnly(true);
    if ($neededCols !== null) {
        $reader->setReadFilter(new KckColumnFilter($neededCols));
    }
    $spreadsheet = $reader->load($fp);
    if ($sheetName !== null && in_array($sheetName, $spreadsheet->getSheetNames(), true)) {
        $sheet = $spreadsheet->getSheetByName($sheetName);
    } else {
        $sheet = $spreadsheet->getSheet(0);
    }
    $all = $sheet->toArray(null, true, false, false);
    // PhpSpreadsheet's internal cell/style objects hold circular references,
    // so PHP's refcounting GC won't reclaim a workbook's memory just because
    // $spreadsheet goes out of scope - disconnectWorksheets() is
    // PhpSpreadsheet's own documented way to actually free it. Without this,
    // parsing a real upload's 8 real files (Payment/Bill Item/Discount/KOT x
    // 2 branches) in one request accumulates every workbook's memory on top
    // of the last instead of releasing each one - easily enough to exceed
    // this host's actual enforced memory cap (which can be stricter than
    // php.ini's own memory_limit on shared hosting) well before 8 real-sized
    // files are done, killing the process with no PHP-level error to log.
    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet, $sheet);
    return array_slice($all, $minRow - 1);
}

function kck_col(array $row, int $idx) {
    return $row[$idx] ?? null;
}

function kck_find_branch_file(string $reportDir, string $branchHint): string {
    foreach (glob($reportDir . '/*.xlsx') ?: [] as $fp) {
        if (stripos(basename($fp), $branchHint) !== false) {
            return $fp;
        }
    }
    throw new RuntimeException("No file matching '$branchHint' in $reportDir");
}

/** Case-insensitive lookup of a subfolder named $name inside $parent - see
 * parsers.py's find_dir_ci docstring for why this matters on Linux. */
function kck_find_dir_ci(string $parent, string $name): string {
    $exact = $parent . '/' . $name;
    if (is_dir($exact)) return $exact;
    if (!is_dir($parent)) return $exact;
    $target = strtolower($name);
    foreach (scandir($parent) ?: [] as $entry) {
        if (strtolower($entry) === $target && is_dir($parent . '/' . $entry)) {
            return $parent . '/' . $entry;
        }
    }
    return $exact;
}

/** Payment/Bill-Item date-times come as 'DD-MM-YYYY hh:mm:ss pm' or
 * 'DD-Mon-YYYY hh:mm:ss pm' strings, or occasionally a real Excel date
 * serial number. Returns 'YYYY-MM-DD' or null. */
function kck_to_iso_date($value): ?string {
    if ($value === null) return null;
    if (kck_is_number($value)) {
        try {
            return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
        } catch (Throwable $e) {
            return null;
        }
    }
    $s = trim((string) $value);
    foreach (['d-m-Y h:i:s A', 'd-M-Y h:i:s A'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt !== false) return $dt->format('Y-m-d');
    }
    return null;
}

function kck_to_hour($value): ?int {
    if ($value === null) return null;
    if (kck_is_number($value)) {
        try {
            return (int) ExcelDate::excelToDateTimeObject($value)->format('G');
        } catch (Throwable $e) {
            return null;
        }
    }
    $s = trim((string) $value);
    foreach (['d-m-Y h:i:s A', 'd-M-Y h:i:s A'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        if ($dt !== false) return (int) $dt->format('G');
    }
    return null;
}

const KCK_SERVICE_TYPE_BANQUET_NAMES = ['BANQUETS', 'ODC'];

function kck_service_type(?string $tabName, ?string $tabType): string {
    $tabName = trim($tabName ?? '');
    $tabType = strtolower(trim($tabType ?? ''));
    if (in_array(strtoupper($tabName), KCK_SERVICE_TYPE_BANQUET_NAMES, true)) {
        return 'Banquets & Catering';
    }
    if ($tabType === 'delivery') return 'Home Delivery';
    if ($tabType === 'takeout') return 'Takeaway';
    return 'Dine-In';
}

/** -> [bills rows, bill_no => total_amount map (for the discount join)].
 * See parsers.py's parse_payment_report docstring for the same-bill-number-
 * on-multiple-rows aggregation this performs. */
function kck_parse_payment_report(string $fp, string $branchLabel): array {
    // bill_no, open_time, tab_name, tab_type, covers, total_amount, net_sales, void_flag
    $rows = kck_sheet_rows($fp, 'Sheet1', 7, [1, 4, 6, 7, 8, 11, 13, 45]);
    $agg = [];
    $order = [];
    $billTotals = [];
    foreach ($rows as $row) {
        $billNo = kck_col($row, 1);
        if ($billNo === null) continue; // date-section marker row
        $voidRaw = kck_col($row, 45);
        $voidFlag = $voidRaw !== null ? strtoupper(trim((string) $voidRaw)) : 'NO';
        if ($voidFlag === 'YES') continue;

        $openTime = kck_col($row, 4);
        $rowDate = kck_to_iso_date($openTime);
        $hour = kck_to_hour($openTime);
        $covers = kck_py_truthy(kck_col($row, 8)) ? kck_col($row, 8) : 0;
        $netSales = kck_col($row, 13) !== null ? kck_col($row, 13) : 0.0;
        $totalAmount = kck_col($row, 11) !== null ? kck_col($row, 11) : 0.0;
        $tabName = kck_col($row, 6);
        $tabType = kck_col($row, 7);

        $billKey = trim((string) $billNo);
        $billTotals[$billKey] = ($billTotals[$billKey] ?? 0.0) + (float) $totalAmount;

        if ($rowDate === null || $hour === null) continue;
        if ((float) $netSales == 0.0) continue; // not a real bill for counting purposes

        $covers = kck_is_number($covers) ? (int) $covers : 0;
        if (isset($agg[$billKey])) {
            $agg[$billKey]['covers'] += $covers;
            $agg[$billKey]['salesValue'] += (float) $netSales;
        } else {
            $agg[$billKey] = [
                'date' => $rowDate,
                'hour' => $hour,
                'branch' => $branchLabel,
                'bill' => $billNo,
                'covers' => $covers,
                'salesValue' => (float) $netSales,
                'serviceType' => kck_service_type($tabName, $tabType),
            ];
            $order[] = $billKey;
        }
    }
    $bills = array_map(fn($k) => $agg[$k], $order);
    return [$bills, $billTotals];
}

function kck_clean_name($value) {
    if (!kck_py_truthy($value)) return $value;
    return trim(preg_replace('/\s+/', ' ', (string) $value));
}

/** -> aggregated sales rows (per date+branch+category+item). */
function kck_parse_bill_item_report(string $fp, string $branchLabel): array {
    // open_time, category, item, qty, amount
    $rows = kck_sheet_rows($fp, 'Sheet1', 9, [7, 10, 11, 16, 18]);
    $agg = []; // "date\x01category\x01item" => ['qty'=>, 'value'=>]
    // Total portions per exact spelling of each case-insensitive dish, used below to
    // pick the spelling to show.
    $spellQty = []; // "lower(category)\x01lower(item)" => ["category\x02item" => qty]
    foreach ($rows as $row) {
        $qty = kck_col($row, 16);
        if (!kck_is_number($qty) || $qty <= 0) continue; // header repeats / BILL TOTAL / combo sub-lines
        $category = kck_clean_name(kck_col($row, 10));
        $item = kck_clean_name(kck_col($row, 11));
        $amountRaw = kck_col($row, 18);
        $amount = kck_is_number($amountRaw) ? $amountRaw : 0.0;
        $rowDate = kck_to_iso_date(kck_col($row, 7)); // Open Time
        if ($rowDate === null || !kck_py_truthy($item)) continue;
        // Posist spells one dish in several cases ("Erachi curry" / "ERACHI CURRY"), and the
        // database treats those as the SAME key - so they must be summed here, not
        // inserted separately (the second insert would overwrite the first's quantity).
        $dishKey = mb_strtolower((string) $category) . "\x01" . mb_strtolower((string) $item);
        $spellQty[$dishKey][$category . "\x02" . $item] = ($spellQty[$dishKey][$category . "\x02" . $item] ?? 0) + $qty;
        $key = $rowDate . "\x01" . $dishKey;
        if (!isset($agg[$key])) $agg[$key] = ['date' => $rowDate, 'dishKey' => $dishKey, 'qty' => 0.0, 'value' => 0.0];
        $agg[$key]['qty'] += $qty;
        $agg[$key]['value'] += $amount;
    }
    $canonical = [];
    foreach ($spellQty as $dishKey => $spellings) {
        arsort($spellings);
        $best = (string) array_key_first($spellings);
        [$cat, $itm] = explode("\x02", $best, 2);
        $canonical[$dishKey] = [$cat, $itm];
    }
    foreach ($agg as $k => $v) {
        $agg[$k]['category'] = $canonical[$v['dishKey']][0];
        $agg[$k]['item'] = $canonical[$v['dishKey']][1];
    }

    $sales = [];
    foreach ($agg as $v) {
        $rate = $v['qty'] ? round($v['value'] / $v['qty'], 2) : 0.0;
        $qty = $v['qty'];
        $sales[] = [
            'date' => $v['date'],
            'branch' => $branchLabel,
            'category' => $v['category'],
            'item' => $v['item'],
            'qty' => fmod($qty, 1) != 0 ? $qty : (int) $qty,
            'rate' => $rate,
            'value' => round($v['value'], 2),
        ];
    }
    return $sales;
}

function kck_discount_reason_from_remark($remarks): string {
    $r = kck_py_truthy($remarks) ? kck_clean_name($remarks) : null;
    if (!kck_py_truthy($r) || $r === '-') return 'Not Specified';
    return strtoupper($r);
}

/** The Discount report's date column is already plain text, unlike the
 * datetime-stamped Payment/Bill-Item reports - only a datetime/date-object
 * safety net is needed, no string parsing. */
function kck_discount_date($value) {
    if (kck_is_number($value)) {
        try {
            return ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
        } catch (Throwable $e) {
            return $value;
        }
    }
    return $value;
}

/** See parsers.py's parse_discount_report docstring for why "lineSeq" (this
 * line's 0-based position among this bill's own discount lines) is part of
 * the dedup key. */
function kck_parse_discount_report(string $fp, string $branchLabel, array $billTotals): array {
    // trxno, date, disc_amt, type, remarks, is_foc
    $rows = kck_sheet_rows($fp, 'Sheet1', 7, [3, 4, 6, 7, 9, 11]);
    $discounts = [];
    $lineSeq = [];
    foreach ($rows as $row) {
        $trxno = kck_col($row, 4);
        if (!kck_py_truthy($trxno)) continue;
        $rowDate = kck_discount_date(kck_col($row, 3));
        $discAmtRaw = kck_col($row, 7);
        $discAmt = kck_is_number($discAmtRaw) ? $discAmtRaw : 0.0;
        if ($discAmt <= 0) continue;   // listed in the report but nothing was discounted
        $isFocRaw = kck_col($row, 11);
        $isFoc = $isFocRaw !== null ? (strtoupper(trim((string) $isFocRaw)) === 'YES') : false;
        $billKey = trim((string) $trxno);
        $itemValue = $billTotals[$billKey] ?? null;
        if ($itemValue === null) {
            $itemValue = $discAmt; // bill not found in this month's Payment Report
        }
        $net = round($itemValue - $discAmt, 2);
        $seq = $lineSeq[$billKey] ?? 0;
        $lineSeq[$billKey] = $seq + 1;
        $discounts[] = [
            'date' => $rowDate,
            'branch' => $branchLabel,
            'bill' => $trxno,
            'lineSeq' => $seq,
            'itemValue' => round($itemValue, 2),
            'discountValue' => round($discAmt, 2),
            'net' => $net,
            'isFull' => $isFoc,
            'reason' => kck_discount_reason_from_remark(kck_col($row, 9)),
            'type' => kck_col($row, 6),
            'remarks' => kck_col($row, 9),
        ];
    }
    return $discounts;
}

function kck_classify_cancel_reason($comment): string {
    $c = $comment === null ? '' : trim((string) $comment);
    if ($c === '') return 'Not Specified';
    $cl = strtolower($c);
    if ($cl === 'guest cancellation') return 'Guest Cancellation';
    if ($cl === 'change of item') return 'Change Of Item';
    return 'Other';
}

/** A comment cell's text exactly as typed in the KOT report, or '' only when the cell is blank.
 * Anything else ("-", "N/A", "1", "w"...) is a real comment and is kept as the reason. */
function kck_real_comment($comment): string {
    if (is_float($comment) && floor($comment) == $comment) $comment = (int) $comment; // Excel-typed "1" arrives as 1.0
    return $comment === null ? '' : trim(preg_replace('/\s+/', ' ', (string) $comment));
}

/** Shown when Posist holds no reason anywhere for a cancelled item; the KOT's own status
 * ("KOT Voided" / "Bill Voided") is the only extra information available. */
function kck_no_reason_text(string $status): string {
    return 'No reason entered in Posist' . (($status !== '' && $status !== '-') ? " ($status)" : '');
}

/** -> cancellation rows, one per cancelled ("Deleted") item line. See
 * parsers.py's parse_kot_tracking_report docstring in full - this is the
 * most structurally unusual report (nested/stateful layout, rows classified
 * by value SHAPE rather than fixed column index since the column layout
 * itself drifts per branch). Ported as closely to the line-by-line Python
 * logic as possible. */
function kck_parse_kot_tracking_report(string $fp, string $branchLabel): array {
    // Real-world exports don't reliably name their one data tab "Sheet1" -
    // kck_sheet_rows() already falls back to the first sheet when "Sheet1"
    // isn't present.
    // c0 (date marker/item name), qty, bill-header-or-rate shape check, value, status, reason
    // Start at row 8 (the column-title row) so each file's own column layout can be read; the
    // title row is recognised below and never treated as data. Columns 0-9 cover the KOT header
    // row (incl. KOT Status / KOT Comment / Edit Bill Comment, whose position differs per branch).
    $rows = kck_sheet_rows($fp, 'Sheet1', 8, [0, 1, 2, 3, 4, 5, 6, 7, 8, 9]);
    $colStatus = null; $colKotComment = null; $colBillComment = null;
    $curStatus = ''; $curKotComment = ''; $curBillComment = '';
    $agg = []; // "date\x01bill\x01lower(item)\x01reason" => ['qty'=>,'value'=>,'reason'=>]
    $spellQty = []; // lower(item) => [exact spelling => qty], to pick the spelling to show
    $curDate = null;
    $curBill = null;
    foreach ($rows as $row) {
        $c0 = kck_col($row, 0);
        if ($c0 === null) continue;
        $s0 = trim((string) $c0);
        if ($s0 === 'KOT Number') {
            foreach ($row as $i => $h) {
                $name = trim((string) $h);
                if ($name === 'KOT Status') $colStatus = $i;
                elseif ($name === 'KOT Comment') $colKotComment = $i;
                elseif ($name === 'Edit Bill Comment') $colBillComment = $i;
            }
            continue;
        }
        if (kck_col($row, 1) === null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s0)) {
            $curDate = $s0;
            continue;
        }
        if (strpos($s0, 'Instance') === 0 || $s0 === 'Item Name' || $s0 === 'KOT Total') {
            continue;
        }
        $c2 = kck_col($row, 2);
        if (is_string($c2)) {
            // KOT header row: the bill number, plus this KOT's status and any KOT / bill level
            // comment (used when an item has no comment of its own).
            $curBill = trim($c2);
            $curStatus = $colStatus !== null ? trim((string) kck_col($row, $colStatus)) : '';
            $curKotComment = $colKotComment !== null ? kck_real_comment(kck_col($row, $colKotComment)) : '';
            $curBillComment = $colBillComment !== null ? kck_real_comment(kck_col($row, $colBillComment)) : '';
            continue;
        }
        $c1 = kck_col($row, 1);
        if (!(kck_is_number($c1) && kck_is_number($c2))) {
            continue; // unrecognised row shape - skip rather than guess
        }
        $statusRaw = kck_col($row, 4);
        $status = $statusRaw !== null ? trim((string) $statusRaw) : '';
        if ($status !== 'Deleted' || $curDate === null || $curBill === null) continue;
        $item = kck_clean_name($c0);
        if (!kck_py_truthy($item)) continue;
        $qty = $c1;
        $valueRaw = kck_col($row, 3);
        $value = kck_is_number($valueRaw) ? (float) $valueRaw : 0.0;
        // The item's own comment wins; otherwise the KOT's comment, then the bill's. Only when
        // none of them has any text is the item truly "Not Specified".
        $effective = kck_real_comment(kck_col($row, 5));
        if ($effective === '') $effective = $curKotComment;
        if ($effective === '') $effective = $curBillComment;
        $reason = kck_classify_cancel_reason($effective === '' ? null : $effective);
        $reasonText = $effective !== '' ? $effective : kck_no_reason_text($curStatus);
        $itemKey = mb_strtolower((string) $item);
        $spellQty[$itemKey][$item] = ($spellQty[$itemKey][$item] ?? 0) + $qty;
        $key = $curDate . "\x01" . $curBill . "\x01" . $itemKey . "\x01" . $reason;
        if (isset($agg[$key])) {
            $agg[$key]['qty'] += $qty;
            $agg[$key]['value'] += $value;
            if (!in_array($reasonText, $agg[$key]['texts'], true)) $agg[$key]['texts'][] = $reasonText;
        } else {
            $agg[$key] = ['date' => $curDate, 'bill' => $curBill, 'itemKey' => $itemKey, 'reason' => $reason, 'qty' => $qty, 'value' => $value, 'texts' => [$reasonText]];
        }
    }
    $canonicalItem = [];
    foreach ($spellQty as $itemKey => $spellings) {
        arsort($spellings);
        $canonicalItem[$itemKey] = (string) array_key_first($spellings);
    }

    $cancellations = [];
    foreach ($agg as $v) {
        $qty = $v['qty'];
        $cancellations[] = [
            'date' => $v['date'],
            'branch' => $branchLabel,
            'bill' => $v['bill'],
            'item' => $canonicalItem[$v['itemKey']],
            'qty' => fmod($qty, 1) != 0 ? $qty : (int) $qty,
            'rate' => $qty ? round($v['value'] / $qty, 2) : 0.0,
            'value' => round($v['value'], 2),
            'reason' => $v['reason'],
            'reasonText' => mb_substr(implode('; ', $v['texts']), 0, 255),
        ];
    }
    return $cancellations;
}

const KCK_BRANCH_MAP = ['Bangalore' => 'Bengaluru', 'Chennai' => 'Chennai'];

/** Parse a whole Posist export folder -> [bills, sales, discounts,
 * cancellations, missing]. See parsers.py's parse_posist_export docstring:
 * a missing report type/branch file is tolerated (empty section + a
 * "missing" note); only raises if there isn't a single bill anywhere. */
/** Logs a step marker with elapsed time, current memory, and peak memory -
 * if the process gets killed externally (OOM/resource-limit kill, which
 * bypasses PHP's own exception/error handling entirely - no catch block or
 * shutdown function runs), whatever was logged right before the kill is the
 * only evidence of which file/branch/step was in progress at the time. */
function kck_log_step(string $msg, float $startTime): void {
    $elapsed = round(microtime(true) - $startTime, 1);
    $mem = round(memory_get_usage(true) / 1024 / 1024, 1);
    $peak = round(memory_get_peak_usage(true) / 1024 / 1024, 1);
    error_log("[upload-progress] +{$elapsed}s mem={$mem}MB peak={$peak}MB: $msg");
}

function kck_parse_posist_export(string $posistRoot): array {
    $t0 = microtime(true);
    $paymentDir = kck_find_dir_ci($posistRoot, 'Payment Report');
    $billItemDir = kck_find_dir_ci($posistRoot, 'Bill Item Detailed Report');
    $discountDir = kck_find_dir_ci($posistRoot, 'Discount and Voucher Report');
    $kotDir = kck_find_dir_ci($posistRoot, 'KOT Tracking Report');

    $allBills = [];
    $salesData = [];
    $discountData = [];
    $cancellationData = [];
    $billTotalsByBranch = [];
    $missing = [];

    foreach (KCK_BRANCH_MAP as $fileHint => $branchLabel) {
        try {
            $fp = kck_find_branch_file($paymentDir, $fileHint);
        } catch (RuntimeException $e) {
            $missing[] = "Payment Report ($branchLabel)";
            $billTotalsByBranch[$branchLabel] = [];
            continue;
        }
        kck_log_step("starting Payment Report ($branchLabel), " . round(filesize($fp) / 1024, 0) . " KB", $t0);
        [$bills, $billTotals] = kck_parse_payment_report($fp, $branchLabel);
        kck_log_step("finished Payment Report ($branchLabel): " . count($bills) . " bills", $t0);
        $allBills = array_merge($allBills, $bills);
        $billTotalsByBranch[$branchLabel] = $billTotals;
    }

    if (!$allBills) {
        throw new RuntimeException(
            "No 'Payment Report' found for either branch - can't tell which dates this upload covers."
        );
    }

    foreach (KCK_BRANCH_MAP as $fileHint => $branchLabel) {
        try {
            $fp = kck_find_branch_file($billItemDir, $fileHint);
        } catch (RuntimeException $e) {
            $missing[] = "Bill Item Detailed Report ($branchLabel)";
            continue;
        }
        kck_log_step("starting Bill Item Detailed Report ($branchLabel), " . round(filesize($fp) / 1024, 0) . " KB", $t0);
        $rows = kck_parse_bill_item_report($fp, $branchLabel);
        kck_log_step("finished Bill Item Detailed Report ($branchLabel): " . count($rows) . " rows", $t0);
        $salesData = array_merge($salesData, $rows);
    }

    foreach (KCK_BRANCH_MAP as $fileHint => $branchLabel) {
        try {
            $fp = kck_find_branch_file($discountDir, $fileHint);
        } catch (RuntimeException $e) {
            $missing[] = "Discount and Voucher Report ($branchLabel)";
            continue;
        }
        kck_log_step("starting Discount and Voucher Report ($branchLabel), " . round(filesize($fp) / 1024, 0) . " KB", $t0);
        $rows = kck_parse_discount_report($fp, $branchLabel, $billTotalsByBranch[$branchLabel]);
        kck_log_step("finished Discount and Voucher Report ($branchLabel): " . count($rows) . " rows", $t0);
        $discountData = array_merge($discountData, $rows);
    }

    foreach (KCK_BRANCH_MAP as $fileHint => $branchLabel) {
        try {
            $fp = kck_find_branch_file($kotDir, $fileHint);
        } catch (RuntimeException $e) {
            $missing[] = "KOT Tracking Report ($branchLabel)";
            continue;
        }
        kck_log_step("starting KOT Tracking Report ($branchLabel), " . round(filesize($fp) / 1024, 0) . " KB", $t0);
        $rows = kck_parse_kot_tracking_report($fp, $branchLabel);
        kck_log_step("finished KOT Tracking Report ($branchLabel): " . count($rows) . " rows", $t0);
        $cancellationData = array_merge($cancellationData, $rows);
    }

    kck_log_step('parsing complete, starting DB write', $t0);
    return [$allBills, $salesData, $discountData, $cancellationData, $missing];
}
