<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lesson_media.php';
require_role('admin');

$connection = db();
$error = '';
$form = ['id' => '', 'title' => '', 'description' => '', 'effective_date' => '', 'is_active' => '1'];
$newPdf = null;
$read = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $read('action');
        $id = filter_var($read('policy_id'), FILTER_VALIDATE_INT);
        if ($action === 'deactivate') {
            if ($id === false || $id < 1) {
                throw new InvalidArgumentException('Choose a valid policy.');
            }
            $update = $connection->prepare('UPDATE policies SET is_active = 0 WHERE id = ?');
            $update->execute([$id]);
            if ($update->rowCount() === 0) {
                $lookup = $connection->prepare('SELECT id FROM policies WHERE id = ?');
                $lookup->execute([$id]);
                if ($lookup->fetchColumn() === false) {
                    throw new InvalidArgumentException('Policy not found.');
                }
            }
            redirect_to('/admin/policies.php?saved=1');
        }
        if ($action !== 'save') {
            throw new InvalidArgumentException('Choose a valid action.');
        }
        foreach (array_keys($form) as $key) {
            $form[$key] = $read($key === 'id' ? 'policy_id' : $key);
        }
        if ($form['id'] !== '' && ($id === false || $id < 1)) {
            throw new InvalidArgumentException('Invalid policy ID.');
        }
        if ($form['title'] === '' || strlen($form['title']) > 200 || strlen($form['description']) > 10000) {
            throw new InvalidArgumentException('Enter a title of at most 200 bytes and a description of at most 10000 bytes.');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $form['effective_date']);
        if ($date === false || $date->format('Y-m-d') !== $form['effective_date'] || (int) $date->format('Y') < 1000) {
            throw new InvalidArgumentException('Enter a valid effective date.');
        }
        if (!in_array($form['is_active'], ['0', '1'], true)) {
            throw new InvalidArgumentException('Choose a valid policy status.');
        }
        if (isset($_FILES['policy_pdf'])) {
            if (!is_array($_FILES['policy_pdf'])) {
                throw new InvalidArgumentException('The PDF upload was not received correctly.');
            }
            $newPdf = store_single_pdf_upload($_FILES['policy_pdf'], __DIR__ . '/../uploads/pdfs');
        }
        if ($form['id'] === '' && $newPdf === null) {
            throw new InvalidArgumentException('A PDF is required for a new policy.');
        }
        $connection->beginTransaction();
        $oldPath = null;
        if ($form['id'] !== '') {
            $lookup = $connection->prepare('SELECT file_path, version FROM policies WHERE id = ? FOR UPDATE');
            $lookup->execute([$id]);
            $existing = $lookup->fetch();
            if ($existing === false) {
                throw new InvalidArgumentException('Policy not found.');
            }
            $oldPath = (string) $existing['file_path'];
            $version = (int) $existing['version'] + ($newPdf !== null ? 1 : 0);
            $save = $connection->prepare('UPDATE policies SET title = ?, description = ?, effective_date = ?, is_active = ?, file_path = ?, version = ? WHERE id = ?');
            $save->execute([$form['title'], $form['description'], $form['effective_date'], (int) $form['is_active'], $newPdf['file_path'] ?? $oldPath, $version, $id]);
        } else {
            $save = $connection->prepare('INSERT INTO policies (title, description, effective_date, is_active, file_path, version) VALUES (?, ?, ?, ?, ?, 1)');
            $save->execute([$form['title'], $form['description'], $form['effective_date'], (int) $form['is_active'], $newPdf['file_path']]);
        }
        $connection->commit();
        if ($newPdf !== null && $oldPath !== null) {
            delete_managed_pdf($oldPath);
        }
        redirect_to('/admin/policies.php?saved=1');
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        if ($newPdf !== null) { delete_managed_pdf($newPdf['file_path']); }
        error_log('Policy save failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'The policy could not be saved. Please try again.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        if ($newPdf !== null) { delete_managed_pdf($newPdf['file_path']); }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$editValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? $read('policy_id') : ($_GET['edit'] ?? '');
$editId = is_string($editValue) ? filter_var($editValue, FILTER_VALIDATE_INT) : false;
$editing = $editId !== false && $editId > 0;
if ($editing) {
    $query = $connection->prepare('SELECT * FROM policies WHERE id = ?');
    $query->execute([$editId]);
    $existing = $query->fetch();
    if ($existing === false) {
        render_error_page(404);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $form = $existing;
    }
}
$policies = $connection->query('SELECT id, title, version, effective_date, is_active, updated_at FROM policies ORDER BY title, id')->fetchAll();
$pageTitle = 'Policies';
$activeNav = 'policies';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">POLICY LIBRARY</p><h1>Manage policies</h1><p class="muted">Replacing a PDF creates a new version that employees must acknowledge again.</p></div><a class="button button-secondary" href="<?= e(app_url('/admin/policy-report.php')) ?>">Acknowledgement reports</a></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="notice notice-success">Policy saved.</p><?php endif; ?>
<section class="panel admin-form-panel"><h2><?= $editing ? 'Edit policy' : 'Add policy' ?></h2>
    <form method="post" enctype="multipart/form-data" action="<?= e(app_url('/admin/policies.php')) ?>" class="admin-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="policy_id" value="<?= e((string) $form['id']) ?>">
        <label>Title<input name="title" maxlength="200" required value="<?= e((string) $form['title']) ?>"></label>
        <label>Effective date<input type="date" name="effective_date" required value="<?= e((string) $form['effective_date']) ?>"></label>
        <label class="form-field-wide">Description<textarea name="description"><?= e((string) $form['description']) ?></textarea></label>
        <label>Status<select name="is_active"><option value="1" <?= (string) $form['is_active'] === '1' ? 'selected' : '' ?>>Active</option><option value="0" <?= (string) $form['is_active'] === '0' ? 'selected' : '' ?>>Inactive</option></select></label>
        <label><?= $editing ? 'Replace PDF (optional)' : 'Policy PDF' ?><input type="file" name="policy_pdf" accept=".pdf,application/pdf" <?= $editing ? '' : 'required' ?>><small class="form-help">PDF only, maximum 10 MB.</small></label>
        <div class="form-field-wide admin-form-actions"><button class="button button-primary">Save policy</button><?php if ($editing): ?><a href="<?= e(app_url('/admin/policies.php')) ?>">Cancel</a><a href="<?= e(app_url('/admin/download.php?type=policy&id=' . (int) $editId)) ?>">Download current PDF</a><?php endif; ?></div>
    </form>
</section>
<section class="admin-list-section"><h2>Policies</h2>
<?php if ($policies === []): ?><p class="empty-state">No policies have been added.</p><?php else: ?>
<div class="table-wrap"><table><thead><tr><th>Title</th><th>Version</th><th>Effective</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($policies as $policy): ?><tr><td><?= e((string) $policy['title']) ?></td><td><?= (int) $policy['version'] ?></td><td><?= e((string) $policy['effective_date']) ?></td><td><?= (int) $policy['is_active'] === 1 ? 'Active' : 'Inactive' ?></td><td class="admin-row-actions">
<a class="table-action" href="<?= e(app_url('/admin/policies.php?edit=' . (int) $policy['id'])) ?>">Edit</a><a class="table-action" href="<?= e(app_url('/admin/policy-report.php?id=' . (int) $policy['id'])) ?>">Report</a>
<?php if ((int) $policy['is_active'] === 1): ?><form method="post" class="inline-form" data-confirm="Deactivate this policy?"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="deactivate"><input type="hidden" name="policy_id" value="<?= (int) $policy['id'] ?>"><button class="table-action table-action-danger">Deactivate</button></form><?php endif; ?>
</td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
