<?php
$pageTitle = 'Register';
require __DIR__ . '/../../templates/header.php';
?>
<div class="card">
    <h2>Create an Account</h2>
    <form method="post" action="/api/auth.php">
        <input type="hidden" name="action" value="register">
        <input type="hidden" name="_token" value="<?= csrf_token() ?>">
        <label>Username
            <input type="text" name="username" required>
        </label>
        <label>Email
            <input type="email" name="email" required>
        </label>
        <label>Password
            <input type="password" name="password" required>
        </label>
        <label>Confirm Password
            <input type="password" name="password_confirmation" required>
        </label>
        <p>Passwords must be at least 10 characters.</p>
        <button type="submit">Register</button>
    </form>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
