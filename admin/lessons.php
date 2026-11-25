<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lesson_media.php';
require_role('admin');

$error = '';
$form = [
    'id' => '',
    'category_id' => '',
    'title' => '',
    'description' => '',
    'video_url' => '',
    'duration_min' => '0',
    'is_mandatory' => '0',
    'is_published' => '0',
];
$existingAttachments = [];
$newUploads = [];
$connection = null;
$readPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};
$cleanupNewUploads = static function (array $uploads): void {
    foreach ($uploads as $upload) {
        delete_managed_pdf($upload['file_path']);
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $readPostString('action');
        $lessonId = filter_var($readPostString('lesson_id'), FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if ($lessonId === false || $lessonId < 1) {
                throw new InvalidArgumentException('Choose a valid lesson to delete.');
            }
            $connection = db();
            $attachmentQuery = $connection->prepare('SELECT file_path FROM attachments WHERE lesson_id = :lesson_id');
            $attachmentQuery->execute(['lesson_id' => $lessonId]);
            $oldFiles = $attachmentQuery->fetchAll(PDO::FETCH_COLUMN);

            $connection->beginTransaction();
            $delete = $connection->prepare('DELETE FROM lessons WHERE id = :id');
            $delete->execute(['id' => $lessonId]);
            if ($delete->rowCount() === 0) {
                throw new InvalidArgumentException('That lesson could not be found.');
            }
            $connection->commit();
            foreach ($oldFiles as $oldFile) {
                delete_managed_pdf((string) $oldFile);
            }
            redirect_to('/admin/lessons.php?deleted=1');
        }

        if ($action === 'toggle') {
            if ($lessonId === false || $lessonId < 1) {
                throw new InvalidArgumentException('Choose a valid lesson to update.');
            }
            $connection = db();
            $toggle = $connection->prepare('UPDATE lessons SET is_published = 1 - is_published WHERE id = :id');
            $toggle->execute(['id' => $lessonId]);
            if ($toggle->rowCount() === 0) {
                throw new InvalidArgumentException('That lesson could not be found.');
            }
            redirect_to('/admin/lessons.php?updated=1');
        }

        if ($action !== 'save') {
            throw new InvalidArgumentException('Choose a valid lesson action.');
        }

        $form['id'] = $readPostString('lesson_id');
        $form['category_id'] = $readPostString('category_id');
        $form['title'] = $readPostString('title');
        $form['description'] = $readPostString('description');
        $form['video_url'] = $readPostString('video_url');
        $form['duration_min'] = $readPostString('duration_min');
        $form['is_mandatory'] = $readPostString('is_mandatory');
        $form['is_published'] = $readPostString('is_published');

        $categoryId = filter_var($form['category_id'], FILTER_VALIDATE_INT);
        $duration = filter_var($form['duration_min'], FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 1) {
            throw new InvalidArgumentException('Choose a lesson category.');
        }
        if ($form['title'] === '' || strlen($form['title']) > 200) {
            throw new InvalidArgumentException('Enter a lesson title of 200 characters or fewer.');
        }
        if ($duration === false || $duration < 0 || $duration > 65535) {
            throw new InvalidArgumentException('Duration must be a whole number between 0 and 65535 minutes.');
        }
        if (!in_array($form['is_mandatory'], ['0', '1'], true)
            || !in_array($form['is_published'], ['0', '1'], true)) {
            throw new InvalidArgumentException('Choose valid mandatory and publishing settings.');
        }
        if ($form['id'] !== '' && ($lessonId === false || $lessonId < 1)) {
            throw new InvalidArgumentException('The lesson ID is invalid. Reload the page and try again.');
        }

        $connection = db();
        $categoryCheck = $connection->prepare('SELECT id FROM categories WHERE id = :id');
        $categoryCheck->execute(['id' => $categoryId]);
        if ($categoryCheck->fetchColumn() === false) {
            throw new InvalidArgumentException('The selected category no longer exists.');
        }

        $video = normalize_lesson_video_url($form['video_url']);
        $storedUploads = [];
        if (isset($_FILES['attachments'])) {
            $storedUploads = store_pdf_uploads(
                $_FILES['attachments'],
                __DIR__ . '/../uploads/pdfs'
            );
            $newUploads = $storedUploads;
        }

        $connection->beginTransaction();
        if ($lessonId !== false && $lessonId > 0) {
            $exists = $connection->prepare('SELECT id FROM lessons WHERE id = :id');
            $exists->execute(['id' => $lessonId]);
            if ($exists->fetchColumn() === false) {
                throw new InvalidArgumentException('That lesson could not be found.');
            }
            $save = $connection->prepare(
                'UPDATE lessons
                 SET category_id = :category_id, title = :title, description = :description,
                     video_type = :video_type, video_url = :video_url, duration_min = :duration_min,
                     is_mandatory = :is_mandatory, is_published = :is_published
                 WHERE id = :id'
            );
            $save->execute([
                'category_id' => $categoryId,
                'title' => $form['title'],
                'description' => $form['description'],
                'video_type' => $video['video_type'],
                'video_url' => $video['video_url'],
                'duration_min' => $duration,
                'is_mandatory' => (int) $form['is_mandatory'],
                'is_published' => (int) $form['is_published'],
                'id' => $lessonId,
            ]);
            $savedLessonId = $lessonId;
        } else {
            $sortQuery = $connection->prepare(
                'SELECT COALESCE(MAX(sort_order), 0) + 1
                 FROM lessons WHERE category_id = :category_id'
            );
            $sortQuery->execute(['category_id' => $categoryId]);
            $nextSortOrder = (int) $sortQuery->fetchColumn();
            $save = $connection->prepare(
                'INSERT INTO lessons
                    (category_id, title, description, video_type, video_url,
                     duration_min, is_mandatory, is_published, sort_order)
                 VALUES
                    (:category_id, :title, :description, :video_type, :video_url,
                     :duration_min, :is_mandatory, :is_published, :sort_order)'
            );
            $save->execute([
                'category_id' => $categoryId,
                'title' => $form['title'],
                'description' => $form['description'],
                'video_type' => $video['video_type'],
                'video_url' => $video['video_url'],
                'duration_min' => $duration,
                'is_mandatory' => (int) $form['is_mandatory'],
                'is_published' => (int) $form['is_published'],
                'sort_order' => $nextSortOrder,
            ]);
            $savedLessonId = (int) $connection->lastInsertId();
        }

        $removedFiles = [];
        $requestedRemovals = $_POST['remove_attachment_ids'] ?? [];
        if ($requestedRemovals !== []) {
            if (!is_array($requestedRemovals)) {
                throw new InvalidArgumentException('The selected PDF removals are invalid.');
            }
            $validRemovals = [];
            foreach ($requestedRemovals as $requestedId) {
                $attachmentId = filter_var($requestedId, FILTER_VALIDATE_INT);
                if ($attachmentId !== false && $attachmentId > 0) {
                    $validRemovals[] = $attachmentId;
                }
            }
            if ($validRemovals !== []) {
                $placeholders = implode(',', array_fill(0, count($validRemovals), '?'));
                $filesQuery = $connection->prepare(
                    "SELECT id, file_path FROM attachments
                     WHERE lesson_id = ? AND id IN ($placeholders)"
                );
                $filesQuery->execute(array_merge([$savedLessonId], $validRemovals));
                $removedFiles = $filesQuery->fetchAll();
                $deleteFiles = $connection->prepare(
                    "DELETE FROM attachments
                     WHERE lesson_id = ? AND id IN ($placeholders)"
                );
                $deleteFiles->execute(array_merge([$savedLessonId], $validRemovals));
            }
        }

        if ($storedUploads !== []) {
            $addAttachment = $connection->prepare(
                'INSERT INTO attachments (lesson_id, title, file_path)
                 VALUES (:lesson_id, :title, :file_path)'
            );
            foreach ($storedUploads as $upload) {
                $addAttachment->execute([
                    'lesson_id' => $savedLessonId,
                    'title' => $upload['title'],
                    'file_path' => $upload['file_path'],
                ]);
            }
        }
        $connection->commit();

        foreach ($removedFiles as $removedFile) {
            delete_managed_pdf((string) $removedFile['file_path']);
        }
        redirect_to('/admin/lessons.php?saved=1');
    } catch (PDOException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        $cleanupNewUploads($newUploads);
        error_log('Learning Hub lesson operation failed: ' . $exception->getMessage());
        $error = 'The lesson could not be saved. Please review its fields and try again.';
    } catch (InvalidArgumentException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        $cleanupNewUploads($newUploads);
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        $cleanupNewUploads($newUploads);
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.'
            ? 'Your form session expired. Reload the page and try again.'
            : $exception->getMessage();
    }
}

