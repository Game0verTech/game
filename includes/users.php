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

function ensure_user_profile_schema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo = db();
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'user_profiles'");
    $tableExists = $tableCheck->fetchColumn() !== false;

    if (!$tableExists) {
        $pdo->exec(<<<SQL
CREATE TABLE user_profiles (
    user_id INT PRIMARY KEY,
    display_name VARCHAR(120) DEFAULT NULL,
    public_about TEXT DEFAULT NULL,
    gamer_tags JSON DEFAULT NULL,
    social_links JSON DEFAULT NULL,
    icon_path VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_profiles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );
        return;
    }

    $columns = [
        'display_name' => "ALTER TABLE user_profiles ADD COLUMN display_name VARCHAR(120) DEFAULT NULL AFTER user_id",
        'public_about' => "ALTER TABLE user_profiles ADD COLUMN public_about TEXT DEFAULT NULL AFTER display_name",
        'gamer_tags' => "ALTER TABLE user_profiles ADD COLUMN gamer_tags JSON DEFAULT NULL AFTER public_about",
        'social_links' => "ALTER TABLE user_profiles ADD COLUMN social_links JSON DEFAULT NULL AFTER gamer_tags",
        'icon_path' => "ALTER TABLE user_profiles ADD COLUMN icon_path VARCHAR(255) DEFAULT NULL AFTER social_links",
        'created_at' => "ALTER TABLE user_profiles ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER icon_path",
        'updated_at' => "ALTER TABLE user_profiles ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at",
    ];

    foreach ($columns as $column => $sql) {
        $columnCheck = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_profiles' AND COLUMN_NAME = :column");
        $columnCheck->execute([':column' => $column]);
        if ($columnCheck->fetchColumn()) {
            continue;
        }
        $pdo->exec($sql);
    }

    $primaryCheck = $pdo->query("SHOW INDEX FROM user_profiles WHERE Key_name = 'PRIMARY'");
    if ($primaryCheck->fetchColumn() === false) {
        $pdo->exec('ALTER TABLE user_profiles ADD PRIMARY KEY (user_id)');
    }
}

function profile_gamer_tag_fields(): array
{
    return [
        'xbox' => ['label' => 'Xbox Gamertag'],
        'psn' => ['label' => 'PlayStation Network'],
        'steam' => ['label' => 'Steam'],
        'switch' => ['label' => 'Nintendo Switch'],
    ];
}

function profile_social_fields(): array
{
    return [
        'twitch' => ['label' => 'Twitch', 'url_format' => 'https://www.twitch.tv/%s', 'strip_at' => true],
        'youtube' => ['label' => 'YouTube', 'url_format' => 'https://www.youtube.com/@%s', 'strip_at' => true],
        'twitter' => ['label' => 'X (Twitter)', 'url_format' => 'https://twitter.com/%s', 'strip_at' => true],
        'instagram' => ['label' => 'Instagram', 'url_format' => 'https://www.instagram.com/%s', 'strip_at' => true],
        'facebook' => ['label' => 'Facebook', 'url_format' => 'https://www.facebook.com/%s', 'strip_at' => true],
        'discord' => ['label' => 'Discord', 'url_format' => null, 'strip_at' => true],
    ];
}

function ensure_user_profile_record(int $userId): array
{
    ensure_user_profile_schema();

    $pdo = db();
    $select = $pdo->prepare('SELECT * FROM user_profiles WHERE user_id = :id');
    $select->execute([':id' => $userId]);
    $profile = $select->fetch();
    if ($profile) {
        return $profile;
    }

    $insert = $pdo->prepare('INSERT INTO user_profiles (user_id) VALUES (:id)');
    $insert->execute([':id' => $userId]);

    $select->execute([':id' => $userId]);
    return $select->fetch() ?: ['user_id' => $userId];
}

function decode_profile_json($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value)) {
        return [];
    }
    $trimmed = trim($value);
    if ($trimmed === '') {
        return [];
    }
    $decoded = json_decode($trimmed, true);
    return is_array($decoded) ? $decoded : [];
}

function default_user_icon_url(): string
{
    return '/assets/images/default-avatar.svg';
}

