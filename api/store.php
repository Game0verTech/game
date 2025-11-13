<?php
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json');

if (!is_post()) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_login();
require_role('admin');
require_csrf();

$action = $_POST['action'] ?? '';
$terminalKey = trim($_POST['terminal_key'] ?? '');

if ($terminalKey === '') {
    $terminalKey = 'global';
}

try {
    switch ($action) {
        case 'state':
            respondSuccess([
                'session' => formatSession(store_get_active_session($terminalKey)),
                'products' => array_map('formatProduct', store_list_products()),
                'recent' => formatRecentList($terminalKey),
            ]);
            break;

        case 'open_session':
            $password = $_POST['password'] ?? '';
            $startingCash = store_currency_to_decimal($_POST['starting_cash'] ?? '0');
            $user = current_user();
            if (!store_verify_user_password((int)$user['id'], $password)) {
                throw new RuntimeException('Password verification failed.');
            }
            $session = store_open_session($terminalKey, (int)$user['id'], $startingCash);
            respondSuccess([
                'session' => formatSession($session),
                'recent' => formatRecentList($terminalKey, $session ? (int)$session['id'] : null),
            ]);
            break;

        case 'close_session':
            $sessionId = (int)($_POST['session_id'] ?? 0);
            if ($sessionId <= 0) {
                throw new InvalidArgumentException('Invalid session.');
            }
            $password = $_POST['password'] ?? '';
            $closingCash = store_currency_to_decimal($_POST['closing_cash'] ?? '0');
            $user = current_user();
            if (!store_verify_user_password((int)$user['id'], $password)) {
                throw new RuntimeException('Password verification failed.');
            }
            store_close_session($sessionId, (int)$user['id'], $closingCash);
            respondSuccess(['session' => null]);
            break;

        case 'create_product':
            $name = trim($_POST['name'] ?? '');
            $cost = store_currency_to_decimal($_POST['cost'] ?? '0');
            $price = store_currency_to_decimal($_POST['price'] ?? '0');
            if ($name === '') {
                throw new InvalidArgumentException('Product name is required.');
            }
            $product = store_create_product($name, $cost, $price);
            if (!empty($_FILES['icon'])) {
                $path = store_process_icon_upload((int)$product['id'], $_FILES['icon']);
                if ($path) {
                    $product = store_update_product((int)$product['id'], $name, $cost, $price, $path);
                }
            }
            respondSuccess(['products' => array_map('formatProduct', store_list_products())]);
            break;

        case 'update_product':
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new InvalidArgumentException('Product not found.');
            }
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new InvalidArgumentException('Product name is required.');
            }
            $cost = store_currency_to_decimal($_POST['cost'] ?? '0');
            $price = store_currency_to_decimal($_POST['price'] ?? '0');
            $imagePath = null;
            if (!empty($_FILES['icon'])) {
                $imagePath = store_process_icon_upload($productId, $_FILES['icon']);
            }
            store_update_product($productId, $name, $cost, $price, $imagePath);
            respondSuccess(['products' => array_map('formatProduct', store_list_products())]);
            break;

        case 'delete_product':
            $productId = (int)($_POST['product_id'] ?? 0);
            if ($productId <= 0) {
                throw new InvalidArgumentException('Product not found.');
            }
            store_delete_product($productId);
            respondSuccess(['deleted' => true]);
            break;

        case 'record_transaction':
            $sessionId = (int)($_POST['session_id'] ?? 0);
            if ($sessionId <= 0) {
                throw new InvalidArgumentException('Session is required.');
            }
            $itemsJson = $_POST['items'] ?? '[]';
            $items = json_decode($itemsJson, true);
            if (!is_array($items)) {
                throw new InvalidArgumentException('Invalid items payload.');
            }
            $transaction = store_record_transaction($sessionId, $terminalKey, (int)current_user()['id'], $items);
            respondSuccess([
                'transaction' => $transaction,
                'recent' => formatRecentList($terminalKey, $sessionId),
            ]);
            break;

        case 'report':
            $date = $_POST['report_date'] ?? '';
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException('Invalid report date.');
            }
            $sessions = store_sessions_by_date($date);
            $summary = store_daily_summary($date);
            respondSuccess([
                'sessions' => array_map('formatSession', $sessions),
                'summary' => $summary,
                'items' => array_map('formatReportItem', store_item_sales_by_date($date)),
                'transactions' => array_map('formatReportTransaction', store_transactions_by_date($date)),
            ]);
            break;

        default:
            throw new InvalidArgumentException('Unsupported action.');
    }
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}

