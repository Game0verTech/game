<?php

function site_url(string $path = ''): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $config = load_config();
    $base = trim($config['site']['url'] ?? '');

    if ($base === '' && isset($_SERVER['HTTP_HOST'])) {
        $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (isset($_SERVER['REQUEST_SCHEME']) && strtolower((string)$_SERVER['REQUEST_SCHEME']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            || (is_string($forwardedProto) && strtolower($forwardedProto) === 'https');
        $scheme = $isHttps ? 'https' : 'http';
        $base = $scheme . '://' . $_SERVER['HTTP_HOST'];
    }

    $normalizedBase = rtrim($base, '/');
    $normalizedPath = ltrim($path, '/');

    if ($normalizedBase === '') {
        return $normalizedPath === '' ? '/' : '/' . $normalizedPath;
    }

    if ($normalizedPath === '') {
        return $normalizedBase . '/';
    }

    return $normalizedBase . '/' . $normalizedPath;
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

function normalize_utf8_value(&$value): void
{
    if (is_array($value)) {
        foreach ($value as &$item) {
            normalize_utf8_value($item);
        }
        unset($item);
        return;
    }

    if (!is_string($value)) {
        return;
    }

    $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
    if ($converted === false) {
        $converted = @mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }
    if ($converted === false) {
        $converted = preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '';
    }
    $value = $converted;
}

function safe_json_encode($value): string
{
    $normalized = $value;
    normalize_utf8_value($normalized);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('JSON encoding failed: ' . json_last_error_msg());
    }
    return $json;
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
