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
    case 'create_user':
    case 'seed_test_player':
    case 'ban_user':
    case 'unban_user':
    case 'delete_user':
    case 'verify_user':
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

    case 'create_user':
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirmation'] ?? '';
        $role = $_POST['role'] ?? 'player';

        if ($username === '' || $email === '' || $password === '' || $confirm === '') {
            flash('error', 'All fields are required.');
            redirect('/?page=admin&t=users');
        }

        if (!preg_match('/^[A-Za-z0-9_\-]{3,50}$/', $username)) {
            flash('error', 'Username must be 3-50 characters and may include letters, numbers, dashes, and underscores.');
            redirect('/?page=admin&t=users');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
            redirect('/?page=admin&t=users');
        }

        if ($password !== $confirm) {
            flash('error', 'Passwords do not match.');
            redirect('/?page=admin&t=users');
        }

        if (strlen($password) < 10) {
            flash('error', 'Password must be at least 10 characters.');
            redirect('/?page=admin&t=users');
        }

        if (!in_array($role, ['admin', 'manager', 'player'], true)) {
            flash('error', 'Invalid role selection.');
            redirect('/?page=admin&t=users');
        }

        if (get_user_by_username($username) || get_user_by_email($email)) {
            flash('error', 'Username or email already exists.');
            redirect('/?page=admin&t=users');
        }

        create_user($username, $email, $password, $role, true);
        flash('success', 'User account created successfully.');
        redirect('/?page=admin&t=users');

    case 'seed_test_player':
        $player = create_test_player();
        flash('success', 'Created test player ' . $player['username'] . ' with default password.');
        redirect('/?page=admin&t=users');

    case 'verify_user':
        $targetId = (int)($_POST['user_id'] ?? 0);
        if ($targetId === 0) {
            flash('error', 'User not specified.');
            redirect('/?page=admin&t=users');
        }
        if (!manually_verify_user($targetId)) {
            flash('error', 'User not found.');
        } else {
            flash('success', 'User verified successfully.');
        }
        redirect('/?page=admin&t=users');

    case 'ban_user':
    case 'unban_user':
        $targetId = (int)($_POST['user_id'] ?? 0);
        $current = current_user();
        if ($targetId === 0) {
            flash('error', 'User not specified.');
            redirect('/?page=admin&t=users');
        }
        if ($targetId === (int)$current['id']) {
            flash('error', 'You cannot modify your own ban status.');
            redirect('/?page=admin&t=users');
        }
        $target = get_user_by_id($targetId);
        if (!$target) {
            flash('error', 'User not found.');
            redirect('/?page=admin&t=users');
        }
        if ($target['role'] === 'admin' && remaining_admins_excluding($targetId) === 0 && $action === 'ban_user') {
            flash('error', 'At least one active administrator must remain.');
            redirect('/?page=admin&t=users');
        }
        if ($action === 'ban_user') {
            ban_user($targetId);
            flash('success', 'User banned successfully.');
        } else {
            unban_user($targetId);
            flash('success', 'User reinstated successfully.');
        }
        redirect('/?page=admin&t=users');

    case 'delete_user':
        $targetId = (int)($_POST['user_id'] ?? 0);
        $current = current_user();
        if ($targetId === 0) {
            flash('error', 'User not specified.');
            redirect('/?page=admin&t=users');
        }
        if ($targetId === (int)$current['id']) {
            flash('error', 'You cannot delete your own account.');
            redirect('/?page=admin&t=users');
        }
        $target = get_user_by_id($targetId);
        if (!$target) {
            flash('error', 'User not found.');
            redirect('/?page=admin&t=users');
        }
        if ($target['role'] === 'admin' && remaining_admins_excluding($targetId) === 0) {
            flash('error', 'Cannot delete the last active administrator.');
            redirect('/?page=admin&t=users');
        }
        delete_user($targetId);
        flash('success', 'User deleted successfully.');
        redirect('/?page=admin&t=users');

    default:
        http_response_code(400);
        echo 'Unknown action';
}
