<?php
$pageTitle = 'Verify Email';
require __DIR__ . '/../../templates/header.php';

$token = $_GET['token'] ?? null;
$status = 'invalid';
if ($token) {
    $status = mark_user_verified($token);
}
?>
<div class="card">
    <h2>Email Verification</h2>
    <?php if ($status === 'verified'): ?>
        <p>Your email has been verified. You may now <a href="/?page=login">login</a>.</p>
    <?php elseif ($status === 'already'): ?>
        <p>Your email was already verified. You can go ahead and <a href="/?page=login">login</a>.</p>
    <?php else: ?>
        <p>We couldn't verify this link. Please try <a href="/?page=login">logging in</a>. If you still can't access your account, contact the event staff and they can manually activate your account.</p>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
