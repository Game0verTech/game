<?php
require_once __DIR__ . '/includes/bootstrap.php';

$requestedPage = $_GET['page'] ?? null;
$page = $requestedPage ?? (current_user() ? 'dashboard' : 'home');
$allowedPages = [
    'home' => __DIR__ . '/pages/public/home.php',
    'login' => __DIR__ . '/pages/user/login.php',
    'register' => __DIR__ . '/pages/user/register.php',
    'dashboard' => __DIR__ . '/pages/user/dashboard.php',
    'verify' => __DIR__ . '/pages/user/verify.php',
    'calendar' => __DIR__ . '/pages/user/calendar.php',
    'my-profile' => __DIR__ . '/pages/user/my-profile.php',
    'account' => __DIR__ . '/pages/user/account.php',
    'change-password' => __DIR__ . '/pages/user/change-password.php',
    'change-icon' => __DIR__ . '/pages/user/change-icon.php',
    'admin' => __DIR__ . '/admin/index.php',
    'store' => __DIR__ . '/pages/admin/store.php',
    'profile' => __DIR__ . '/pages/public/profile.php',
];

if (!array_key_exists($page, $allowedPages)) {
    http_response_code(404);
    echo 'Page not found';
    exit;
}

require $allowedPages[$page];
