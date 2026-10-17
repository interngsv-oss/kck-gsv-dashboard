<?php
// Relational (real typed columns per field) storage layer - one table per
// dataset, matching schema.sql. Same function signatures/semantics as the
// original backend/storage_db.py (and this file's own earlier JSON-blob
// version) - see those for the *why* behind each operation; this file only
// notes where the relational shape changes *how* something is done.

require_once __DIR__ . '/db.php';

const KCK_KEY_FIELDS = [
    'bills' => ['branch', 'bill', 'date'],
    'sales' => ['branch', 'date', 'category', 'item'],
    'discounts' => ['branch', 'bill', 'date', 'lineSeq'],
    'cancellations' => ['branch', 'bill', 'date', 'item', 'reason'],
];

// Column order for each dataset's table - must match schema.sql exactly.
const KCK_TABLE_COLUMNS = [
    'bills' => ['branch', 'bill', 'date', 'hour', 'covers', 'salesValue', 'serviceType'],
    'sales' => ['branch', 'date', 'category', 'item', 'qty', 'rate', 'value'],
    'discounts' => ['branch', 'bill', 'date', 'lineSeq', 'itemValue', 'discountValue', 'net', 'isFull', 'reason', 'type', 'remarks'],
    'cancellations' => ['branch', 'bill', 'date', 'item', 'reason', 'qty', 'rate', 'value', 'reasonText'],
];

function kck_default_meta(): array {
    return [
        'dataStart' => null, 'dataEnd' => null, 'lastRefreshed' => null,
        'reasons' => ['Guest Cancellation', 'Change Of Item', 'Not Specified', 'Other'],
        'discountReasons' => [], 'serviceTypes' => ['Dine-In', 'Home Delivery', 'Takeaway', 'Banquets & Catering'],
    ];
}

/** Mirrors Python's str(x) closely enough for an in-memory dedup key: str(None)
 * is the literal "None" in Python, not an empty string. Only used to collapse
 * duplicate business-key rows within a single upload batch before writing -
 * the database's own PRIMARY KEY is what actually enforces uniqueness. */
function kck_py_str($v): string {
    if ($v === null) return 'None';
    if (is_bool($v)) return $v ? 'True' : 'False';
    return (string) $v;
}

function kck_row_key(string $dataset, array $row): string {
    $parts = [];
    foreach (KCK_KEY_FIELDS[$dataset] as $f) {
        $parts[] = kck_py_str($row[$f] ?? null);
    }
    return implode('|', $parts);
}

function kck_month_key(string $dateStr): string {
    return substr($dateStr, 0, 7);
}

/** Row array -> bound-parameter array, in each table's column order. */
function kck_row_to_db(string $dataset, array $row): array {
    $out = [];
    foreach (KCK_TABLE_COLUMNS[$dataset] as $col) {
        $v = $row[$col] ?? null;
        if (is_bool($v)) $v = $v ? 1 : 0;
        $out[$col] = $v;
    }
    return $out;
}

/** DB row -> the same row shape parsers.php/the frontend expect. PDO returns
 * DECIMAL columns as strings - "+ 0" numifies them the same way Python's
 * json module would (int if whole, float otherwise), matching qty's
 * int-or-float behaviour in parse_bill_item_report/parse_kot_tracking_report. */
function kck_row_from_db(string $dataset, array $r): array {
    switch ($dataset) {
        case 'bills':
            return [
                'branch' => $r['branch'], 'bill' => $r['bill'], 'date' => $r['date'],
                'hour' => (int) $r['hour'], 'covers' => (int) $r['covers'],
                'salesValue' => (float) $r['salesValue'], 'serviceType' => $r['serviceType'],
            ];
        case 'sales':
            return [
                'branch' => $r['branch'], 'date' => $r['date'], 'category' => $r['category'], 'item' => $r['item'],
                'qty' => $r['qty'] + 0, 'rate' => (float) $r['rate'], 'value' => (float) $r['value'],
            ];
        case 'discounts':
            return [
                'branch' => $r['branch'], 'bill' => $r['bill'], 'date' => $r['date'], 'lineSeq' => (int) $r['lineSeq'],
                'itemValue' => (float) $r['itemValue'], 'discountValue' => (float) $r['discountValue'],
                'net' => (float) $r['net'], 'isFull' => (bool) $r['isFull'],
                'reason' => $r['reason'], 'type' => $r['type'], 'remarks' => $r['remarks'],
            ];
        case 'cancellations':
            return [
                'branch' => $r['branch'], 'bill' => $r['bill'], 'date' => $r['date'], 'item' => $r['item'],
                'reason' => $r['reason'], 'qty' => $r['qty'] + 0, 'rate' => (float) $r['rate'], 'value' => (float) $r['value'],
                'reasonText' => $r['reasonText'] ?? null,
            ];
    }
    throw new InvalidArgumentException("Unknown dataset '$dataset'");
}

