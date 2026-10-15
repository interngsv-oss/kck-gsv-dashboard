<?php
// Small JSON-response helpers shared by index.php and auth.php.

function kck_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Mirrors app.py's HTTPException(status, detail) -> {"detail": ...} body. */
function kck_json_error(int $status, string $detail): void {
    kck_json(['detail' => $detail], $status);
}

/** Mirrors app.py's catch-all exception handler: log the real error server-
 * side only, never leak it to the response body. */
function kck_install_error_handler(): void {
    set_exception_handler(function (Throwable $e) {
        error_log((string) $e);
        kck_json_error(500, 'Something went wrong processing that upload. Please try again or contact support.');
    });
    // Also convert PHP warnings/notices (e.g. an unexpected cell shape) into
    // exceptions, same spirit as Python unexpectedly raising on a bad value,
    // rather than silently continuing or printing a raw warning into the
    // response body. Respects "@"-suppression (error_reporting() reads 0
    // during a suppressed call) exactly like PHP's own default handler would -
    // without this check, "@mkdir()" etc. everywhere in index.php would throw
    // instead of being silently ignored as intended.
    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) return false;
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
}
