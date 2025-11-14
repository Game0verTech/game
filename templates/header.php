<?php
$pageTitle = $pageTitle ?? 'Play for Purpose Ohio';
$user = current_user();
$extraStylesheets = $extraStylesheets ?? [];
$headScripts = $headScripts ?? [];
$deferScripts = $deferScripts ?? [];
$bodyClass = trim($bodyClass ?? '');
$mainClass = trim($mainClass ?? 'container');
$navCurrentPage = $_GET['page'] ?? ($user ? 'dashboard' : 'home');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?></title>
    <link rel="stylesheet" href="/assets/css/site.css">
    <link rel="stylesheet" href="/assets/vendor/jquery-bracket/jquery.bracket.min.css">
    <?php foreach ($extraStylesheets as $sheet): ?>
        <link rel="stylesheet" href="<?= sanitize($sheet) ?>">
    <?php endforeach; ?>
    <script src="/assets/js/jquery.min.js"></script>
    <script src="/assets/js/handlebars-lite.js"></script>
    <script src="/assets/vendor/underscore/underscore-min.js"></script>
    <script src="/assets/js/underscore-bridge.js"></script>
    <script src="/assets/vendor/jquery-bracket/jquery.bracket.min.js"></script>
    <?php foreach ($headScripts as $script): ?>
        <script src="<?= sanitize($script) ?>"></script>
    <?php endforeach; ?>
    <script src="/assets/js/app.js" defer></script>
    <?php foreach ($deferScripts as $script): ?>
        <script src="<?= sanitize($script) ?>" defer></script>
    <?php endforeach; ?>
</head>
<body<?= $bodyClass !== '' ? ' class="' . sanitize($bodyClass) . '"' : '' ?>>
<header class="site-header">
    <div class="container">
        <h1 class="logo"><a href="/">Play for Purpose Ohio</a></h1>
        <nav>
            <?php if ($user): ?>
                <a href="/?page=dashboard"<?= $navCurrentPage === 'dashboard' ? ' aria-current="page"' : '' ?>>Dashboard</a>
                <a href="/?page=calendar"<?= $navCurrentPage === 'calendar' ? ' aria-current="page"' : '' ?>>Calendar</a>
                <?php if (user_has_role('admin', 'manager')): ?>
                    <a href="/?page=admin"<?= $navCurrentPage === 'admin' ? ' aria-current="page"' : '' ?>>Admin</a>
                <?php endif; ?>
                <?php if (user_has_role('admin')): ?>
                    <a href="/?page=store"<?= $navCurrentPage === 'store' ? ' aria-current="page"' : '' ?>>Store</a>
                <?php endif; ?>
                <?php
                    $menuLabel = $user['display_name'] ?? $user['username'];
                    $menuIcon = user_icon_url($user);
                ?>
                <div class="user-menu js-user-menu">
                    <button class="user-menu__trigger" type="button" aria-haspopup="true" aria-expanded="false">
                        <span class="user-menu__avatar" aria-hidden="true">
                            <img src="<?= sanitize($menuIcon) ?>" alt="" loading="lazy">
                        </span>
                        <span class="user-menu__name"><?= sanitize($menuLabel) ?></span>
                        <span class="user-menu__caret" aria-hidden="true">▾</span>
                    </button>
                    <div class="user-menu__dropdown" role="menu">
                        <a class="user-menu__item" href="/?page=my-profile" role="menuitem">My Profile</a>
                        <a class="user-menu__item" href="/?page=account" role="menuitem">Edit Account Information</a>
                        <a class="user-menu__item" href="/?page=change-password" role="menuitem">Change Password</a>
                        <a class="user-menu__item" href="/?page=change-icon" role="menuitem">Change User Icon</a>
                        <form method="post" action="/api/auth.php" class="user-menu__logout" role="none">
                            <input type="hidden" name="action" value="logout">
                            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
                            <button type="submit" class="user-menu__item" role="menuitem">Logout</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <a href="/?page=home"<?= $navCurrentPage === 'home' ? ' aria-current="page"' : '' ?>>Dashboard</a>
                <a href="/?page=login"<?= $navCurrentPage === 'login' ? ' aria-current="page"' : '' ?>>Login</a>
                <a href="/?page=register"<?= $navCurrentPage === 'register' ? ' aria-current="page"' : '' ?>>Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="<?= sanitize($mainClass) ?>">
<?php if ($flash = flash('success')): ?>
    <div class="flash success"><?= sanitize($flash) ?></div>
<?php endif; ?>
<?php if ($flash = flash('error')): ?>
    <div class="flash error"><?= sanitize($flash) ?></div>
<?php endif; ?>