function respondSuccess(array $payload): void
{
    echo json_encode(array_merge(['success' => true], $payload));
    exit;
}

function formatProduct(array $product): array
{
    return [
        'id' => (int)$product['id'],
        'name' => $product['name'],
        'cost' => number_format((float)$product['cost'], 2, '.', ''),
        'price' => number_format((float)$product['price'], 2, '.', ''),
        'image_path' => store_product_image_url($product['image_path'] ?? null),
    ];
}

function formatSession(?array $session): ?array
{
    if (!$session) {
        return null;
    }
    return [
        'id' => (int)$session['id'],
        'terminal_key' => $session['terminal_key'],
        'opened_by' => (int)$session['opened_by'],
        'opened_by_username' => $session['opened_by_username'],
        'opened_at' => $session['opened_at'],
        'starting_cash' => number_format((float)$session['starting_cash'], 2, '.', ''),
        'closing_cash' => $session['closing_cash'] !== null ? number_format((float)$session['closing_cash'], 2, '.', '') : null,
        'closed_at' => $session['closed_at'],
        'closed_by' => $session['closed_by'] !== null ? (int)$session['closed_by'] : null,
        'closed_by_username' => $session['closed_by_username'],
        'is_open' => (int)$session['is_open'] === 1,
        'transaction_count' => isset($session['transaction_count']) ? (int)$session['transaction_count'] : null,
        'sales_total' => isset($session['sales_total']) ? number_format((float)$session['sales_total'], 2, '.', '') : null,
    ];
}

function formatRecentList(string $terminalKey, ?int $sessionId = null): array
{
    $session = $sessionId ? store_get_session($sessionId) : store_get_active_session($terminalKey);
    if (!$session) {
        return [];
    }
    $transactions = store_list_recent_transactions((int)$session['id']);
    return array_map(function ($transaction) {
        return [
            'id' => (int)$transaction['id'],
            'total' => number_format((float)$transaction['total'], 2, '.', ''),
            'created_at' => $transaction['created_at'],
            'user' => $transaction['user'],
            'items' => array_map(function ($item) {
                return [
                    'product_name' => $item['product_name'],
                    'quantity' => (int)$item['quantity'],
                    'product_price' => number_format((float)$item['product_price'], 2, '.', ''),
                    'line_total' => number_format((float)$item['line_total'], 2, '.', ''),
                ];
            }, $transaction['items'] ?? []),
        ];
    }, $transactions);
}

function formatReportItem(array $item): array
{
    return [
        'product_name' => $item['product_name'],
        'quantity' => (int)$item['quantity'],
        'sales_total' => $item['sales_total'],
        'cost_total' => $item['cost_total'],
        'profit_total' => $item['profit_total'],
    ];
}

function formatReportTransaction(array $transaction): array
{
    $items = array_map('formatReportTransactionItem', $transaction['items'] ?? []);
    $itemCount = 0;
    foreach ($items as $item) {
        $itemCount += $item['quantity'];
    }
    return [
        'id' => (int)$transaction['id'],
        'session_id' => (int)$transaction['session_id'],
        'terminal_key' => $transaction['terminal_key'],
        'user' => $transaction['created_by_username'] ?? '',
        'created_at' => $transaction['created_at'],
        'total' => number_format((float)$transaction['total'], 2, '.', ''),
        'item_count' => $itemCount,
        'items' => $items,
    ];
}

function formatReportTransactionItem(array $item): array
{
    return [
        'product_name' => $item['product_name'],
        'quantity' => (int)$item['quantity'],
        'product_price' => number_format((float)$item['product_price'], 2, '.', ''),
        'line_total' => number_format((float)$item['line_total'], 2, '.', ''),
    ];
}
