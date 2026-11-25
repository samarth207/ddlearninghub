<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
$user = require_role('employee');

$lessonId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$lessonId || $lessonId < 1) {
    render_error_page(404);
}

$statement = db()->prepare(
    'SELECT l.id, l.category_id, l.title, l.description, l.video_type, l.video_url, l.duration_min,
            l.is_mandatory, c.name AS category_name, c.slug AS category_slug
     FROM lessons l
     INNER JOIN categories c ON c.id = l.category_id
     WHERE l.id = :id AND l.is_published = 1
     LIMIT 1'
);
$statement->execute(['id' => $lessonId]);
$lesson = $statement->fetch();
if ($lesson === false) {
    render_error_page(404);
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $complete = db()->prepare(
            "INSERT INTO progress (user_id, lesson_id, status, completed_at)
             VALUES (:user_id, :lesson_id, 'completed', CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE status = 'completed',
                 completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)"
        );
        $complete->execute(['user_id' => $user['id'], 'lesson_id' => $lesson['id']]);
        redirect_to('/learner/lesson.php?id=' . (int) $lesson['id']);
    } catch (RuntimeException $exception) {
        http_response_code(400);
        $error = 'Your form session expired. Reload the page and try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $start = db()->prepare(
        "INSERT IGNORE INTO progress (user_id, lesson_id, status)
         VALUES (:user_id, :lesson_id, 'started')"
    );
    $start->execute(['user_id' => $user['id'], 'lesson_id' => $lesson['id']]);
}

$sequenceQuery = db()->prepare(
    'SELECT id, title
     FROM lessons
     WHERE category_id = :category_id AND is_published = 1
    ORDER BY sort_order, created_at DESC, id DESC'
);
$sequenceQuery->execute(['category_id' => $lesson['category_id']]);
$lessonSequence = $sequenceQuery->fetchAll();
$currentIndex = null;
foreach ($lessonSequence as $index => $sequenceLesson) {
    if ((int) $sequenceLesson['id'] === (int) $lesson['id']) {
        $currentIndex = $index;
        break;
    }
}
$previousLesson = $currentIndex !== null && $currentIndex > 0
    ? $lessonSequence[$currentIndex - 1]
    : null;
$nextLesson = $currentIndex !== null && $currentIndex < count($lessonSequence) - 1
    ? $lessonSequence[$currentIndex + 1]
    : null;

$videoEmbedUrl = '';
$videoUrl = filter_var($lesson['video_url'], FILTER_VALIDATE_URL) ? (string) $lesson['video_url'] : '';
if ($videoUrl !== '') {
    $videoHost = strtolower((string) parse_url($videoUrl, PHP_URL_HOST));
    if ($lesson['video_type'] === 'youtube' && in_array($videoHost, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'], true)) {
        $videoId = '';
        if (str_ends_with($videoHost, 'youtu.be')) {
            $videoId = trim((string) parse_url($videoUrl, PHP_URL_PATH), '/');
        } elseif (str_starts_with((string) parse_url($videoUrl, PHP_URL_PATH), '/embed/')) {
            $videoId = basename((string) parse_url($videoUrl, PHP_URL_PATH));
        } else {
            parse_str((string) parse_url($videoUrl, PHP_URL_QUERY), $query);
            $videoId = (string) ($query['v'] ?? '');
        }
        if (preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId) === 1) {
            $videoEmbedUrl = 'https://www.youtube-nocookie.com/embed/' . $videoId;
        }
    } elseif ($lesson['video_type'] === 'drive' && in_array($videoHost, ['drive.google.com', 'docs.google.com'], true)) {
        $path = (string) parse_url($videoUrl, PHP_URL_PATH);
        $fileId = '';
        if (preg_match('~/file/d/([A-Za-z0-9_-]+)~', $path, $matches) === 1) {
            $fileId = $matches[1];
        } else {
            parse_str((string) parse_url($videoUrl, PHP_URL_QUERY), $query);
            $fileId = (string) ($query['id'] ?? '');
        }
        if (preg_match('/\A[A-Za-z0-9_-]{10,}\z/', $fileId) === 1) {
            $videoEmbedUrl = 'https://drive.google.com/file/d/' . $fileId . '/preview';
        }
    }
}

$progressQuery = db()->prepare('SELECT status FROM progress WHERE user_id = :user_id AND lesson_id = :lesson_id');
$progressQuery->execute(['user_id' => $user['id'], 'lesson_id' => $lesson['id']]);
$progress = $progressQuery->fetch();
$attachmentsQuery = db()->prepare('SELECT id, title FROM attachments WHERE lesson_id = :lesson_id ORDER BY id');
$attachmentsQuery->execute(['lesson_id' => $lesson['id']]);
$attachments = $attachmentsQuery->fetchAll();
$pageTitle = $lesson['title'];
$activeNav = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<p class="breadcrumb-link"><a href="<?= e(app_url('/learner/category.php?slug=' . rawurlencode($lesson['category_slug']))) ?>">← <?= e($lesson['category_name']) ?></a></p>
<section class="page-heading">
    <div>
        <p class="eyebrow"><?= e($lesson['category_name']) ?><?= (int) $lesson['is_mandatory'] === 1 ? ' · REQUIRED' : '' ?></p>
        <h1><?= e($lesson['title']) ?></h1>
        <p class="muted"><?= (int) $lesson['duration_min'] ?> minutes</p>
    </div>
</section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($videoEmbedUrl !== ''): ?>
    <div class="lesson-player">
        <iframe class="video-frame" src="<?= e($videoEmbedUrl) ?>" title="<?= e($lesson['title']) ?>" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>
    </div>
<?php else: ?>
    <p class="notice notice-info">A supported YouTube or Google Drive video is not available for this lesson yet. Contact your administrator.</p>
<?php endif; ?>
<section class="panel lesson-description"><h2>About this lesson</h2><p><?= nl2br(e((string) $lesson['description'])) ?></p></section>
<?php if ($attachments !== []): ?>
    <section class="attachment-list"><h2>Downloads</h2><ul>
        <?php foreach ($attachments as $attachment): ?>
            <li><a href="<?= e(app_url('/learner/download.php?' . http_build_query(['type' => 'attachment', 'id' => (int) $attachment['id']]))) ?>"><?= e($attachment['title']) ?> <span aria-hidden="true">↓</span></a></li>
        <?php endforeach; ?>
    </ul></section>
<?php endif; ?>
<?php if ($progress !== false && $progress['status'] === 'completed'): ?>
    <p class="lesson-complete-message" role="status"><span aria-hidden="true">✓</span> You’ve completed this lesson.</p>
<?php else: ?>
    <form method="post" class="lesson-complete-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button class="button button-primary" type="submit">Mark as complete</button>
    </form>
<?php endif; ?>
<nav class="lesson-pagination" aria-label="Lesson navigation">
    <?php if ($previousLesson !== null): ?>
        <a class="lesson-pagination-link" href="<?= e(app_url('/learner/lesson.php?id=' . (int) $previousLesson['id'])) ?>"><span>← Previous lesson</span><strong><?= e($previousLesson['title']) ?></strong></a>
    <?php else: ?>
        <span></span>
    <?php endif; ?>
    <?php if ($nextLesson !== null): ?>
        <a class="lesson-pagination-link lesson-pagination-next" href="<?= e(app_url('/learner/lesson.php?id=' . (int) $nextLesson['id'])) ?>"><span>Next lesson →</span><strong><?= e($nextLesson['title']) ?></strong></a>
    <?php endif; ?>
</nav>
<?php require __DIR__ . '/../includes/footer.php'; ?>
