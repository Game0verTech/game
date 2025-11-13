<?php
return [
    'app' => [
        'timezone' => 'America/New_York',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'game',
        'user' => 'root',
        'pass' => '',
    ],
    'smtp' => [
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'user@example.com',
        'password' => 'secret',
        'from_name' => 'Play for Purpose Ohio',
        'from_email' => 'noreply@example.com',
    ],
    'site' => [
        'url' => 'https://game.playforpurposeohio.com',
        'name' => 'Play for Purpose Ohio',
    ],
];
