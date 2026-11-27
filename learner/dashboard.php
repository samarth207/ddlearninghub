<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$user = require_role('employee');
$pageTitle = 'Learning dashboard';
$activeNav = 'dashboard';

$categoryQuery = db()->query(
    'SELECT c.id, c.name, c.slug, c.description, c.icon, COUNT(l.id) AS lesson_count
     FROM categories c
     LEFT JOIN lessons l ON l.category_id = c.id AND l.is_published = 1
     GROUP BY c.id, c.name, c.slug, c.description, c.icon, c.sort_order
     ORDER BY c.sort_order, c.name'
);
$categories = $categoryQuery->fetchAll();

$mandatoryQuery = db()->prepare(
    'SELECT l.id, l.title, l.duration_min, c.name AS category_name,
            c.slug AS category_slug, p.status AS progress_status,
            DATEDIFF(DATE_ADD(u.joining_date, INTERVAL l.due_in_days DAY), CURRENT_DATE) AS days_remaining
     FROM lessons l
     INNER JOIN categories c ON c.id = l.category_id
     INNER JOIN users u ON u.id = :user_id
     LEFT JOIN progress p ON p.lesson_id = l.id AND p.user_id = u.id
     WHERE l.is_published = 1 AND l.is_mandatory = 1
     ORDER BY CASE WHEN (p.status IS NULL OR p.status <> \'completed\')
                       AND DATE_ADD(u.joining_date, INTERVAL l.due_in_days DAY) < CURRENT_DATE
                   THEN 0 ELSE 1 END,
              CASE WHEN c.slug = \'posh-training\' THEN 0 ELSE 1 END,
              c.sort_order, l.sort_order, l.created_at DESC'
);
$mandatoryQuery->execute(['user_id' => $user['id']]);
$mandatoryLessons = $mandatoryQuery->fetchAll();

$continueQuery = db()->prepare(
    "SELECT l.id, l.title, l.description, l.duration_min,
            c.name AS category_name
     FROM progress p
     INNER JOIN lessons l ON l.id = p.lesson_id
     INNER JOIN categories c ON c.id = l.category_id
     WHERE p.user_id = :user_id AND p.status = 'started' AND l.is_published = 1
     ORDER BY l.sort_order, l.created_at DESC"
);
$continueQuery->execute(['user_id' => $user['id']]);
$continueLessons = $continueQuery->fetchAll();

$progressQuery = db()->prepare(
    'SELECT COUNT(l.id) AS total_lessons,
            COALESCE(SUM(CASE WHEN p.status = \'completed\' THEN 1 ELSE 0 END), 0) AS completed_lessons
     FROM lessons l
     LEFT JOIN progress p ON p.lesson_id = l.id AND p.user_id = :user_id
     WHERE l.is_published = 1'
);
$progressQuery->execute(['user_id' => $user['id']]);
$progress = $progressQuery->fetch();
$totalLessons = (int) $progress['total_lessons'];
$completedLessons = (int) $progress['completed_lessons'];
$progressPercent = $totalLessons > 0 ? (int) round(($completedLessons / $totalLessons) * 100) : 0;
$pendingPolicyQuery = db()->prepare(
    'SELECT COUNT(*) FROM policies p
     WHERE p.is_active = 1 AND NOT EXISTS
         (SELECT 1 FROM policy_acknowledgements a WHERE a.policy_id = p.id AND a.user_id = :user_id AND a.version_acknowledged = p.version)'
);
$pendingPolicyQuery->execute(['user_id' => $user['id']]);
$pendingPolicies = (int) $pendingPolicyQuery->fetchColumn();
$pathQuery = db()->prepare(
    "SELECT p.id AS path_id, p.name, l.id AS lesson_id, pr.status
     FROM user_paths up JOIN paths p ON p.id = up.path_id
     LEFT JOIN path_items pi ON pi.path_id = p.id
     LEFT JOIN lessons l ON l.id = pi.lesson_id AND l.is_published = 1
     LEFT JOIN progress pr ON pr.lesson_id = l.id AND pr.user_id = up.user_id
     WHERE up.user_id = ? ORDER BY up.assigned_at, p.id, pi.sort_order, pi.id"
);
$pathQuery->execute([$user['id']]);
$learningPaths = [];
foreach ($pathQuery->fetchAll() as $row) {
    $pathId = (int) $row['path_id'];
    if (!isset($learningPaths[$pathId])) {
        $learningPaths[$pathId] = ['id' => $pathId, 'name' => $row['name'], 'total' => 0, 'completed' => 0, 'next' => null];
    }
    if ($row['lesson_id'] !== null) {
        $learningPaths[$pathId]['total']++;
        if ($row['status'] === 'completed') {
            $learningPaths[$pathId]['completed']++;
        } elseif ($learningPaths[$pathId]['next'] === null) {
            $learningPaths[$pathId]['next'] = (int) $row['lesson_id'];
        }
    }
}

require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">YOUR LEARNING SPACE</p>
        <h1>Welcome, <?= e((string) $user['name']) ?></h1>
        <p class="muted">Build useful skills, one lesson at a time.</p>
    </div>
    <div class="dashboard-actions">
        <a class="button button-secondary" href="<?= e(app_url('/learner/university-fees.php')) ?>">Find university fees</a>
        <a class="button button-primary" href="<?= e(app_url('/learner/search.php')) ?>">Search training</a>
    </div>
</section>

<?php if ($pendingPolicies > 0): ?>
    <p class="notice notice-info" role="status">You have <?= $pendingPolicies ?> <?= $pendingPolicies === 1 ? 'policy' : 'policies' ?> pending acknowledgement. <a href="<?= e(app_url('/learner/policies.php')) ?>">View policies</a></p>
<?php endif; ?>

<section class="learner-section" aria-labelledby="paths-title"><div class="section-title-row"><div><p class="eyebrow">ONBOARDING</p><h2 id="paths-title">Your learning path</h2></div></div>
<?php if ($learningPaths === []): ?><p class="empty-state">No learning paths have been assigned yet.</p><?php else: ?><div class="card-grid"><?php foreach ($learningPaths as $path): ?><article class="lesson-card"><h3><?= e((string) $path['name']) ?></h3><p><?= $path['completed'] ?> of <?= $path['total'] ?> lessons completed</p><div class="mandatory-card-footer"><a href="<?= e(app_url('/learner/path.php?id=' . $path['id'])) ?>">View path →</a><?php if ($path['next'] !== null): ?><a class="button button-primary" href="<?= e(app_url('/learner/lesson.php?id=' . $path['next'])) ?>">Next lesson</a><?php endif; ?></div></article><?php endforeach; ?></div><?php endif; ?></section>

<section class="learner-section" aria-labelledby="overall-progress-title">
    <div class="section-title-row">
        <div><p class="eyebrow">YOUR PROGRESS</p><h2 id="overall-progress-title">My overall progress</h2></div>
        <strong class="progress-summary"><?= $completedLessons ?> of <?= $totalLessons ?> lessons</strong>
    </div>
    <div class="progress-track" role="progressbar" aria-label="Overall lesson completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $progressPercent ?>">
        <span class="progress-fill" style="width: <?= $progressPercent ?>%"></span>
    </div>
    <p class="muted progress-caption"><?= $progressPercent ?>% complete</p>
</section>

<section class="learner-section" aria-labelledby="mandatory-title">
    <div class="section-title-row">
        <div><p class="eyebrow">REQUIRED LEARNING</p><h2 id="mandatory-title">Mandatory trainings</h2></div>
    </div>
    <?php if ($mandatoryLessons === []): ?>
        <p class="empty-state">There are no mandatory published trainings right now.</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($mandatoryLessons as $lesson): ?>
                <?php $isCompleted = $lesson['progress_status'] === 'completed'; ?>
                <article class="lesson-card mandatory-card">
                    <div class="lesson-meta"><?= e($lesson['category_name']) ?> · <?= (int) $lesson['duration_min'] ?> min</div>
                    <h3><?= e($lesson['title']) ?></h3>
                    <?php if (!$isCompleted && $lesson['days_remaining'] !== null): ?>
                        <?php $daysRemaining = (int) $lesson['days_remaining']; ?>
                        <span class="training-badge <?= $daysRemaining < 0 ? 'badge-overdue' : 'badge-pending' ?>">
                            <?= $daysRemaining < 0 ? 'Overdue' : ($daysRemaining === 0 ? 'Due today' : 'Due in ' . $daysRemaining . ' days') ?>
                        </span>
                    <?php endif; ?>
                    <div class="mandatory-card-footer">
                        <span class="training-badge <?= $isCompleted ? 'badge-completed' : 'badge-pending' ?>">
                            <?= $isCompleted ? 'Completed' : 'Pending' ?>
                        </span>
                        <a href="<?= e(app_url('/learner/lesson.php?id=' . (int) $lesson['id'])) ?>">
                            <?= $isCompleted ? 'Review lesson' : 'Start training' ?> →
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="learner-section" aria-labelledby="categories-title">
    <div class="section-title-row">
        <div><p class="eyebrow">BROWSE THE LIBRARY</p><h2 id="categories-title">Explore topics</h2></div>
    </div>
    <?php if ($categories === []): ?>
        <p class="empty-state">Training topics will appear here when they are added.</p>
    <?php else: ?>
        <div class="card-grid category-grid">
            <?php foreach ($categories as $category): ?>
                <a class="topic-card" href="<?= e(app_url('/learner/category.php?slug=' . rawurlencode($category['slug']))) ?>">
                    <span class="topic-icon" aria-hidden="true"><?= e($category['icon'] ?: '✦') ?></span>
                    <span class="topic-card-copy">
                        <strong><?= e($category['name']) ?></strong>
                        <small><?= e((string) $category['description']) ?></small>
                        <span class="topic-count"><?= (int) $category['lesson_count'] ?> <?= (int) $category['lesson_count'] === 1 ? 'lesson' : 'lessons' ?></span>
                    </span>
                    <span class="topic-arrow" aria-hidden="true">→</span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="learner-section" aria-labelledby="continue-title">
    <div class="section-title-row">
        <div><p class="eyebrow">PICK UP ANYTIME</p><h2 id="continue-title">Continue where you left off</h2></div>
    </div>
    <?php if ($continueLessons === []): ?>
        <p class="empty-state">Lessons you start will appear here so you can continue them later.</p>
    <?php else: ?>
        <div class="card-grid">
            <?php foreach ($continueLessons as $lesson): ?>
                <article class="lesson-card continue-card">
                    <div class="lesson-meta"><?= e($lesson['category_name']) ?> · <?= (int) $lesson['duration_min'] ?> min</div>
                    <h3><?= e($lesson['title']) ?></h3>
                    <p><?= e((string) $lesson['description']) ?></p>
                    <a href="<?= e(app_url('/learner/lesson.php?id=' . (int) $lesson['id'])) ?>">Continue lesson →</a>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
