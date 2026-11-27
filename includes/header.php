<?php
declare(strict_types=1);

require_once __DIR__ . '/csrf.php';

$pageTitle = isset($pageTitle) && is_string($pageTitle) ? $pageTitle : 'Learning Hub';
$activeNav = isset($activeNav) && is_string($activeNav) ? $activeNav : '';
$signedInUser = current_user();
$isAdminArea = $signedInUser !== null && $signedInUser['role'] === 'admin';
$headerNavGroups = $isAdminArea ? [
    'Training' => [
        ['Categories', '/admin/categories.php', 'categories'],
        ['Lessons', '/admin/lessons.php', 'lessons'],
        ['Learning paths', '/admin/paths.php', 'paths'],
    ],
    'Resources' => [
        ['Universities', '/admin/universities.php', 'universities'],
        ['Fee rows', '/admin/university-fees.php', 'fees'],
        ['Policies', '/admin/policies.php', 'policies'],
        ['Scripts & templates', '/admin/templates.php', 'templates'],
    ],
    'People' => [
        ['Users', '/admin/users.php', 'users'],
        ['Onboarding, contacts & holidays', '/admin/onboarding.php', 'onboarding'],
    ],
    'Reports & backups' => [
        ['Reports', '/admin/reports.php', 'reports'],
        ['Backups', '/admin/backup.php', 'backup'],
    ],
] : [
    'Learning' => [
        ['Search training', '/learner/search.php', 'search'],
        ['University fees', '/learner/university-fees.php', 'fees'],
        ['Scripts & FAQ', '/learner/library.php', 'library'],
    ],
    'Onboarding' => [
        ['Policies', '/learner/policies.php', 'policies'],
        ['Checklist', '/learner/checklist.php', 'checklist'],
        ['Contacts & holidays', '/learner/contacts.php', 'contacts'],
    ],
];
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
        <a class="<?= $activeNav === 'dashboard' ? 'active' : '' ?>" <?= $activeNav === 'dashboard' ? 'aria-current="page"' : '' ?> href="<?= e(app_url($isAdminArea ? '/admin/index.php' : '/learner/dashboard.php')) ?>"><?= $isAdminArea ? 'Overview' : 'Home' ?></a>
        <?php foreach ($headerNavGroups as $headerGroupName => $headerLinks): ?>
            <?php $headerGroupActive = in_array($activeNav, array_column($headerLinks, 2), true); ?>
            <details class="nav-group<?= $headerGroupActive ? ' active' : '' ?>">
                <summary><?= e($headerGroupName) ?></summary>
                <div class="nav-group-links">
                    <?php foreach ($headerLinks as [$headerLabel, $headerPath, $headerKey]): ?>
                        <a class="<?= $activeNav === $headerKey ? 'active' : '' ?>" <?= $activeNav === $headerKey ? 'aria-current="page"' : '' ?> href="<?= e(app_url($headerPath)) ?>"><?= e($headerLabel) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endforeach; ?>
        <span class="nav-user"><?= e((string) $signedInUser['name']) ?></span>
        <form class="logout-form" action="<?= e(app_url('/logout.php')) ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <button type="submit" class="nav-logout">Sign out</button>
        </form>
    </nav>
</header>
<main class="page-content">
