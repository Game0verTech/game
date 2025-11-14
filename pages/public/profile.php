<?php
$username = trim((string)($_GET['user'] ?? ''));
if ($username === '') {
    http_response_code(404);
    $pageTitle = 'Player Not Found';
    require __DIR__ . '/../../templates/header.php';
    ?>
    <div class="card">
        <h2>Player Not Found</h2>
        <p class="muted">We couldn&rsquo;t find the profile you were looking for. Check the link and try again.</p>
    </div>
    <?php
    require __DIR__ . '/../../templates/footer.php';
    return;
}

$profile = get_public_user_profile($username);
if (!$profile) {
    http_response_code(404);
    $pageTitle = 'Player Not Found';
    require __DIR__ . '/../../templates/header.php';
    ?>
    <div class="card">
        <h2>Player Not Found</h2>
        <p class="muted">This player hasn&rsquo;t set up a public profile yet or may not exist.</p>
    </div>
    <?php
    require __DIR__ . '/../../templates/footer.php';
    return;
}

$pageTitle = ($profile['display_name'] ?? null) ? ($profile['display_name'] . ' | Player Profile') : ($profile['username'] . ' | Player Profile');
$displayName = $profile['display_name'] ?? $profile['username'];
$publicAbout = $profile['public_about'] ?? '';
$gamerTags = $profile['gamer_tags'] ?? [];
$socialLinks = $profile['social_links'] ?? [];
$iconUrl = $profile['icon_url'] ?? default_user_icon_url();
$gamerFields = profile_gamer_tag_fields();
$socialFields = profile_social_fields();
$joinedDate = isset($profile['created_at']) && $profile['created_at']
    ? date('F j, Y', strtotime($profile['created_at']))
    : null;

require __DIR__ . '/../../templates/header.php';
?>
<div class="profile-page">
    <div class="card">
        <div class="profile-hero">
            <div class="profile-hero__avatar">
                <img src="<?= sanitize($iconUrl) ?>" alt="<?= sanitize($displayName) ?> profile icon" loading="lazy">
            </div>
            <div class="profile-hero__meta">
                <h2><?= sanitize($displayName) ?></h2>
                <div class="profile-hero__username">@<?= sanitize($profile['username']) ?></div>
                <?php if ($joinedDate): ?>
                    <div class="muted">Member since <?= sanitize($joinedDate) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="card profile-section">
        <h3>About Me</h3>
        <?php if (trim($publicAbout) !== ''): ?>
            <p class="profile-about"><?= nl2br(sanitize($publicAbout), false) ?></p>
        <?php else: ?>
            <p class="profile-section__empty">This player hasn&rsquo;t shared any details yet.</p>
        <?php endif; ?>
    </div>

    <div class="card profile-section">
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
            <p class="profile-section__empty">No gamer tags have been added yet.</p>
        <?php endif; ?>
    </div>

    <div class="card profile-section">
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
            <p class="profile-section__empty">No social links have been shared yet.</p>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../../templates/footer.php';
