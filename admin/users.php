<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/learning_paths.php';
require_role('admin');

$error = '';
$form = [
    'id' => '',
    'name' => '',
    'email' => '',
    'emp_code' => '',
    'department' => '',
    'joining_date' => '',
];
$connection = null;
$readPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $readPostString('action');

        if ($action === 'save') {
            $form['id'] = $readPostString('user_id');
            $form['name'] = $readPostString('name');
            $form['email'] = $readPostString('email');
            $form['emp_code'] = $readPostString('emp_code');
            $form['department'] = $readPostString('department');
            $form['joining_date'] = $readPostString('joining_date');

            if ($form['name'] === '' || strlen($form['name']) > 150) {
                throw new InvalidArgumentException('Enter an employee name of 150 characters or fewer.');
            }
            if (strlen($form['email']) > 254 || filter_var($form['email'], FILTER_VALIDATE_EMAIL) === false) {
                throw new InvalidArgumentException('Enter a valid employee email address.');
            }
            if ($form['emp_code'] === '' || strlen($form['emp_code']) > 50) {
                throw new InvalidArgumentException('Enter an employee code of 50 characters or fewer.');
            }
            if (strlen($form['department']) > 120) {
                throw new InvalidArgumentException('Department must be 120 characters or fewer.');
            }
            if ($form['joining_date'] !== '') {
                $date = DateTimeImmutable::createFromFormat('!Y-m-d', $form['joining_date']);
                if ($date === false || $date->format('Y-m-d') !== $form['joining_date'] || (int) $date->format('Y') < 1000) {
                    throw new InvalidArgumentException('Enter a valid joining date.');
                }
            }

            $userId = filter_var($form['id'], FILTER_VALIDATE_INT);
            if ($form['id'] !== '' && ($userId === false || $userId < 1)) {
                throw new InvalidArgumentException('The employee ID is invalid. Reload the page and try again.');
            }
            if ($userId !== false && $userId > 0) {
                $save = db()->prepare(
                    "UPDATE users
                     SET name = :name, email = :email, emp_code = :emp_code, department = :department, joining_date = :joining_date
                     WHERE id = :id AND role = 'employee'"
                );
                $save->execute([
                    'name' => $form['name'],
                    'email' => $form['email'],
                    'emp_code' => $form['emp_code'],
                    'department' => $form['department'] === '' ? null : $form['department'],
                    'joining_date' => $form['joining_date'] === '' ? null : $form['joining_date'],
                    'id' => $userId,
                ]);
                if ($save->rowCount() === 0) {
                    $exists = db()->prepare("SELECT id FROM users WHERE id = :id AND role = 'employee'");
                    $exists->execute(['id' => $userId]);
                    if ($exists->fetchColumn() === false) {
                        throw new InvalidArgumentException('That employee account could not be found.');
                    }
                }
                redirect_to('/admin/users.php?saved=1');
            }

            $temporaryPassword = bin2hex(random_bytes(12));
            $connection = db();
            $connection->beginTransaction();
            $insert = $connection->prepare(
                "INSERT INTO users (name, email, emp_code, password_hash, role, department, joining_date, is_active)
                 VALUES (:name, :email, :emp_code, :password_hash, 'employee', :department, :joining_date, 1)"
            );
            $insert->execute([
                'name' => $form['name'],
                'email' => $form['email'],
                'emp_code' => $form['emp_code'],
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'department' => $form['department'] === '' ? null : $form['department'],
                'joining_date' => $form['joining_date'] === '' ? null : $form['joining_date'],
            ]);
            assign_matching_paths($connection, (int) $connection->lastInsertId());
            $connection->commit();
            $_SESSION['admin_user_flash'] = [
                'message' => 'Employee added. Share this temporary password securely; it is shown only once and is not emailed.',
                'credentials' => [[
                    'name' => $form['name'],
                    'email' => $form['email'],
                    'temporary_password' => $temporaryPassword,
                ]],
                'issues' => [],
            ];
            redirect_to('/admin/users.php');
        }

        if ($action === 'deactivate') {
            $userId = filter_var($readPostString('user_id'), FILTER_VALIDATE_INT);
            if ($userId === false || $userId < 1) {
                throw new InvalidArgumentException('Choose a valid employee account.');
            }
            $deactivate = db()->prepare("UPDATE users SET is_active = 0 WHERE id = :id AND role = 'employee'");
            $deactivate->execute(['id' => $userId]);
            redirect_to('/admin/users.php?deactivated=1');
        }

        if ($action === 'reset_password') {
            $userId = filter_var($readPostString('user_id'), FILTER_VALIDATE_INT);
            if ($userId === false || $userId < 1) {
                throw new InvalidArgumentException('Choose a valid employee account.');
            }
            $employeeQuery = db()->prepare("SELECT id, name, email FROM users WHERE id = :id AND role = 'employee'");
            $employeeQuery->execute(['id' => $userId]);
            $employee = $employeeQuery->fetch();
            if ($employee === false) {
                throw new InvalidArgumentException('That employee account could not be found.');
            }
            $temporaryPassword = bin2hex(random_bytes(12));
            $reset = db()->prepare("UPDATE users SET password_hash = :password_hash WHERE id = :id AND role = 'employee'");
            $reset->execute([
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);
            $_SESSION['admin_user_flash'] = [
                'message' => 'Password reset. Share this temporary password securely; it is shown only once and is not emailed.',
                'credentials' => [[
                    'name' => $employee['name'],
                    'email' => $employee['email'],
                    'temporary_password' => $temporaryPassword,
                ]],
                'issues' => [],
            ];
            redirect_to('/admin/users.php');
        }

        if ($action === 'import') {
            $upload = $_FILES['csv_file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !isset($upload['tmp_name']) || !is_string($upload['tmp_name'])
                || !is_uploaded_file($upload['tmp_name'])) {
                throw new InvalidArgumentException('Choose a valid CSV file to import.');
            }
            if (strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION)) !== 'csv') {
                throw new InvalidArgumentException('The import file must have a .csv extension.');
            }
            if ((int) ($upload['size'] ?? 0) > 2 * 1024 * 1024) {
                throw new InvalidArgumentException('CSV files must be 2 MB or smaller.');
            }

            $handle = fopen($upload['tmp_name'], 'rb');
            if ($handle === false) {
                throw new RuntimeException('The uploaded CSV could not be opened.');
            }
            $header = fgetcsv($handle, 0, ',', '"', '');
            if ($header === false) {
                fclose($handle);
                throw new InvalidArgumentException('The CSV is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $header = array_map(static fn ($column): string => strtolower(trim((string) $column)), $header);
            $requiredColumns = ['name', 'email', 'emp_code', 'department'];
            if (count($header) !== count($requiredColumns)
                || count(array_unique($header)) !== count($requiredColumns)
                || array_diff($requiredColumns, $header) !== []) {
                fclose($handle);
                throw new InvalidArgumentException('CSV headers must be name, email, emp_code, department.');
            }

            $credentials = [];
            $issues = [];
            $lineNumber = 1;
            $connection = db();
            $connection->beginTransaction();
            try {
                $insert = $connection->prepare(
                    "INSERT INTO users (name, email, emp_code, password_hash, role, department, is_active)
                     VALUES (:name, :email, :emp_code, :password_hash, 'employee', :department, 1)"
                );
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $lineNumber++;
                    if ($lineNumber > 501) {
                        $issues[] = 'Import stopped after 500 data rows.';
                        break;
                    }
                    if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) {
                        continue;
                    }
                    if (count($row) !== count($header)) {
                        $issues[] = 'Row ' . $lineNumber . ': expected four columns.';
                        continue;
                    }
                    $values = array_combine($header, array_map(static fn ($value): string => trim((string) $value), $row));
                    if (implode('', $values) === '') {
                        continue;
                    }
                    if ($values['name'] === '' || strlen($values['name']) > 150
                        || strlen($values['email']) > 254
                        || filter_var($values['email'], FILTER_VALIDATE_EMAIL) === false
                        || $values['emp_code'] === '' || strlen($values['emp_code']) > 50
                        || strlen($values['department']) > 120) {
                        $issues[] = 'Row ' . $lineNumber . ': invalid name, email, employee code, or department.';
                        continue;
                    }

                    $temporaryPassword = bin2hex(random_bytes(12));
                    try {
                        $insert->execute([
                            'name' => $values['name'],
                            'email' => $values['email'],
                            'emp_code' => $values['emp_code'],
                            'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                            'department' => $values['department'] === '' ? null : $values['department'],
                        ]);
                    } catch (PDOException $exception) {
                        if (pdo_sql_state($exception) !== '23000') {
                            throw $exception;
                        }
                        $issues[] = 'Row ' . $lineNumber . ': email or employee code already exists.';
                        continue;
                    }
                    assign_matching_paths($connection, (int) $connection->lastInsertId());
                    $credentials[] = [
                        'name' => $values['name'],
                        'email' => $values['email'],
                        'temporary_password' => $temporaryPassword,
                    ];
                }
                $connection->commit();
            } catch (Throwable $exception) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                fclose($handle);
                throw $exception;
            }
            fclose($handle);
            $_SESSION['admin_user_flash'] = [
                'message' => count($credentials) . ' employee(s) imported. Share these temporary passwords securely; they are shown only once and are not emailed.',
                'credentials' => $credentials,
                'issues' => $issues,
            ];
            redirect_to('/admin/users.php');
        }

        throw new InvalidArgumentException('Choose a valid employee action.');
    } catch (PDOException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Learning Hub employee operation failed: ' . $exception->getMessage());
        $error = pdo_sql_state($exception) === '23000'
            ? 'That email address or employee code is already in use.'
            : 'The employee operation failed. Please try again.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.'
            ? 'Your form session expired. Reload the page and try again.'
            : $exception->getMessage();
    }
}

