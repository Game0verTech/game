<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    http_response_code(405);
    exit;
}

require_login();
require_csrf();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'update_smtp':
    case 'test_smtp':
        require_role('admin');
        break;
    case 'bump_version':
    case 'rebuild_stats':
        require_role('admin', 'manager');
        break;
    case 'set_role':
        require_role('admin');
        break;
    default:
        require_role('admin', 'manager');
        break;
}

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

    case 'set_role':
        $targetId = (int)($_POST['user_id'] ?? 0);
        $role = $_POST['role'] ?? '';
        $current = current_user();
        if ($targetId === 0) {
            flash('error', 'User not specified.');
            redirect('/?page=admin&t=users');
        }
        if ($targetId === (int)$current['id']) {
            flash('error', 'You cannot change your own role.');
            redirect('/?page=admin&t=users');
        }
        try {
            update_user_role($targetId, $role);
        } catch (InvalidArgumentException $e) {
            flash('error', 'Invalid role selection.');
            redirect('/?page=admin&t=users');
        }
        flash('success', 'Role updated successfully.');
        redirect('/?page=admin&t=users');

    default:
        http_response_code(400);
        echo 'Unknown action';
}
