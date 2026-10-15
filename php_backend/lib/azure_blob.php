<?php
// Optional: keeps a copy of every successfully processed upload in a PRIVATE
// Azure Blob Storage container, off the web server. Talks to the Blob REST API
// directly (Put Blob over cURL) with a write-only SAS token from config.php,
// so no SDK is needed and the token can't read or delete anything.
// Not configured => every function here is a silent no-op.
//
// config.php:
//   'azure' => [
//       'account'   => 'mystorageaccount',
//       'container' => 'kck-uploads',
//       'sas'       => 'sv=...&sp=cw&sig=...',   // permissions: Create + Write only
//   ],

function kck_azure_config(): ?array {
    $config = require __DIR__ . '/../config.php';
    $a = $config['azure'] ?? null;
    if (!is_array($a) || empty($a['account']) || empty($a['container']) || empty($a['sas'])) return null;
    return $a;
}

function kck_azure_blob_name(string $filename): string {
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', basename(str_replace('\\', '/', $filename)));
    return 'uploads/' . gmdate('Y/m/d/His') . '_' . bin2hex(random_bytes(3)) . '_' . $safe;
}

/** PUT one blob. $source is a file path (streamed, so a 50MB export doesn't
 * have to fit in memory) or, when $isData, the raw bytes. Returns true on 201. */
function kck_azure_put(array $a, string $blobName, string $source, bool $isData = false): bool {
    $url = 'https://' . $a['account'] . '.blob.core.windows.net/' . $a['container'] . '/'
        . implode('/', array_map('rawurlencode', explode('/', $blobName)))
        . '?' . ltrim($a['sas'], '?');
    $ch = curl_init($url);
    $headers = ['x-ms-blob-type: BlockBlob', 'x-ms-version: 2021-08-06'];
    $fh = null;
    if ($isData) {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $source);
        $headers[] = 'Content-Type: application/json';
    } else {
        $fh = fopen($source, 'rb');
        if (!$fh) return false;
        curl_setopt($ch, CURLOPT_PUT, true);
        curl_setopt($ch, CURLOPT_INFILE, $fh);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($source));
        $headers[] = 'Content-Type: application/octet-stream';
    }
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 300,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($fh) fclose($fh);
    if ($code !== 201) {
        // Log the status only - never the URL, which contains the SAS token.
        error_log("Azure Blob upload of $blobName failed: HTTP $code $err " . substr((string) $resp, 0, 300));
        return false;
    }
    return true;
}

/** Called once an upload has been parsed and is about to be written.
 * $items: [['name' => 'x.xlsx', 'path' => '/tmp/..'] | ['name' => 'x.json', 'data' => '...'], ...]
 * After the HTTP response has gone out (so the user isn't kept waiting and the
 * host's request timeout can't cut it off), and only if that response was a
 * success, copies each item to Blob, then runs $cleanup (temp-file removal). */
function kck_azure_queue_after_response(array $items, ?callable $cleanup = null): void {
    $a = kck_azure_config();
    if (!$a || !function_exists('curl_init')) {
        if ($cleanup) register_shutdown_function($cleanup);
        return;
    }
    register_shutdown_function(function () use ($a, $items, $cleanup) {
        $ok = http_response_code() === 200;
        if ($ok) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            ignore_user_abort(true);
            set_time_limit(0);
            $sent = 0;
            foreach ($items as $it) {
                try {
                    $name = kck_azure_blob_name($it['name']);
                    $done = isset($it['data'])
                        ? kck_azure_put($a, $name, $it['data'], true)
                        : kck_azure_put($a, $name, $it['path']);
                    if ($done) $sent++;
                } catch (Throwable $e) {
                    error_log('Azure Blob upload error: ' . $e->getMessage());
                }
            }
            try { kck_audit("AZURE BLOB: stored $sent of " . count($items) . ' uploaded file(s)'); } catch (Throwable $e) {}
        }
        if ($cleanup) $cleanup();
    });
}