function resolve_user_icon_url(?string $path): string
{
    if ($path === null) {
        return default_user_icon_url();
    }

    $trimmed = trim($path);
    if ($trimmed === '') {
        return default_user_icon_url();
    }

    $normalized = ltrim($trimmed, '/');
    if ($normalized === '') {
        return default_user_icon_url();
    }

    $fullPath = __DIR__ . '/../' . $normalized;
    if (!file_exists($fullPath)) {
        return default_user_icon_url();
    }

    return '/' . $normalized;
}

function user_icon_url(?array $user = null): string
{
    $user = $user ?? current_user();
    if (!$user) {
        return default_user_icon_url();
    }

    $path = $user['icon_path'] ?? null;
    if (!$path) {
        return default_user_icon_url();
    }

    $trimmed = trim((string)$path);
    if ($trimmed === '') {
        return default_user_icon_url();
    }

    return $trimmed[0] === '/' ? $trimmed : '/' . ltrim($trimmed, '/');
}

function user_social_profile_url(string $platform, string $handle): ?string
{
    $fields = profile_social_fields();
    if (!isset($fields[$platform])) {
        return null;
    }
    $format = $fields[$platform]['url_format'] ?? null;
    if (!$format) {
        return null;
    }

    $normalized = trim($handle);
    if ($normalized === '') {
        return null;
    }
    $normalized = ltrim($normalized, '@');
    $normalized = preg_replace('/\s+/u', '', $normalized) ?? '';
    if ($normalized === '') {
        return null;
    }

    return sprintf($format, rawurlencode($normalized));
}

function get_user_profile(int $userId): array
{
    $profile = ensure_user_profile_record($userId);

    $displayName = $profile['display_name'] ?? null;
    if ($displayName !== null) {
        $normalizedDisplay = normalize_single_line_text((string)$displayName, 120);
        $profile['display_name'] = $normalizedDisplay === '' ? null : $normalizedDisplay;
    }

    $gamerTagsRaw = decode_profile_json($profile['gamer_tags'] ?? null);
    $allowedGamer = profile_gamer_tag_fields();
    $gamerTags = [];
    foreach ($allowedGamer as $key => $meta) {
        if (!isset($gamerTagsRaw[$key])) {
            continue;
        }
        $value = normalize_single_line_text((string)$gamerTagsRaw[$key], 80);
        if ($value === '') {
            continue;
        }
        $gamerTags[$key] = $value;
    }

    $socialRaw = decode_profile_json($profile['social_links'] ?? null);
    $allowedSocial = profile_social_fields();
    $socialLinks = [];
    foreach ($allowedSocial as $key => $meta) {
        if (!isset($socialRaw[$key])) {
            continue;
        }
        $value = normalize_single_line_text((string)$socialRaw[$key], 80);
        if ($value === '') {
            continue;
        }
        if (!empty($meta['strip_at'])) {
            $value = ltrim($value, '@');
        }
        $socialLinks[$key] = $value;
    }

    $profile['gamer_tags'] = $gamerTags;
    $profile['social_links'] = $socialLinks;
    $profile['icon_url'] = resolve_user_icon_url($profile['icon_path'] ?? null);

    return $profile;
}

function user_icon_directory(): string
{
    $path = __DIR__ . '/../assets/user-icons';
    if (!is_dir($path)) {
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException('Failed to create user icon directory.');
        }
    }
    return realpath($path) ?: $path;
}

