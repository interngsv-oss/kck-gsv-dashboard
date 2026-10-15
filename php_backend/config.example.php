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
    ],
];