function kck_read_meta(): array {
    $pdo = kck_db();
    $row = $pdo->query('SELECT dataStart, dataEnd, lastRefreshed FROM app_meta WHERE id = 1')->fetch();
    $reasons = $pdo->query('SELECT reason FROM discount_reasons ORDER BY reason')->fetchAll(PDO::FETCH_COLUMN);
    $defaults = kck_default_meta();
    return [
        'dataStart' => $row['dataStart'] ?? $defaults['dataStart'],
        'dataEnd' => $row['dataEnd'] ?? $defaults['dataEnd'],
        'lastRefreshed' => $row['lastRefreshed'] ?? $defaults['lastRefreshed'],
        'reasons' => $defaults['reasons'],
        'discountReasons' => $reasons ?: $defaults['discountReasons'],
        'serviceTypes' => $defaults['serviceTypes'],
    ];
}

function kck_write_meta(array $meta): void {
    $pdo = kck_db();
    $pdo->prepare(
        'INSERT INTO app_meta (id, dataStart, dataEnd, lastRefreshed) VALUES (1, :s, :e, :r) ' .
        'ON DUPLICATE KEY UPDATE dataStart = VALUES(dataStart), dataEnd = VALUES(dataEnd), lastRefreshed = VALUES(lastRefreshed)'
    )->execute([
        ':s' => $meta['dataStart'] ?? null,
        ':e' => $meta['dataEnd'] ?? null,
        ':r' => $meta['lastRefreshed'] ?? null,
    ]);

    if (array_key_exists('discountReasons', $meta)) {
        $pdo->exec('DELETE FROM discount_reasons');
        if ($meta['discountReasons']) {
            $ins = $pdo->prepare('INSERT INTO discount_reasons (reason) VALUES (:r)');
            foreach ($meta['discountReasons'] as $r) {
                $ins->execute([':r' => $r]);
            }
        }
    }
}

function kck_read_range(string $dataset, ?string $fromMonth = null, ?string $toMonth = null): array {
    $pdo = kck_db();
    $sql = "SELECT * FROM `$dataset`";
    $where = [];
    $params = [];
    if ($fromMonth) { $where[] = "DATE_FORMAT(date, '%Y-%m') >= :from_month"; $params[':from_month'] = $fromMonth; }
    if ($toMonth) { $where[] = "DATE_FORMAT(date, '%Y-%m') <= :to_month"; $params[':to_month'] = $toMonth; }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map(fn($r) => kck_row_from_db($dataset, $r), $stmt->fetchAll());
}

function kck_insert_sql(string $dataset): string {
    $cols = KCK_TABLE_COLUMNS[$dataset];
    $colList = implode(', ', array_map(fn($c) => "`$c`", $cols));
    $placeholders = implode(', ', array_map(fn($c) => ":$c", $cols));
    $nonKey = array_diff($cols, KCK_KEY_FIELDS[$dataset]);
    $updateList = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $nonKey));
    return "INSERT INTO `$dataset` ($colList) VALUES ($placeholders) ON DUPLICATE KEY UPDATE $updateList";
}

/** Core of kck_replace_months, against an already-open PDO so a caller
 * juggling several datasets (bulk_write_upload) can do it all in one
 * transaction instead of reconnecting per dataset. Upserts (not a plain
 * INSERT) so a frontend-retried, overlapping upload request racing the
 * first one can't fatally collide on the PRIMARY KEY - see
 * hostinger_php_backend_gotchas memory. */
function kck_replace_months_tx(PDO $pdo, string $dataset, array $rows): array {
    $byMonth = [];
    foreach ($rows as $row) {
        $byMonth[kck_month_key($row['date'])][] = $row;
    }

    $result = [];
    $del = $pdo->prepare("DELETE FROM `$dataset` WHERE DATE_FORMAT(date, '%Y-%m') = :month");
    $ins = $pdo->prepare(kck_insert_sql($dataset));
    foreach ($byMonth as $month => $newRows) {
        $del->execute([':month' => $month]);
        $deduped = [];
        foreach ($newRows as $row) {
            $deduped[kck_row_key($dataset, $row)] = $row;
        }
        foreach ($deduped as $row) {
            $ins->execute(kck_row_to_db($dataset, $row));
        }
        $result[$month] = ['added' => count($deduped), 'updated' => 0];
    }
    return $result;
}

