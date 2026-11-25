<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_role('employee');

$slugValue = $_GET['slug'] ?? '';
$slug = is_string($slugValue) ? trim($slugValue) : '';
$categoryQuery = db()->prepare(
    'SELECT id, name, slug, description
     FROM categories
     WHERE slug = :slug
     LIMIT 1'
);
$categoryQuery->execute(['slug' => $slug]);
$category = $categoryQuery->fetch();
if ($category === false) {
    render_error_page(404);
}

$lessons = [];
if ($category !== false) {
    $lessonQuery = db()->prepare(
        'SELECT l.id, l.title, l.description, l.duration_min,
                p.status AS progress_status
         FROM lessons l
         LEFT JOIN progress p ON p.lesson_id = l.id AND p.user_id = :user_id
         WHERE l.category_id = :category_id AND l.is_published = 1
         ORDER BY l.sort_order, l.created_at DESC, l.id DESC'
    );
    $lessonQuery->execute([
        'user_id' => $user['id'],
        'category_id' => $category['id'],
    ]);
    $lessons = $lessonQuery->fetchAll();
}

$pageTitle = $category === false ? 'Topic not found' : $category['name'];
$activeNav = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<?php if ($category === false): ?>
    <section class="page-heading">
        <div><p class="eyebrow">TRAINING TOPIC</p><h1>Topic not found</h1><p class="muted">This topic may have moved or is not available.</p></div>
    </section>
    <a class="button button-primary" href="<?= e(app_url('/learner/dashboard.php')) ?>">Back to learning hub</a>
<?php else: ?>
    <p class="breadcrumb-link"><a href="<?= e(app_url('/learner/dashboard.php')) ?>">Learning hub</a> <span aria-hidden="true">/</span> <?= e($category['name']) ?></p>
    <section class="page-heading">
        <div><p class="eyebrow">TRAINING TOPIC</p><h1><?= e($category['name']) ?></h1><p class="muted"><?= e((string) $category['description']) ?></p></div>
        <span class="topic-count"><?= count($lessons) ?> <?= count($lessons) === 1 ? 'lesson' : 'lessons' ?></span>
    </section>

    <?php if ($lessons === []): ?>
        <p class="empty-state">There are no published lessons in this topic yet. Check back soon.</p>
    <?php else: ?>
        <div class="lesson-list">
            <?php foreach ($lessons as $lesson): ?>
                <?php $isCompleted = $lesson['progress_status'] === 'completed'; ?>
                <article class="lesson-row">
                    <span class="lesson-status <?= $isCompleted ? 'lesson-status-completed' : '' ?>" aria-label="<?= $isCompleted ? 'Completed' : 'Not completed' ?>">
                        <?= $isCompleted ? '✓' : '○' ?>
                    </span>
                    <div class="lesson-row-copy">
                        <h2><?= e($lesson['title']) ?></h2>
                        <p><?= e((string) $lesson['description']) ?></p>
                    </div>
                    <span class="lesson-duration"><?= (int) $lesson['duration_min'] ?> min</span>
                    <a class="button button-primary lesson-open" href="<?= e(app_url('/learner/lesson.php?id=' . (int) $lesson['id'])) ?>">
                        <?= $isCompleted ? 'Review' : 'Open lesson' ?>
                    </a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
