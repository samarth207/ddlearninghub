<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');

$embeddedQuiz = isset($embeddedQuiz) && $embeddedQuiz === true;
$idValue = $embeddedQuiz ? (string) $editId : ($_GET['lesson_id'] ?? '');
$lessonId = is_string($idValue) ? filter_var($idValue, FILTER_VALIDATE_INT) : false;
if ($lessonId === false || $lessonId < 1) {
    render_error_page(404);
}
$db = db();
$lessonQuery = $db->prepare('SELECT id, title FROM lessons WHERE id = :id');
$lessonQuery->execute(['id' => $lessonId]);
$lesson = $lessonQuery->fetch();
if ($lesson === false) {
    render_error_page(404);
}

$error = '';
if (!$embeddedQuiz && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';
        if (!is_string($action)) {
            throw new InvalidArgumentException('Invalid action.');
        }
        $db->beginTransaction();
        $lock = $db->prepare('SELECT id FROM lessons WHERE id = :id FOR UPDATE');
        $lock->execute(['id' => $lessonId]);
        if ($lock->fetchColumn() === false) {
            throw new InvalidArgumentException('Lesson no longer exists.');
        }
        $query = $db->prepare('SELECT * FROM quizzes WHERE lesson_id = :id FOR UPDATE');
        $query->execute(['id' => $lessonId]);
        $quiz = $query->fetch();
        if ($action === 'settings') {
            $mark = filter_var($_POST['pass_mark'] ?? null, FILTER_VALIDATE_INT);
            $attemptValue = $_POST['max_attempts'] ?? '';
            $maxAttempts = $attemptValue === '' ? null : filter_var($attemptValue, FILTER_VALIDATE_INT);
            if ($mark === false || $mark < 1 || $mark > 100 || ($maxAttempts !== null && ($maxAttempts === false || $maxAttempts < 1 || $maxAttempts > 10000))) {
                throw new InvalidArgumentException('Pass mark must be 1–100 and maximum attempts must be blank or 1–10000.');
            }
            if ($quiz === false) {
                $save = $db->prepare('INSERT INTO quizzes (lesson_id, pass_mark_percent, max_attempts) VALUES (?, ?, ?)');
                $save->execute([$lessonId, $mark, $maxAttempts]);
            } else {
                $attempts = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ?');
                $attempts->execute([$quiz['id']]);
                if ((int) $attempts->fetchColumn() > 0 && $mark !== (int) $quiz['pass_mark_percent']) {
                    throw new InvalidArgumentException('The pass mark is locked because this quiz has attempts.');
                }
                $save = $db->prepare('UPDATE quizzes SET pass_mark_percent = ?, max_attempts = ? WHERE id = ?');
                $save->execute([$mark, $maxAttempts, $quiz['id']]);
            }
        } else {
            if ($quiz === false) {
                throw new InvalidArgumentException('Save quiz settings first.');
            }
            $attempts = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ?');
            $attempts->execute([$quiz['id']]);
            if ((int) $attempts->fetchColumn() > 0) {
                throw new InvalidArgumentException('Questions are locked because this quiz has attempts.');
            }
            $questionCountQuery = $db->prepare('SELECT COUNT(*) FROM quiz_questions WHERE quiz_id = ?');
            $questionCountQuery->execute([$quiz['id']]);
            $questionCount = (int) $questionCountQuery->fetchColumn();
            $validateQuestion = static function (array $values): array {
                $keys = ['question', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option', 'explanation', 'sort_order'];
                $result = [];
                foreach ($keys as $key) {
                    $value = $values[$key] ?? '';
                    if (!is_string($value)) {
                        throw new InvalidArgumentException('Question fields must be text.');
                    }
                    $result[$key] = trim($value);
                }
                foreach (['question', 'option_a', 'option_b', 'option_c', 'option_d'] as $key) {
                    if ($result[$key] === '' || strlen($result[$key]) > 5000) {
                        throw new InvalidArgumentException('Question and all four options are required (maximum 5000 bytes each).');
                    }
                }
                if (!in_array($result['correct_option'], ['a', 'b', 'c', 'd'], true) || strlen($result['explanation']) > 5000) {
                    throw new InvalidArgumentException('Choose a correct option from a to d and an explanation of at most 5000 bytes.');
                }
                $order = filter_var($result['sort_order'], FILTER_VALIDATE_INT);
                if ($order === false || $order < 0 || $order > 100000) {
                    throw new InvalidArgumentException('Sort order must be a whole number between 0 and 100000.');
                }
                $result['sort_order'] = $order;
                return $result;
            };
            if ($action === 'delete') {
                $questionId = filter_var($_POST['question_id'] ?? null, FILTER_VALIDATE_INT);
                if ($questionId === false || $questionId < 1) {
                    throw new InvalidArgumentException('Invalid question.');
                }
                $delete = $db->prepare('DELETE FROM quiz_questions WHERE id = ? AND quiz_id = ?');
                $delete->execute([$questionId, $quiz['id']]);
                if ($delete->rowCount() !== 1) {
                    throw new InvalidArgumentException('Question not found.');
                }
            } elseif ($action === 'question') {
                $fields = $validateQuestion($_POST);
                $questionId = filter_var($_POST['question_id'] ?? '', FILTER_VALIDATE_INT);
                if (($_POST['question_id'] ?? '') !== '' && ($questionId === false || $questionId < 1)) {
                    throw new InvalidArgumentException('Invalid question.');
                }
                if ($questionId !== false) {
                    $exists = $db->prepare('SELECT id FROM quiz_questions WHERE id = ? AND quiz_id = ?');
                    $exists->execute([$questionId, $quiz['id']]);
                    if ($exists->fetchColumn() === false) {
                        throw new InvalidArgumentException('Question not found.');
                    }
                    $save = $db->prepare('UPDATE quiz_questions SET question = ?, option_a = ?, option_b = ?, option_c = ?, option_d = ?, correct_option = ?, explanation = ?, sort_order = ? WHERE id = ? AND quiz_id = ?');
                    $save->execute(array_merge(array_values($fields), [$questionId, $quiz['id']]));
                } else {
                    if ($questionCount >= 500) {
                        throw new InvalidArgumentException('A quiz may contain at most 500 questions.');
                    }
                    $save = $db->prepare('INSERT INTO quiz_questions (question, option_a, option_b, option_c, option_d, correct_option, explanation, sort_order, quiz_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $save->execute(array_merge(array_values($fields), [$quiz['id']]));
                }
            } elseif ($action === 'import') {
                $file = $_FILES['csv_file'] ?? null;
                if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
                    || !is_uploaded_file($file['tmp_name']) || strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv'
                    || (int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
                    throw new InvalidArgumentException('Choose a CSV file no larger than 2 MB.');
                }
                $handle = fopen($file['tmp_name'], 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Unable to open the CSV.');
                }
                try {
                    $header = fgetcsv($handle, 0, ',', '"', '');
                    $expected = ['question', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option', 'explanation', 'sort_order'];
                    if (!is_array($header)) {
                        throw new InvalidArgumentException('CSV is empty.');
                    }
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                    $header = array_map(static fn ($v): string => strtolower(trim((string) $v)), $header);
                    if ($header !== $expected) {
                        throw new InvalidArgumentException('CSV headers must match the template shown.');
                    }
                    $insert = $db->prepare('INSERT INTO quiz_questions (question, option_a, option_b, option_c, option_d, correct_option, explanation, sort_order, quiz_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $count = 0;
                    while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                        if (++$count > 500 || $questionCount + $count > 500 || count($row) !== count($header)) {
                            throw new InvalidArgumentException('CSV must have eight columns per row and the quiz cannot exceed 500 questions.');
                        }
                        try {
                            $values = $validateQuestion(array_combine($header, $row));
                        } catch (InvalidArgumentException $exception) {
                            throw new InvalidArgumentException('Row ' . ($count + 1) . ': ' . $exception->getMessage());
                        }
                        $insert->execute(array_merge(array_values($values), [$quiz['id']]));
                    }
                    if ($count === 0) {
                        throw new InvalidArgumentException('CSV contains no questions.');
                    }
                } finally {
                    fclose($handle);
                }
            } else {
                throw new InvalidArgumentException('Invalid action.');
            }
        }
        $db->commit();
        redirect_to('/admin/quiz.php?lesson_id=' . (int) $lessonId . '&saved=1');
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Quiz management failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'The quiz could not be saved. Please try again.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$query = $db->prepare('SELECT * FROM quizzes WHERE lesson_id = ?');
$query->execute([$lessonId]);
$quiz = $query->fetch();
$questions = [];
$locked = false;
if ($quiz !== false) {
    $query = $db->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id');
    $query->execute([$quiz['id']]);
    $questions = $query->fetchAll();
    $query = $db->prepare('SELECT COUNT(*) FROM quiz_attempts WHERE quiz_id = ?');
    $query->execute([$quiz['id']]);
    $locked = (int) $query->fetchColumn() > 0;
}
$editValue = $_GET['question'] ?? '';
$editId = is_string($editValue) ? filter_var($editValue, FILTER_VALIDATE_INT) : false;
$editingQuestion = null;
foreach ($questions as $question) {
    if ((int) $question['id'] === $editId) {
        $editingQuestion = $question;
    }
}
if (!$embeddedQuiz) {
    $pageTitle = 'Quiz';
    $activeNav = 'lessons';
    require __DIR__ . '/../includes/header.php';
}
?>
<?php if (!$embeddedQuiz): ?>
<section class="page-heading"><div><p class="eyebrow">LESSON QUIZ</p><h1><?= e((string) $lesson['title']) ?></h1><a href="<?= e(app_url('/admin/lessons.php?edit=' . $lessonId)) ?>">Back to lesson</a></div></section>
<?php else: ?>
<h2>Quiz</h2>
<?php endif; ?>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="notice notice-success">Quiz saved.</p><?php endif; ?>
<?php if ($locked): ?><p class="notice notice-info">Questions and pass mark are locked after the first attempt to preserve results. Maximum attempts can still be changed.</p><?php endif; ?>
<section class="panel admin-form-panel"><h2>Quiz settings</h2>
    <form method="post" action="<?= e(app_url('/admin/quiz.php?lesson_id=' . (int) $lessonId)) ?>" class="admin-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="settings">
        <label>Pass mark (%)<input type="number" name="pass_mark" min="1" max="100" required value="<?= (int) ($quiz['pass_mark_percent'] ?? 70) ?>" <?= $locked ? 'readonly' : '' ?>></label>
        <label>Maximum attempts<input type="number" name="max_attempts" min="1" max="10000" value="<?= e((string) ($quiz['max_attempts'] ?? '')) ?>"><small class="form-help">Leave blank for unlimited attempts.</small></label>
        <div class="form-field-wide"><button class="button button-primary">Save quiz settings</button></div>
    </form>
</section>
<?php if ($quiz !== false && !$locked): ?>
<section class="panel admin-form-panel"><h2><?= $editingQuestion === null ? 'Add question' : 'Edit question' ?></h2>
    <form method="post" action="<?= e(app_url('/admin/quiz.php?lesson_id=' . (int) $lessonId)) ?>" class="admin-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="question"><input type="hidden" name="question_id" value="<?= e((string) ($editingQuestion['id'] ?? '')) ?>">
        <label class="form-field-wide">Question<textarea name="question" required><?= e((string) ($editingQuestion['question'] ?? '')) ?></textarea></label>
        <?php foreach (['a', 'b', 'c', 'd'] as $option): ?><label>Option <?= e(strtoupper($option)) ?><input name="option_<?= e($option) ?>" required value="<?= e((string) ($editingQuestion['option_' . $option] ?? '')) ?>"></label><?php endforeach; ?>
        <label>Correct option<select name="correct_option"><?php foreach (['a', 'b', 'c', 'd'] as $option): ?><option value="<?= e($option) ?>" <?= ($editingQuestion['correct_option'] ?? 'a') === $option ? 'selected' : '' ?>><?= e(strtoupper($option)) ?></option><?php endforeach; ?></select></label>
        <label>Sort order<input type="number" name="sort_order" min="0" max="100000" required value="<?= e((string) ($editingQuestion['sort_order'] ?? '0')) ?>"></label>
        <label class="form-field-wide">Explanation<textarea name="explanation"><?= e((string) ($editingQuestion['explanation'] ?? '')) ?></textarea></label>
        <div class="form-field-wide"><button class="button button-primary">Save question</button></div>
    </form>
</section>
<section class="panel admin-form-panel"><h2>Import questions</h2>
    <p class="muted">CSV headers: <code>question,option_a,option_b,option_c,option_d,correct_option,explanation,sort_order</code>. Maximum 500 questions per quiz, 2 MB per file. Import is all-or-nothing.</p>
    <form method="post" action="<?= e(app_url('/admin/quiz.php?lesson_id=' . (int) $lessonId)) ?>" enctype="multipart/form-data" class="import-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import"><label class="file-label">CSV file<input type="file" name="csv_file" accept=".csv,text/csv" required></label><button class="button button-primary">Import</button></form>
</section>
<?php endif; ?>
<?php if ($quiz !== false): ?><section class="admin-list-section"><h2>Questions</h2>
<?php if ($questions === []): ?><p class="empty-state">Add at least one question before employees can take this quiz.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Question</th><th>Correct option</th><th>Order</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($questions as $question): ?><tr><td><?= e((string) $question['question']) ?></td><td><?= e(strtoupper((string) $question['correct_option'])) ?></td><td><?= (int) $question['sort_order'] ?></td><td>
<?php if (!$locked): ?><a href="<?= e(app_url('/admin/quiz.php?lesson_id=' . $lessonId . '&question=' . (int) $question['id'])) ?>">Edit</a>
<form method="post" action="<?= e(app_url('/admin/quiz.php?lesson_id=' . (int) $lessonId)) ?>" class="inline-form" data-confirm="Delete this question?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="question_id" value="<?= (int) $question['id'] ?>"><button class="table-action table-action-danger">Delete</button></form><?php else: ?>Locked<?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>
<?php if (!$embeddedQuiz) { require __DIR__ . '/../includes/footer.php'; } ?>
