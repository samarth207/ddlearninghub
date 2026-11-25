<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$pageTitle = 'Admin overview';
$activeNav = 'dashboard';

$counts = [
    'employees' => (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'employee'")->fetchColumn(),
    'lessons' => (int) db()->query('SELECT COUNT(*) FROM lessons')->fetchColumn(),
    'completions_week' => (int) db()->query(
        "SELECT COUNT(*) FROM progress
         WHERE status = 'completed'
           AND YEARWEEK(completed_at, 1) = YEARWEEK(CURRENT_DATE, 1)"
    )->fetchColumn(),
];
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">ADMINISTRATION</p><h1>Learning Hub overview</h1><p class="muted">Manage lessons, categories, employee accounts, university fees, and training progress.</p></div></section>
<div class="stat-grid">
    <div class="stat-card"><strong><?= $counts['employees'] ?></strong><span>Employees</span></div>
    <div class="stat-card"><strong><?= $counts['lessons'] ?></strong><span>Lessons</span></div>
    <div class="stat-card"><strong><?= $counts['completions_week'] ?></strong><span>Completions this week</span></div>
</div>
<p class="notice notice-info">Use the navigation to manage the course catalog and employee accounts, or review completion reports.</p>
<?php require __DIR__ . '/../includes/footer.php'; ?>
