<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_role('admin');
$connection = db();
$policies = $connection->query('SELECT id, title, version, is_active FROM policies ORDER BY title, id')->fetchAll();
$value = $_GET['id'] ?? '';
$id = $value === '' ? null : (is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false);
if ($id === false || ($id !== null && $id < 1)) {
    render_error_page(404);
}
$policy = null;
$rows = [];
if ($id !== null) {
    $query = $connection->prepare('SELECT id, title, version, is_active, effective_date FROM policies WHERE id = ?');
    $query->execute([$id]);
    $policy = $query->fetch();
    if ($policy === false) {
        render_error_page(404);
    }
    $query = $connection->prepare(
        "SELECT u.name, u.email, u.emp_code, u.department, u.is_active, a.acknowledged_at, a.ip_address
         FROM users u LEFT JOIN policy_acknowledgements a ON a.user_id = u.id AND a.policy_id = :policy_id AND a.version_acknowledged = :version
         WHERE u.role = 'employee' ORDER BY u.department, u.name, u.id"
    );
    $query->execute(['policy_id' => $id, 'version' => $policy['version']]);
    $rows = $query->fetchAll();
}
if (($_GET['export'] ?? '') === 'csv') {
    if ($policy === null) {
        http_response_code(400);
        exit('Choose a policy before exporting.');
    }
    $safe = static function (string $value): string {
        return preg_match('/\A[\p{C}\p{Z}\s]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
    };
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('The policy CSV could not be generated.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="policy-acknowledgements-' . (int) $id . '.csv"');
    header('Cache-Control: private, no-store');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Policy', 'Version', 'Effective date', 'Employee', 'Email', 'Employee code', 'Department', 'Account', 'Status', 'Acknowledged at', 'IP address'], ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($output, [
            $safe((string) $policy['title']), (int) $policy['version'], $safe((string) $policy['effective_date']),
            $safe((string) $row['name']), $safe((string) $row['email']), $safe((string) $row['emp_code']),
            $safe((string) $row['department']), (int) $row['is_active'] === 1 ? 'Active' : 'Inactive',
            $row['acknowledged_at'] !== null ? 'Acknowledged' : 'Pending',
            $safe((string) $row['acknowledged_at']), $safe((string) $row['ip_address']),
        ], ',', '"', '');
    }
    fclose($output);
    exit;
}
$pageTitle = 'Policy acknowledgements';
$activeNav = 'policies';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">COMPLIANCE RECORD</p><h1>Policy acknowledgements</h1><p class="muted">Reports include active and inactive employee accounts and the current policy version.</p></div><a class="button button-secondary" href="<?= e(app_url('/admin/policies.php')) ?>">Manage policies</a></section>
<section class="panel"><h2>Select a policy</h2>
<?php if ($policies === []): ?><p class="empty-state">No policies have been added.</p><?php else: ?>
<div class="card-grid"><?php foreach ($policies as $item): ?><a class="category-link" href="<?= e(app_url('/admin/policy-report.php?id=' . (int) $item['id'])) ?>"><?= e((string) $item['title']) ?> · v<?= (int) $item['version'] ?><?= (int) $item['is_active'] === 1 ? '' : ' · Inactive' ?></a><?php endforeach; ?></div>
<?php endif; ?></section>
<?php if ($policy !== null): ?>
<section class="admin-list-section"><div class="section-title-row"><h2><?= e((string) $policy['title']) ?> · version <?= (int) $policy['version'] ?></h2><a class="button button-primary" href="<?= e(app_url('/admin/policy-report.php?id=' . (int) $id . '&export=csv')) ?>">Export CSV</a></div>
<?php if ($rows === []): ?><p class="empty-state">No employee accounts are available.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Employee</th><th>Department</th><th>Account</th><th>Status</th><th>Acknowledged at</th><th>IP address</th></tr></thead><tbody>
<?php foreach ($rows as $row): ?><tr><td><?= e((string) $row['name']) ?><span class="table-subtext"><?= e((string) $row['emp_code']) ?> · <?= e((string) $row['email']) ?></span></td><td><?= e((string) $row['department']) ?></td><td><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></td><td><?= $row['acknowledged_at'] !== null ? 'Acknowledged' : 'Pending' ?></td><td><?= e((string) $row['acknowledged_at']) ?></td><td><?= e((string) $row['ip_address']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?></section><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
