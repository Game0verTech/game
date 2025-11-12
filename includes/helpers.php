<?php

function site_url(string $path = ''): string
{
    $config = load_config();
    $base = $config['site']['url'] ?? '';
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

function is_post(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

function sanitize(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function current_user_role(): ?string
{
    $user = current_user();
    return $user['role'] ?? null;
}

function user_has_role(string ...$roles): bool
{
    $current = current_user_role();
    if ($current === null) {
        return false;
    }
    return in_array($current, $roles, true);
}

function require_login(): void
{
    $sessionUser = current_user();
    if (!$sessionUser) {
        redirect('/?page=login');
    }
    $fresh = get_user_by_id((int)$sessionUser['id']);
    if (!$fresh || (int)$fresh['is_active'] !== 1 || (int)$fresh['is_banned'] === 1) {
        logout_user();
        flash('error', 'Your account is not permitted to access the site.');
        redirect('/?page=login');
    }
    if ($fresh['username'] !== $sessionUser['username'] || $fresh['email'] !== $sessionUser['email'] || $fresh['role'] !== $sessionUser['role']) {
        store_session_user($fresh);
    }
}

function require_role(string ...$roles): void
{
    if (!user_has_role(...$roles)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

function require_admin(): void
{
    require_role('admin');
}

function flash(string $key, ?string $message = null)
{
    if (!isset($_SESSION['flash'])) {
        $_SESSION['flash'] = [];
    }

    if ($message === null) {
        $msg = $_SESSION['flash'][$key] ?? null;
        unset($_SESSION['flash'][$key]);
        return $msg;
    }

    $_SESSION['flash'][$key] = $message;
}

function check_csrf_token(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    $token = $_POST['_token'] ?? '';
    if (!check_csrf_token($token)) {
        http_response_code(400);
        echo 'Invalid CSRF token';
        exit;
    }
}

function get_version(): string
{
    $path = __DIR__ . '/../VERSION';
    if (!file_exists($path)) {
        return 'unknown';
    }
    return trim(file_get_contents($path));
}

function bump_version(): string
{
    $path = __DIR__ . '/../VERSION';
    $current = get_version();
    if (!preg_match('/^(.+?)\s+(\d+)\.(\d+)$/', $current, $matches)) {
        throw new RuntimeException('Invalid version format');
    }
    $prefix = $matches[1];
    $major = $matches[2];
    $minor = $matches[3];
    $length = strlen($minor);
    $value = (int)$minor + 1;
    $minor = str_pad((string)$value, $length, '0', STR_PAD_LEFT);
    $new = sprintf('%s %s.%s', $prefix, $major, $minor);
    file_put_contents($path, $new);
    return $new;
}