$flash = $_SESSION['admin_user_flash'] ?? null;
unset($_SESSION['admin_user_flash']);
if (is_array($flash) && !empty($flash['credentials'])) {
    header('Cache-Control: no-store, private');
}
$editValue = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $readPostString('user_id')
    : ($_GET['edit'] ?? '');
$editId = is_string($editValue) ? filter_var($editValue, FILTER_VALIDATE_INT) : false;
$editing = $editId !== false && $editId !== null && $editId > 0;
if ($editing) {
    $editQuery = db()->prepare(
        "SELECT id, name, email, emp_code, department, joining_date
         FROM users WHERE id = :id AND role = 'employee'"
    );
    $editQuery->execute(['id' => $editId]);
    $employeeToEdit = $editQuery->fetch();
    if ($employeeToEdit === false) {
        render_error_page(404);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $form = $employeeToEdit;
    }
}

$users = db()->query(
    "SELECT id, name, email, emp_code, department, is_active, created_at
     FROM users WHERE role = 'employee'
     ORDER BY created_at DESC, id DESC"
)->fetchAll();
$pageTitle = 'Users';
$activeNav = 'users';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">TEAM ACCESS</p><h1>Employees</h1><p class="muted">Add and maintain employee accounts. Passwords are stored as hashes.</p></div>
</section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="notice notice-success" role="status">Employee details saved.</p><?php endif; ?>
<?php if (isset($_GET['deactivated'])): ?><p class="notice notice-success" role="status">Employee deactivated.</p><?php endif; ?>
<?php if (is_array($flash)): ?>
    <p class="notice notice-success" role="status"><?= e((string) ($flash['message'] ?? '')) ?></p>
    <?php if (!empty($flash['credentials'])): ?>
        <div class="table-wrap temporary-passwords">
            <table><thead><tr><th>Employee</th><th>Email</th><th>Temporary password (copy now)</th></tr></thead><tbody>
            <?php foreach ($flash['credentials'] as $credential): ?>
                <tr><td><?= e($credential['name']) ?></td><td><?= e($credential['email']) ?></td><td><code><?= e($credential['temporary_password']) ?></code></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </div>
    <?php endif; ?>
    <?php foreach (($flash['issues'] ?? []) as $issue): ?><p class="notice notice-info"><?= e((string) $issue) ?></p><?php endforeach; ?>
