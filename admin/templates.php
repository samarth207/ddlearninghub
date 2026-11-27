<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/templates.php';
require_role('admin');

$connection = db();
$types = template_types();
$filter = $_GET['type'] ?? '';
if (!is_string($filter) || ($filter !== '' && !isset($types[$filter]))) { render_error_page(404); }
$error = '';
$form = ['id' => '', 'type' => $filter ?: 'call_script', 'title' => '', 'body' => '', 'tags' => '', 'department' => '', 'is_published' => '0'];
$read = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    if (!is_string($value)) { throw new InvalidArgumentException('Invalid form input.'); }
    return trim($value);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $read('action');
        if ($action === 'save') {
            foreach (array_keys($form) as $field) { $form[$field] = $read($field); }
            $id = $form['id'] === '' ? null : filter_var($form['id'], FILTER_VALIDATE_INT);
            if ($id === false || ($id !== null && $id < 1)) { throw new InvalidArgumentException('Choose a valid template.'); }
            $values = validate_template($form);
            if ($id === null) {
                $query = $connection->prepare('INSERT INTO templates (type, title, body, tags, department, is_published) VALUES (?, ?, ?, ?, ?, ?)');
            } else {
                $query = $connection->prepare('UPDATE templates SET type = ?, title = ?, body = ?, tags = ?, department = ?, is_published = ? WHERE id = ?');
            }
            $parameters = [$values['type'], $values['title'], $values['body'], $values['tags'], $values['department'], $values['is_published']];
            if ($id !== null) { $parameters[] = $id; }
            $query->execute($parameters);
            if ($id !== null && $query->rowCount() === 0) {
                $exists = $connection->prepare('SELECT id FROM templates WHERE id = ?');
                $exists->execute([$id]);
                if ($exists->fetchColumn() === false) { throw new InvalidArgumentException('Template not found.'); }
            }
        } elseif ($action === 'delete' || $action === 'publish' || $action === 'unpublish') {
            $id = filter_var($read('id'), FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) { throw new InvalidArgumentException('Choose a valid template.'); }
            if ($action === 'delete') {
                $query = $connection->prepare('DELETE FROM templates WHERE id = ?');
                $query->execute([$id]);
                if ($query->rowCount() !== 1) { throw new InvalidArgumentException('Template not found.'); }
            } else {
                $query = $connection->prepare('UPDATE templates SET is_published = ? WHERE id = ?');
                $query->execute([$action === 'publish' ? 1 : 0, $id]);
                if ($query->rowCount() === 0) {
                    $exists = $connection->prepare('SELECT id FROM templates WHERE id = ?');
                    $exists->execute([$id]);
                    if ($exists->fetchColumn() === false) { throw new InvalidArgumentException('Template not found.'); }
                }
            }
        } elseif ($action === 'import') {
            $upload = $_FILES['csv_file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
                !isset($upload['tmp_name'], $upload['name'], $upload['size']) ||
                !is_string($upload['tmp_name']) || !is_string($upload['name']) ||
                !is_uploaded_file($upload['tmp_name']) || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'csv' ||
                (int) $upload['size'] > 2 * 1024 * 1024) {
                throw new InvalidArgumentException('Choose a CSV file of at most 2 MB.');
            }
            $handle = fopen($upload['tmp_name'], 'rb');
            if ($handle === false) { throw new RuntimeException('The CSV could not be opened.'); }
            try {
                $header = fgetcsv($handle, 0, ',', '"', '');
                if ($header === false) { throw new InvalidArgumentException('The CSV is empty.'); }
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                $header = array_map(static fn ($value): string => strtolower(trim((string) $value)), $header);
                $expected = ['type', 'title', 'body', 'tags', 'department', 'is_published'];
                if (count($header) !== count($expected) || count(array_unique($header)) !== count($expected) || array_diff($expected, $header) !== []) {
                    throw new InvalidArgumentException('CSV headers must be type, title, body, tags, department, is_published.');
                }
                $connection->beginTransaction();
                $insert = $connection->prepare('INSERT INTO templates (type, title, body, tags, department, is_published) VALUES (?, ?, ?, ?, ?, ?)');
                $count = 0;
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) { continue; }
                    $count++;
                    if ($count > 500 || count($row) !== count($header)) {
                        throw new InvalidArgumentException('CSV must have at most 500 records and six columns per record.');
                    }
                    try {
                        $values = validate_template(array_combine($header, $row));
                    } catch (InvalidArgumentException $exception) {
                        throw new InvalidArgumentException('Record ' . $count . ': ' . $exception->getMessage());
                    }
                    $insert->execute([$values['type'], $values['title'], $values['body'], $values['tags'], $values['department'], $values['is_published']]);
                }
                if ($count === 0) { throw new InvalidArgumentException('The CSV contains no records.'); }
                $connection->commit();
                $_SESSION['template_import_count'] = $count;
            } finally {
                fclose($handle);
            }
        } else {
            throw new InvalidArgumentException('Choose a valid action.');
        }
        redirect_to('/admin/templates.php' . ($filter === '' ? '?saved=1' : '?type=' . rawurlencode($filter) . '&saved=1'));
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        error_log('Template operation failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'The library operation failed. Please try again.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $id = is_string($_GET['edit']) ? filter_var($_GET['edit'], FILTER_VALIDATE_INT) : false;
    if ($id === false || $id < 1) { render_error_page(404); }
    $query = $connection->prepare('SELECT id, type, title, body, tags, department, is_published FROM templates WHERE id = ?');
    $query->execute([$id]);
    $form = $query->fetch();
    if ($form === false) { render_error_page(404); }
}
$query = $connection->prepare('SELECT id, type, title, department, is_published, updated_at FROM templates' . ($filter === '' ? '' : ' WHERE type = ?') . ' ORDER BY updated_at DESC, id DESC');
$query->execute($filter === '' ? [] : [$filter]);
$templates = $query->fetchAll();
$importCount = $_SESSION['template_import_count'] ?? null;
unset($_SESSION['template_import_count']);
$pageTitle = 'Scripts & templates';
$activeNav = 'templates';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">SALES &amp; COUNSELLING</p><h1>Scripts, templates &amp; FAQ</h1><p class="muted">Review the example records before use. Blank department means everyone.</p></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?><?php if (isset($_GET['saved'])): ?><p class="notice notice-success"><?= $importCount !== null ? (int) $importCount . ' records imported.' : 'Changes saved.' ?></p><?php endif; ?>
<section class="panel admin-form-panel"><h2><?= $form['id'] === '' ? 'Add item' : 'Edit item' ?></h2>
<form method="post" class="admin-form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e((string) $form['id']) ?>">
<label>Type<select name="type"><?php foreach ($types as $value => $label): ?><option value="<?= e($value) ?>" <?= $form['type'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
<label>Title<input name="title" maxlength="200" required value="<?= e((string) $form['title']) ?>"></label>
<label class="form-field-wide">Body<textarea name="body" rows="8" required><?= e((string) $form['body']) ?></textarea><small class="form-help">Use placeholders such as {student_name}, {university}, {program}, and {counselor_name}.</small></label>
<label>Tags (comma-separated)<input name="tags" maxlength="500" value="<?= e((string) $form['tags']) ?>"></label><label>Department (optional)<input name="department" maxlength="120" value="<?= e((string) $form['department']) ?>"></label>
<label>Status<select name="is_published"><option value="0" <?= (string) $form['is_published'] === '0' ? 'selected' : '' ?>>Draft</option><option value="1" <?= (string) $form['is_published'] === '1' ? 'selected' : '' ?>>Published</option></select></label>
<div class="admin-form-actions"><button class="button button-primary">Save</button><?php if ($form['id'] !== ''): ?><a href="<?= e(app_url('/admin/templates.php' . ($filter !== '' ? '?type=' . rawurlencode($filter) : ''))) ?>">Cancel</a><?php endif; ?></div></form></section>
<section class="panel"><h2>Import CSV</h2><p class="muted">Headers: type, title, body, tags, department, is_published (0 or 1). Quote fields containing commas or newlines. Maximum 500 records, 2 MB; all records must be valid.</p><form method="post" enctype="multipart/form-data" class="import-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="import"><label class="file-label">CSV file<input type="file" name="csv_file" accept=".csv,text/csv" required></label><button class="button button-primary">Import</button></form></section>
<section class="admin-list-section"><h2>Library items</h2><p><a href="<?= e(app_url('/admin/templates.php')) ?>">All</a><?php foreach ($types as $value => $label): ?> · <a href="<?= e(app_url('/admin/templates.php?type=' . rawurlencode($value))) ?>"><?= e($label) ?></a><?php endforeach; ?></p>
<?php if ($templates === []): ?><p class="empty-state">No items for this type.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Title</th><th>Type</th><th>Department</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead><tbody><?php foreach ($templates as $item): ?><tr><td><?= e((string) $item['title']) ?></td><td><?= e($types[$item['type']]) ?></td><td><?= e((string) ($item['department'] ?: 'Everyone')) ?></td><td><?= (int) $item['is_published'] === 1 ? 'Published' : 'Draft' ?></td><td><?= e((string) $item['updated_at']) ?></td><td class="admin-row-actions"><a class="table-action" href="<?= e(app_url('/admin/templates.php?' . ($filter !== '' ? 'type=' . rawurlencode($filter) . '&' : '') . 'edit=' . (int) $item['id'])) ?>">Edit</a><?php foreach ([(int) $item['is_published'] === 1 ? 'unpublish' : 'publish', 'delete'] as $action): ?><form method="post" class="inline-form" <?= $action === 'delete' ? 'data-confirm="Delete this item?"' : '' ?>><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="<?= e($action) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="table-action <?= $action === 'delete' ? 'table-action-danger' : '' ?>"><?= e(ucfirst($action)) ?></button></form><?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
