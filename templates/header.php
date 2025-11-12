<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Play for Purpose Ohio';
}
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/vendor/jquery-bracket/jquery.bracket.min.css">
    <link rel="stylesheet" href="/assets/vendor/jquery-group/jquery.group.min.css">
    <script src="/assets/js/jquery.min.js"></script>
    <script src="/assets/js/handlebars-lite.js"></script>
    <script src="/assets/vendor/jquery-bracket/jquery.bracket.min.js"></script>
    <script src="/assets/vendor/jquery-group/jquery.group.min.js"></script>
    <script src="/assets/js/app.js" defer></script>
</head>
<body>
<header class="site-header">
    <div class="container">
        <h1 class="logo"><a href="/">Play for Purpose Ohio</a></h1>
        <nav>
            <a href="/">Home</a>
            <a href="/?page=tournaments">Tournaments</a>
            <?php if ($user): ?>
                <a href="/?page=dashboard">Dashboard</a>
                <?php if (user_has_role('admin', 'manager')): ?>
                    <a href="/?page=admin">Admin</a>
                <?php endif; ?>
                <form method="post" action="/api/auth.php" class="logout-form">
                    <input type="hidden" name="action" value="logout">
                    <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                    <button type="submit">Logout (<?= sanitize($user['username']) ?>)</button>
                </form>
            <?php else: ?>
                <a href="/?page=login">Login</a>
                <a href="/?page=register">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
<?php if ($flash = flash('success')): ?>
    <div class="flash success"><?= sanitize($flash) ?></div>
<?php endif; ?>
<?php if ($flash = flash('error')): ?>
    <div class="flash error"><?= sanitize($flash) ?></div>
<?php endif; ?>
