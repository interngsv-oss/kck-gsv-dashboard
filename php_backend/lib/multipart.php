<?php
// The frontend sends every uploaded file under the SAME field name "files"
// (no "[]" suffix - see kck_sales_dashboard_V11.html's attemptUpload()).
// PHP's normal $_FILES auto-population silently keeps only the LAST
// same-named file and drops the rest (verified against this exact server).
// Changing the frontend's field name would fix that, but the frontend is
// shared with the existing FastAPI/Render backend, which binds to the exact
// field name "files" - so instead, this directory's .htaccess sets
// `php_value enable_post_data_reading 0`, which stops PHP from touching
// $_POST/$_FILES itself and leaves the raw body on php://input for this
// parser to read in full, keeping every part.
//
// Returns a list of ['name' => field name, 'filename' => string|null,
// 'tmp_path' => string|null (file parts only), 'data' => string (non-file
// parts only)].
function kck_parse_multipart(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (!preg_match('/boundary=(?:"([^"]+)"|([^;]+))/', $contentType, $m)) {
        throw new RuntimeException('Not a multipart/form-data request.');
    }
    $boundary = $m[1] !== '' ? $m[1] : $m[2];
    $raw = file_get_contents('php://input');

    $parts = explode('--' . $boundary, $raw);
    $results = [];
    foreach ($parts as $part) {
        $part = ltrim($part, "\r\n");
        if ($part === '' || $part === "--" || $part === "--\r\n") continue;
        // Each part ends with \r\n right before the next boundary marker.
        $part = preg_replace('/\r\n$/', '', $part, 1);

        $sep = strpos($part, "\r\n\r\n");
        if ($sep === false) continue;
        $headerBlock = substr($part, 0, $sep);
        $body = substr($part, $sep + 4);

        $name = null;
        $filename = null;
        foreach (explode("\r\n", $headerBlock) as $headerLine) {
            if (stripos($headerLine, 'Content-Disposition:') === 0) {
                if (preg_match('/name="([^"]*)"/', $headerLine, $nm)) $name = $nm[1];
                if (preg_match('/filename="([^"]*)"/', $headerLine, $fm)) $filename = $fm[1];
            }
        }
        if ($name === null) continue;

        if ($filename !== null) {
            $tmpPath = tempnam(sys_get_temp_dir(), 'kckup_');
            file_put_contents($tmpPath, $body);
            $results[] = ['name' => $name, 'filename' => $filename, 'tmp_path' => $tmpPath, 'data' => null];
        } else {
            $results[] = ['name' => $name, 'filename' => null, 'tmp_path' => null, 'data' => $body];
        }
    }
    return $results;
}
