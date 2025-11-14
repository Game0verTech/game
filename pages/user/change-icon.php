<?php
require_login();
$user = current_user();
$pageTitle = 'Change User Icon';

if (is_post()) {
    require_csrf();
    $action = $_POST['action'] ?? 'upload';

    try {
        if ($action === 'remove') {
            remove_user_icon((int)$user['id']);
            flash('success', 'Your profile icon has been reset to the default image.');
        } else {
            if (!isset($_FILES['icon'])) {
                throw new InvalidArgumentException('Select an image to upload.');
            }
            update_user_icon((int)$user['id'], $_FILES['icon']);
            flash('success', 'Your profile icon has been updated.');
        }
        $fresh = get_user_by_id((int)$user['id']);
        if ($fresh) {
            store_session_user($fresh);
        }
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[change-icon] ' . $e->getMessage());
        flash('error', 'Unable to update your icon. Please try again later.');
    }

    redirect('/?page=change-icon');
}

$profile = get_user_profile((int)$user['id']);
$currentIcon = $profile['icon_url'] ?? user_icon_url($user);
require __DIR__ . '/../../templates/header.php';
?>
<div class="settings">
    <div class="card settings-card">
        <div class="settings__header">
            <h2>Profile Icon</h2>
            <p class="muted">Upload a square image (PNG, JPG, GIF, or WebP) up to 2MB. Images are cropped to 256&times;256 pixels.</p>
        </div>
        <div class="icon-preview">
            <img src="<?= sanitize($currentIcon) ?>" alt="Current profile icon" loading="lazy">
        </div>
        <form method="post" enctype="multipart/form-data" class="settings-form">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="upload">
            <label>Upload New Icon
                <input type="file" name="icon" accept="image/png,image/jpeg,image/gif,image/webp" required>
            </label>
            <div class="settings-form__actions">
                <button type="submit">Upload Icon</button>
            </div>
        </form>
        <form method="post">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="remove">
            <div class="settings-form__actions">
                <button type="submit" class="btn subtle">Use Default Icon</button>
            </div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
