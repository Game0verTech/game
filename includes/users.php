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

function ensure_user_email_verification_table(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo = db();
    $check = $pdo->query("SHOW TABLES LIKE 'user_email_verifications'");
    if ($check->fetchColumn() !== false) {
        return;
    }

    $pdo->exec(<<<SQL
CREATE TABLE user_email_verifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME DEFAULT NULL,
    consumed_ip VARCHAR(45) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_email_verifications_token (token),
    KEY idx_user_email_verifications_user (user_id),
    CONSTRAINT fk_user_email_verifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function issue_email_verification_token(int $userId): array
{
    ensure_user_email_verification_table();

    $pdo = db();
    $pdo->prepare('UPDATE user_email_verifications SET consumed_at = UTC_TIMESTAMP() WHERE user_id = :user AND consumed_at IS NULL')
        ->execute([':user' => $userId]);

    $token = bin2hex(random_bytes(32));
    $expiresUtc = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 day');

    $insert = $pdo->prepare('INSERT INTO user_email_verifications (user_id, token, expires_at) VALUES (:user_id, :token, :expires)');
    $insert->execute([
        ':user_id' => $userId,
        ':token' => $token,
        ':expires' => $expiresUtc->format('Y-m-d H:i:s'),
    ]);

    $legacy = $pdo->prepare('UPDATE users SET email_verify_token = NULL, email_verify_expires = NULL WHERE id = :id');
    $legacy->execute([':id' => $userId]);

    return [
        'token' => $token,
        'expires_at_utc' => $expiresUtc,
    ];
}

function create_user(string $username, string $email, string $password, string $role = 'player', bool $isActive = false): array
{
    ensure_user_email_verification_table();

    $hash = password_hash($password, PASSWORD_DEFAULT);

    $sql = 'INSERT INTO users (username, email, password_hash, role, is_active, is_banned, created_at, updated_at, email_verify_token, email_verify_expires)'
            . ' VALUES (:username, :email, :password_hash, :role, :is_active, 0, NOW(), NOW(), NULL, NULL)';
    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':username' => $username,
        ':email' => $email,
        ':password_hash' => $hash,
        ':role' => $role,
        ':is_active' => $isActive ? 1 : 0,
    ]);

    $id = (int)db()->lastInsertId();
    $user = get_user_by_id($id);

    if (!$isActive) {
        $verification = issue_email_verification_token($id);
        $user['email_verify_token'] = $verification['token'];
        $user['email_verify_expires'] = $verification['expires_at_utc']
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i:s');
    }

    return $user;
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
    ensure_user_email_verification_table();

    $pdo = db();
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $select = $pdo->prepare('SELECT * FROM user_email_verifications WHERE token = :token ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $select->execute([':token' => $token]);
        $record = $select->fetch();

        if (!$record) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return legacy_mark_user_verified($token);
        }

        if (!empty($record['consumed_at'])) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$record['expires_at'], new DateTimeZone('UTC'));
        if (!$expiresAt) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($expiresAt <= $nowUtc) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return false;
        }

        $activate = $pdo->prepare('UPDATE users SET is_active = 1, email_verify_token = NULL, email_verify_expires = NULL, updated_at = NOW() WHERE id = :id');
        $activate->execute([':id' => $record['user_id']]);

        $consume = $pdo->prepare('UPDATE user_email_verifications SET consumed_at = UTC_TIMESTAMP() WHERE id = :id');
        $consume->execute([':id' => $record['id']]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return true;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function legacy_mark_user_verified(string $token): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email_verify_token = :token');
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    $config = load_config();
    $timezone = configured_timezone($config);
    $tz = new DateTimeZone($timezone);
    $now = new DateTimeImmutable('now', $tz);

    $expiresAt = null;
    $rawExpiry = $user['email_verify_expires'] ?? null;
    if (!empty($rawExpiry)) {
        $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $rawExpiry, $tz);
        if ($expiresAt === false) {
            $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s.u', $rawExpiry, $tz);
        }
    }

    if ($expiresAt instanceof DateTimeImmutable && $expiresAt <= $now) {
        return false;
    }

    $update = db()->prepare('UPDATE users SET is_active = 1, email_verify_token = NULL, email_verify_expires = NULL, updated_at = NOW() WHERE id = :id');
    $update->execute([':id' => $user['id']]);
    return true;
}

function regenerate_email_token(int $userId): array
{
    $verification = issue_email_verification_token($userId);
    return [
        'token' => $verification['token'],
        'expires' => $verification['expires_at_utc']->format('Y-m-d H:i:s'),
    ];
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
