<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
$user = require_role('employee');
$value = $_GET['id'] ?? '';
$id = is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
if ($id === false || $id < 1) {
    render_error_page(404);
}
$connection = db();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $versionValue = $_POST['version'] ?? '';
        $version = is_string($versionValue) ? filter_var($versionValue, FILTER_VALIDATE_INT) : false;
        if (($_POST['acknowledge'] ?? null) !== '1' || $version === false || $version < 1) {
            throw new InvalidArgumentException('Tick the acknowledgement checkbox before submitting.');
        }
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            error_log('Policy acknowledgement failed: invalid remote IP address.');
            throw new RuntimeException('Your acknowledgement could not be recorded. Please contact an administrator.');
        }
        $connection->beginTransaction();
        $query = $connection->prepare('SELECT version, is_active FROM policies WHERE id = ? FOR UPDATE');
        $query->execute([$id]);
        $current = $query->fetch();
        if ($current === false || (int) $current['is_active'] !== 1) {
            throw new InvalidArgumentException('This policy is no longer active.');
        }
        if ((int) $current['version'] !== $version) {
            throw new InvalidArgumentException('This policy has changed. Read the current version before acknowledging.');
        }
        $query = $connection->prepare('SELECT id FROM policy_acknowledgements WHERE user_id = ? AND policy_id = ? AND version_acknowledged = ?');
        $query->execute([$user['id'], $id, $version]);
        if ($query->fetchColumn() === false) {
            $query = $connection->prepare('INSERT INTO policy_acknowledgements (user_id, policy_id, version_acknowledged, ip_address) VALUES (?, ?, ?, ?)');
            $query->execute([$user['id'], $id, $version, $ip]);
        }
        $connection->commit();
        redirect_to('/learner/policy.php?id=' . $id . '&acknowledged=1');
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        error_log('Policy acknowledgement failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'Your acknowledgement could not be recorded. Please try again.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$query = $connection->prepare(
    'SELECT p.id, p.title, p.description, p.version, p.effective_date, a.acknowledged_at
     FROM policies p LEFT JOIN policy_acknowledgements a
       ON a.policy_id = p.id AND a.version_acknowledged = p.version AND a.user_id = :user_id
     WHERE p.id = :id AND p.is_active = 1'
);
$query->execute(['user_id' => $user['id'], 'id' => $id]);
$policy = $query->fetch();
if ($policy === false) {
    render_error_page(404);
}
$pageTitle = (string) $policy['title'];
$activeNav = 'policies';
require __DIR__ . '/../includes/header.php';
?>
<p class="breadcrumb-link"><a href="<?= e(app_url('/learner/policies.php')) ?>">← All policies</a></p>
<section class="page-heading"><div><p class="eyebrow">POLICY LIBRARY</p><h1><?= e((string) $policy['title']) ?></h1><p class="muted">Version <?= (int) $policy['version'] ?> · Effective <?= e((string) $policy['effective_date']) ?></p></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="panel"><p><?= nl2br(e((string) $policy['description'])) ?></p><a class="button button-secondary" href="<?= e(app_url('/learner/download.php?type=policy&id=' . $id . '&version=' . (int) $policy['version'])) ?>">Download policy PDF</a></section>
<?php if ($policy['acknowledged_at'] !== null): ?>
    <p class="notice notice-success">Acknowledged on <?= e((string) $policy['acknowledged_at']) ?>.</p>
<?php else: ?>
    <form method="post" class="panel lesson-complete-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="version" value="<?= (int) $policy['version'] ?>">
        <label class="attachment-choice"><input type="checkbox" name="acknowledge" value="1" required> I have read and understood this policy.</label>
        <button class="button button-primary" type="submit">Submit acknowledgement</button>
    </form>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
