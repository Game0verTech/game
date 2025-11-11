<?php

require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

date_default_timezone_set('UTC');

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/users.php';
require_once __DIR__ . '/tournaments.php';
require_once __DIR__ . '/stats.php';

if (!env_config_exists() || !install_is_locked()) {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if (strpos($path, '/install') !== 0) {
        header('Location: /install/');
        exit;
    }
}