function update_user_account_info(int $userId, string $email, ?string $displayName, string $currentPassword): void
{
    $user = get_user_by_id($userId);
    if (!$user) {
        throw new RuntimeException('User not found.');
    }

    if (!password_verify($currentPassword, $user['password_hash'])) {
        throw new InvalidArgumentException('Current password is incorrect.');
    }

    $normalizedEmail = strtolower(trim($email));
    if (!filter_var($normalizedEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }

    $emailCheck = db()->prepare('SELECT id FROM users WHERE email = :email AND id <> :id LIMIT 1');
    $emailCheck->execute([':email' => $normalizedEmail, ':id' => $userId]);
    if ($emailCheck->fetch()) {
        throw new InvalidArgumentException('Email address is already registered.');
    }

    $display = normalize_single_line_text($displayName, 120);
    if ($display !== '' && contains_profanity($display)) {
        throw new InvalidArgumentException('Display name contains inappropriate language.');
    }

    $pdo = db();
    $pdo->prepare('UPDATE users SET email = :email, updated_at = NOW() WHERE id = :id')
        ->execute([':email' => $normalizedEmail, ':id' => $userId]);

    ensure_user_profile_record($userId);
    $pdo->prepare('UPDATE user_profiles SET display_name = :display_name, updated_at = NOW() WHERE user_id = :id')
        ->execute([':display_name' => $display === '' ? null : $display, ':id' => $userId]);
}

function update_user_public_profile(int $userId, string $about, array $gamerTags, array $socialHandles): array
{
    ensure_user_profile_record($userId);

    $aboutNormalized = normalize_multiline_text($about, 500);
    if ($aboutNormalized !== '' && contains_profanity($aboutNormalized)) {
        throw new InvalidArgumentException('About me text contains inappropriate language.');
    }

    $gamerNormalized = [];
    foreach (profile_gamer_tag_fields() as $key => $meta) {
        $value = $gamerTags[$key] ?? '';
        $clean = normalize_single_line_text($value, 80);
        if ($clean === '') {
            continue;
        }
        if (contains_profanity($clean)) {
            throw new InvalidArgumentException('Gamer tag contains inappropriate language.');
        }
        $gamerNormalized[$key] = $clean;
    }

    $socialNormalized = [];
    foreach (profile_social_fields() as $key => $meta) {
        $value = $socialHandles[$key] ?? '';
        $clean = normalize_single_line_text($value, 80);
        if ($clean === '') {
            continue;
        }
        if (!empty($meta['strip_at'])) {
            $clean = ltrim($clean, '@');
        }
        $clean = str_replace(' ', '', $clean);
        if (contains_profanity($clean)) {
            throw new InvalidArgumentException('Social handle contains inappropriate language.');
        }
        $socialNormalized[$key] = $clean;
    }

    $pdo = db();
    $pdo->prepare('UPDATE user_profiles SET public_about = :about, gamer_tags = :gamer, social_links = :social, updated_at = NOW() WHERE user_id = :id')
        ->execute([
            ':about' => $aboutNormalized === '' ? null : $aboutNormalized,
            ':gamer' => $gamerNormalized ? json_encode($gamerNormalized, JSON_UNESCAPED_UNICODE) : null,
            ':social' => $socialNormalized ? json_encode($socialNormalized, JSON_UNESCAPED_UNICODE) : null,
            ':id' => $userId,
        ]);

    return [
        'public_about' => $aboutNormalized,
        'gamer_tags' => $gamerNormalized,
        'social_links' => $socialNormalized,
    ];
}

function normalize_icon_crop_request(array $request, int $imageWidth, int $imageHeight): ?array
{
    $rawX = $request['crop_x'] ?? null;
    $rawY = $request['crop_y'] ?? null;
    $rawSize = $request['crop_size'] ?? null;

    if ($rawX === null || $rawY === null || $rawSize === null) {
        return null;
    }

    if ($rawX === '' || $rawY === '' || $rawSize === '') {
        return null;
    }

    if (!is_numeric($rawX) || !is_numeric($rawY) || !is_numeric($rawSize)) {
        return null;
    }

    $expectedWidth = $request['image_width'] ?? null;
    $expectedHeight = $request['image_height'] ?? null;

    if (is_numeric($expectedWidth)) {
        $expectedWidth = (int)round((float)$expectedWidth);
        if (abs($expectedWidth - $imageWidth) > 2) {
            return null;
        }
    }

    if (is_numeric($expectedHeight)) {
        $expectedHeight = (int)round((float)$expectedHeight);
        if (abs($expectedHeight - $imageHeight) > 2) {
            return null;
        }
    }

    $size = (float)$rawSize;
    if ($size <= 0) {
        return null;
    }

    $maxSize = min($imageWidth, $imageHeight);
    $size = min($size, $maxSize);

    $x = max(0.0, (float)$rawX);
    $y = max(0.0, (float)$rawY);

    if ($x > $imageWidth) {
        $x = (float)$imageWidth;
    }
    if ($y > $imageHeight) {
        $y = (float)$imageHeight;
    }

    if ($x + $size > $imageWidth) {
        $x = (float)($imageWidth - $size);
    }
    if ($y + $size > $imageHeight) {
        $y = (float)($imageHeight - $size);
    }

    $x = max(0.0, $x);
    $y = max(0.0, $y);

    return [
        'x' => $x,
        'y' => $y,
        'size' => $size,
    ];
}

function update_user_icon(int $userId, array $file, array $request = []): string
{
    ensure_user_profile_record($userId);

    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload failed. Please try again.');
    }

    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Invalid upload attempt.');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new InvalidArgumentException('Icon must be a valid image file.');
    }

    $type = $info[2] ?? null;
    $allowed = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported image format. Use PNG, JPG, GIF, or WebP.');
    }

    $sizeLimit = 2 * 1024 * 1024;
    if (!empty($file['size']) && (int)$file['size'] > $sizeLimit) {
        throw new InvalidArgumentException('Icon must be 2MB or smaller.');
    }

    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($file['tmp_name']);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($file['tmp_name']);
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($file['tmp_name']);
            break;
        case IMAGETYPE_WEBP:
            if (!function_exists('imagecreatefromwebp')) {
                throw new InvalidArgumentException('WebP images are not supported on this server.');
            }
            $source = imagecreatefromwebp($file['tmp_name']);
            break;
        default:
            $source = false;
    }

    if (!$source) {
        throw new RuntimeException('Failed to read uploaded image.');
    }

    $width = imagesx($source);
    $height = imagesy($source);

    $crop = normalize_icon_crop_request($request, $width, $height);
    if ($crop) {
        $cropSize = max(1, (int)round($crop['size']));
        $srcX = max(0, (int)round($crop['x']));
        $srcY = max(0, (int)round($crop['y']));
    } else {
        $cropSize = min($width, $height);
        $srcX = (int)max(0, ($width - $cropSize) / 2);
        $srcY = (int)max(0, ($height - $cropSize) / 2);
    }

    if ($cropSize < 1) {
        imagedestroy($source);
        throw new RuntimeException('Failed to determine crop size.');
    }

    $targetSize = 256;
    $target = imagecreatetruecolor($targetSize, $targetSize);
    imagealphablending($target, false);
    $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
    imagefilledrectangle($target, 0, 0, $targetSize, $targetSize, $transparent);
    imagesavealpha($target, true);
    imagecopyresampled($target, $source, 0, 0, $srcX, $srcY, $targetSize, $targetSize, $cropSize, $cropSize);

    if (function_exists('imagepalettetotruecolor')) {
        imagepalettetotruecolor($target);
    }

    $directory = user_icon_directory();
    $basename = 'user_' . $userId . '_' . bin2hex(random_bytes(6));
    $relativePath = null;
    $saved = false;

    if (function_exists('imagewebp')) {
        $webpFilename = $basename . '.webp';
        $webpPath = $directory . DIRECTORY_SEPARATOR . $webpFilename;
        $saved = imagewebp($target, $webpPath, 88);
        if ($saved) {
            $relativePath = 'assets/user-icons/' . $webpFilename;
        }
    }

    if (!$saved) {
        $pngFilename = $basename . '.png';
        $pngPath = $directory . DIRECTORY_SEPARATOR . $pngFilename;
        $saved = imagepng($target, $pngPath, 7);
        if ($saved) {
            $relativePath = 'assets/user-icons/' . $pngFilename;
        }
    }

    imagedestroy($source);
    imagedestroy($target);

    if (!$saved || !$relativePath) {
        throw new RuntimeException('Failed to save resized icon.');
    }

    $publicPath = '/' . ltrim($relativePath, '/');

    $profile = get_user_profile($userId);
    $previous = $profile['icon_path'] ?? null;
    if ($previous) {
        $previousPath = __DIR__ . '/../' . ltrim($previous, '/');
        if (is_file($previousPath)) {
            @unlink($previousPath);
        }
    }

    db()->prepare('UPDATE user_profiles SET icon_path = :path, updated_at = NOW() WHERE user_id = :id')
        ->execute([':path' => $relativePath, ':id' => $userId]);

    return $publicPath;
}

