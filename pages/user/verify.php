<?php
$pageTitle = 'Verify Email';
require __DIR__ . '/../../templates/header.php';

$token = $_GET['token'] ?? null;
$success = false;
if ($token) {
    if (mark_user_verified($token)) {
        $success = true;
    }
}
?>
<div class="card">
    <h2>Email Verification</h2>
    <?php if ($success): ?>
        <p>Your email has been verified. You may now <a href="/?page=login">login</a>.</p>
    <?php else: ?>
        <p>Invalid or expired verification link.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
