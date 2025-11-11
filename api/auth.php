<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!is_post()) {
    http_response_code(405);
    exit;
}

require_csrf();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'register':
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['password_confirmation'] ?? '';

        if ($username === '' || $email === '' || $password === '' || $confirm === '') {
            flash('error', 'All fields are required.');
            redirect('/?page=register');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
            redirect('/?page=register');
        }
        if ($password !== $confirm) {
            flash('error', 'Passwords do not match.');
            redirect('/?page=register');
        }
        if (strlen($password) < 10) {
            flash('error', 'Password must be at least 10 characters.');
            redirect('/?page=register');
        }
        if (get_user_by_username($username) || get_user_by_email($email)) {
            flash('error', 'Username or email already registered.');
            redirect('/?page=register');
        }
        $user = create_user($username, $email, $password, 'user', false);
        $token = $user['email_verify_token'];
        $link = site_url('/?page=verify&token=' . urlencode($token));
        $body = '<p>Hello ' . sanitize($username) . ',</p>';
        $body .= '<p>Please verify your email by clicking the link below:</p>';
        $body .= '<p><a href="' . $link . '">' . $link . '</a></p>';
        if (!send_mail($email, $username, 'Verify your email', $body)) {
            flash('error', 'Failed to send verification email.');
        } else {
            flash('success', 'Registration complete! Check your email to verify your account.');
        }
        redirect('/?page=login');

    case 'login':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = authenticate_user($username, $password);
        if ($user) {
            store_session_user($user);
            flash('success', 'Welcome back, ' . $user['username'] . '!');
            redirect('/?page=dashboard');
        }
        flash('error', 'Invalid credentials or unverified account.');
        redirect('/?page=login');

    case 'logout':
        logout_user();
        flash('success', 'You have been logged out.');
        redirect('/');

    default:
        http_response_code(400);
        echo 'Unknown action';
}
