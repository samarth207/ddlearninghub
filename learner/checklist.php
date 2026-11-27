<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
$user = require_role('employee');
$connection = db();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $idValue = $_POST['item_id'] ?? '';
        $id = is_string($idValue) ? filter_var($idValue, FILTER_VALIDATE_INT) : false;
        $checked = $_POST['done'] ?? null;
        if ($id === false || $id < 1 || ($checked !== null && $checked !== '1')) {
            throw new InvalidArgumentException('Choose a valid checklist item.');
        }
        $connection->beginTransaction();
        $query = $connection->prepare(
            "SELECT ci.id FROM checklist_items ci JOIN users u ON u.id = ?
             WHERE ci.id = ? AND (ci.department IS NULL OR TRIM(ci.department) = '' OR ci.department = u.department) FOR UPDATE"
        );
        $query->execute([$user['id'], $id]);
        if ($query->fetchColumn() === false) {
            throw new InvalidArgumentException('This checklist item is not available to you.');
        }
        if ($checked === '1') {
            $query = $connection->prepare('INSERT INTO user_checklist (user_id, item_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)');
        } else {
            $query = $connection->prepare('DELETE FROM user_checklist WHERE user_id = ? AND item_id = ?');
        }
        $query->execute([$user['id'], $id]);
        $connection->commit();
        redirect_to('/learner/checklist.php');
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        error_log('Checklist update failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Your checklist could not be updated. Please try again.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$query = $connection->prepare(
    "SELECT ci.id, ci.title, ci.description, uc.done_at
     FROM checklist_items ci JOIN users u ON u.id = :user_id
     LEFT JOIN user_checklist uc ON uc.item_id = ci.id AND uc.user_id = u.id
     WHERE ci.department IS NULL OR TRIM(ci.department) = '' OR ci.department = u.department
     ORDER BY ci.sort_order, ci.id"
);
$query->execute(['user_id' => $user['id']]);
$items = $query->fetchAll();
$done = count(array_filter($items, static fn (array $item): bool => $item['done_at'] !== null));
$total = count($items);
$percent = $total > 0 ? (int) round($done * 100 / $total) : 0;
$pageTitle = 'My onboarding checklist';
$activeNav = 'checklist';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">ONBOARDING</p><h1>My onboarding checklist</h1><p class="muted"><?= $done ?> of <?= $total ?> completed</p></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<div class="progress-track" role="progressbar" aria-label="Checklist progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>"><span class="progress-fill" style="width: <?= $percent ?>%"></span></div>
<?php if ($items === []): ?><p class="empty-state">There are no checklist items for you yet.</p><?php else: ?><div class="card-grid"><?php foreach ($items as $item): ?><article class="lesson-card"><h2><?= e((string) $item['title']) ?></h2><p><?= nl2br(e((string) $item['description'])) ?></p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><label class="attachment-choice"><input type="checkbox" name="done" value="1" <?= $item['done_at'] === null ? '' : 'checked' ?>> Completed</label><button class="button button-secondary">Save</button></form></article><?php endforeach; ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
