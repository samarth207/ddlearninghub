<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/csrf.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $submitted = $_POST['no'] ?? '';
        if (!is_string($submitted) || strlen($submitted) > 32) {
            throw new InvalidArgumentException('Enter a valid certificate number.');
        }
        redirect_to('/verify.php?no=' . rawurlencode(trim($submitted)));
    } catch (RuntimeException | InvalidArgumentException $exception) {
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$number = $_GET['no'] ?? '';
$valid = false;
$searched = is_string($number) && $number !== '';
if ($searched && preg_match('/\ADD-[0-9]{4}-[A-F0-9]{24}\z/', $number) === 1) {
    $query = db()->prepare('SELECT id FROM certificates WHERE certificate_no = ?');
    $query->execute([$number]);
    $valid = $query->fetchColumn() !== false;
}
header('Cache-Control: no-store');
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Verify certificate | DegreeDrishti Learning Hub</title><link rel="stylesheet" href="<?= e(app_url('/assets/css/theme.css')) ?>"><link rel="stylesheet" href="<?= e(app_url('/assets/css/app.css')) ?>"></head>
<body><header class="site-header"><a class="brand" href="<?= e(app_url('/index.php')) ?>"><span class="brand-mark" aria-hidden="true">D</span><span>DegreeDrishti<small>LEARNING HUB</small></span></a></header>
<main class="page-content"><section class="panel"><h1>Verify certificate</h1><p>Enter a certificate number to check whether it was issued by DegreeDrishti Learning Hub.</p>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<form method="post" class="stack-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Certificate number<input name="no" maxlength="32" required value="<?= e(is_string($number) ? $number : '') ?>"></label><button class="button button-primary">Verify</button></form>
<?php if ($searched): ?><p class="notice <?= $valid ? 'notice-success' : 'notice-error' ?>" role="status"><?= $valid ? 'This certificate number is valid.' : 'No valid certificate was found for this number.' ?></p><?php endif; ?>
</section></main></body></html>
