<?php

function ensure_user_role_enum(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $stmt = db()->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'");
    $stmt->execute();
    $columnType = strtolower((string)$stmt->fetchColumn());

    $required = ["'admin'", "'manager'", "'player'"];
    foreach ($required as $value) {
        if (strpos($columnType, $value) === false) {
            db()->exec("ALTER TABLE users MODIFY role ENUM('admin','manager','player') NOT NULL DEFAULT 'player'");
            break;
        }
    }
}

function ensure_user_ban_column(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $stmt = db()->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_banned'");
    $stmt->execute();
    if (!$stmt->fetchColumn()) {
        db()->exec("ALTER TABLE users ADD COLUMN is_banned TINYINT(1) NOT NULL DEFAULT 0 AFTER is_active");
        db()->exec("ALTER TABLE users ADD INDEX idx_user_banned (is_banned)");
    } else {
        $indexCheck = db()->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_user_banned'");
        $indexCheck->execute();
        if (!$indexCheck->fetchColumn()) {
            db()->exec("ALTER TABLE users ADD INDEX idx_user_banned (is_banned)");
        }
    }
}

function create_user(string $username, string $email, string $password, string $role = 'player', bool $isActive = false): array
{
    $generateVerification = !$isActive;
    $token = $generateVerification ? bin2hex(random_bytes(32)) : null;
    $expires = $generateVerification ? (new DateTime('+1 day'))->format('Y-m-d H:i:s') : null;
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (username, email, password_hash, role, is_active, is_banned, created_at, updated_at, email_verify_token, email_verify_expires)
            VALUES (:username, :email, :password_hash, :role, :is_active, 0, NOW(), NOW(), :token, :expires)';
    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $hash,
        ':role' => $role,
        ':is_active' => $isActive ? 1 : 0,
        ':token' => $token,
        ':expires' => $expires,
    ]);

    $id = (int)db()->lastInsertId();
    return get_user_by_id($id);
}

function update_user_password(int $userId, string $password): void
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = db()->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':hash' => $hash, ':id' => $userId]);
}

function get_user_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

function get_user_by_email(string $email): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email');
    $stmt->execute([':email' => $email]);
    return $stmt->fetch() ?: null;
}

function get_user_by_username(string $username): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->execute([':username' => $username]);
    return $stmt->fetch() ?: null;
}

function authenticate_user(string $usernameOrEmail, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username OR email = :email');
    $stmt->execute([
        ':username' => $usernameOrEmail,
        ':email' => $usernameOrEmail,
    ]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash']) && (int)$user['is_active'] === 1 && (int)$user['is_banned'] === 0) {
        return $user;
    }
    return null;
}

function mark_user_verified(string $token): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email_verify_token = :token AND email_verify_expires > NOW()');
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }
    $update = db()->prepare('UPDATE users SET is_active = 1, email_verify_token = NULL, email_verify_expires = NULL, updated_at = NOW() WHERE id = :id');
    $update->execute([':id' => $user['id']]);
    return true;
}

function regenerate_email_token(int $userId): array
{
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
    $stmt = db()->prepare('UPDATE users SET email_verify_token = :token, email_verify_expires = :expires WHERE id = :id');
    $stmt->execute([
        ':token' => $token,
        ':expires' => $expires,
        ':id' => $userId,
    ]);
    return ['token' => $token, 'expires' => $expires];
}

function store_session_user(array $user): void
{
    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'is_banned' => (int)$user['is_banned'],
        'is_active' => (int)$user['is_active'],
    ];
}

function logout_user(): void
{
    unset($_SESSION['user']);
}

function all_users(): array
{
    $stmt = db()->query('SELECT id, username, role FROM users WHERE is_active = 1 AND is_banned = 0 ORDER BY username');
    return $stmt->fetchAll();
}

function list_users(): array
{
    $stmt = db()->query('SELECT id, username, email, role, is_active, is_banned, created_at, updated_at FROM users ORDER BY created_at DESC');
    return $stmt->fetchAll();
}

function update_user_role(int $userId, string $role): void
{
    $allowed = ['admin', 'manager', 'player'];
    if (!in_array($role, $allowed, true)) {
        throw new InvalidArgumentException('Invalid role supplied');
    }
    $stmt = db()->prepare('UPDATE users SET role = :role, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':role' => $role, ':id' => $userId]);
}

function create_test_player(): array
{
    $suffix = 1;
    $stmt = db()->query("SELECT username, email FROM users WHERE username REGEXP '^Player[0-9]+$' ORDER BY CAST(SUBSTRING(username, 7) AS UNSIGNED) DESC LIMIT 1");
    $last = $stmt->fetch();
    if ($last && preg_match('/^Player(\d+)$/', $last['username'], $matches)) {
        $suffix = (int)$matches[1] + 1;
    }

    do {
        $username = 'Player' . $suffix;
        $email = 'player' . $suffix . '@example.com';
        $suffix++;
    } while (get_user_by_username($username) || get_user_by_email($email));

    return create_user($username, $email, 'playinggame', 'player', true);
}

function ban_user(int $userId): void
{
    $stmt = db()->prepare('UPDATE users SET is_banned = 1, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $userId]);
}

function unban_user(int $userId): void
{
    $stmt = db()->prepare('UPDATE users SET is_banned = 0, updated_at = NOW() WHERE id = :id');
    $stmt->execute([':id' => $userId]);
}

function delete_user(int $userId): void
{
    $stmt = db()->prepare('DELETE FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
}

function remaining_admins_excluding(int $userId): int
{
    $stmt = db()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND id <> :id AND is_banned = 0 AND is_active = 1");
    $stmt->execute([':id' => $userId]);
    return (int)$stmt->fetchColumn();
}
