<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');
$connection = db();
$error = '';
$read = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};
$positiveId = static function (string $value): int {
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if ($id === false || $id < 1) {
        throw new InvalidArgumentException('Choose a valid record.');
    }
    return $id;
};
$pathValue = $_SERVER['REQUEST_METHOD'] === 'POST' ? $read('path_id') : ($_GET['id'] ?? '');
$pathId = is_string($pathValue) && $pathValue !== '' ? filter_var($pathValue, FILTER_VALIDATE_INT) : null;
if ($pathId === false || ($pathId !== null && $pathId < 1)) {
    render_error_page(404);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $read('action');
        if ($action === 'save_path') {
            $name = $read('name');
            $department = $read('department');
            $description = $read('description');
            if ($name === '' || strlen($name) > 200 || strlen($department) > 120 || strlen($description) > 10000) {
                throw new InvalidArgumentException('Enter a name of at most 200 bytes, department of at most 120 bytes, and description of at most 10000 bytes.');
            }
            if ($pathId === null) {
                $query = $connection->prepare('INSERT INTO paths (name, department, description) VALUES (?, ?, ?)');
                $query->execute([$name, $department === '' ? null : $department, $description]);
                $pathId = (int) $connection->lastInsertId();
            } else {
                $query = $connection->prepare('UPDATE paths SET name = ?, department = ?, description = ? WHERE id = ?');
                $query->execute([$name, $department === '' ? null : $department, $description, $pathId]);
            }
            redirect_to('/admin/paths.php?id=' . $pathId . '&saved=1');
        }
        if ($pathId === null) {
            throw new InvalidArgumentException('Choose a learning path.');
        }
        $connection->beginTransaction();
        $check = $connection->prepare('SELECT id FROM paths WHERE id = ? FOR UPDATE');
        $check->execute([$pathId]);
        if ($check->fetchColumn() === false) {
            throw new InvalidArgumentException('Learning path not found.');
        }
        if ($action === 'add_lesson' || $action === 'set_day') {
            $dayText = $read('suggested_day');
            $day = $dayText === '' ? null : filter_var($dayText, FILTER_VALIDATE_INT);
            if ($day === false || ($day !== null && ($day < 1 || $day > 100000))) {
                throw new InvalidArgumentException('Suggested day must be between 1 and 100000, or blank.');
            }
            if ($action === 'add_lesson') {
                $lessonId = $positiveId($read('lesson_id'));
                $query = $connection->prepare('SELECT id FROM lessons WHERE id = ?');
                $query->execute([$lessonId]);
                if ($query->fetchColumn() === false) {
                    throw new InvalidArgumentException('Lesson not found.');
                }
                $query = $connection->prepare('INSERT INTO path_items (path_id, lesson_id, sort_order, suggested_day) SELECT ?, ?, COALESCE(MAX(sort_order), 0) + 1, ? FROM path_items WHERE path_id = ?');
                $query->execute([$pathId, $lessonId, $day, $pathId]);
            } else {
                $query = $connection->prepare('UPDATE path_items SET suggested_day = ? WHERE id = ? AND path_id = ?');
                $itemId = $positiveId($read('item_id'));
                $query->execute([$day, $itemId, $pathId]);
                if ($query->rowCount() === 0) {
                    $query = $connection->prepare('SELECT id FROM path_items WHERE id = ? AND path_id = ?');
                    $query->execute([$itemId, $pathId]);
                    if ($query->fetchColumn() === false) {
                        throw new InvalidArgumentException('Path lesson not found.');
                    }
                }
            }
        } elseif ($action === 'remove_lesson') {
            $query = $connection->prepare('DELETE FROM path_items WHERE id = ? AND path_id = ?');
            $query->execute([$positiveId($read('item_id')), $pathId]);
            if ($query->rowCount() !== 1) {
                throw new InvalidArgumentException('Path lesson not found.');
            }
        } elseif ($action === 'move') {
            $itemId = $positiveId($read('item_id'));
            $direction = $read('direction');
            if (!in_array($direction, ['up', 'down'], true)) {
                throw new InvalidArgumentException('Choose a valid direction.');
            }
            $query = $connection->prepare('SELECT id FROM path_items WHERE path_id = ? ORDER BY sort_order, id');
            $query->execute([$pathId]);
            $ids = array_map('intval', $query->fetchAll(PDO::FETCH_COLUMN));
            $index = array_search($itemId, $ids, true);
            if ($index === false) {
                throw new InvalidArgumentException('Path lesson not found.');
            }
            $neighbor = $direction === 'up' ? $index - 1 : $index + 1;
            if (isset($ids[$neighbor])) {
                [$ids[$index], $ids[$neighbor]] = [$ids[$neighbor], $ids[$index]];
                $update = $connection->prepare('UPDATE path_items SET sort_order = ? WHERE id = ? AND path_id = ?');
                foreach ($ids as $position => $id) {
                    $update->execute([$position + 1, $id, $pathId]);
                }
            }
        } elseif ($action === 'assign') {
            $selected = $_POST['users'] ?? null;
            if (!is_array($selected) || count($selected) < 1 || count($selected) > 500) {
                throw new InvalidArgumentException('Select between 1 and 500 employees.');
            }
            $checkUser = $connection->prepare("SELECT id FROM users WHERE id = ? AND role = 'employee'");
            $assign = $connection->prepare('INSERT INTO user_paths (user_id, path_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id)');
            foreach ($selected as $value) {
                if (!is_string($value)) {
                    throw new InvalidArgumentException('Choose valid employees.');
                }
                $userId = $positiveId($value);
                $checkUser->execute([$userId]);
                if ($checkUser->fetchColumn() === false) {
                    throw new InvalidArgumentException('An employee was not found.');
                }
                $assign->execute([$userId, $pathId]);
            }
        } else {
            throw new InvalidArgumentException('Choose a valid action.');
        }
        $connection->commit();
        redirect_to('/admin/paths.php?id=' . $pathId . '&saved=1');
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        error_log('Learning path operation failed: ' . $exception->getMessage());
        $isConstraintError = pdo_sql_state($exception) === '23000';
        http_response_code($isConstraintError ? 400 : 500);
        $error = $isConstraintError ? 'That lesson is already in this path, or a related record no longer exists.' : 'The learning path could not be saved.';
    } catch (InvalidArgumentException | RuntimeException $exception) {
        if ($connection->inTransaction()) { $connection->rollBack(); }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$paths = $connection->query('SELECT id, name, department FROM paths ORDER BY name, id')->fetchAll();
$path = null;
$items = [];
$lessons = [];
$employees = [];
if ($pathId !== null) {
    $query = $connection->prepare('SELECT * FROM paths WHERE id = ?');
    $query->execute([$pathId]);
    $path = $query->fetch();
    if ($path === false) { render_error_page(404); }
    $query = $connection->prepare('SELECT pi.id, pi.suggested_day, l.title, l.is_published FROM path_items pi JOIN lessons l ON l.id = pi.lesson_id WHERE pi.path_id = ? ORDER BY pi.sort_order, pi.id');
    $query->execute([$pathId]);
    $items = $query->fetchAll();
    $lessons = $connection->query('SELECT id, title, is_published FROM lessons ORDER BY title, id')->fetchAll();
    $query = $connection->prepare("SELECT u.id, u.name, u.emp_code, u.department, u.is_active, up.user_id AS assigned FROM users u LEFT JOIN user_paths up ON up.user_id = u.id AND up.path_id = ? WHERE u.role = 'employee' ORDER BY u.name, u.id");
    $query->execute([$pathId]);
    $employees = $query->fetchAll();
}
$pathForm = $path ?? ['name' => '', 'department' => '', 'description' => ''];
if ($error !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && $read('action') === 'save_path') {
    foreach (['name', 'department', 'description'] as $field) {
        $pathForm[$field] = $read($field);
    }
}
$pageTitle = 'Learning paths';
$activeNav = 'paths';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">ONBOARDING</p><h1>Learning paths</h1><p class="muted">New employees receive paths for everyone and paths matching their department.</p></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="notice notice-success">Learning path updated.</p><?php endif; ?>
<section class="panel"><h2><?= $path === null ? 'Create a path' : 'Edit path' ?></h2>
<form method="post" class="admin-form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_path"><input type="hidden" name="path_id" value="<?= $pathId === null ? '' : (int) $pathId ?>">
<label>Name<input name="name" required maxlength="200" value="<?= e((string) $pathForm['name']) ?>"></label><label>Department (blank for everyone)<input name="department" maxlength="120" value="<?= e((string) $pathForm['department']) ?>"></label><label class="form-field-wide">Description<textarea name="description"><?= e((string) $pathForm['description']) ?></textarea></label><div class="admin-form-actions"><button class="button button-primary">Save path</button><?php if ($path !== null): ?> <a href="<?= e(app_url('/admin/paths.php')) ?>">Create another</a><?php endif; ?></div></form></section>
<section class="learner-section"><h2>All paths</h2><?php if ($paths === []): ?><p class="empty-state">No paths yet.</p><?php else: ?><div class="card-grid"><?php foreach ($paths as $entry): ?><a class="category-link" href="<?= e(app_url('/admin/paths.php?id=' . (int) $entry['id'])) ?>"><?= e((string) $entry['name']) ?> · <?= e((string) ($entry['department'] ?: 'Everyone')) ?></a><?php endforeach; ?></div><?php endif; ?></section>
<?php if ($path !== null): ?>
<section class="panel"><h2>Ordered lessons</h2><p class="muted">Unpublished lessons are hidden from employees until published.</p>
<?php if ($items === []): ?><p class="empty-state">No lessons in this path.</p><?php else: ?><div class="table-wrap"><table><thead><tr><th>Lesson</th><th>Suggested day</th><th>Order</th></tr></thead><tbody><?php foreach ($items as $item): ?><tr><td><?= e((string) $item['title']) ?><?= (int) $item['is_published'] === 1 ? '' : ' (unpublished)' ?></td><td><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="set_day"><input type="hidden" name="path_id" value="<?= (int) $pathId ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><input aria-label="Suggested day" name="suggested_day" type="number" min="1" max="100000" value="<?= e((string) $item['suggested_day']) ?>"><button class="table-action">Update</button></form></td><td><?php foreach (['up' => '↑', 'down' => '↓', 'remove_lesson' => 'Remove'] as $action => $label): ?><form method="post" class="inline-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="path_id" value="<?= (int) $pathId ?>"><input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="<?= $action === 'remove_lesson' ? 'remove_lesson' : 'move' ?>"><input type="hidden" name="direction" value="<?= e($action) ?>"><button class="table-action" aria-label="<?= e($label . ' ' . $item['title']) ?>"><?= e($label) ?></button></form><?php endforeach; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
<?php if ($lessons !== []): ?><form method="post" class="admin-form-grid"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="add_lesson"><input type="hidden" name="path_id" value="<?= (int) $pathId ?>"><label>Lesson<select name="lesson_id" required><?php foreach ($lessons as $lesson): ?><option value="<?= (int) $lesson['id'] ?>"><?= e((string) $lesson['title']) ?><?= (int) $lesson['is_published'] === 1 ? '' : ' (unpublished)' ?></option><?php endforeach; ?></select></label><label>Suggested day (optional)<input type="number" min="1" max="100000" name="suggested_day"></label><button class="button button-primary">Add lesson</button></form><?php else: ?><p class="muted">Create a lesson before adding it to a path.</p><?php endif; ?></section>
<section class="panel"><h2>Assign to employees</h2><p class="muted">Previously assigned employees remain assigned. Select up to 500 employees.</p><?php if ($employees === []): ?><p class="empty-state">No employees available.</p><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="assign"><input type="hidden" name="path_id" value="<?= (int) $pathId ?>"><?php foreach ($employees as $employee): ?><label class="attachment-choice"><input type="checkbox" name="users[]" value="<?= (int) $employee['id'] ?>"> <?= e((string) $employee['name']) ?> (<?= e((string) $employee['emp_code']) ?><?= (int) $employee['is_active'] === 1 ? '' : ', inactive' ?><?= $employee['assigned'] !== null ? ', assigned' : '' ?>)</label><?php endforeach; ?><button class="button button-primary">Assign selected</button></form><?php endif; ?></section>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
