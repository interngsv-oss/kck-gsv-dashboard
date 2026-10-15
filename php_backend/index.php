<?php
// Front controller - PHP port of backend/app.py's route table. See that
// file's own module docstring for the endpoint list; this mirrors it
// exactly except it's a manual router instead of FastAPI's decorators.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/storage.php';
require_once __DIR__ . '/lib/parsers.php';
require_once __DIR__ . '/lib/multipart.php';

kck_install_error_handler();

// No CORS headers: the dashboard and its API are same-origin, and a wildcard
// policy would only let other sites' scripts talk to the API.
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
// Only the pages' own inline script/styles and the three cdnjs libraries may
// run; nothing can frame the page.
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
    . "style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; "
    . "worker-src 'self' blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'; object-src 'none'");

const KCK_MAX_UPLOAD_BYTES = 200 * 1024 * 1024;
const KCK_MAX_UNZIPPED_BYTES = 1024 * 1024 * 1024;   // zip-bomb guard
const KCK_ALLOWED_UPLOAD_EXTENSIONS = ['xlsx', 'xls', 'zip'];

const KCK_EDITABLE_DATASETS = ['bills', 'sales', 'discounts', 'cancellations'];

function kck_require_dataset(string $dataset): void {
    if (!in_array($dataset, KCK_EDITABLE_DATASETS, true)) {
        kck_json_error(400, "Unknown dataset '$dataset'. Must be one of " . implode(', ', KCK_EDITABLE_DATASETS) . '.');
    }
}

function kck_require_key_fields(string $dataset, array $row): void {
    $missing = [];
    foreach (KCK_KEY_FIELDS[$dataset] as $f) {
        if (empty($row[$f])) $missing[] = $f;
    }
    if ($missing) {
        kck_json_error(400, "Missing required field(s) for '$dataset': " . implode(', ', $missing));
    }
}

