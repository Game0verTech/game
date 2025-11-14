<?php
require_login();
$user = current_user();
$pageTitle = 'Change User Icon';

if (is_post()) {
    require_csrf();
    $action = $_POST['action'] ?? 'upload';
    $ajax = is_ajax_request();

    try {
        if ($action === 'remove') {
            remove_user_icon((int)$user['id']);
            $message = 'Your profile icon has been reset to the default image.';
            $iconUrl = default_user_icon_url();
        } else {
            if (!isset($_FILES['icon'])) {
                throw new InvalidArgumentException('Select an image to upload.');
            }
            $iconUrl = update_user_icon((int)$user['id'], $_FILES['icon'], $_POST);
            $message = 'Your profile icon has been updated.';
        }

        $fresh = get_user_by_id((int)$user['id']);
        if ($fresh) {
            store_session_user($fresh);
        }

        if ($ajax) {
            json_response([
                'status' => 'success',
                'message' => $message,
                'iconUrl' => $iconUrl,
            ]);
        }

        flash('success', $message);
    } catch (InvalidArgumentException $e) {
        if ($ajax) {
            json_response([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 422);
        }
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[change-icon] ' . $e->getMessage());
        if ($ajax) {
            json_response([
                'status' => 'error',
                'message' => 'Unable to update your icon. Please try again later.',
            ], 500);
        }
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
            <img class="js-current-icon" src="<?= sanitize($currentIcon) ?>" alt="Current profile icon" loading="lazy">
        </div>
        <form method="post" enctype="multipart/form-data" class="settings-form js-icon-editor-form" novalidate>
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <input type="hidden" name="action" value="upload">
            <div class="icon-editor js-icon-editor" data-viewport-size="256">
                <div class="icon-editor__upload">
                    <label class="icon-editor__file-label">Choose Image
                        <input class="js-icon-editor-input" type="file" name="icon" accept="image/png,image/jpeg,image/gif,image/webp" required>
                    </label>
                    <p class="muted">Upload a square image (PNG, JPG, GIF, or WebP) up to 2MB. Drag inside the square to adjust the crop.</p>
                </div>
                <div class="icon-editor__workspace" hidden>
                    <div class="icon-editor__stage">
                        <div class="icon-editor__viewport js-icon-editor-viewport" data-size="256">
                            <img class="icon-editor__image js-icon-editor-image" src="" alt="Icon workspace" draggable="false">
                        </div>
                        <div class="icon-editor__preview">
                            <span class="icon-editor__preview-label">Live Preview</span>
                            <img class="icon-editor__preview-image js-icon-editor-preview" src="<?= sanitize($currentIcon) ?>" alt="Icon preview" loading="lazy">
                        </div>
                    </div>
                    <div class="icon-editor__controls">
                        <label class="icon-editor__control-label">Zoom
                            <input class="icon-editor__zoom js-icon-editor-zoom" type="range" min="1" max="4" step="0.01" value="1">
                        </label>
                        <button type="button" class="btn subtle js-icon-editor-reset">Reset</button>
                    </div>
                </div>
                <div class="icon-editor__progress js-upload-progress" hidden>
                    <div class="icon-editor__progress-bar js-upload-progress-bar"></div>
                </div>
                <div class="icon-editor__feedback js-icon-editor-feedback" role="status" aria-live="polite"></div>
                <input type="hidden" name="crop_x" value="">
                <input type="hidden" name="crop_y" value="">
                <input type="hidden" name="crop_size" value="">
                <input type="hidden" name="image_width" value="">
                <input type="hidden" name="image_height" value="">
                <div class="settings-form__actions">
                    <button type="submit" class="btn js-icon-editor-save" data-requires-image="true">Save Icon</button>
                </div>
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
