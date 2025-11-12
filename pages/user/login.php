<?php
$pageTitle = 'Login';
require __DIR__ . '/../../templates/header.php';
?>
<div class="card">
    <h2>Login</h2>
    <form method="post" action="/api/auth.php">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
        <label>Username or Email
            <input type="text" name="username" required>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <button type="submit">Login</button>
    </form>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
