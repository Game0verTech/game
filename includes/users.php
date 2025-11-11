<?php

function create_user(string $username, string $email, string $password, string $role = 'player', bool $isActive = false): array
{
    $token = bin2hex(random_bytes(32));
    $expires = (new DateTime('+1 day'))->format('Y-m-d H:i:s');
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (username, email, password_hash, role, is_active, created_at, updated_at, email_verify_token, email_verify_expires)
            VALUES (:username, :email, :password_hash, :role, :is_active, NOW(), NOW(), :token, :expires)';
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
    if ($user && password_verify($password, $user['password_hash']) && (int)$user['is_active'] === 1) {
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
    ];
}

function logout_user(): void
{
    unset($_SESSION['user']);
}

function all_users(): array
{
    $stmt = db()->query('SELECT id, username, role FROM users WHERE is_active = 1 ORDER BY username');
    return $stmt->fetchAll();
}

function list_users(): array
{
    $stmt = db()->query('SELECT id, username, email, role, is_active, created_at, updated_at FROM users ORDER BY created_at DESC');
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
