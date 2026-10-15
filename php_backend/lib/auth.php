<?php
// Real server-side sign-in - port of app.py's session auth. POST /api/login
// checks the email/password HERE (no credentials ever ship to the browser)
// and sets an HttpOnly, Secure, SameSite=Strict cookie holding a signed,
// expiring session token. "/" only serves the dashboard when that cookie is
// valid (otherwise just the login page) and every /api/ route requires it.
// kck_json_error() exits the request, so callers use kck_require_*() as a
// leading statement, not an expression.

const KCK_COOKIE_NAME = 'kck_session';
const KCK_SESSION_TTL = 8 * 3600;       // short-lived: sign in again after 8 hours
const KCK_MAX_FAILED_LOGINS = 5;        // per email AND per client IP...
const KCK_LOCKOUT_SECONDS = 15 * 60;    // ...then locked out for this long

function kck_auth_config(): array {
    $config = require __DIR__ . '/../config.php';
    return $config['auth'];
}

function kck_auth_configured(): bool {
    $auth = kck_auth_config();
    return !empty($auth['admin_password']) && !empty($auth['viewer_password']);
}

function kck_session_key(): string {
    // session_secret is preferred; otherwise derive from the two passwords, so
    // changing a password also invalidates every existing session.
    $auth = kck_auth_config();
    $secret = $auth['session_secret'] ?? ($auth['admin_password'] . '|' . $auth['viewer_password']);
    return hash('sha256', 'kck-session|' . $secret, true);
}

function kck_b64(string $b): string { return rtrim(strtr(base64_encode($b), '+/', '-_'), '='); }
function kck_unb64(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/')); }

function kck_make_session(string $role, string $email): string {
    $payload = kck_b64(json_encode(['r' => $role, 'e' => $email, 'x' => time() + KCK_SESSION_TTL, 'j' => bin2hex(random_bytes(8))]));
    return $payload . '.' . kck_b64(hash_hmac('sha256', $payload, kck_session_key(), true));
}

function kck_state_dir(): string {
    $dir = sys_get_temp_dir() . '/kck_auth_state';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}

/** Revoked session ids (logout) live as tiny files so they survive across
 * PHP requests; stale ones are swept on read. */
function kck_session_revoked(string $jti): bool {
    return $jti !== '' && is_file(kck_state_dir() . '/revoked_' . preg_replace('/[^a-f0-9]/', '', $jti));
}

function kck_revoke_session(string $jti, int $exp): void {
    $dir = kck_state_dir();
    foreach (glob($dir . '/revoked_*') ?: [] as $f) {
        if (filemtime($f) < time() - KCK_SESSION_TTL - 60) @unlink($f);
    }
    file_put_contents($dir . '/revoked_' . preg_replace('/[^a-f0-9]/', '', $jti), (string) $exp);
}

/** The signed session payload array, or null if missing/forged/expired/revoked. */
function kck_current_session(): ?array {
    if (!kck_auth_configured()) return null;
    $token = $_COOKIE[KCK_COOKIE_NAME] ?? '';
    if (!is_string($token) || strpos($token, '.') === false) return null;
    [$payload, $sig] = explode('.', $token, 2);
    $expected = kck_b64(hash_hmac('sha256', $payload, kck_session_key(), true));
    if (!hash_equals($expected, $sig)) return null;
    $data = json_decode(kck_unb64($payload), true);
    if (!is_array($data) || ($data['x'] ?? 0) < time() || kck_session_revoked($data['j'] ?? '')) return null;
    return $data;
}

function kck_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '?';
}

/** Audit trail: PHP error log (as before) AND the database, so it survives
 * and an admin can read it via /api/audit-log. Never breaks the request. */
function kck_audit(string $msg): void {
    error_log('AUDIT ' . $msg);
    try { kck_write_audit($msg); } catch (Throwable $e) { error_log('could not persist audit entry: ' . $e->getMessage()); }
}

