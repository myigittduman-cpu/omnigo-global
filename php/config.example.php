<?php
// Copy outside the public document root. Never commit real credentials.
return [
    'origin' => 'https://portal.omnigoglobal.com',
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=REPLACE_DATABASE;charset=utf8mb4',
        'user' => 'REPLACE_USER',
        'password' => 'REPLACE_PASSWORD',
    ],
    // SHA-256 of a cryptographically random one-time setup code, entered by owner.
    'setup_token_hash' => '',
];
