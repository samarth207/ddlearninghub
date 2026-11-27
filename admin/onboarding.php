<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');

$definitions = [
    'checklist' => [
        'table' => 'checklist_items', 'title' => 'Checklist items', 'order' => 'sort_order, id',
        'fields' => [
            'title' => ['Title', 'text', 200, true],
            'description' => ['Description', 'textarea', 10000, false],
            'department' => ['Department (blank for everyone)', 'text', 120, false],
            'sort_order' => ['Sort order', 'number', 0, true],
        ],
    ],
    'contacts' => [
        'table' => 'contacts', 'title' => 'Contacts', 'order' => 'sort_order, name, id',
        'fields' => [
            'name' => ['Name', 'text', 150, true],
            'role' => ['Role', 'text', 150, true],
            'department' => ['Department', 'text', 120, false],
            'email' => ['Email', 'email', 254, false],
            'phone' => ['Phone', 'text', 50, false],
            'sort_order' => ['Sort order', 'number', 0, true],
        ],
    ],
    'holidays' => [
        'table' => 'holidays', 'title' => 'Holidays', 'order' => 'holiday_date, id',
        'fields' => [
            'name' => ['Name', 'text', 200, true],
            'holiday_date' => ['Date', 'date', 0, true],
        ],
    ],
];
$type = $_GET['type'] ?? 'checklist';
if (!is_string($type) || !isset($definitions[$type])) { render_error_page(404); }
$definition = $definitions[$type];
$table = $definition['table'];
$connection = db();
$error = '';
$form = ['id' => ''];
foreach ($definition['fields'] as $field => $rules) {
    $form[$field] = $field === 'sort_order' ? '0' : '';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';
        $idValue = $_POST['id'] ?? '';
        if (!is_string($action) || !is_string($idValue)) { throw new InvalidArgumentException('Invalid form submission.'); }
        $id = $idValue === '' ? null : filter_var($idValue, FILTER_VALIDATE_INT);
        if ($id === false || ($id !== null && $id < 1)) { throw new InvalidArgumentException('Choose a valid record.'); }
        if ($action === 'delete') {
            if ($id === null) { throw new InvalidArgumentException('Choose a record to delete.'); }
            $query = $connection->prepare("DELETE FROM $table WHERE id = ?");
            $query->execute([$id]);
            if ($query->rowCount() !== 1) { throw new InvalidArgumentException('Record not found.'); }
        } elseif ($action === 'save') {
            $form['id'] = $idValue;
            $values = [];
            foreach ($definition['fields'] as $field => [$label, $kind, $limit, $required]) {
                $value = $_POST[$field] ?? '';
                if (!is_string($value)) { throw new InvalidArgumentException('Invalid ' . $label . '.'); }
                $value = trim($value);
                $form[$field] = $value;
                if (($required && $value === '') || ($limit > 0 && strlen($value) > $limit)) {
                    throw new InvalidArgumentException('Check ' . $label . ($limit > 0 ? ' (maximum ' . $limit . ' bytes).' : '.'));
                }
                if ($kind === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidArgumentException('Enter a valid email address.');
                }
                if ($kind === 'date') {
                    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                    if ($date === false || $date->format('Y-m-d') !== $value || (int) $date->format('Y') < 1000) {
                        throw new InvalidArgumentException('Enter a valid date.');
                    }
                }
                if ($kind === 'number') {
                    $number = filter_var($value, FILTER_VALIDATE_INT);
                    if ($number === false || $number < -2147483648 || $number > 2147483647) {
                        throw new InvalidArgumentException('Sort order must be a whole number.');
                    }
                    $value = $number;
                }
                $values[] = $value === '' ? null : $value;
            }
            $fields = array_keys($definition['fields']);
            if ($id === null) {
                $query = $connection->prepare("INSERT INTO $table (" . implode(', ', $fields) . ') VALUES (' . implode(', ', array_fill(0, count($fields), '?')) . ')');
            } else {
                $assignments = array_map(static fn (string $field): string => $field . ' = ?', $fields);
                $query = $connection->prepare("UPDATE $table SET " . implode(', ', $assignments) . ' WHERE id = ?');
                $values[] = $id;
            }
            $query->execute($values);
            if ($id !== null && $query->rowCount() === 0) {
                $exists = $connection->prepare("SELECT id FROM $table WHERE id = ?");
                $exists->execute([$id]);
                if ($exists->fetchColumn() === false) { throw new InvalidArgumentException('Record not found.'); }
            }
        } else {
            throw new InvalidArgumentException('Choose a valid action.');
        }
        redirect_to('/admin/onboarding.php?type=' . $type . '&saved=1');
    } catch (PDOException $exception) {
        error_log('Onboarding management failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'The record could not be saved. Please try again.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $edit = is_string($_GET['edit']) ? filter_var($_GET['edit'], FILTER_VALIDATE_INT) : false;
    if ($edit === false || $edit < 1) { render_error_page(404); }
    $query = $connection->prepare("SELECT * FROM $table WHERE id = ?");
    $query->execute([$edit]);
    $form = $query->fetch();
    if ($form === false) { render_error_page(404); }
}
$rows = $connection->query("SELECT * FROM $table ORDER BY " . $definition['order'])->fetchAll();
$pageTitle = $definition['title'];
$activeNav = 'onboarding';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">ONBOARDING</p><h1><?= e($definition['title']) ?></h1></div></section>
<p><a href="<?= e(app_url('/admin/onboarding.php?type=checklist')) ?>">Checklist</a> · <a href="<?= e(app_url('/admin/onboarding.php?type=contacts')) ?>">Contacts</a> · <a href="<?= e(app_url('/admin/onboarding.php?type=holidays')) ?>">Holidays</a></p>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?><?php if (isset($_GET['saved'])): ?><p class="notice notice-success">Changes saved.</p><?php endif; ?>
<section class="panel"><h2><?= $form['id'] === '' ? 'Add' : 'Edit' ?> <?= e($definition['title']) ?></h2><form method="post" class="admin-form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= e((string) $form['id']) ?>">
<?php foreach ($definition['fields'] as $field => [$label, $kind, $limit, $required]): ?><label><?= e($label) ?><?php if ($kind === 'textarea'): ?><textarea name="<?= e($field) ?>"><?= e((string) $form[$field]) ?></textarea><?php else: ?><input type="<?= e($kind) ?>" name="<?= e($field) ?>" value="<?= e((string) $form[$field]) ?>" <?= $limit > 0 ? 'maxlength="' . (int) $limit . '"' : '' ?> <?= $required ? 'required' : '' ?>><?php endif; ?></label><?php endforeach; ?>
<div class="admin-form-actions"><button class="button button-primary">Save</button><?php if ($form['id'] !== ''): ?> <a href="<?= e(app_url('/admin/onboarding.php?type=' . $type)) ?>">Cancel</a><?php endif; ?></div></form></section>
<section class="admin-list-section"><h2>Existing <?= e($definition['title']) ?></h2><?php if ($rows === []): ?><p class="empty-state">No records yet.</p><?php else: ?><div class="table-wrap"><table><thead><tr><?php foreach ($definition['fields'] as $rules): ?><th><?= e($rules[0]) ?></th><?php endforeach; ?><th>Actions</th></tr></thead><tbody><?php foreach ($rows as $row): ?><tr><?php foreach ($definition['fields'] as $field => $rules): ?><td><?= e((string) $row[$field]) ?></td><?php endforeach; ?><td><a class="table-action" href="<?= e(app_url('/admin/onboarding.php?type=' . $type . '&edit=' . (int) $row['id'])) ?>">Edit</a><form method="post" class="inline-form" data-confirm="<?= $type === 'checklist' ? 'Delete this item and its completion records?' : 'Delete this record?' ?>"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $row['id'] ?>"><button class="table-action table-action-danger">Delete</button></form></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
