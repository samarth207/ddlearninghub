<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('employee');
$value = $_GET['id'] ?? '';
$id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
if ($id === false || $id < 1) {
    render_error_page(404);
}
$query = db()->prepare('SELECT certificate_no, issued_at, employee_name, lesson_title FROM certificates WHERE id = ? AND user_id = ?');
$query->execute([$id, $user['id']]);
$certificate = $query->fetch();
if ($certificate === false) {
    render_error_page(404);
}
header('Cache-Control: private, no-store');
$issuedAt = new DateTimeImmutable((string) $certificate['issued_at']);
$host = $_SERVER['HTTP_HOST'] ?? '';
$verifyPath = app_url('/verify.php?no=' . rawurlencode((string) $certificate['certificate_no']));
$verifyUrl = is_string($host) && preg_match('/\A[a-z0-9.-]+(?::[0-9]{1,5})?\z/i', $host) === 1
    ? (SESSION_COOKIE_SECURE ? 'https://' : 'http://') . $host . $verifyPath
    : $verifyPath;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Certificate | DegreeDrishti Learning Hub</title>
<link rel="stylesheet" href="<?= e(app_url('/assets/css/theme.css')) ?>">
<link rel="stylesheet" href="<?= e(app_url('/assets/css/certificate.css')) ?>">
</head><body>
<div class="certificate-actions"><a href="<?= e(app_url('/learner/dashboard.php')) ?>">Return to dashboard</a> <button type="button" onclick="window.print()">Print / save as PDF</button></div>
<main class="certificate">
    <header class="certificate-brand"><img src="<?= e(app_url('/assets/img/logo-icon.png')) ?>" alt=""><strong>DegreeDrishti</strong></header>
    <p class="certificate-eyebrow">DEGREEDRISHTI LEARNING HUB</p>
    <h1>Certificate of Completion</h1>
    <p>This certificate is presented to</p>
    <h2><?= e((string) $certificate['employee_name']) ?></h2>
    <p>for successfully completing</p>
    <h3><?= e((string) $certificate['lesson_title']) ?></h3>
    <div class="certificate-details"><span>Issued <?= e($issuedAt->format('d M Y')) ?></span><span>Certificate no. <?= e((string) $certificate['certificate_no']) ?></span></div>
    <p class="certificate-verify">Verify at <a href="<?= e($verifyUrl) ?>"><?= e($verifyUrl) ?></a></p>
</main></body></html>
