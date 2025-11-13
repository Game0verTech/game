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
        $user = create_user($username, $email, $password, 'player', false);
        $token = $user['email_verify_token'];
        if (!$token) {
            flash('error', 'Failed to generate a verification token. Please contact support.');
            redirect('/?page=register');
        }

        $config = load_config();
        $siteName = $config['site']['name'] ?? 'Play for Purpose Ohio';
        $timezone = configured_timezone($config);
        $verifyUrl = site_url('/?page=verify&token=' . urlencode($token));

        $expiresAt = null;
        if (!empty($user['email_verify_expires'])) {
            $expiresAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $user['email_verify_expires'], new DateTimeZone($timezone));
        }
        if (!$expiresAt) {
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone($timezone)))->modify('+1 day');
        }
        $expiresFormatted = $expiresAt->format('F j, Y g:i A T');

        $messageLine = 'Thanks for signing up for Play for Purpose Game Night! Please confirm your email address to activate your account.';
        $usernameHtml = sanitize($username);
        $siteNameHtml = sanitize($siteName);
        $verifyUrlHtml = sanitize($verifyUrl);
        $expiresHtml = sanitize($expiresFormatted);
        $messageHtml = sanitize($messageLine);

        $body = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Verify your email</title>
</head>
<body style="background-color:#f5f5f5;margin:0;padding:0;">
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
        <tr>
            <td align="center" style="padding:24px;">
                <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.05);">
                    <tr>
                        <td style="background-color:#1f3c88;color:#ffffff;padding:24px 32px;font-family:Arial, Helvetica, sans-serif;">
                            <h1 style="margin:0;font-size:24px;font-weight:600;">{$siteNameHtml}</h1>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:32px;font-family:Arial, Helvetica, sans-serif;color:#333333;font-size:16px;line-height:1.5;">
                            <p style="margin-top:0;">Hello {$usernameHtml},</p>
                            <p>{$messageHtml}</p>
                            <p style="text-align:center;margin:32px 0;">
                                <a href="{$verifyUrlHtml}" style="display:inline-block;background-color:#1f3c88;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:4px;font-weight:600;">Verify Email</a>
                            </p>
                            <p>Or copy and paste this link into your browser:</p>
                            <p style="word-break:break-all;"><a href="{$verifyUrlHtml}" style="color:#1f3c88;text-decoration:none;">{$verifyUrlHtml}</a></p>
                            <p style="margin-bottom:0;">This link expires on <strong>{$expiresHtml}</strong>. If it stops working, you can request a new verification email from the login page.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:24px 32px;background-color:#f0f3f9;font-family:Arial, Helvetica, sans-serif;color:#555555;font-size:12px;line-height:1.4;">
                            <p style="margin:0;">If you didn’t create this account, you can safely ignore this message.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

        $altBody = "Hello {$username},\n\n"
            . "{$messageLine}\n\n"
            . "Verification link: {$verifyUrl}\n"
            . "This link expires on {$expiresFormatted}. If it stops working, you can request a new verification email from the login page.\n\n"
            . "If you didn't create this account, you can ignore this message.";

        $subject = 'Verify your email for ' . $siteName;

        if (!send_mail($email, $username, $subject, $body, $altBody)) {
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
        $lookup = get_user_by_username($username);
        if (!$lookup) {
            $lookup = get_user_by_email($username);
        }
        if ($lookup && password_verify($password, $lookup['password_hash'])) {
            if ((int)$lookup['is_banned'] === 1) {
                flash('error', 'Your account has been banned. Contact an administrator for assistance.');
                redirect('/?page=login');
            }
            if ((int)$lookup['is_active'] !== 1) {
                flash('error', 'Please verify your email before logging in.');
                redirect('/?page=login');
            }
        }
        flash('error', 'Invalid credentials.');
        redirect('/?page=login');

    case 'logout':
        logout_user();
        flash('success', 'You have been logged out.');
        redirect('/');

    default:
        http_response_code(400);
        echo 'Unknown action';
}