// ---- TOTP (RFC 6238; SHA-1, 6 digits, 30s) for the optional admin second factor ----
function kck_base32_decode(string $s): string {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s = strtoupper(preg_replace('/[\s=]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $ch) {
        $v = strpos($alphabet, $ch);
        if ($v === false) return '';
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) $out .= chr(bindec($byte));
    }
    return $out;
}

function kck_totp_code(string $secretB32, int $counter): string {
    $mac = hash_hmac('sha1', pack('N2', 0, $counter), kck_base32_decode($secretB32), true);
    $off = ord($mac[19]) & 0x0F;
    $num = ((ord($mac[$off]) & 0x7F) << 24 | ord($mac[$off + 1]) << 16 | ord($mac[$off + 2]) << 8 | ord($mac[$off + 3])) % 1000000;
    return str_pad((string) $num, 6, '0', STR_PAD_LEFT);
}

/** The matching time-step, or null. Allows +/-1 step of clock drift. */
function kck_totp_verify(string $secretB32, string $code): ?int {
    $code = preg_replace('/\s/', '', $code);
    if (!preg_match('/^\d{6}$/', $code)) return null;
    $counter = intdiv(time(), 30);
    for ($c = $counter - 1; $c <= $counter + 1; $c++) {
        if (hash_equals(kck_totp_code($secretB32, $c), $code)) return $c;
    }
    return null;
}

/** Replay guard: a code's step may only be accepted once. */
function kck_totp_step_used(int $step): bool {
    $f = kck_state_dir() . '/totp_last_step';
    $last = is_file($f) ? (int) file_get_contents($f) : 0;
    if ($step <= $last) return true;
    file_put_contents($f, (string) $step, LOCK_EX);
    return false;
}

function kck_lockout_file(string $key): string {
    return kck_state_dir() . '/fail_' . hash('sha256', $key);
}

/** Seconds left on a lockout for any of these keys (0 = not locked). */
function kck_lockout_remaining(array $keys): int {
    $now = time();
    $worst = 0;
    foreach ($keys as $k) {
        $f = kck_lockout_file($k);
        $times = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $recent = array_values(array_filter($times, fn($t) => $t > $now - KCK_LOCKOUT_SECONDS));
        if (count($recent) >= KCK_MAX_FAILED_LOGINS) {
            $worst = max($worst, $recent[0] + KCK_LOCKOUT_SECONDS - $now);
        }
    }
    return $worst;
}

function kck_record_failed_login(array $keys): void {
    foreach ($keys as $k) {
        $f = kck_lockout_file($k);
        $times = is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: []) : [];
        $times[] = time();
        file_put_contents($f, json_encode($times), LOCK_EX);
    }
}

function kck_clear_failed_logins(array $keys): void {
    foreach ($keys as $k) @unlink(kck_lockout_file($k));
}

function kck_cookie_options(int $expires): array {
    return ['expires' => $expires, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict'];
}

/** Returns "admin" or "viewer", or sends 401/503 and exits. */
function kck_require_viewer(): string {
    if (!kck_auth_configured()) {
        kck_json_error(503, "Server auth isn't configured - set admin_password and viewer_password in config.php.");
    }
    $session = kck_current_session();
    if (!$session) kck_json_error(401, 'Not authenticated');
    $GLOBALS['kck_actor'] = $session['e'] . ' (' . $session['r'] . ') from ' . kck_client_ip();
    return $session['r'];
}

/** Same as kck_require_viewer(), but also rejects a viewer account (403) and
 * writes the action to the server log as an audit trail. */
function kck_require_admin(): string {
    $role = kck_require_viewer();
    if ($role !== 'admin') {
        kck_json_error(403, 'This action requires an admin account.');
    }
    kck_audit('ADMIN ACTION ' . $_SERVER['REQUEST_METHOD'] . ' ' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) . ' by ' . $GLOBALS['kck_actor']);
    return $role;
}