<?php endif; ?>

<section class="panel admin-form-panel">
    <h2><?= $editing ? 'Edit employee' : 'Add employee' ?></h2>
    <form method="post" class="admin-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="user_id" value="<?= e((string) $form['id']) ?>">
        <label>Name<input name="name" required maxlength="150" value="<?= e((string) $form['name']) ?>"></label>
        <label>Email<input type="email" name="email" required maxlength="254" value="<?= e((string) $form['email']) ?>"></label>
        <label>Employee code<input name="emp_code" required maxlength="50" value="<?= e((string) $form['emp_code']) ?>"></label>
        <label>Department<input name="department" maxlength="120" value="<?= e((string) $form['department']) ?>"></label>
        <label>Joining date<input type="date" name="joining_date" value="<?= e((string) $form['joining_date']) ?>"></label>
        <div class="admin-form-actions form-field-wide">
            <button class="button button-primary" type="submit"><?= $editing ? 'Save employee' : 'Add employee' ?></button>
            <?php if ($editing): ?><a class="button button-secondary" href="<?= e(app_url('/admin/users.php')) ?>">Cancel</a><?php endif; ?>
            <?php if (!$editing): ?><small class="form-help">A random temporary password will be shown once after creation.</small><?php endif; ?>
        </div>
    </form>
