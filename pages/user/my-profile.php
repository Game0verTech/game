<?php
require_login();
$user = current_user();
$pageTitle = 'My Profile';

$gamerFields = profile_gamer_tag_fields();
$socialFields = profile_social_fields();

if (is_post()) {
    require_csrf();
    $about = $_POST['about'] ?? '';
    $gamerInput = isset($_POST['gamer']) && is_array($_POST['gamer']) ? $_POST['gamer'] : [];
    $socialInput = isset($_POST['social']) && is_array($_POST['social']) ? $_POST['social'] : [];

    try {
        update_user_public_profile((int) $user['id'], $about, $gamerInput, $socialInput);
        flash('success', 'Your public profile has been updated.');
    } catch (InvalidArgumentException $e) {
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('[my-profile] ' . $e->getMessage());
        flash('error', 'Unable to update your profile at this time.');
    }

    redirect('/?page=my-profile');
}

$profile = get_user_profile((int) $user['id']);
$publicAbout = $profile['public_about'] ?? '';
$gamerTags = $profile['gamer_tags'] ?? [];
$socialLinks = $profile['social_links'] ?? [];
$iconUrl = $profile['icon_url'] ?? user_icon_url($user);
$displayName = $profile['display_name'] ?? ($user['display_name'] ?? $user['username']);

require __DIR__ . '/../../templates/header.php';
?>
<div class="settings settings--split">
    <div class="card settings-card">
        <div class="settings__header">
            <h2>Public Profile</h2>
            <p class="muted">Share gamer tags, social handles, and a short bio that other players can see.</p>
        </div>
        <form method="post" class="settings-form">
            <input type="hidden" name="_token" value="<?= csrf_token() ?>">
            <label>About Me
                <textarea name="about" rows="5" maxlength="500" placeholder="Tell other players a little about yourself."><?= sanitize($publicAbout) ?></textarea>
            </label>
            <span class="settings-note">Maximum 500 characters. Keep it friendly and helpful.</span>

            <h3>Gamer Tags</h3>
            <div class="settings-form__grid">
                <?php foreach ($gamerFields as $key => $meta): ?>
                    <label><?= sanitize($meta['label']) ?>
                        <input
                            type="text"
                            name="gamer[<?= sanitize($key) ?>]"
                            maxlength="80"
                            value="<?= sanitize($gamerTags[$key] ?? '') ?>"
                        >
                    </label>
                <?php endforeach; ?>
            </div>

            <h3>Social Handles</h3>
            <span class="settings-note">Enter usernames only &mdash; links are generated automatically.</span>
            <div class="settings-form__grid">
                <?php foreach ($socialFields as $key => $meta): ?>
                    <label><?= sanitize($meta['label']) ?>
                        <input
                            type="text"
                            name="social[<?= sanitize($key) ?>]"
                            maxlength="80"
                            value="<?= sanitize($socialLinks[$key] ?? '') ?>"
                        >
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="settings-form__actions">
                <button type="submit">Save Public Profile</button>
            </div>
        </form>
    </div>

    <div class="card settings-card">
        <div class="settings__header">
            <h2>Profile Preview</h2>
            <p class="muted">This is how other players will see your profile.</p>
        </div>
        <div class="profile-hero">
            <div class="profile-hero__avatar">
                <img src="<?= sanitize($iconUrl) ?>" alt="Profile icon" loading="lazy">
            </div>
            <div class="profile-hero__meta">
                <h3><?= sanitize($displayName) ?></h3>
                <div class="profile-hero__username">@<?= sanitize($user['username']) ?></div>
            </div>
        </div>

        <div class="profile-section">
            <h3>About Me</h3>
            <?php if (trim($publicAbout) !== ''): ?>
                <p class="profile-about"><?= nl2br(sanitize($publicAbout), false) ?></p>
            <?php else: ?>
                <p class="profile-section__empty">You haven&rsquo;t shared anything yet.</p>
            <?php endif; ?>
        </div>

        <div class="profile-section">
            <h3>Gamer Tags</h3>
            <?php if ($gamerTags): ?>
                <ul class="profile-section__list">
                    <?php foreach ($gamerTags as $key => $value): ?>
                        <li>
                            <span class="profile-section__label"><?= sanitize($gamerFields[$key]['label'] ?? ucfirst($key)) ?></span>
                            <span class="profile-section__value"><?= sanitize($value) ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="profile-section__empty">Add the platforms you play on.</p>
            <?php endif; ?>
        </div>

        <div class="profile-section">
            <h3>Social Links</h3>
            <?php if ($socialLinks): ?>
                <ul class="profile-section__list">
                    <?php foreach ($socialLinks as $key => $value): ?>
                        <?php
                            $platform = $socialFields[$key]['label'] ?? ucfirst($key);
                            $profileUrl = user_social_profile_url($key, $value);
                        ?>
                        <li>
                            <span class="profile-section__label"><?= sanitize($platform) ?></span>
                            <?php if ($profileUrl): ?>
                                <a class="profile-section__value profile-social-link" href="<?= sanitize($profileUrl) ?>" target="_blank" rel="noopener">
                                    <?= sanitize(ltrim($value, '@')) ?>
                                </a>
                            <?php else: ?>
                                <span class="profile-section__value"><?= sanitize($value) ?></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="profile-section__empty">Link your social accounts so friends can connect.</p>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
