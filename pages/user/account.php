<?php
require_login();
$user = current_user();
$pageTitle = 'Account Settings';

if (is_post()) {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $displayName = $_POST['display_name'] ?? '';
    $currentPassword = $_POST['current_password'] ?? '';

    if ($currentPassword === '') {
        flash('error', 'Enter your current password to confirm account changes.');
        redirect('/?page=account');
    }

    try {
        update_user_account_info((int)$user['id'], $email, $displayName, $currentPassword);
        $fresh = get_user_by_id((int)$user['id']);
        if ($fresh) {
            store_session_user($fresh);
        }
        flash('success', 'Your account information has been updated.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[account] Update failed: ' . $e->getMessage());
        flash('error', 'Unable to update your account right now. Please try again later.');
    }

    redirect('/?page=account');
}

$profile = get_user_profile((int)$user['id']);
require __DIR__ . '/../../templates/header.php';
?>
<div class="settings">
    <div class="card settings-card">
        <div class="settings__header">
            <h2>Account Information</h2>
            <p class="muted">Update the name and email associated with your account. Usernames cannot be changed.</p>
        </div>
        <p class="settings-note">Username: <strong><?= user_profile_link($user['username']) ?></strong></p>
        <form method="post" class="settings-form">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <div class="settings-form__grid">
                <label>Display Name
                    <input
                        type="text"
                        name="display_name"
                        maxlength="120"
                        value="<?= sanitize($profile['display_name'] ?? '') ?>"
                        placeholder="How other players will see you"
                    >
                    <span class="settings-note">Optional. Used on your public profile and tournament rosters.</span>
                </label>
                <label>Email Address
                    <input
                        type="email"
                        name="email"
                        required
                        value="<?= sanitize($user['email']) ?>"
                    >
                </label>
            </div>
            <label>Current Password
                <input type="password" name="current_password" required>
            </label>
            <span class="settings-note">For security, you must confirm your current password before saving changes.</span>
            <div class="settings-form__actions">
                <button type="submit">Save Changes</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
