<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$user = require_role('employee');
$query = db()->prepare(
    'SELECT p.id, p.title, p.description, p.version, p.effective_date, a.acknowledged_at
     FROM policies p LEFT JOIN policy_acknowledgements a
       ON a.policy_id = p.id AND a.version_acknowledged = p.version AND a.user_id = :user_id
     WHERE p.is_active = 1 ORDER BY a.id IS NOT NULL, p.title, p.id'
);
$query->execute(['user_id' => $user['id']]);
$policies = $query->fetchAll();
$pageTitle = 'Policy library';
$activeNav = 'policies';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">POLICY LIBRARY</p><h1>Policies</h1><p class="muted">Read and acknowledge the current version of each policy.</p></div></section>
<?php if ($policies === []): ?><p class="empty-state">There are no active policies.</p><?php else: ?>
<div class="card-grid"><?php foreach ($policies as $policy): ?>
    <article class="lesson-card"><div class="lesson-meta">Version <?= (int) $policy['version'] ?> · Effective <?= e((string) $policy['effective_date']) ?></div>
        <h2><?= e((string) $policy['title']) ?></h2><p><?= e((string) $policy['description']) ?></p>
        <div class="mandatory-card-footer"><span class="training-badge <?= $policy['acknowledged_at'] !== null ? 'badge-completed' : 'badge-pending' ?>"><?= $policy['acknowledged_at'] !== null ? 'Acknowledged' : 'Pending' ?></span>
        <a href="<?= e(app_url('/learner/policy.php?id=' . (int) $policy['id'])) ?>">View policy →</a></div>
    </article>
<?php endforeach; ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