function kck_read_json_body(): array {
    $body = json_decode(file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

function kck_serve_dashboard(string $method): void {
    // No-cache so every deploy is visible immediately - see app.py's
    // serve_dashboard for the same reasoning.
    // The server decides what an anonymous visitor gets: without a valid
    // session they receive ONLY the small login page - none of the
    // dashboard's markup, report names or admin controls.
    header('Cache-Control: no-store');
    header('Content-Type: text/html; charset=utf-8');
    if ($method === 'HEAD') exit;
    readfile(__DIR__ . '/' . (kck_current_session() ? 'kck_sales_dashboard_V11.html' : 'login.html'));
    exit;
}

function kck_route_login(): void {
    if (!kck_auth_configured()) kck_json_error(503, "Server auth isn't configured.");
    $body = kck_read_json_body();
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $password = (string) ($body['password'] ?? '');
    $keys = ['ip:' . kck_client_ip(), 'email:' . $email];
    $wait = kck_lockout_remaining($keys);
    if ($wait) kck_json_error(429, 'Too many failed attempts. Try again in ' . (intdiv($wait, 60) + 1) . ' minute(s).');

    $auth = kck_auth_config();
    $isAdmin = hash_equals($auth['admin_email'], $email) & hash_equals($auth['admin_password'], $password);
    $isViewer = hash_equals($auth['viewer_email'], $email) & hash_equals($auth['viewer_password'], $password);
    if (!$isAdmin && !$isViewer) {
        kck_record_failed_login($keys);
        kck_audit('FAILED LOGIN for ' . json_encode($email) . ' from ' . kck_client_ip());
        // Same generic message for a wrong email and a wrong password.
        kck_json_error(401, 'Invalid email or password.');
    }
    if ($isAdmin && !empty($auth['admin_totp_secret'])) {
        // Second factor for the admin account (authenticator-app code).
        $code = (string) ($body['code'] ?? '');
        if (trim($code) === '') {
            // Password was right, code just not asked for yet - not a failed attempt.
            kck_json(['detail' => 'Enter the 6-digit code from your authenticator app.', 'mfa_required' => true], 401);
        }
        $step = kck_totp_verify($auth['admin_totp_secret'], $code);
        if ($step === null || kck_totp_step_used($step)) {
            kck_record_failed_login($keys);
            kck_audit('FAILED MFA for ' . json_encode($email) . ' from ' . kck_client_ip());
            kck_json(['detail' => 'Invalid or expired code.', 'mfa_required' => true], 401);
        }
    }
    kck_clear_failed_logins($keys);
    $role = $isAdmin ? 'admin' : 'viewer';
    kck_audit("LOGIN $email ($role) from " . kck_client_ip());
    setcookie(KCK_COOKIE_NAME, kck_make_session($role, $email), kck_cookie_options(time() + KCK_SESSION_TTL));
    kck_json(['role' => $role]);
}

function kck_route_logout(): void {
    $session = kck_current_session();
    if ($session) {
        kck_revoke_session($session['j'], $session['x']);   // dead server-side, not just cleared in the browser
        kck_audit('LOGOUT ' . $session['e']);
    }
    setcookie(KCK_COOKIE_NAME, '', kck_cookie_options(time() - 3600));
    kck_json(['ok' => true]);
}

function kck_route_me(): void {
    kck_json(['role' => kck_require_viewer()]);
}

function kck_route_meta(): void {
    kck_require_viewer();
    kck_json(kck_read_meta());
}

function kck_route_upload_history(): void {
    kck_require_admin();
    kck_json(array_reverse(kck_read_upload_history()));
}

function kck_route_list_rows(string $dataset): void {
    kck_require_viewer();
    kck_require_dataset($dataset);
    $branch = $_GET['branch'] ?? null;
    $from = $_GET['from'] ?? null;
    $to = $_GET['to'] ?? null;
    $rows = kck_read_range($dataset, $from ? substr($from, 0, 7) : null, $to ? substr($to, 0, 7) : null);
    if ($from) $rows = array_values(array_filter($rows, fn($r) => $r['date'] >= $from));
    if ($to) $rows = array_values(array_filter($rows, fn($r) => $r['date'] <= $to));
    if ($branch) $rows = array_values(array_filter($rows, fn($r) => ($r['branch'] ?? null) === $branch));
    kck_json($rows);
}

function kck_route_create_row(string $dataset): void {
    kck_require_admin();
    kck_require_dataset($dataset);
    $row = kck_read_json_body();
    kck_require_key_fields($dataset, $row);
    $key = kck_row_key($dataset, $row);
    foreach (kck_read_range($dataset) as $r) {
        if (kck_row_key($dataset, $r) === $key) {
            kck_json_error(409, 'A row with this key already exists - use PUT to update it.');
        }
    }
    kck_upsert_rows($dataset, [$row]);
    if ($dataset === 'bills') kck_recompute_meta_dates();
    kck_json($row);
}

function kck_route_update_row(string $dataset): void {
    kck_require_admin();
    kck_require_dataset($dataset);
    $row = kck_read_json_body();
    kck_require_key_fields($dataset, $row);
    $key = kck_row_key($dataset, $row);
    $found = false;
    foreach (kck_read_range($dataset) as $r) {
        if (kck_row_key($dataset, $r) === $key) { $found = true; break; }
    }
    if (!$found) kck_json_error(404, 'No row with this key exists - use POST to create it.');
    kck_upsert_rows($dataset, [$row]);
    if ($dataset === 'bills') kck_recompute_meta_dates();
    kck_json($row);
}

function kck_route_delete_row(string $dataset): void {
    kck_require_admin();
    kck_require_dataset($dataset);
    $key = kck_read_json_body();
    kck_require_key_fields($dataset, $key);
    if (!kck_delete_row($dataset, $key)) {
        kck_json_error(404, 'No row with this key exists.');
    }
    if ($dataset === 'bills') kck_recompute_meta_dates();
    kck_json(['deleted' => true]);
}

/** Snapshot every dataset (plus meta) into the database BEFORE a destructive
 * action, so it can be undone with /api/restore-backup. */
function kck_take_backup(string $label): int {
    $datasets = [];
    foreach (KCK_EDITABLE_DATASETS as $d) $datasets[$d] = kck_read_range($d);
    $id = kck_save_backup($label, ['meta' => kck_read_meta(), 'datasets' => $datasets]);
    kck_audit("BACKUP #$id taken: $label");
    return $id;
}

function kck_route_audit_log(): void {
    kck_require_admin();
    kck_json(kck_read_audit(500));
}

function kck_route_backups(): void {
    kck_require_admin();
    kck_json(kck_list_backups());
}

function kck_route_restore_backup(): void {
    kck_require_admin();
    $body = kck_read_json_body();
    if (!hash_equals(kck_auth_config()['admin_password'], (string) ($body['password'] ?? ''))) {
        kck_json_error(403, 'Password incorrect - nothing was restored.');
    }
    $id = (int) ($body['id'] ?? 0);
    $snapshot = $id ? kck_get_backup($id) : null;
    if (!$snapshot) kck_json_error(404, 'No backup with that id.');
    foreach ($snapshot['datasets'] as $dataset => $rows) {
        if (in_array($dataset, KCK_EDITABLE_DATASETS, true) && $rows) kck_replace_months($dataset, $rows);
    }
    kck_write_meta($snapshot['meta']);
    kck_recompute_meta_dates();
    kck_audit("RESTORE of backup #$id");
    kck_json(['restored' => $id]);
}

function kck_route_clear_all(): void {
    kck_require_admin();
    // Irreversible, so the admin must re-enter their password.
    $body = kck_read_json_body();
    if (!hash_equals(kck_auth_config()['admin_password'], (string) ($body['password'] ?? ''))) {
        kck_json_error(403, 'Password incorrect - nothing was deleted.');
    }
    $backupId = kck_take_backup('before Clear All Data');
    kck_clear_all();
    kck_json(['cleared' => true, 'backupId' => $backupId]);
}

function kck_route_delete_month(): void {
    kck_require_admin();
    $payload = kck_read_json_body();
    $month = $payload['month'] ?? null;
    $datasets = $payload['datasets'] ?? [];
    if (!$month) kck_json_error(400, "Missing 'month' (expected 'YYYY-MM').");
    $unknown = array_values(array_diff($datasets, KCK_EDITABLE_DATASETS));
    if ($unknown) {
        kck_json_error(400, 'Unknown dataset(s): ' . implode(', ', $unknown) . '. Must be one of ' . implode(', ', KCK_EDITABLE_DATASETS) . '.');
    }
    if (!$datasets) kck_json_error(400, 'No datasets selected to delete.');

    $backupId = kck_take_backup("before deleting $month (" . implode(', ', $datasets) . ')');
    $removed = [];
    foreach ($datasets as $d) $removed[$d] = kck_delete_month($d, $month);
    if (in_array('bills', $datasets, true)) kck_recompute_meta_dates();

    $historyRemoved = 0;
    if (!array_diff(KCK_EDITABLE_DATASETS, $datasets)) {
        $historyRemoved = kck_delete_upload_history_month($month);
        if ($historyRemoved) kck_recompute_last_refreshed();
    }
    kck_json(['month' => $month, 'removed' => $removed, 'historyRemoved' => $historyRemoved, 'backupId' => $backupId]);
}

function kck_route_migrate_discount_reasons(): void {
    kck_require_admin();
    kck_take_backup('before migrate-discount-reasons');
    $rows = kck_read_range('discounts');
    foreach ($rows as &$row) {
        $row['reason'] = kck_discount_reason_from_remark($row['remarks'] ?? null);
    }
    unset($row);
    kck_replace_months('discounts', $rows);
    $reasons = array_values(array_unique(array_map(fn($r) => $r['reason'], $rows)));
    sort($reasons);
    $meta = kck_read_meta();
    $meta['discountReasons'] = $reasons;
    kck_write_meta($meta);
    kck_json(['updated' => count($rows), 'reasons' => $reasons]);
}

function kck_route_migrate_zero_sales_bills(): void {
    kck_require_admin();
    kck_take_backup('before migrate-zero-sales-bills');
    $allRows = kck_read_range('bills');
    $kept = array_values(array_filter($allRows, fn($r) => ($r['salesValue'] ?? null) != 0));
    $months = array_values(array_unique(array_map(fn($r) => substr($r['date'], 0, 7), $allRows)));
    sort($months);
    foreach ($months as $m) kck_delete_month('bills', $m);
    kck_replace_months('bills', $kept);
    kck_recompute_meta_dates();
    kck_json(['kept' => count($kept), 'removed' => count($allRows) - count($kept)]);
}

function kck_safe_relpath(string $filename): string {
    $parts = array_values(array_filter(
        explode('/', str_replace('\\', '/', $filename)),
        fn($p) => $p !== '' && $p !== '.' && $p !== '..'
    ));
    if (!$parts) kck_json_error(400, "Invalid file name: '$filename'");
    return implode('/', $parts);
}

const KCK_IGNORED_DIR_PREFIXES = ['__MACOSX', '.'];

function kck_find_posist_root(string $extractDir, int $maxDepth = 4): string {
    $search = function ($d, $depth) use (&$search) {
        if (is_dir(kck_find_dir_ci($d, 'Payment Report'))) return $d;
        if ($depth <= 0 || !is_dir($d)) return null;
        $subdirs = [];
        foreach (scandir($d) ?: [] as $e) {
            if ($e === '.' || $e === '..') continue;
            $full = $d . '/' . $e;
            if (!is_dir($full)) continue;
            $ignored = false;
            foreach (KCK_IGNORED_DIR_PREFIXES as $p) {
                if (strpos($e, $p) === 0) { $ignored = true; break; }
            }
            if (!$ignored) $subdirs[] = $e;
        }
        foreach ($subdirs as $sub) {
            $found = $search($d . '/' . $sub, $depth - 1);
            if ($found) return $found;
        }
        return null;
    };
    $root = $search($extractDir, $maxDepth);
    if ($root === null) {
        kck_json_error(400, "Could not find a 'Payment Report' folder in what was uploaded.");
    }
    return $root;
}

/** Extract every entry of an uploaded zip into $destDir, rejecting any path
 * that would escape it (zip-slip) - same protection as kck_safe_relpath. */
function kck_safe_extract_zip(string $zipPath, string $destDir, string $originalFilename): void {
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        kck_json_error(400, "'$originalFilename' is not a valid zip file.");
    }
    $total = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) $total += $zip->statIndex($i)['size'];
    if ($total > KCK_MAX_UNZIPPED_BYTES) kck_json_error(400, 'That zip expands to too much data.');
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $parts = array_values(array_filter(
            explode('/', str_replace('\\', '/', $name)),
            fn($p) => $p !== '' && $p !== '.' && $p !== '..'
        ));
        if (!$parts) continue;
        $target = $destDir . '/' . implode('/', $parts);
        if (substr($name, -1) === '/') {
            @mkdir($target, 0777, true);
            continue;
        }
        @mkdir(dirname($target), 0777, true);
        file_put_contents($target, $zip->getFromIndex($i));
    }
    $zip->close();
}

