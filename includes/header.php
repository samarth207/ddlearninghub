<?php
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Learning Hub';
$activeNav = isset($activeNav) && is_string($activeNav) ? $activeNav : '';
$signedInUser = current_user();
$isAdminArea = $signedInUser !== null && $signedInUser['role'] === 'admin';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2C3E50">
    <title><?= e($pageTitle) ?> | DegreeDrishti Learning Hub</title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>">
    <script src="<?= e(app_url('/assets/js/app.js')) ?>" defer></script>
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= e(app_url('/index.php')) ?>">
        <span class="brand-mark" aria-hidden="true">D</span>
        <span>DegreeDrishti<small>LEARNING HUB</small></span>
    </a>
    <button class="menu-toggle" type="button" aria-expanded="false" aria-controls="site-nav">Menu</button>
    <nav class="site-nav" id="site-nav" aria-label="Main navigation">
        <?php if ($isAdminArea): ?>
            <a class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= e(app_url('/admin/index.php')) ?>">Overview</a>
            <a class="<?= $activeNav === 'categories' ? 'active' : '' ?>" href="<?= e(app_url('/admin/categories.php')) ?>">Categories</a>
            <a class="<?= $activeNav === 'lessons' ? 'active' : '' ?>" href="<?= e(app_url('/admin/lessons.php')) ?>">Lessons</a>
            <a class="<?= $activeNav === 'users' ? 'active' : '' ?>" href="<?= e(app_url('/admin/users.php')) ?>">Users</a>
            <a class="<?= $activeNav === 'universities' ? 'active' : '' ?>" href="<?= e(app_url('/admin/universities.php')) ?>">Universities</a>
            <a class="<?= $activeNav === 'fees' ? 'active' : '' ?>" href="<?= e(app_url('/admin/university-fees.php')) ?>">Fee rows</a>
            <a class="<?= $activeNav === 'reports' ? 'active' : '' ?>" href="<?= e(app_url('/admin/reports.php')) ?>">Reports</a>
        <?php else: ?>
            <a class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>" href="<?= e(app_url('/learner/dashboard.php')) ?>">Home</a>
            <a class="<?= $activeNav === 'search' ? 'active' : '' ?>" href="<?= e(app_url('/learner/search.php')) ?>">Search training</a>
            <a class="<?= $activeNav === 'fees' ? 'active' : '' ?>" href="<?= e(app_url('/learner/university-fees.php')) ?>">University fees</a>
        <?php endif; ?>
        <span class="nav-user"><?= e((string) $signedInUser['name']) ?></span>
        <form class="logout-form" action="<?= e(app_url('/logout.php')) ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="nav-logout">Sign out</button>
        </form>
    </nav>
</header>
<main class="page-content">