</section>

<section class="panel admin-form-panel">
    <h2>Bulk import employees</h2>
    <p class="muted">Upload a CSV with the header row <code>name,email,emp_code,department</code>. Maximum size: 2 MB and 500 employees.</p>
    <form method="post" enctype="multipart/form-data" class="import-form">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="import">
        <label class="file-label">CSV file<input type="file" name="csv_file" accept=".csv,text/csv" required></label>
        <button class="button button-primary" type="submit">Import CSV</button>
    </form>
</section>

<section class="admin-list-section">
    <h2>Employee accounts</h2>
    <?php if ($users === []): ?>
        <p class="empty-state">No employee accounts yet. Add one above or import a CSV.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Name</th><th>Email</th><th>Code</th><th>Department</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($users as $employee): ?>
                    <tr>
                        <td><?= e($employee['name']) ?></td>
                        <td><?= e($employee['email']) ?></td>
                        <td><?= e($employee['emp_code']) ?></td>
                        <td><?= e((string) $employee['department']) ?></td>
                        <td><span class="training-badge <?= (int) $employee['is_active'] === 1 ? 'badge-completed' : 'badge-pending' ?>"><?= (int) $employee['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td class="admin-row-actions">
                            <a class="table-action" href="<?= e(app_url('/admin/users.php?edit=' . (int) $employee['id'])) ?>">Edit</a>
                            <form method="post" class="inline-form" data-confirm="Reset this employee's password?">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="user_id" value="<?= (int) $employee['id'] ?>">
                                <button class="table-action" type="submit">Reset password</button>
                            </form>
                            <?php if ((int) $employee['is_active'] === 1): ?>
                                <form method="post" class="inline-form" data-confirm="Deactivate this employee account?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="deactivate">
                                    <input type="hidden" name="user_id" value="<?= (int) $employee['id'] ?>">
                                    <button class="table-action table-action-danger" type="submit">Deactivate</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