function kck_rrmdir(string $dir): void {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        is_dir($path) ? kck_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

function kck_route_upload(): void {
    kck_require_admin();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > KCK_MAX_UPLOAD_BYTES) kck_json_error(413, 'Upload is too large.');
    $t0 = microtime(true);
    kck_log_step('upload request received, Content-Length=' . ($_SERVER['CONTENT_LENGTH'] ?? '?'), $t0);

    // A large real export (seen in production: a 46MB Bill Item Detailed
    // Report) can take well over a minute to parse. The frontend's own
    // transient-failure retry doesn't know a previous attempt for the same
    // upload is still running on the server and fires a NEW one anyway -
    // observed in this server's logs as 3+ overlapping ~400MB PhpSpreadsheet
    // parses piling up at once, which is almost certainly what exhausts the
    // server and surfaces as an otherwise-unexplained 503.
    //
    // A filesystem lock (not a MySQL GET_LOCK) makes a second overlapping
    // request fail fast and cheaply instead of competing for the same
    // memory - a MySQL advisory lock was tried first and didn't work: it's
    // tied to the specific DB *connection* that acquired it, and
    // kck_db()'s own stale-connection auto-reconnect (see hostinger_php_
    // backend_gotchas memory) silently swaps in a fresh connection the
    // moment the lock-holding one sits idle past MySQL's 20s wait_timeout -
    // which is exactly what happens during many seconds of pure CPU-bound
    // Excel parsing with no query in between, releasing the lock out from
    // under the still-running upload. A flock() has no such timeout.
    $lockPath = sys_get_temp_dir() . '/kck_upload.lock';
    $lockFh = fopen($lockPath, 'c');
    if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
        kck_log_step('another upload is already in progress - rejecting this overlapping request', $t0);
        kck_json_error(409, 'Another upload is already being processed - please wait for it to finish, then try again (do not click Upload repeatedly).');
    }
    try {
        kck_route_upload_locked($t0);
    } finally {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }
}

