<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('employee');
$value = $_GET['id'] ?? '';
$id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
if ($id === false || $id < 1) { render_error_page(404); }
$query = db()->prepare('SELECT p.name, p.description FROM paths p JOIN user_paths up ON up.path_id = p.id WHERE p.id = ? AND up.user_id = ?');
$query->execute([$id, $user['id']]);
$path = $query->fetch();
if ($path === false) { render_error_page(404); }
$query = db()->prepare(
    "SELECT l.id, l.title, l.duration_min, pi.suggested_day, pr.status
     FROM path_items pi JOIN lessons l ON l.id = pi.lesson_id AND l.is_published = 1
     LEFT JOIN progress pr ON pr.lesson_id = l.id AND pr.user_id = ?
     WHERE pi.path_id = ? ORDER BY pi.sort_order, pi.id"
);
$query->execute([$user['id'], $id]);
$items = $query->fetchAll();
$pageTitle = (string) $path['name'];
$activeNav = 'paths';
require __DIR__ . '/../includes/header.php';
?>
<p class="breadcrumb-link"><a href="<?= e(app_url('/learner/dashboard.php')) ?>">← Dashboard</a></p>
<section class="page-heading"><div><p class="eyebrow">YOUR LEARNING PATH</p><h1><?= e((string) $path['name']) ?></h1><p class="muted"><?= e((string) $path['description']) ?></p></div></section>
<?php if ($items === []): ?><p class="empty-state">There are no published lessons in this path yet.</p><?php else: ?>
<div class="card-grid"><?php foreach ($items as $index => $item): ?><article class="lesson-card"><div class="lesson-meta"><?= $index + 1 ?> · <?= (int) $item['duration_min'] ?> min<?= $item['suggested_day'] === null ? '' : ' · Suggested day ' . (int) $item['suggested_day'] ?></div><h2><?= e((string) $item['title']) ?></h2><div class="mandatory-card-footer"><span class="training-badge <?= $item['status'] === 'completed' ? 'badge-completed' : 'badge-pending' ?>"><?= $item['status'] === 'completed' ? '✓ Completed' : 'Pending' ?></span><a href="<?= e(app_url('/learner/lesson.php?id=' . (int) $item['id'])) ?>">Open lesson →</a></div></article><?php endforeach; ?></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
