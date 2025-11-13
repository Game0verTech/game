<?php

function ensure_store_schema(): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    $ensured = true;

    $pdo = db();

    $pdo->exec('CREATE TABLE IF NOT EXISTS store_products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        image_path VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_store_products_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS store_sessions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        terminal_key VARCHAR(64) NOT NULL,
        opened_by INT NOT NULL,
        opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        starting_cash DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        closing_cash DECIMAL(10,2) DEFAULT NULL,
        closed_at DATETIME DEFAULT NULL,
        closed_by INT DEFAULT NULL,
        is_open TINYINT(1) NOT NULL DEFAULT 1,
        open_lock VARCHAR(64) DEFAULT NULL,
        CONSTRAINT fk_store_sessions_opened_by FOREIGN KEY (opened_by) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_store_sessions_closed_by FOREIGN KEY (closed_by) REFERENCES users(id) ON DELETE SET NULL,
        UNIQUE KEY uq_store_sessions_open_lock (open_lock),
        INDEX idx_store_sessions_terminal (terminal_key),
        INDEX idx_store_sessions_opened_at (opened_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // Ensure schema upgrades for existing installations
    $columnCheck = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_sessions' AND COLUMN_NAME = 'open_lock'");
    $columnCheck->execute();
    if (!$columnCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE store_sessions ADD COLUMN open_lock VARCHAR(64) DEFAULT NULL AFTER is_open");
    }

    $indexCheck = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_sessions' AND INDEX_NAME = 'uq_store_sessions_open_lock'");
    $indexCheck->execute();
    if (!$indexCheck->fetchColumn()) {
        $pdo->exec('CREATE UNIQUE INDEX uq_store_sessions_open_lock ON store_sessions (open_lock)');
    }

    $terminalIndex = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_sessions' AND INDEX_NAME = 'idx_store_sessions_terminal'");
    $terminalIndex->execute();
    if (!$terminalIndex->fetchColumn()) {
        $pdo->exec('CREATE INDEX idx_store_sessions_terminal ON store_sessions (terminal_key)');
    }

    $legacyIndex = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_sessions' AND INDEX_NAME = 'uq_store_sessions_terminal_open'");
    $legacyIndex->execute();
    if ($legacyIndex->fetchColumn()) {
        $pdo->exec('ALTER TABLE store_sessions DROP INDEX uq_store_sessions_terminal_open');
    }

    $pdo->exec('CREATE TABLE IF NOT EXISTS store_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        terminal_key VARCHAR(64) NOT NULL,
        created_by INT NOT NULL,
        total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_store_transactions_session FOREIGN KEY (session_id) REFERENCES store_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_store_transactions_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_store_transactions_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    $pdo->exec('CREATE TABLE IF NOT EXISTS store_transaction_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transaction_id INT NOT NULL,
        product_id INT DEFAULT NULL,
        product_name VARCHAR(150) NOT NULL,
        product_price DECIMAL(10,2) NOT NULL,
        product_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantity INT NOT NULL DEFAULT 1,
        line_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        CONSTRAINT fk_store_items_transaction FOREIGN KEY (transaction_id) REFERENCES store_transactions(id) ON DELETE CASCADE,
        CONSTRAINT fk_store_items_product FOREIGN KEY (product_id) REFERENCES store_products(id) ON DELETE SET NULL,
        INDEX idx_store_items_transaction (transaction_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
}

function store_currency_to_decimal(string $value): string
{
    $normalized = trim($value);
    $normalized = str_replace([',', '$', '\\u00a0', ' '], '', $normalized);
    if ($normalized === '' || !is_numeric($normalized)) {
        throw new InvalidArgumentException('Amount must be numeric.');
    }
    return number_format((float)$normalized, 2, '.', '');
}

function store_list_products(): array
{
    ensure_store_schema();
    $stmt = db()->query('SELECT id, name, cost, price, image_path, created_at, updated_at FROM store_products ORDER BY name');
    return $stmt->fetchAll();
}

function store_get_product(int $productId): ?array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT * FROM store_products WHERE id = :id');
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch();
    return $product ?: null;
}

function store_create_product(string $name, string $cost, string $price, ?string $imagePath = null): array
{
    ensure_store_schema();
    $stmt = db()->prepare('INSERT INTO store_products (name, cost, price, image_path) VALUES (:name, :cost, :price, :image)');
    $stmt->execute([
        ':name' => $name,
        ':cost' => $cost,
        ':price' => $price,
        ':image' => $imagePath,
    ]);
    $id = (int)db()->lastInsertId();
    return store_get_product($id);
}

function store_update_product(int $productId, string $name, string $cost, string $price, ?string $imagePath = null): array
{
    ensure_store_schema();
    $stmt = db()->prepare('UPDATE store_products SET name = :name, cost = :cost, price = :price, image_path = COALESCE(:image, image_path), updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':id' => $productId,
        ':name' => $name,
        ':cost' => $cost,
        ':price' => $price,
        ':image' => $imagePath,
    ]);
    return store_get_product($productId);
}

function store_delete_product(int $productId): void
{
    ensure_store_schema();
    $product = store_get_product($productId);
    if ($product && $product['image_path']) {
        $path = __DIR__ . '/../' . ltrim($product['image_path'], '/');
        if (is_file($path)) {
            @unlink($path);
        }
    }
    $stmt = db()->prepare('DELETE FROM store_products WHERE id = :id');
    $stmt->execute([':id' => $productId]);
}

function store_product_image_url(?string $path): string
{
    return $path ?: '/assets/store/icons/default-product.svg';
}

function store_icons_directory(): string
{
    $path = __DIR__ . '/../assets/store/icons';
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
    return $path;
}

function store_process_icon_upload(int $productId, array $file): ?string
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return null;
    }

    $imageData = file_get_contents($file['tmp_name']);
    if ($imageData === false) {
        throw new RuntimeException('Unable to read uploaded file.');
    }

    $image = @imagecreatefromstring($imageData);
    if (!$image) {
        throw new InvalidArgumentException('Unsupported image format. Use PNG or JPEG files.');
    }

    $size = 256;
    $width = imagesx($image);
    $height = imagesy($image);
    $destination = imagecreatetruecolor($size, $size);
    imagesavealpha($destination, true);
    $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
    imagefill($destination, 0, 0, $transparent);

    $scale = min($size / $width, $size / $height);
    $newWidth = (int)round($width * $scale);
    $newHeight = (int)round($height * $scale);
    $dstX = (int)floor(($size - $newWidth) / 2);
    $dstY = (int)floor(($size - $newHeight) / 2);

    imagecopyresampled($destination, $image, $dstX, $dstY, 0, 0, $newWidth, $newHeight, $width, $height);

    $directory = store_icons_directory();
    $filePath = $directory . '/product-' . $productId . '.png';
    if (!imagepng($destination, $filePath)) {
        throw new RuntimeException('Failed to save product icon.');
    }

    imagedestroy($image);
    imagedestroy($destination);

    return '/assets/store/icons/product-' . $productId . '.png';
}