function kck_route_upload_locked(float $t0): void {
    $parts = kck_parse_multipart();
    $files = array_values(array_filter($parts, fn($p) => $p['name'] === 'files' && $p['filename'] !== null));
    if (!$files) kck_json_error(400, 'No files received.');
    // A picked folder can carry stray files (.DS_Store, notes, ...); only Excel
    // files and zips are ever kept - anything else is dropped, never written to disk.
    $files = array_values(array_filter($files, fn($f) => in_array(strtolower(pathinfo($f['filename'], PATHINFO_EXTENSION)), KCK_ALLOWED_UPLOAD_EXTENSIONS, true)));
    if (!$files) kck_json_error(400, 'No Excel (.xlsx/.xls) or .zip files in that upload.');
    kck_log_step('multipart parsed: ' . count($files) . ' file(s): ' . implode(', ', array_map(fn($f) => basename($f['filename']) . '(' . round(filesize($f['tmp_path']) / 1024, 0) . 'KB)', $files)), $t0);

    $tmp = sys_get_temp_dir() . '/kckupload_' . bin2hex(random_bytes(8));
    $extractDir = $tmp . '/extracted';
    @mkdir($extractDir, 0777, true);
    try {
        foreach ($files as $f) {
            $filename = $f['filename'] ?? '';
            if (strtolower(substr($filename, -4)) === '.zip') {
                kck_safe_extract_zip($f['tmp_path'], $extractDir, $filename);
            } else {
                $rel = kck_safe_relpath($filename);
                $dest = $extractDir . '/' . $rel;
                @mkdir(dirname($dest), 0777, true);
                rename($f['tmp_path'], $dest);
            }
        }

        $posistRoot = kck_find_posist_root($extractDir);
        try {
            [$bills, $sales, $discounts, $cancellations, $missingSections] = kck_parse_posist_export($posistRoot);
        } catch (RuntimeException $e) {
            kck_json_error(400, $e->getMessage());
        }

        kck_write_parsed_upload(
            $bills, $sales, $discounts, $cancellations, $missingSections,
            array_values(array_filter(array_map(fn($f) => $f['filename'], $files))),
            $t0
        );
    } finally {
        kck_rrmdir($tmp);
    }
}

