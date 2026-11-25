<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('employee');

$query = trim((string) ($_POST['q'] ?? ''));
$lessons = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
    } catch (RuntimeException $exception) {
        http_response_code(400);
        exit('Your form session expired. Reload the page and try again.');
    }
}
if ($query !== '') {
    $statement = db()->prepare(
        'SELECT l.id, l.title, l.description, l.duration_min, c.name AS category_name
         FROM lessons l
         INNER JOIN categories c ON c.id = l.category_id
         WHERE l.is_published = 1
           AND (l.title LIKE :title_query OR l.description LIKE :description_query OR c.name LIKE :category_query)
         ORDER BY l.sort_order, l.created_at DESC
         LIMIT 50'
    );
    $like = '%' . $query . '%';
    $statement->execute(['title_query' => $like, 'description_query' => $like, 'category_query' => $like]);
    $lessons = $statement->fetchAll();
}
$pageTitle = 'Search training';
$activeNav = 'search';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">FIND A RESOURCE</p><h1>Search training</h1><p class="muted">Look up a lesson by title, topic, or keyword.</p></div></section>
<form class="search-form" method="post" action="<?= e(app_url('/learner/search.php')) ?>">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <label class="visually-hidden" for="lesson-search">Search training</label>
    <input id="lesson-search" type="search" name="q" value="<?= e($query) ?>" maxlength="100" placeholder="Try “leave”, “POSH”, or “fees”">
    <button class="button button-primary" type="submit">Search</button>
</form>
<?php if ($query !== ''): ?>
    <?php if ($lessons === []): ?>
        <p class="empty-state">No published lessons matched “<?= e($query) ?>”.</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($lessons as $lesson): ?>
                <article class="lesson-card"><div class="lesson-meta"><?= e($lesson['category_name']) ?> · <?= (int) $lesson['duration_min'] ?> min</div><h3><?= e($lesson['title']) ?></h3><p><?= e((string) $lesson['description']) ?></p><a href="<?= e(app_url('/learner/lesson.php?id=' . (int) $lesson['id'])) ?>">Open lesson →</a></article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