function store_get_active_session(string $terminalKey): ?array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT ss.*, uo.username AS opened_by_username, uc.username AS closed_by_username
        FROM store_sessions ss
        LEFT JOIN users uo ON uo.id = ss.opened_by
        LEFT JOIN users uc ON uc.id = ss.closed_by
        WHERE ss.terminal_key = :terminal AND ss.is_open = 1
        ORDER BY ss.opened_at DESC
        LIMIT 1');
    $stmt->execute([':terminal' => $terminalKey]);
    $session = $stmt->fetch();
    return $session ?: null;
}

function store_open_session(string $terminalKey, int $userId, string $startingCash): array
{
    ensure_store_schema();
    $existing = store_get_active_session($terminalKey);
    if ($existing) {
        throw new RuntimeException('This station already has an active session.');
    }

    $stmt = db()->prepare('INSERT INTO store_sessions (terminal_key, opened_by, starting_cash, open_lock) VALUES (:terminal, :user, :cash, :lock)');
    $stmt->execute([
        ':terminal' => $terminalKey,
        ':user' => $userId,
        ':cash' => $startingCash,
        ':lock' => $terminalKey,
    ]);

    $id = (int)db()->lastInsertId();
    return store_get_session($id);
}

function store_get_session(int $sessionId): ?array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT ss.*, uo.username AS opened_by_username, uc.username AS closed_by_username
        FROM store_sessions ss
        LEFT JOIN users uo ON uo.id = ss.opened_by
        LEFT JOIN users uc ON uc.id = ss.closed_by
        WHERE ss.id = :id');
    $stmt->execute([':id' => $sessionId]);
    $session = $stmt->fetch();
    return $session ?: null;
}

