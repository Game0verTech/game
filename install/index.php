<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mailer.php';

if (env_config_exists() && install_is_locked()) {
    redirect('/');
}

$step = (int)($_GET['step'] ?? 1);
if ($step < 1 || $step > 3) {
    $step = 1;
}

if (!isset($_SESSION)) {
    session_start();
}

if (!isset($_SESSION['install'])) {
    $_SESSION['install'] = [];
}

$errors = [];
$messages = [];

if ($step === 1 && is_post()) {
    $dbConfig = [
        'host' => trim($_POST['db_host'] ?? ''),
        'port' => (int)($_POST['db_port'] ?? 3306),
        'name' => trim($_POST['db_name'] ?? ''),
        'user' => trim($_POST['db_user'] ?? ''),
        'pass' => $_POST['db_pass'] ?? '',
    ];
    if (in_array('', [$dbConfig['host'], $dbConfig['name'], $dbConfig['user']], true)) {
        $errors[] = 'All fields except password are required.';
    } else {
        try {
            db_test_connection($dbConfig);
            $_SESSION['install']['db'] = $dbConfig;
            redirect('/install/?step=2');
        } catch (Throwable $e) {
            $errors[] = 'Database connection failed: ' . $e->getMessage();
        }
    }
}

if ($step === 2) {
    if (empty($_SESSION['install']['db'])) {
        redirect('/install/?step=1');
    }
    $suggestedPassword = bin2hex(random_bytes(6));
    if (is_post()) {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if ($username === '' || $email === '') {
            $errors[] = 'Username and email are required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if ($password === '' || $confirm === '') {
            $errors[] = 'Password and confirmation are required.';
        } elseif ($password !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }
        if (strlen($password) < 10) {
            $errors[] = 'Password must be at least 10 characters.';
        }

        if (!$errors) {
            $dbConfig = $_SESSION['install']['db'];
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbConfig['host'], $dbConfig['port'], $dbConfig['name']);
            try {
                $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $schema = file_get_contents(__DIR__ . '/../database/schema.sql');
                $pdo->exec($schema);

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, is_active, is_banned, created_at, updated_at) VALUES (:username, :email, :hash, 'admin', 1, 0, NOW(), NOW())");
                $stmt->execute([
                    ':username' => $username,
                    ':email' => $email,
                    ':hash' => $hash,
                ]);

                $_SESSION['install']['admin_created'] = true;
                $_SESSION['install']['admin_email'] = $email;
                redirect('/install/?step=3');
            } catch (Throwable $e) {
                $errors[] = 'Failed to create admin user: ' . $e->getMessage();
            }
        }
    }
}

