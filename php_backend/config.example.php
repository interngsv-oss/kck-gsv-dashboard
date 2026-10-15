<?php
// Copy to config.php on the server only - NEVER commit real values to git.
// The two accounts the server-side login (POST /api/login) accepts. Passwords
// live only here - never in the repo or the HTML. Optionally add
// 'session_secret' => '<long random string>' to sign session cookies with a
// dedicated key; if omitted, one is derived from the two passwords (so
// changing a password also signs everyone out).
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'u878470360_kck_dashboard',
        'user' => 'u878470360_kck_dashboard',
        'pass' => 'CHANGE_ME',
    ],
    'auth' => [
        'admin_email' => 'admin@gsvassociates.com',
        'admin_password' => 'CHANGE_ME',
        'viewer_email' => 'user@gsvassociates.com',
        'viewer_password' => 'CHANGE_ME',
        // Optional but recommended: base32 secret for admin authenticator-app
        // (TOTP) login. Generate one with `python backend/make_totp_secret.py`.
        // 'admin_totp_secret' => 'BASE32SECRET',
    ],
    // Optional: copy every successfully processed upload to a PRIVATE Azure
    // Blob container. 'sas' = a SAS token with Create + Write permission only.
    // 'azure' => [
    //     'account'   => 'mystorageaccount',
    //     'container' => 'kck-uploads',
    //     'sas'       => 'sv=...&sp=cw&sig=...',
    // ],
];
