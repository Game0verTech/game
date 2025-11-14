<?php
require_login();
$user = current_user();
$pageTitle = 'Change Password';

if (is_post()) {
    require_csrf();
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        flash('error', 'All password fields are required.');
        redirect('/?page=change-password');
    }

    if ($newPassword !== $confirmPassword) {
        flash('error', 'New passwords do not match.');
        redirect('/?page=change-password');
    }

    if (strlen($newPassword) < 10) {
        flash('error', 'Choose a password with at least 10 characters.');
        redirect('/?page=change-password');
    }

    $fullUser = get_user_by_id((int)$user['id']);
    if (!$fullUser || !password_verify($currentPassword, $fullUser['password_hash'])) {
        flash('error', 'Your current password is incorrect.');
        redirect('/?page=change-password');
    }

    try {
        update_user_password((int)$user['id'], $newPassword);
        flash('success', 'Your password has been updated.');
    } catch (Throwable $e) {
        error_log('[change-password] ' . $e->getMessage());
        flash('error', 'Unable to change your password. Please try again later.');
    }

    redirect('/?page=change-password');
}

require __DIR__ . '/../../templates/header.php';
?>
<div class="settings">
    <div class="card settings-card">
        <div class="settings__header">
            <h2>Change Password</h2>
            <p class="muted">Create a strong password to keep your account secure. Passwords must be at least 10 characters long.</p>
        </div>
        <form method="post" class="settings-form">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <label>Current Password
                <input type="password" name="current_password" required autocomplete="current-password">
            </label>
            <div class="settings-form__grid">
                <label>New Password
                    <input type="password" name="new_password" required autocomplete="new-password">
                </label>
                <label>Confirm New Password
                    <input type="password" name="confirm_password" required autocomplete="new-password">
                </label>
            </div>
            <span class="settings-note">Use a mix of letters, numbers, and symbols for the best results.</span>
            <div class="settings-form__actions">
                <button type="submit">Update Password</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
