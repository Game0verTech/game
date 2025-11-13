<?php

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = load_config();
    $timezone = configured_timezone($config);
    if (empty($config['db'])) {
        throw new RuntimeException('Database configuration missing.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $config['db']['host'],
        $config['db']['port'] ?? 3306,
        $config['db']['name']
    );

    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Ensure MySQL uses the same timezone as PHP so time-based comparisons remain consistent.
    // Using the named timezone allows MySQL to account for daylight saving time automatically.
    try {
        $pdo->exec('SET time_zone = ' . $pdo->quote($timezone));
    } catch (PDOException $e) {
        error_log('Failed to set database time zone using identifier: ' . $e->getMessage());
        try {
            $offset = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->format('P');
            $pdo->exec('SET time_zone = ' . $pdo->quote($offset));
        } catch (Throwable $fallbackException) {
            error_log('Failed to set database time zone offset: ' . $fallbackException->getMessage());
        }
    }

    return $pdo;
}

function db_test_connection(array $settings): bool
{
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $settings['host'],
        $settings['port'] ?? 3306,
        $settings['name']
    );
    $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $pdo = null;
    return true;
}

function db_initialize_schema(PDO $pdo): void
{
    $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
    $pdo->exec($schema);
}