if ($step === 3) {
    if (empty($_SESSION['install']['admin_created'])) {
        redirect('/install/?step=2');
    }
    if (!isset($_SESSION['install']['smtp'])) {
        $_SESSION['install']['smtp'] = [
            'host' => '',
            'port' => 587,
            'encryption' => '',
            'username' => '',
            'password' => '',
            'from_name' => 'Play for Purpose Ohio',
            'from_email' => '',
        ];
    }
    $smtpState = $_SESSION['install']['smtp'];
    if (is_post()) {
        $smtp = [
            'host' => trim($_POST['smtp_host'] ?? $smtpState['host']),
            'port' => (int)($_POST['smtp_port'] ?? $smtpState['port']),
            'encryption' => trim($_POST['smtp_encryption'] ?? $smtpState['encryption']),
            'username' => trim($_POST['smtp_username'] ?? $smtpState['username']),
            'password' => $_POST['smtp_password'] ?? $smtpState['password'],
            'from_name' => trim($_POST['smtp_from_name'] ?? $smtpState['from_name']),
            'from_email' => trim($_POST['smtp_from_email'] ?? $smtpState['from_email']),
        ];
        $_SESSION['install']['smtp'] = $smtp;
        $smtpState = $smtp;
        $action = $_POST['action'] ?? 'save';
        if ($smtp['from_email'] === '' || !filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid from email is required.';
        }
        if (!$errors && $action === 'test') {
            try {
                $result = send_test_email($smtp, $_POST['test_recipient'] ?? $smtp['from_email']);
                if ($result['success']) {
                    $messages[] = $result['message'];
                } else {
                    $errors[] = $result['message'];
                }
            } catch (Throwable $e) {
                $errors[] = 'Test failed: ' . $e->getMessage();
            }
        }
        if (!$errors && $action === 'save') {
            $config = [
                'app' => [
                    'timezone' => 'America/New_York',
                ],
                'db' => $_SESSION['install']['db'],
                'smtp' => $smtp,
                'site' => [
                    'url' => 'https://game.playforpurposeohio.com',
                    'name' => 'Play for Purpose Ohio',
                ],
            ];
            save_config($config);
            create_install_lock();
            unset($_SESSION['install']);
            flash('success', 'Installation complete. Please login.');
            redirect('/?page=login');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer - Play for Purpose Ohio</title>
    <link rel="stylesheet" href="/assets/css/install.css">
</head>
<body>
    <div class="installer">
        <h1>Play for Purpose Ohio - Installation</h1>
        <ol class="steps">
            <li class="<?= $step === 1 ? 'active' : '' ?>">Database</li>
            <li class="<?= $step === 2 ? 'active' : '' ?>">Admin Account</li>
            <li class="<?= $step === 3 ? 'active' : '' ?>">Email</li>
        </ol>
        <?php if ($errors): ?>
            <div class="alert error">
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= sanitize($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        <?php if ($messages): ?>
            <div class="alert success">
                <ul>
                    <?php foreach ($messages as $message): ?>
                        <li><?= sanitize($message) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="post">
                <label>Database Host
                    <input type="text" name="db_host" required>
                </label>
                <label>Database Port
                    <input type="number" name="db_port" value="3306" required>
                </label>
                <label>Database Name
                    <input type="text" name="db_name" required>
                </label>
                <label>Database Username
                    <input type="text" name="db_user" required>
                </label>
                <label>Database Password
                    <input type="password" name="db_pass">
                </label>
                <button type="submit">Save &amp; Continue</button>
            </form>
        <?php elseif ($step === 2): ?>
            <form method="post">
                <p>Suggested strong password: <strong><?= sanitize($suggestedPassword) ?></strong></p>
                <label>Admin Username
                    <input type="text" name="username" required>
                </label>
                <label>Admin Email
                    <input type="email" name="email" required>
                </label>
                <label>Password
                    <input type="password" name="password" required>
                </label>
                <label>Confirm Password
                    <input type="password" name="confirm_password" required>
                </label>
                <button type="submit">Create Admin</button>
            </form>
        <?php elseif ($step === 3): ?>
            <form method="post">
                <label>SMTP Host
                    <input type="text" name="smtp_host" value="<?= sanitize($smtpState['host'] ?? '') ?>" required>
                </label>
                <label>SMTP Port
                    <input type="number" name="smtp_port" value="<?= sanitize((string)($smtpState['port'] ?? 587)) ?>" required>
                </label>
                <label>Encryption (tls/ssl)
                    <input type="text" name="smtp_encryption" value="<?= sanitize($smtpState['encryption'] ?? '') ?>">
                </label>
                <label>Username
                    <input type="text" name="smtp_username" value="<?= sanitize($smtpState['username'] ?? '') ?>">
                </label>
                <label>Password
                    <input type="password" name="smtp_password" value="<?= sanitize($smtpState['password'] ?? '') ?>">
                </label>
                <label>From Name
                    <input type="text" name="smtp_from_name" value="<?= sanitize($smtpState['from_name'] ?? 'Play for Purpose Ohio') ?>" required>
                </label>
                <label>From Email
                    <input type="email" name="smtp_from_email" value="<?= sanitize($smtpState['from_email'] ?? '') ?>" required>
                </label>
                <label>Test Recipient (optional)
                    <input type="email" name="test_recipient" value="<?= sanitize($_POST['test_recipient'] ?? ($_SESSION['install']['admin_email'] ?? $smtpState['from_email'] ?? '')) ?>">
                </label>
                <div class="buttons">
                    <button type="submit" name="action" value="test">Send Test Email</button>
                    <button type="submit" name="action" value="save">Finish Installation</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>
