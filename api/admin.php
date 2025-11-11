<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    http_response_code(405);
    exit;
}

require_admin();
require_csrf();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'update_smtp':
        $config = load_config();
        $smtp = $config['smtp'] ?? [];
        $smtp['host'] = trim($_POST['smtp_host'] ?? '');
        $smtp['port'] = (int)($_POST['smtp_port'] ?? 587);
        $smtp['encryption'] = trim($_POST['smtp_encryption'] ?? '');
        $smtp['username'] = trim($_POST['smtp_username'] ?? '');
        $smtp['password'] = $_POST['smtp_password'] ?? '';
        $smtp['from_name'] = trim($_POST['smtp_from_name'] ?? '');
        $smtp['from_email'] = trim($_POST['smtp_from_email'] ?? '');
        if ($smtp['from_email'] === '' || !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
            flash('error', 'A valid from email is required.');
            redirect('/?page=admin&t=settings');
        }
        $config['smtp'] = $smtp;
        save_config($config);
        flash('success', 'SMTP settings updated.');
        redirect('/?page=admin&t=settings');

    case 'test_smtp':
        $smtp = [
            'host' => trim($_POST['smtp_host'] ?? ''),
            'port' => (int)($_POST['smtp_port'] ?? 587),
            'encryption' => trim($_POST['smtp_encryption'] ?? ''),
            'username' => trim($_POST['smtp_username'] ?? ''),
            'password' => $_POST['smtp_password'] ?? '',
            'from_name' => trim($_POST['smtp_from_name'] ?? ''),
            'from_email' => trim($_POST['smtp_from_email'] ?? ''),
        ];
        $recipient = trim($_POST['test_recipient'] ?? '');
        $result = send_test_email($smtp, $recipient ?: $smtp['from_email']);
        if ($result['success']) {
            flash('success', $result['message']);
        } else {
            flash('error', $result['message']);
        }
        redirect('/?page=admin&t=settings');

    case 'bump_version':
        $new = bump_version();
        flash('success', 'Version updated to ' . $new);
        redirect('/?page=admin&t=settings');

    case 'rebuild_stats':
        rebuild_user_stats();
        flash('success', 'Player statistics rebuilt.');
        redirect('/?page=admin&t=settings');

    default:
        http_response_code(400);
        echo 'Unknown action';
}