/** Shared by both upload routes: the write phase is identical whether the
 * rows came from parsing real Excel files here (kck_route_upload_locked)
 * or were already parsed locally and sent as JSON (kck_route_upload_json). */
function kck_write_parsed_upload(array $bills, array $sales, array $discounts, array $cancellations, array $missingSections, array $fileNames, float $t0): void {
    $discountReasons = $discounts ? array_values(array_unique(array_map(fn($d) => $d['reason'], $discounts))) : null;
    $meta = kck_read_meta();
    if ($discountReasons) {
        $merged = array_values(array_unique(array_merge($meta['discountReasons'] ?? [], $discountReasons)));
        sort($merged);
        $meta['discountReasons'] = $merged;
    }
    // UTC + "Z" suffix - see app.py's upload_export for why (browser
    // Date parsing of a bare timestamp assumes local time otherwise).
    $meta['lastRefreshed'] = gmdate('Y-m-d\TH:i:s\Z');

    $results = kck_bulk_write_upload(
        ['bills' => $bills, 'sales' => $sales, 'discounts' => $discounts, 'cancellations' => $cancellations],
        $meta,
        ['files' => $fileNames, 'missing' => $missingSections]
    );

    kck_recompute_meta_dates();
    kck_log_step('DB write complete, sending response', $t0);

    kck_json([
        'bills' => $results['bills'],
        'sales' => $results['sales'],
        'discounts' => $results['discounts'],
        'cancellations' => $results['cancellations'],
        'missing' => $missingSections,
    ]);
}

/** Accepts a Posist export already parsed LOCALLY (see
 * backend/local_prepare_upload.py, which runs the exact same parsers.py
 * logic on the user's own PC) as a plain JSON body, instead of raw Excel
 * files - skips Excel parsing on the server entirely, which is what was
 * actually exhausting this shared-hosting account's resources for large
 * real exports (see hostinger_php_backend_gotchas memory). Still goes
 * through the same upload lock and the same DB-write path as a normal
 * upload, since that part was never the bottleneck. */
