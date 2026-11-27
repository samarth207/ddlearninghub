<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
$user = require_role('employee');
$value = $_GET['lesson_id'] ?? '';
$lessonId = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
if ($lessonId === false || $lessonId < 1) {
    render_error_page(404);
}
$db = db();
$query = $db->prepare('SELECT l.id, l.title, l.is_mandatory, q.id AS quiz_id, q.pass_mark_percent, q.max_attempts FROM lessons l INNER JOIN quizzes q ON q.lesson_id = l.id WHERE l.id = ? AND l.is_published = 1');
$query->execute([$lessonId]);
$lesson = $query->fetch();
if ($lesson === false) {
    render_error_page(404);
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $db->beginTransaction();
        $lock = $db->prepare('SELECT id, title, is_mandatory FROM lessons WHERE id = ? AND is_published = 1 FOR UPDATE');
        $lock->execute([$lessonId]);
        $lockedLesson = $lock->fetch();
        if ($lockedLesson === false) {
            $db->rollBack();
            render_error_page(404);
        }
        $lock = $db->prepare('SELECT * FROM quizzes WHERE lesson_id = ? FOR UPDATE');
        $lock->execute([$lessonId]);
        $quiz = $lock->fetch();
        if ($quiz === false) {
            throw new InvalidArgumentException('This quiz is no longer available.');
        }
        $query = $db->prepare('SELECT COUNT(*) AS attempts, COALESCE(MAX(passed), 0) AS passed FROM quiz_attempts WHERE user_id = ? AND quiz_id = ?');
        $query->execute([$user['id'], $quiz['id']]);
        $attemptInfo = $query->fetch();
        if ((int) $attemptInfo['passed'] === 1) {
            throw new InvalidArgumentException('You have already passed this quiz.');
        }
        if ($quiz['max_attempts'] !== null && (int) $attemptInfo['attempts'] >= (int) $quiz['max_attempts']) {
            throw new InvalidArgumentException('You have used all available attempts. Contact an administrator.');
        }
        $query = $db->prepare('SELECT id, correct_option FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id');
        $query->execute([$quiz['id']]);
        $questions = $query->fetchAll();
        $answers = $_POST['answers'] ?? null;
        if ($questions === [] || !is_array($answers) || count($answers) !== count($questions)) {
            throw new InvalidArgumentException('Answer every question before submitting.');
        }
        $correct = 0;
        $savedAnswers = [];
        foreach ($questions as $question) {
            $answer = $answers[(int) $question['id']] ?? null;
            if (!is_string($answer) || !in_array($answer, ['a', 'b', 'c', 'd'], true)) {
                throw new InvalidArgumentException('Answer every question before submitting.');
            }
            $savedAnswers[(string) $question['id']] = $answer;
            $correct += $answer === $question['correct_option'] ? 1 : 0;
        }
        $total = count($questions);
        $score = round(100 * $correct / $total, 2);
        $passed = $score >= (int) $quiz['pass_mark_percent'];
        $json = json_encode($savedAnswers, JSON_THROW_ON_ERROR);
        $save = $db->prepare('INSERT INTO quiz_attempts (user_id, quiz_id, score_percent, passed, answers_json) VALUES (?, ?, ?, ?, ?)');
        $save->execute([$user['id'], $quiz['id'], $score, $passed ? 1 : 0, $json]);
        $attemptId = (int) $db->lastInsertId();
        if ($passed) {
            $save = $db->prepare("INSERT INTO progress (user_id, lesson_id, status, completed_at) VALUES (?, ?, 'completed', CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE status = 'completed', completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)");
            $save->execute([$user['id'], $lessonId]);
            if ((int) $lockedLesson['is_mandatory'] === 1) {
                $accountQuery = $db->prepare("SELECT name FROM users WHERE id = ? AND role = 'employee' AND is_active = 1 FOR UPDATE");
                $accountQuery->execute([$user['id']]);
                $employeeName = $accountQuery->fetchColumn();
                if ($employeeName === false) {
                    throw new InvalidArgumentException('Your account is no longer active.');
                }
                $number = 'DD-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(12)));
                $save = $db->prepare('INSERT INTO certificates (user_id, lesson_id, certificate_no, employee_name, lesson_title) VALUES (?, ?, ?, ?, ?)');
                $save->execute([$user['id'], $lessonId, $number, $employeeName, $lockedLesson['title']]);
            }
        }
        $db->commit();
        redirect_to('/learner/quiz.php?lesson_id=' . (int) $lessonId . '&attempt=' . $attemptId);
    } catch (PDOException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('Quiz submission failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Your attempt could not be saved. Please try again.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$result = null;
$resultAnswers = [];
$attemptValue = $_GET['attempt'] ?? '';
if ($attemptValue !== '') {
    $attemptId = is_string($attemptValue) ? filter_var($attemptValue, FILTER_VALIDATE_INT) : false;
    if ($attemptId === false || $attemptId < 1) {
        render_error_page(404);
    }
    $query = $db->prepare('SELECT id, score_percent, passed, answers_json FROM quiz_attempts WHERE id = ? AND user_id = ? AND quiz_id = ?');
    $query->execute([$attemptId, $user['id'], $lesson['quiz_id']]);
    $result = $query->fetch();
    if ($result === false) {
        render_error_page(404);
    }
    $resultAnswers = json_decode((string) $result['answers_json'], true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($resultAnswers)) {
        throw new RuntimeException('Invalid saved quiz answers.');
    }
}
$query = $db->prepare('SELECT * FROM quiz_questions WHERE quiz_id = ? ORDER BY sort_order, id');
$query->execute([$lesson['quiz_id']]);
$questions = $query->fetchAll();
$query = $db->prepare('SELECT COUNT(*) AS attempts, COALESCE(MAX(passed), 0) AS passed, MAX(id) AS latest_attempt FROM quiz_attempts WHERE user_id = ? AND quiz_id = ?');
$query->execute([$user['id'], $lesson['quiz_id']]);
$attemptInfo = $query->fetch();
$canAttempt = (int) $attemptInfo['passed'] === 0 && ($lesson['max_attempts'] === null || (int) $attemptInfo['attempts'] < (int) $lesson['max_attempts']);
$certificateId = false;
if ($result !== null && (int) $result['passed'] === 1) {
    $query = $db->prepare('SELECT id FROM certificates WHERE user_id = ? AND lesson_id = ?');
    $query->execute([$user['id'], $lessonId]);
    $certificateId = $query->fetchColumn();
}
$pageTitle = 'Quiz';
$activeNav = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">LESSON QUIZ</p><h1><?= e((string) $lesson['title']) ?></h1><a href="<?= e(app_url('/learner/lesson.php?id=' . $lessonId)) ?>">Back to lesson</a></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($result !== null): ?>
    <p class="notice <?= (int) $result['passed'] === 1 ? 'notice-success' : 'notice-error' ?>">Score: <?= e((string) $result['score_percent']) ?>% — <?= (int) $result['passed'] === 1 ? 'Passed' : 'Not passed' ?>. Pass mark: <?= (int) $lesson['pass_mark_percent'] ?>%.</p>
    <?php foreach ($questions as $question): ?>
        <?php $correctOption = (string) $question['correct_option']; $selected = $resultAnswers[(string) $question['id']] ?? ''; ?>
        <section class="panel admin-form-panel"><h2><?= e((string) $question['question']) ?></h2>
            <p>Your answer: <?= e(strtoupper((string) $selected)) ?> — <?= e((string) ($question['option_' . $selected] ?? '')) ?></p>
            <p>Correct answer: <?= e(strtoupper($correctOption)) ?> — <?= e((string) $question['option_' . $correctOption]) ?></p>
            <?php if ((string) $question['explanation'] !== ''): ?><p><?= nl2br(e((string) $question['explanation'])) ?></p><?php endif; ?>
        </section>
    <?php endforeach; ?>
    <?php if ($certificateId !== false): ?><a class="button button-primary" href="<?= e(app_url('/learner/certificate.php?id=' . (int) $certificateId)) ?>">Download certificate</a><?php endif; ?>
    <?php if ($canAttempt): ?><a class="button button-primary" href="<?= e(app_url('/learner/quiz.php?lesson_id=' . $lessonId)) ?>">Retry</a><?php endif; ?>
<?php elseif ($questions === []): ?>
    <p class="empty-state">This quiz has no questions yet. Contact your administrator.</p>
<?php elseif (!$canAttempt): ?>
    <p class="notice notice-info"><?= (int) $attemptInfo['passed'] === 1 ? 'You have already passed this quiz.' : 'You have used all available attempts. Contact an administrator.' ?></p>
    <?php if ($attemptInfo['latest_attempt'] !== null): ?><a href="<?= e(app_url('/learner/quiz.php?lesson_id=' . $lessonId . '&attempt=' . (int) $attemptInfo['latest_attempt'])) ?>">View last result</a><?php endif; ?>
<?php else: ?>
    <p class="muted">Pass mark: <?= (int) $lesson['pass_mark_percent'] ?>%. Attempts used: <?= (int) $attemptInfo['attempts'] ?><?= $lesson['max_attempts'] !== null ? ' of ' . (int) $lesson['max_attempts'] : ' (unlimited)' ?>.</p>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <?php foreach ($questions as $question): ?><fieldset class="panel admin-form-panel"><legend><?= e((string) $question['question']) ?></legend>
            <?php foreach (['a', 'b', 'c', 'd'] as $option): ?><label class="attachment-choice"><input type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= e($option) ?>" required> <?= e(strtoupper($option)) ?>. <?= e((string) $question['option_' . $option]) ?></label><?php endforeach; ?>
        </fieldset><?php endforeach; ?>
        <button class="button button-primary" type="submit">Submit answers</button>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
