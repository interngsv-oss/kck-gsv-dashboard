<?php
// PDO connection helper. Unlike storage_db.py's _conn(), which wraps a
// psycopg2 connection in a retry loop for a serverless Postgres that can be
// asleep, this MySQL instance is Hostinger's own always-on shared DB server -
// but a single upload request can spend minutes CPU-parsing several real
// Excel files between one DB call and the next (see kck_route_upload: the
// connection opened by kck_ensure_schema() at the top of index.php sits idle
// through the whole parse phase before kck_read_meta() reuses it), and
// MySQL's own idle-connection timeout can be considerably shorter than that
// on shared hosting - closing the connection out from under us and
// surfacing as a bare "MySQL server has gone away" (error 2006). A cheap
// "SELECT 1" liveness check before handing back a cached connection, and a
// transparent reconnect if it's dead, makes every call site immune to this
// without needing to know or care how long the gap since the last query was.
function _kck_connect(): PDO {
    $config = require __DIR__ . '/../config.php';
    $db = $config['db'];
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // The DSN's charset=utf8mb4 alone leaves the session's COLLATION at
    // whatever this server defaults a bare utf8mb4 connection to (observed:
    // utf8mb4_general_ci), which doesn't match the tables' actual
    // utf8mb4_unicode_ci (schema.sql's CREATE TABLE statements didn't pin
    // one, so they got the server's own default instead) - comparing a
    // column against a literal/bound string under mismatched collations
    // fails outright ("Illegal mix of collations"), e.g. the DATE_FORMAT(...)
    // = :month comparison in kck_replace_months_tx. Pin the session to match.
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
    return $pdo;
}

function kck_db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = _kck_connect();
        return $pdo;
    }
    try {
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        $pdo = _kck_connect();
    }
    return $pdo;
}

/** Bump when anything below changes - the whole routine is skipped on every
 * request once the database has recorded this version, so the (slow, table-
 * locking) DDL statements no longer run on every single API call. */
const KCK_SCHEMA_VERSION = 3;

/** Tables/columns added after the first release. Kept here (not only in
 * schema.sql) so they are created even if an older schema.sql is on the server. */
const KCK_EXTRA_TABLES = [
    "CREATE TABLE IF NOT EXISTS app_flags (k VARCHAR(48) PRIMARY KEY, v VARCHAR(255) NOT NULL)",
    "CREATE TABLE IF NOT EXISTS app_holidays (id INT AUTO_INCREMENT PRIMARY KEY, date DATE NOT NULL, name VARCHAR(120) NOT NULL, branch VARCHAR(16) NOT NULL DEFAULT 'All')",
];

function kck_column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function kck_ensure_schema(): void {
    $pdo = kck_db();
    // Fast path: already migrated -> a single cheap SELECT and nothing else.
    try {
        $v = $pdo->query("SELECT v FROM app_flags WHERE k = 'schema_version'")->fetchColumn();
        if ($v !== false && (int) $v >= KCK_SCHEMA_VERSION) return;
    } catch (PDOException $e) {
        // app_flags doesn't exist yet - first run of this version, carry on.
    }

    $sql = file_get_contents(__DIR__ . '/../schema.sql');
    // Strip "-- ..." comment lines before splitting on ";" - schema.sql's own
    // prose comments can contain a literal semicolon (e.g. "produces;
    // KEY_FIELDS..."), which a naive split-on-";" over the whole file would
    // wrongly treat as a statement boundary, slicing a CREATE TABLE
    // statement's leading comment off into its own invalid "statement".
    $sql = preg_replace('/^--.*$/m', '', $sql);
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    foreach (array_merge($statements, KCK_EXTRA_TABLES) as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // CREATE INDEX has no IF NOT EXISTS in MySQL/MariaDB - ignore
            // "Duplicate key name" on repeated calls (mirrors the
            // idempotency CREATE TABLE IF NOT EXISTS gives the other
            // statements).
            if (strpos($e->getMessage(), 'Duplicate key name') === false) {
                throw $e;
            }
        }
    }
    // Index on the holidays table, only if the table has none yet.
    try {
        $pdo->exec('CREATE INDEX holidays_date_idx ON app_holidays (date)');
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'Duplicate key name') === false) throw $e;
    }
    // Exact cancellation reason typed in Posist (the older "reason" column only holds the group).
    if (!kck_column_exists($pdo, 'cancellations', 'reasonText')) {
        try {
            $pdo->exec('ALTER TABLE cancellations ADD COLUMN reasonText VARCHAR(255) NULL');
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') === false) throw $e;   // lost a race with another request
        }
    }
    $pdo->prepare("INSERT INTO app_flags (k, v) VALUES ('schema_version', :v) ON DUPLICATE KEY UPDATE v = VALUES(v)")
        ->execute([':v' => (string) KCK_SCHEMA_VERSION]);
}
