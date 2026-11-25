<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
http_response_code(404);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2C3E50">
    <title>Page not found | DegreeDrishti Learning Hub</title>
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/theme.css')) ?>">
    <link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>">
</head>
<body>
<header class="site-header">
    <a class="brand" href="<?= e(app_url('/index.php')) ?>">
        <span class="brand-mark" aria-hidden="true">D</span>
        <span>DegreeDrishti<small>LEARNING HUB</small></span>
    </a>
</header>
<main class="page-content">
    <section class="panel error-page" aria-labelledby="error-title">
        <p class="eyebrow">404 · NOT FOUND</p>
        <h1 id="error-title">We couldn’t find that page</h1>
        <p class="muted">The resource may have moved or may no longer be available.</p>
        <a class="button button-primary" href="<?= e(app_url('/index.php')) ?>">Return to the hub</a>
    </section>
</main>
<footer class="site-footer"><span>DegreeDrishti Learning Hub</span><span>Internal learning resources</span></footer>
</body>
</html>
