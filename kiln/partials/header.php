<?php
declare(strict_types=1);
$pageTitle = $pageTitle ?? APP_NAME;
$active    = $active ?? '';
$hideNav   = $hideNav ?? false;
$me        = function_exists('current_user') ? current_user() : null;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#17110d">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=VT323&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(asset_url('assets/style.css')) ?>">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><text y='26' font-size='26'>&#128293;</text></svg>">
</head>
<body>
<header class="topbar">
    <a class="brand" href="index.php">
        <span class="brand-mark"></span>
        <?= e(APP_NAME) ?>
    </a>
    <span class="save-status" id="save-status" hidden aria-live="polite"></span>
    <?php if (!$hideNav): ?>
    <nav>
        <a href="index.php" class="<?= $active === 'plan' ? 'on' : '' ?>">Plan</a>
        <a href="firings.php" class="<?= $active === 'log' ? 'on' : '' ?>">Firings</a>
        <a href="users.php" class="<?= $active === 'users' ? 'on' : '' ?>">Users</a>
        <a href="settings.php" class="nav-icon <?= $active === 'settings' ? 'on' : '' ?>" title="Settings" aria-label="Settings">
            <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
                <circle cx="12" cy="12" r="3.1"></circle>
                <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.03 1.56V21a2 2 0 1 1-4 0v-.09A1.7 1.7 0 0 0 8.9 19.3a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.7 1.7 0 0 0 .34-1.87 1.7 1.7 0 0 0-1.56-1.03H3a2 2 0 1 1 0-4h.09A1.7 1.7 0 0 0 4.7 8.9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.7 1.7 0 0 0 1.87.34H9.1a1.7 1.7 0 0 0 1.03-1.56V3a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1.03 1.56 1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.7 1.7 0 0 0-.34 1.87V9.1a1.7 1.7 0 0 0 1.56 1.03H21a2 2 0 1 1 0 4h-.09a1.7 1.7 0 0 0-1.56 1.03z"></path>
            </svg>
        </a>
        <?php if ($me): ?>
            <a href="profile.php" class="nav-icon nav-me <?= $active === 'profile' ? 'on' : '' ?>"
               title="<?= e($me['name'] . ' — ' . $me['email']) ?>" aria-label="Your profile">
                <?php if (!empty($me['picture'])): ?>
                    <img class="avatar" src="<?= e($me['picture']) ?>" alt="" referrerpolicy="no-referrer">
                <?php else: ?>
                    <span class="avatar avatar--letter"><?= e(mb_strtoupper(mb_substr((string)$me['name'], 0, 1))) ?></span>
                <?php endif; ?>
            </a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
</header>
<main>
