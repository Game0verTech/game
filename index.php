<?php
require_once __DIR__ . '/includes/bootstrap.php';

$page = $_GET['page'] ?? 'home';
$allowedPages = [
    'home' => __DIR__ . '/pages/public/home.php',
    'tournaments' => __DIR__ . '/pages/public/tournaments.php',
    'tournament' => __DIR__ . '/pages/public/tournament.php',
    'login' => __DIR__ . '/pages/user/login.php',
    'register' => __DIR__ . '/pages/user/register.php',
    'dashboard' => __DIR__ . '/pages/user/dashboard.php',
    'verify' => __DIR__ . '/pages/user/verify.php',
    'admin' => __DIR__ . '/admin/index.php',
];

if (!array_key_exists($page, $allowedPages)) {
    http_response_code(404);
    echo 'Page not found';
    exit;
}

require $allowedPages[$page];