function remove_user_icon(int $userId): void
{
    ensure_user_profile_record($userId);
    $profile = get_user_profile($userId);
    $existing = $profile['icon_path'] ?? null;
    if ($existing) {
        $path = __DIR__ . '/../' . ltrim($existing, '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }

    db()->prepare('UPDATE user_profiles SET icon_path = NULL, updated_at = NOW() WHERE user_id = :id')
        ->execute([':id' => $userId]);
}

function get_public_user_profile(string $username): ?array
{
    ensure_user_profile_schema();

    $stmt = db()->prepare(
        'SELECT u.id, u.username, u.created_at, up.display_name, up.public_about, up.gamer_tags, up.social_links, up.icon_path
         FROM users u
         LEFT JOIN user_profiles up ON up.user_id = u.id
         WHERE u.username = :username AND u.is_active = 1 AND u.is_banned = 0'
    );
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $gamerTagsRaw = decode_profile_json($row['gamer_tags'] ?? null);
    $gamerTags = [];
    foreach (profile_gamer_tag_fields() as $key => $meta) {
        if (!isset($gamerTagsRaw[$key])) {
            continue;
        }
        $value = normalize_single_line_text((string)$gamerTagsRaw[$key], 80);
        if ($value === '') {
            continue;
        }
        $gamerTags[$key] = $value;
    }

    $socialRaw = decode_profile_json($row['social_links'] ?? null);
    $socialLinks = [];
    foreach (profile_social_fields() as $key => $meta) {
        if (!isset($socialRaw[$key])) {
            continue;
        }
        $value = normalize_single_line_text((string)$socialRaw[$key], 80);
        if ($value === '') {
            continue;
        }
        if (!empty($meta['strip_at'])) {
            $value = ltrim($value, '@');
        }
        $socialLinks[$key] = $value;
    }

    return [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'display_name' => $row['display_name'] ?? null,
        'public_about' => $row['public_about'] ?? null,
        'gamer_tags' => $gamerTags,
        'social_links' => $socialLinks,
        'icon_url' => resolve_user_icon_url($row['icon_path'] ?? null),
        'created_at' => $row['created_at'] ?? null,
    ];
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
    ensure_user_profile_schema();

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
    ensure_user_profile_record($id);

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

function mark_user_verified(string $token): string
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

        $user = get_user_by_id((int)$record['user_id']);
        if (!$user) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return 'invalid';
        }

        if (!empty($record['consumed_at'])) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return (int)$user['is_active'] === 1 ? 'already' : 'invalid';
        }

        $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$record['expires_at'], new DateTimeZone('UTC'));
        if (!$expiresAt) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return 'invalid';
        }

        $nowUtc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($expiresAt <= $nowUtc) {
            if ($startedTransaction) {
                $pdo->rollBack();
            }
            return 'expired';
        }

        $activate = $pdo->prepare('UPDATE users SET is_active = 1, email_verify_token = NULL, email_verify_expires = NULL, updated_at = NOW() WHERE id = :id');
        $activate->execute([':id' => $record['user_id']]);

        $consume = $pdo->prepare('UPDATE user_email_verifications SET consumed_at = UTC_TIMESTAMP() WHERE id = :id');
        $consume->execute([':id' => $record['id']]);

        if ($startedTransaction) {
            $pdo->commit();
        }

        return 'verified';
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function legacy_mark_user_verified(string $token): string
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email_verify_token = :token');
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();
    if (!$user) {
        return 'invalid';
    }

    if ((int)$user['is_active'] === 1) {
        return 'already';
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
        return 'expired';
    }

    $update = db()->prepare('UPDATE users SET is_active = 1, email_verify_token = NULL, email_verify_expires = NULL, updated_at = NOW() WHERE id = :id');
    $update->execute([':id' => $user['id']]);
    return 'verified';
}

function manually_verify_user(int $userId): bool
{
    ensure_user_email_verification_table();

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, is_active FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        return false;
    }

    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->prepare('UPDATE users SET is_active = 1, email_verify_token = NULL, email_verify_expires = NULL, updated_at = NOW() WHERE id = :id')
            ->execute([':id' => $userId]);

        $pdo->prepare('UPDATE user_email_verifications SET consumed_at = COALESCE(consumed_at, UTC_TIMESTAMP()) WHERE user_id = :id AND consumed_at IS NULL')
            ->execute([':id' => $userId]);

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
    $profile = get_user_profile((int)$user['id']);
    $iconUrl = $profile['icon_url'] ?? default_user_icon_url();

    $_SESSION['user'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'is_banned' => (int)$user['is_banned'],
        'is_active' => (int)$user['is_active'],
        'display_name' => $profile['display_name'] ?? null,
        'icon_path' => $iconUrl,
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
