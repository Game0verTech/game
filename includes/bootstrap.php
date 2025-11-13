<?php

require_once __DIR__ . '/config.php';

$config = env_config_exists() ? load_config() : [];
$timezone = configured_timezone($config);

if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

date_default_timezone_set($timezone);

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

if (env_config_exists() && install_is_locked()) {
    ensure_user_role_enum();
    ensure_user_ban_column();
}