$editValue = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $readPostString('lesson_id')
    : ($_GET['edit'] ?? '');
$editId = is_string($editValue) ? filter_var($editValue, FILTER_VALIDATE_INT) : false;
$editing = $editId !== false && $editId !== null && $editId > 0;
if ($editing) {
    $editQuery = db()->prepare(
        'SELECT id, category_id, title, description, video_url, duration_min,
                is_mandatory, is_published
         FROM lessons WHERE id = :id'
    );
    $editQuery->execute(['id' => $editId]);
    $lessonToEdit = $editQuery->fetch();
    if ($lessonToEdit === false) {
        render_error_page(404);
    } else {
        $existingAttachmentsQuery = db()->prepare(
            'SELECT id, title, file_path FROM attachments WHERE lesson_id = :lesson_id ORDER BY id'
        );
        $existingAttachmentsQuery->execute(['lesson_id' => $editId]);
        $existingAttachments = $existingAttachmentsQuery->fetchAll();
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $form = $lessonToEdit;
        }
    }
}

$categories = db()->query('SELECT id, name FROM categories ORDER BY sort_order, name')->fetchAll();
$lessons = db()->query(
    'SELECT l.id, l.title, l.video_type, l.duration_min, l.is_mandatory,
            l.is_published, c.name AS category_name, COUNT(a.id) AS attachment_count
     FROM lessons l
     INNER JOIN categories c ON c.id = l.category_id
     LEFT JOIN attachments a ON a.lesson_id = l.id
     GROUP BY l.id, l.title, l.video_type, l.duration_min, l.is_mandatory,
              l.is_published, c.name, l.created_at
     ORDER BY l.created_at DESC, l.sort_order, l.id DESC'
)->fetchAll();
$pageTitle = 'Lessons';
$activeNav = 'lessons';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">CONTENT LIBRARY</p><h1>Lessons</h1><p class="muted">Create, publish, and maintain training resources.</p></div>
</section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="notice notice-success" role="status">Lesson saved.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="notice notice-success" role="status">Lesson deleted.</p><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><p class="notice notice-success" role="status">Lesson publication status updated.</p><?php endif; ?>

