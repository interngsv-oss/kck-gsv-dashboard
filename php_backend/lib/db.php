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

function kck_ensure_schema(): void {
    $pdo = kck_db();
    $sql = file_get_contents(__DIR__ . '/../schema.sql');
    // Strip "-- ..." comment lines before splitting on ";" - schema.sql's own
    // prose comments can contain a literal semicolon (e.g. "produces;
    // KEY_FIELDS..."), which a naive split-on-";" over the whole file would
    // wrongly treat as a statement boundary, slicing a CREATE TABLE
    // statement's leading comment off into its own invalid "statement".
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            // CREATE INDEX has no IF NOT EXISTS in MySQL/MariaDB - ignore
            // "Duplicate key name" on repeated calls (mirrors the
            // idempotency CREATE TABLE IF NOT EXISTS gives the other
            // statements).
            // ALTER TABLE ... ADD COLUMN likewise has no IF NOT EXISTS on MySQL.
            $msg = $e->getMessage();
            if (strpos($msg, 'Duplicate key name') === false && strpos($msg, 'Duplicate column name') === false) {
                throw $e;
            }
        }
    }
}
