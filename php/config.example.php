<?php
// Copy outside the public document root. Never commit real credentials.
return [
    'origin' => 'https://portal.example.com',
    'database' => [
        'dsn' => 'mysql:host=localhost;dbname=REPLACE_DATABASE;charset=utf8mb4',
        'user' => 'REPLACE_USER',
        'password' => 'REPLACE_PASSWORD',
    ],
    'mail' => [
        'enabled' => true,
        'from' => 'no-reply@example.com',
        'transport' => 'smtp',
        // Set a random 32-byte base64 value in production.
        'key' => 'REPLACE_MAIL_KEY_BASE64',
        // Or set OMNIGO_SMTP_HOST / OMNIGO_SMTP_PORT / OMNIGO_SMTP_USER / OMNIGO_SMTP_SECURE.
        'host' => 'smtp.example.com',
        'port' => 465,
        'username' => 'no-reply@example.com',
        // smtps (465) or tls (587)
        'secure' => 'smtps',
    ],
    // SHA-256 of a cryptographically random one-time setup code, entered by owner.
    'setup_token_hash' => '',
];