function store_close_session(int $sessionId, int $userId, string $closingCash): array
{
    ensure_store_schema();
    $session = store_get_session($sessionId);
    if (!$session || (int)$session['is_open'] !== 1) {
        throw new RuntimeException('Session is not active.');
    }

    $stmt = db()->prepare('UPDATE store_sessions
        SET closing_cash = :cash, closed_at = NOW(), closed_by = :user, is_open = 0, open_lock = NULL
        WHERE id = :id');
    $stmt->execute([
        ':cash' => $closingCash,
        ':user' => $userId,
        ':id' => $sessionId,
    ]);

    return store_get_session($sessionId);
}

function store_verify_user_password(int $userId, string $password): bool
{
    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
    $stmt->execute([':id' => $userId]);
    $hash = $stmt->fetchColumn();
    return $hash ? password_verify($password, $hash) : false;
}

function store_list_recent_transactions(int $sessionId, int $limit = 10): array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT st.id, st.total, st.created_at, u.username AS user
        FROM store_transactions st
        LEFT JOIN users u ON u.id = st.created_by
        WHERE st.session_id = :session
        ORDER BY st.created_at DESC
        LIMIT :limit');
    $stmt->bindValue(':session', $sessionId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $transactions = $stmt->fetchAll();

    $itemStmt = db()->prepare('SELECT product_name, quantity, product_price, line_total FROM store_transaction_items WHERE transaction_id = :id ORDER BY id');
    foreach ($transactions as &$transaction) {
        $itemStmt->execute([':id' => $transaction['id']]);
        $transaction['items'] = $itemStmt->fetchAll();
    }
    unset($transaction);

    return $transactions;
}

function store_record_transaction(int $sessionId, string $terminalKey, int $userId, array $items): array
{
    ensure_store_schema();
    $session = store_get_session($sessionId);
    if (!$session || (int)$session['is_open'] !== 1) {
        throw new RuntimeException('The store is not open for this station.');
    }

    if (empty($items)) {
        throw new InvalidArgumentException('No items were provided.');
    }

    $products = [];
    $total = '0.00';

    foreach ($items as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $quantity = (int)($item['quantity'] ?? 0);
        if ($productId <= 0 || $quantity <= 0) {
            throw new InvalidArgumentException('Invalid product or quantity.');
        }
        $product = store_get_product($productId);
        if (!$product) {
            throw new RuntimeException('Product not found.');
        }
        $lineTotal = number_format((float)$product['price'] * $quantity, 2, '.', '');
        $lineCost = number_format((float)$product['cost'] * $quantity, 2, '.', '');
        $total = number_format((float)$total + (float)$lineTotal, 2, '.', '');
        $products[] = [
            'product_id' => $productId,
            'quantity' => $quantity,
            'name' => $product['name'],
            'price' => number_format((float)$product['price'], 2, '.', ''),
            'cost' => number_format((float)$product['cost'], 2, '.', ''),
            'line_total' => $lineTotal,
            'line_cost' => $lineCost,
        ];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO store_transactions (session_id, terminal_key, created_by, total) VALUES (:session, :terminal, :user, :total)');
        $stmt->execute([
            ':session' => $sessionId,
            ':terminal' => $terminalKey,
            ':user' => $userId,
            ':total' => $total,
        ]);
        $transactionId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO store_transaction_items (transaction_id, product_id, product_name, product_price, product_cost, quantity, line_total) VALUES (:transaction, :product, :name, :price, :cost, :quantity, :total)');
        foreach ($products as $product) {
            $itemStmt->execute([
                ':transaction' => $transactionId,
                ':product' => $product['product_id'],
                ':name' => $product['name'],
                ':price' => $product['price'],
                ':cost' => $product['cost'],
                ':quantity' => $product['quantity'],
                ':total' => $product['line_total'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return store_get_transaction($transactionId);
}

function store_get_transaction(int $transactionId): ?array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT st.*, u.username AS user
        FROM store_transactions st
        LEFT JOIN users u ON u.id = st.created_by
        WHERE st.id = :id');
    $stmt->execute([':id' => $transactionId]);
    $transaction = $stmt->fetch();
    if (!$transaction) {
        return null;
    }
    $itemStmt = db()->prepare('SELECT product_name, quantity, product_price, line_total FROM store_transaction_items WHERE transaction_id = :id ORDER BY id');
    $itemStmt->execute([':id' => $transactionId]);
    $transaction['items'] = $itemStmt->fetchAll();
    return $transaction;
}

function store_sessions_by_date(string $date): array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT ss.*, uo.username AS opened_by_username, uc.username AS closed_by_username,
        COALESCE(SUM(st.total), 0) AS sales_total,
        COUNT(st.id) AS transaction_count
        FROM store_sessions ss
        LEFT JOIN users uo ON uo.id = ss.opened_by
        LEFT JOIN users uc ON uc.id = ss.closed_by
        LEFT JOIN store_transactions st ON st.session_id = ss.id
        WHERE DATE(ss.opened_at) = :date
        GROUP BY ss.id
        ORDER BY ss.opened_at');
    $stmt->execute([':date' => $date]);
    return $stmt->fetchAll();
}

function store_daily_summary(string $date): array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT
        COUNT(DISTINCT ss.id) AS sessions,
        COUNT(st.id) AS transactions,
        COALESCE(SUM(st.total), 0) AS revenue,
        COALESCE(SUM(si.product_cost * si.quantity), 0) AS costs
        FROM store_sessions ss
        LEFT JOIN store_transactions st ON st.session_id = ss.id
        LEFT JOIN store_transaction_items si ON si.transaction_id = st.id
        WHERE DATE(ss.opened_at) = :date');
    $stmt->execute([':date' => $date]);
    $summary = $stmt->fetch();
    if (!$summary) {
        return ['sessions' => 0, 'transactions' => 0, 'revenue' => '0.00', 'costs' => '0.00'];
    }
    $summary['revenue'] = number_format((float)$summary['revenue'], 2, '.', '');
    $summary['costs'] = number_format((float)$summary['costs'], 2, '.', '');
    $summary['profit'] = number_format((float)$summary['revenue'] - (float)$summary['costs'], 2, '.', '');
    return $summary;
}

function store_item_sales_by_date(string $date): array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT
        si.product_name,
        SUM(si.quantity) AS quantity,
        SUM(si.line_total) AS sales_total,
        SUM(si.product_cost * si.quantity) AS cost_total
        FROM store_sessions ss
        JOIN store_transactions st ON st.session_id = ss.id
        JOIN store_transaction_items si ON si.transaction_id = st.id
        WHERE DATE(ss.opened_at) = :date
        GROUP BY si.product_name
        ORDER BY sales_total DESC, si.product_name');
    $stmt->execute([':date' => $date]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $sales = (float)($item['sales_total'] ?? 0);
        $costs = (float)($item['cost_total'] ?? 0);
        $item['sales_total'] = number_format($sales, 2, '.', '');
        $item['cost_total'] = number_format($costs, 2, '.', '');
        $item['profit_total'] = number_format($sales - $costs, 2, '.', '');
    }
    unset($item);
    return $items;
}

function store_transactions_by_date(string $date): array
{
    ensure_store_schema();
    $stmt = db()->prepare('SELECT
        st.id,
        st.session_id,
        st.terminal_key,
        st.created_by,
        st.total,
        st.created_at,
        u.username AS created_by_username
        FROM store_sessions ss
        JOIN store_transactions st ON st.session_id = ss.id
        LEFT JOIN users u ON u.id = st.created_by
        WHERE DATE(ss.opened_at) = :date
        ORDER BY st.created_at');
    $stmt->execute([':date' => $date]);
    $transactions = $stmt->fetchAll();
    if (!$transactions) {
        return [];
    }
    $itemStmt = db()->prepare('SELECT product_name, quantity, product_price, line_total FROM store_transaction_items WHERE transaction_id = :id ORDER BY id');
    foreach ($transactions as &$transaction) {
        $itemStmt->execute([':id' => $transaction['id']]);
        $transaction['items'] = $itemStmt->fetchAll();
    }
    unset($transaction);
    return $transactions;
}