function kck_route_upload_json(): void {
    kck_require_admin();
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > KCK_MAX_UPLOAD_BYTES) kck_json_error(413, 'Upload is too large.');
    $t0 = microtime(true);

    $lockPath = sys_get_temp_dir() . '/kck_upload.lock';
    $lockFh = fopen($lockPath, 'c');
    if (!flock($lockFh, LOCK_EX | LOCK_NB)) {
        kck_json_error(409, 'Another upload is already being processed - please wait for it to finish, then try again.');
    }
    try {
        $body = json_decode(file_get_contents('php://input'), true);
        if (!is_array($body)) {
            kck_json_error(400, 'Invalid JSON body.');
        }
        foreach (['bills', 'sales', 'discounts', 'cancellations'] as $key) {
            if (!isset($body[$key]) || !is_array($body[$key])) {
                kck_json_error(400, "Missing or invalid '$key' in the uploaded JSON.");
            }
            foreach ($body[$key] as $row) {
                if (!is_array($row)) kck_json_error(400, "'$key' must be a list of row objects.");
                // Schema check: every row needs the fields the DB keys on, in the right shape.
                if (!is_string($row['branch'] ?? null) || $row['branch'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['date'] ?? ''))) {
                    kck_json_error(400, "'$key' has a row with a missing branch or a date that isn't YYYY-MM-DD.");
                }
            }
        }
        kck_log_step('upload-json received: ' . count($body['bills']) . ' bills, ' . count($body['sales']) . ' sales, '
            . count($body['discounts']) . ' discounts, ' . count($body['cancellations']) . ' cancellations', $t0);

        kck_write_parsed_upload(
            $body['bills'], $body['sales'], $body['discounts'], $body['cancellations'],
            $body['missing'] ?? [],
            $body['sourceFiles'] ?? [],
            $t0
        );
    } finally {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }
}

// ---- Router ----
// Mirrors app.py's route registration ORDER: /api/upload, /api/upload-
// history, /api/clear-all, /api/delete-month and the two migrate-* routes
// must all be matched before the generic /api/{dataset} pattern below, same
// reasoning as the comments in app.py (a literal path segment like "upload"
// would otherwise be mistaken for a dataset name).

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');
if ($path === '') $path = '/';
$method = $_SERVER['REQUEST_METHOD'];

if (strpos($path, '/api/') === 0) {
    header('Cache-Control: no-store');
    // Only routes that actually touch the database need a connection -
    // opening (or reusing a now-possibly-stale) one just to serve the
    // static dashboard page would be pure overhead for the one route that
    // doesn't need it.
    kck_ensure_schema();
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($path === '/' && in_array($method, ['GET', 'HEAD'], true)) {
    kck_serve_dashboard($method);
} elseif ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /\n";
} elseif ($path === '/healthz' && in_array($method, ['GET', 'HEAD'], true)) {
    kck_read_meta();   // touches the DB so it stays warm; returns no data
    kck_json(['ok' => true]);
} elseif ($path === '/api/login' && $method === 'POST') {
    kck_route_login();
} elseif ($path === '/api/logout' && $method === 'POST') {
    kck_route_logout();
} elseif ($path === '/api/me' && $method === 'GET') {
    kck_route_me();
} elseif ($path === '/api/meta' && in_array($method, ['GET', 'HEAD'], true)) {
    kck_route_meta();
} elseif ($path === '/api/upload-history' && $method === 'GET') {
    kck_route_upload_history();
} elseif ($path === '/api/upload' && $method === 'POST') {
    kck_route_upload();
} elseif ($path === '/api/upload-json' && $method === 'POST') {
    kck_route_upload_json();
} elseif ($path === '/api/audit-log' && $method === 'GET') {
    kck_route_audit_log();
} elseif ($path === '/api/backups' && $method === 'GET') {
    kck_route_backups();
} elseif ($path === '/api/restore-backup' && $method === 'POST') {
    kck_route_restore_backup();
} elseif ($path === '/api/clear-all' && $method === 'POST') {
    kck_route_clear_all();
} elseif ($path === '/api/delete-month' && $method === 'POST') {
    kck_route_delete_month();
} elseif ($path === '/api/migrate-discount-reasons' && $method === 'POST') {
    kck_route_migrate_discount_reasons();
} elseif ($path === '/api/migrate-zero-sales-bills' && $method === 'POST') {
    kck_route_migrate_zero_sales_bills();
} elseif (preg_match('#^/api/([^/]+)$#', $path, $m)) {
    $dataset = $m[1];
    switch ($method) {
        case 'GET': kck_route_list_rows($dataset); break;
        case 'POST': kck_route_create_row($dataset); break;
        case 'PUT': kck_route_update_row($dataset); break;
        case 'DELETE': kck_route_delete_row($dataset); break;
        default: kck_json_error(405, 'Method not allowed.');
    }
} else {
    kck_json_error(404, 'Not found.');
}