<section class="panel admin-form-panel">
    <h2><?= $editing ? 'Edit lesson' : 'Add lesson' ?></h2>
    <?php if ($categories === []): ?>
        <p class="notice notice-info">Create a category before adding a lesson.</p>
    <?php else: ?>
        <form method="post" enctype="multipart/form-data" class="admin-form-grid">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="lesson_id" value="<?= e((string) $form['id']) ?>">
            <label>Category
                <select name="category_id" required>
                    <option value="">Choose a category</option>
                    <?php foreach ($categories as $category): ?>
                        <option value="<?= (int) $category['id'] ?>" <?= (string) $form['category_id'] === (string) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Title<input name="title" required maxlength="200" value="<?= e((string) $form['title']) ?>"></label>
            <label class="form-field-wide">Description<textarea name="description" rows="4"><?= e((string) $form['description']) ?></textarea></label>
            <label class="form-field-wide">YouTube or Google Drive video link
                <input id="lesson-video-url" name="video_url" type="url" required maxlength="1000" value="<?= e((string) $form['video_url']) ?>" placeholder="https://youtu.be/... or https://drive.google.com/file/d/...">
                <small class="form-help" id="video-link-feedback">Paste a YouTube or Google Drive link. It will be validated and converted when saved.</small>
            </label>
            <label>Duration (minutes)<input name="duration_min" type="number" min="0" max="65535" step="1" required value="<?= e((string) $form['duration_min']) ?>"></label>
            <label>Mandatory?
                <select name="is_mandatory"><option value="0" <?= (string) $form['is_mandatory'] === '0' ? 'selected' : '' ?>>No</option><option value="1" <?= (string) $form['is_mandatory'] === '1' ? 'selected' : '' ?>>Yes</option></select>
            </label>
            <label>Publication
                <select name="is_published"><option value="0" <?= (string) $form['is_published'] === '0' ? 'selected' : '' ?>>Draft (hidden from employees)</option><option value="1" <?= (string) $form['is_published'] === '1' ? 'selected' : '' ?>>Published</option></select>
            </label>
            <label class="form-field-wide">PDF attachments (each up to 10 MB)
                <input type="file" name="attachments[]" accept="application/pdf,.pdf" multiple>
                <small class="form-help">PDFs only. Files are stored with random names.</small>
            </label>
            <?php if ($existingAttachments !== []): ?>
                <fieldset class="form-field-wide attachment-management">
                    <legend>Current attachments</legend>
                    <?php foreach ($existingAttachments as $attachment): ?>
                        <label class="attachment-choice">
                            <input type="checkbox" name="remove_attachment_ids[]" value="<?= (int) $attachment['id'] ?>">
                            Remove <?= e($attachment['title']) ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endif; ?>
            <div class="admin-form-actions form-field-wide">
                <button class="button button-primary" type="submit"><?= $editing ? 'Save lesson' : 'Add lesson' ?></button>
                <?php if ($editing): ?><a class="button button-secondary" href="<?= e(app_url('/admin/lessons.php')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="admin-list-section">
    <h2>All lessons</h2>
    <?php if ($lessons === []): ?>
        <p class="empty-state">No lessons yet. Add one above to get started.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Lesson</th><th>Category</th><th>Duration</th><th>Required</th><th>PDFs</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($lessons as $lesson): ?>
                    <tr>
                        <td><?= e($lesson['title']) ?><small class="table-subtext"><?= e(ucfirst($lesson['video_type'])) ?></small></td>
                        <td><?= e($lesson['category_name']) ?></td>
                        <td><?= (int) $lesson['duration_min'] ?> min</td>
                        <td><?= (int) $lesson['is_mandatory'] === 1 ? 'Yes' : 'No' ?></td>
                        <td><?= (int) $lesson['attachment_count'] ?></td>
                        <td><span class="training-badge <?= (int) $lesson['is_published'] === 1 ? 'badge-completed' : 'badge-pending' ?>"><?= (int) $lesson['is_published'] === 1 ? 'Published' : 'Draft' ?></span></td>
                        <td class="admin-row-actions">
                            <a class="table-action" href="<?= e(app_url('/admin/lessons.php?edit=' . (int) $lesson['id'])) ?>">Edit</a>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
                                <button class="table-action" type="submit"><?= (int) $lesson['is_published'] === 1 ? 'Unpublish' : 'Publish' ?></button>
                            </form>
                            <form method="post" class="inline-form" data-confirm="Delete this lesson and its attachments?">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="lesson_id" value="<?= (int) $lesson['id'] ?>">
                                <button class="table-action table-action-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