function kck_replace_months(string $dataset, array $rows): array {
    $pdo = kck_db();
    $pdo->beginTransaction();
    try {
        $result = kck_replace_months_tx($pdo, $dataset, $rows);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Everything POST /api/upload needs to write, in one transaction (either
 * the whole upload lands, or none of it does).
 *
 * datasetsRows: ['bills' => [...], 'sales' => [...], ...]
 * meta: full meta array (caller has already merged in discountReasons/
 *     lastRefreshed/dataStart/dataEnd)
 * historyExtra: ['files' => [...], 'missing' => [...]]
 * Returns [dataset => [month => ['added' => n, 'updated' => 0]]].
 */
function kck_bulk_write_upload(array $datasetsRows, array $meta, array $historyExtra): array {
    $pdo = kck_db();
    $pdo->beginTransaction();
    try {
        $results = [];
        foreach ($datasetsRows as $ds => $rows) {
            $results[$ds] = kck_replace_months_tx($pdo, $ds, $rows);
        }

        kck_write_meta($meta);

        $monthsTouched = [];
        foreach ($results as $r) {
            foreach (array_keys($r) as $m) {
                $monthsTouched[$m] = true;
            }
        }
        $monthsTouched = array_keys($monthsTouched);
        sort($monthsTouched);

        $pdo->prepare('INSERT INTO app_upload_history (timestamp) VALUES (:ts)')
            ->execute([':ts' => $meta['lastRefreshed']]);
        $historyId = (int) $pdo->lastInsertId();

        $fileStmt = $pdo->prepare('INSERT INTO app_upload_history_files (history_id, filename) VALUES (:id, :f)');
        foreach ($historyExtra['files'] as $f) {
            $fileStmt->execute([':id' => $historyId, ':f' => $f]);
        }

        $monthStmt = $pdo->prepare('INSERT INTO app_upload_history_months (history_id, month) VALUES (:id, :m)');
        foreach ($monthsTouched as $m) {
            $monthStmt->execute([':id' => $historyId, ':m' => $m]);
        }

        $missingStmt = $pdo->prepare('INSERT INTO app_upload_history_missing (history_id, description) VALUES (:id, :d)');
        foreach ($historyExtra['missing'] as $d) {
            $missingStmt->execute([':id' => $historyId, ':d' => $d]);
        }

        $countStmt = $pdo->prepare(
            'INSERT INTO app_upload_history_counts (history_id, dataset, month, added, updated) VALUES (:id, :ds, :m, :a, :u)'
        );
        foreach ($results as $ds => $r) {
            foreach ($r as $month => $counts) {
                $countStmt->execute([':id' => $historyId, ':ds' => $ds, ':m' => $month, ':a' => $counts['added'], ':u' => $counts['updated']]);
            }
        }

        // Keep only the most recent 500 (ON DELETE CASCADE cleans up every
        // child table automatically).
        $pdo->exec(
            'DELETE FROM app_upload_history WHERE id NOT IN ' .
            '(SELECT id FROM (SELECT id FROM app_upload_history ORDER BY id DESC LIMIT 500) t)'
        );

        $pdo->commit();
        return $results;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function kck_upsert_rows(string $dataset, array $rows): array {
    $pdo = kck_db();
    $byMonth = [];
    foreach ($rows as $row) {
        $byMonth[kck_month_key($row['date'])][] = $row;
    }

    $result = [];
    // MySQL/MariaDB's own INSERT ... ON DUPLICATE KEY UPDATE row-count
    // convention: 1 affected row for an INSERT, 2 for an UPDATE - used here
    // (same as before) to report added/updated without a separate SELECT.
    $stmt = $pdo->prepare(kck_insert_sql($dataset));
    foreach ($byMonth as $month => $newRows) {
        $deduped = [];
        foreach ($newRows as $row) {
            $deduped[kck_row_key($dataset, $row)] = $row;
        }
        $added = 0;
        $updated = 0;
        foreach ($deduped as $row) {
            $stmt->execute(kck_row_to_db($dataset, $row));
            if ($stmt->rowCount() >= 2) {
                $updated++;
            } else {
                $added++;
            }
        }
        $result[$month] = ['added' => $added, 'updated' => $updated];
    }
    return $result;
}

function kck_delete_month(string $dataset, string $month): int {
    $pdo = kck_db();
    $stmt = $pdo->prepare("DELETE FROM `$dataset` WHERE DATE_FORMAT(date, '%Y-%m') = :month");
    $stmt->execute([':month' => $month]);
    return $stmt->rowCount();
}

function kck_delete_row(string $dataset, array $keyRow): bool {
    $pdo = kck_db();
    $fields = KCK_KEY_FIELDS[$dataset];
    $where = implode(' AND ', array_map(fn($f) => "`$f` = :$f", $fields));
    $params = [];
    foreach ($fields as $f) {
        $params[":$f"] = $keyRow[$f] ?? null;
    }
    $stmt = $pdo->prepare("DELETE FROM `$dataset` WHERE $where");
    $stmt->execute($params);
    return $stmt->rowCount() > 0;
}

function kck_clear_all(): void {
    $pdo = kck_db();
    foreach (['bills', 'sales', 'discounts', 'cancellations'] as $t) {
        $pdo->exec("DELETE FROM `$t`");
    }
    $pdo->exec('DELETE FROM discount_reasons');
    $pdo->exec('DELETE FROM app_upload_history'); // CASCADEs to every child table
    kck_write_meta(kck_default_meta());
}

function kck_recompute_meta_dates(): array {
    $pdo = kck_db();
    $row = $pdo->query('SELECT MIN(date) AS min_d, MAX(date) AS max_d FROM bills')->fetch();
    $meta = kck_read_meta();
    $meta['dataStart'] = $row['min_d'];
    $meta['dataEnd'] = $row['max_d'];
    kck_write_meta($meta);
    return $meta;
}

function kck_read_upload_history(): array {
    $pdo = kck_db();
    $histories = $pdo->query('SELECT id, timestamp FROM app_upload_history ORDER BY id ASC')->fetchAll();

    $filesStmt = $pdo->prepare('SELECT filename FROM app_upload_history_files WHERE history_id = :id');
    $monthsStmt = $pdo->prepare('SELECT month FROM app_upload_history_months WHERE history_id = :id ORDER BY month');
    $missingStmt = $pdo->prepare('SELECT description FROM app_upload_history_missing WHERE history_id = :id');
    $countsStmt = $pdo->prepare('SELECT dataset, month, added, updated FROM app_upload_history_counts WHERE history_id = :id');

    $result = [];
    foreach ($histories as $h) {
        $id = $h['id'];
        $filesStmt->execute([':id' => $id]);
        $monthsStmt->execute([':id' => $id]);
        $missingStmt->execute([':id' => $id]);
        $countsStmt->execute([':id' => $id]);

        $countsByDataset = [];
        foreach ($countsStmt->fetchAll() as $c) {
            $countsByDataset[$c['dataset']][$c['month']] = ['added' => (int) $c['added'], 'updated' => (int) $c['updated']];
        }

        $result[] = [
            'timestamp' => $h['timestamp'],
            'files' => $filesStmt->fetchAll(PDO::FETCH_COLUMN),
            'months' => $monthsStmt->fetchAll(PDO::FETCH_COLUMN),
            'bills' => $countsByDataset['bills'] ?? [],
            'sales' => $countsByDataset['sales'] ?? [],
            'discounts' => $countsByDataset['discounts'] ?? [],
            'cancellations' => $countsByDataset['cancellations'] ?? [],
            'missing' => $missingStmt->fetchAll(PDO::FETCH_COLUMN),
        ];
    }
    return $result;
}

function kck_delete_upload_history_month(string $month): int {
    $pdo = kck_db();
    $stmt = $pdo->prepare(
        'DELETE FROM app_upload_history WHERE id IN ' .
        '(SELECT history_id FROM app_upload_history_months WHERE month = :month)'
    );
    $stmt->execute([':month' => $month]);
    return $stmt->rowCount();
}

function kck_recompute_last_refreshed(): array {
    $pdo = kck_db();
    $row = $pdo->query('SELECT timestamp FROM app_upload_history ORDER BY id DESC LIMIT 1')->fetch();
    $meta = kck_read_meta();
    $meta['lastRefreshed'] = $row['timestamp'] ?? null;
    kck_write_meta($meta);
    return $meta;
}


// ---- audit trail + automatic backups (kept in the database) ----
const KCK_MAX_AUDIT_ROWS = 5000;
const KCK_MAX_BACKUPS = 5;

function kck_write_audit(string $message): void {
    $pdo = kck_db();
    $pdo->prepare('INSERT INTO app_audit_log (ts, message) VALUES (:ts, :m)')
        ->execute([':ts' => gmdate('Y-m-d\TH:i:s\Z'), ':m' => mb_substr($message, 0, 1000)]);
    $pdo->exec('DELETE FROM app_audit_log WHERE id <= (SELECT m FROM (SELECT MAX(id) - ' . KCK_MAX_AUDIT_ROWS . ' AS m FROM app_audit_log) t)');
}

function kck_read_audit(int $limit = 200): array {
    $stmt = kck_db()->query('SELECT ts, message FROM app_audit_log ORDER BY id DESC LIMIT ' . (int) $limit);
    return array_map(fn($r) => ['timestamp' => $r['ts'], 'message' => $r['message']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function kck_save_backup(string $label, array $snapshot): int {
    $pdo = kck_db();
    $pdo->prepare('INSERT INTO app_backups (created, label, data) VALUES (:c, :l, :d)')
        ->execute([':c' => gmdate('Y-m-d\TH:i:s\Z'), ':l' => $label, ':d' => json_encode($snapshot)]);
    $id = (int) $pdo->lastInsertId();
    $pdo->exec('DELETE FROM app_backups WHERE id <= ' . ($id - KCK_MAX_BACKUPS));
    return $id;
}

function kck_list_backups(): array {
    $stmt = kck_db()->query('SELECT id, created, label FROM app_backups ORDER BY id DESC');
    return array_map(fn($r) => ['id' => (int) $r['id'], 'created' => $r['created'], 'label' => $r['label']], $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function kck_get_backup(int $id): ?array {
    $stmt = kck_db()->prepare('SELECT data FROM app_backups WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $data = $stmt->fetchColumn();
    return $data === false ? null : json_decode($data, true);
}


// ---- public holidays (admin-editable) ----
const KCK_HOLIDAY_BRANCHES = ['All', 'Chennai', 'Bengaluru'];

function kck_holidays_seeded(): bool {
    $stmt = kck_db()->prepare("SELECT v FROM app_flags WHERE k = 'holidays_seeded'");
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

function kck_list_holidays(): array {
    $rows = kck_db()->query('SELECT id, date, name, branch FROM app_holidays ORDER BY date, id')->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => ['id' => (int) $r['id'], 'date' => $r['date'], 'name' => $r['name'], 'branch' => $r['branch']], $rows);
}

/** Returns a cleaned [date, name, branch] or null when the input is unusable. */
function kck_clean_holiday($date, $name, $branch): ?array {
    $date = trim((string) $date);
    $name = trim(preg_replace('/\s+/', ' ', (string) $name));
    $branch = (string) $branch;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return null;
    [$y, $m, $d] = array_map('intval', explode('-', $date));
    if (!checkdate($m, $d, $y)) return null;
    if ($name === '' || mb_strlen($name) > 120) return null;
    if (!in_array($branch, KCK_HOLIDAY_BRANCHES, true)) return null;
    return [$date, $name, $branch];
}

function kck_add_holiday(string $date, string $name, string $branch): int {
    $pdo = kck_db();
    $pdo->prepare('INSERT INTO app_holidays (date, name, branch) VALUES (:d, :n, :b)')
        ->execute([':d' => $date, ':n' => $name, ':b' => $branch]);
    return (int) $pdo->lastInsertId();
}

function kck_update_holiday(int $id, string $date, string $name, string $branch): bool {
    $stmt = kck_db()->prepare('UPDATE app_holidays SET date = :d, name = :n, branch = :b WHERE id = :id');
    $stmt->execute([':d' => $date, ':n' => $name, ':b' => $branch, ':id' => $id]);
    // rowCount() is 0 when nothing changed, so confirm the row exists instead
    $chk = kck_db()->prepare('SELECT 1 FROM app_holidays WHERE id = :id');
    $chk->execute([':id' => $id]);
    return $chk->fetchColumn() !== false;
}

function kck_delete_holiday(int $id): bool {
    $stmt = kck_db()->prepare('DELETE FROM app_holidays WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

/** One-time import of the dashboard's built-in holiday list. A no-op once
 * seeded, so it can never overwrite an admin's edits. Returns rows inserted. */
function kck_seed_holidays(array $holidays): int {
    $pdo = kck_db();
    $pdo->beginTransaction();
    try {
        if (kck_holidays_seeded()) { $pdo->rollBack(); return 0; }
        $ins = $pdo->prepare('INSERT INTO app_holidays (date, name, branch) VALUES (:d, :n, :b)');
        $n = 0;
        foreach ($holidays as $h) {
            $c = kck_clean_holiday($h['date'] ?? '', $h['name'] ?? '', $h['branch'] ?? '');
            if (!$c) continue;
            $ins->execute([':d' => $c[0], ':n' => $c[1], ':b' => $c[2]]);
            $n++;
        }
        $pdo->exec("INSERT INTO app_flags (k, v) VALUES ('holidays_seeded', '1') ON DUPLICATE KEY UPDATE v = '1'");
        $pdo->commit();
        return $n;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
