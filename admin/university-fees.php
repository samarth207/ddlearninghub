<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');

$error = '';
$form = [
    'id' => '',
    'university_id' => '',
    'program' => '',
    'duration' => '',
    'total_fee' => '',
    'per_semester_fee' => '',
    'notes' => '',
];
$connection = null;
$readPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};
$isValidFee = static function (string $value): bool {
    return $value === '' || preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/', $value) === 1;
};
$listUrl = static function (int $universityId, string $program = ''): string {
    $query = [];
    if ($universityId > 0) {
        $query['university_id'] = $universityId;
    }
    if ($program !== '') {
        $query['program'] = $program;
    }
    return '/admin/university-fees.php' . ($query === [] ? '' : '?' . http_build_query($query));
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $readPostString('action');

        if ($action === 'filter') {
            $universityValue = $readPostString('filter_university_id');
            $universityId = $universityValue === '' ? 0 : filter_var($universityValue, FILTER_VALIDATE_INT);
            $program = $readPostString('filter_program');
            if ($universityId === false || $universityId < 0 || strlen($program) > 100) {
                throw new InvalidArgumentException('Choose a valid fee-list filter.');
            }
            redirect_to($listUrl((int) $universityId, $program));
        }

        if ($action === 'delete') {
            $feeId = filter_var($readPostString('fee_id'), FILTER_VALIDATE_INT);
            if ($feeId === false || $feeId < 1) {
                throw new InvalidArgumentException('Choose a valid fee row to delete.');
            }
            $connection = db();
            $delete = $connection->prepare('DELETE FROM university_fees WHERE id = :id');
            $delete->execute(['id' => $feeId]);
            if ($delete->rowCount() === 0) {
                throw new InvalidArgumentException('That fee row could not be found.');
            }
            $returnUniversity = filter_var($readPostString('filter_university_id'), FILTER_VALIDATE_INT);
            $returnUrl = $listUrl($returnUniversity !== false && $returnUniversity > 0 ? $returnUniversity : 0);
            redirect_to($returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'deleted=1');
        }

        if ($action === 'save') {
            $form['id'] = $readPostString('fee_id');
            $form['university_id'] = $readPostString('university_id');
            $form['program'] = $readPostString('program');
            $form['duration'] = $readPostString('duration');
            $form['total_fee'] = $readPostString('total_fee');
            $form['per_semester_fee'] = $readPostString('per_semester_fee');
            $form['notes'] = $readPostString('notes');

            $universityId = filter_var($form['university_id'], FILTER_VALIDATE_INT);
            $feeId = filter_var($form['id'], FILTER_VALIDATE_INT);
            if ($universityId === false || $universityId < 1) {
                throw new InvalidArgumentException('Choose a university for this fee row.');
            }
            if ($form['program'] === '' || strlen($form['program']) > 200) {
                throw new InvalidArgumentException('Enter a program name of 200 characters or fewer.');
            }
            if ($form['duration'] === '' || strlen($form['duration']) > 100) {
                throw new InvalidArgumentException('Enter a duration of 100 characters or fewer.');
            }
            if (!$isValidFee($form['total_fee']) || !$isValidFee($form['per_semester_fee'])) {
                throw new InvalidArgumentException('Fees must be non-negative amounts with up to 10 digits and 2 decimals.');
            }
            if (strlen($form['notes']) > 10000) {
                throw new InvalidArgumentException('Fee notes must be 10,000 characters or fewer.');
            }
            if ($form['id'] !== '' && ($feeId === false || $feeId < 1)) {
                throw new InvalidArgumentException('The fee row ID is invalid. Reload the page and try again.');
            }

            $connection = db();
            $universityCheck = $connection->prepare('SELECT id FROM universities WHERE id = :id');
            $universityCheck->execute(['id' => $universityId]);
            if ($universityCheck->fetchColumn() === false) {
                throw new InvalidArgumentException('The selected university no longer exists.');
            }
            if ($feeId !== false && $feeId > 0) {
                $save = $connection->prepare(
                    'UPDATE university_fees
                     SET university_id = :university_id, program = :program, duration = :duration,
                         total_fee = :total_fee, per_semester_fee = :per_semester_fee, notes = :notes
                     WHERE id = :id'
                );
                $save->execute([
                    'university_id' => $universityId,
                    'program' => $form['program'],
                    'duration' => $form['duration'],
                    'total_fee' => $form['total_fee'] === '' ? null : $form['total_fee'],
                    'per_semester_fee' => $form['per_semester_fee'] === '' ? null : $form['per_semester_fee'],
                    'notes' => $form['notes'] === '' ? null : $form['notes'],
                    'id' => $feeId,
                ]);
                if ($save->rowCount() === 0) {
                    $exists = $connection->prepare('SELECT id FROM university_fees WHERE id = :id');
                    $exists->execute(['id' => $feeId]);
                    if ($exists->fetchColumn() === false) {
                        throw new InvalidArgumentException('That fee row could not be found.');
                    }
                }
            } else {
                $save = $connection->prepare(
                    'INSERT INTO university_fees
                        (university_id, program, duration, total_fee, per_semester_fee, notes)
                     VALUES
                        (:university_id, :program, :duration, :total_fee, :per_semester_fee, :notes)'
                );
                $save->execute([
                    'university_id' => $universityId,
                    'program' => $form['program'],
                    'duration' => $form['duration'],
                    'total_fee' => $form['total_fee'] === '' ? null : $form['total_fee'],
                    'per_semester_fee' => $form['per_semester_fee'] === '' ? null : $form['per_semester_fee'],
                    'notes' => $form['notes'] === '' ? null : $form['notes'],
                ]);
            }
            redirect_to('/admin/university-fees.php?university_id=' . $universityId . '&saved=1');
        }

        if ($action === 'import') {
            $upload = $_FILES['csv_file'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || !isset($upload['tmp_name']) || !is_string($upload['tmp_name'])
                || !is_uploaded_file($upload['tmp_name'])) {
                throw new InvalidArgumentException('Choose a valid CSV file to import.');
            }
            if (!isset($upload['name']) || !is_string($upload['name'])
                || strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION)) !== 'csv') {
                throw new InvalidArgumentException('The import file must have a .csv extension.');
            }
            $fileSize = filesize($upload['tmp_name']);
            if ($fileSize === false || $fileSize > 2 * 1024 * 1024) {
                throw new InvalidArgumentException('CSV files must be 2 MB or smaller.');
            }
            $handle = fopen($upload['tmp_name'], 'rb');
            if ($handle === false) {
                throw new RuntimeException('The uploaded CSV could not be opened.');
            }

            $imported = 0;
            $issues = [];
            try {
                $header = fgetcsv($handle, 0, ',', '"', '');
                if ($header === false) {
                    throw new InvalidArgumentException('The CSV is empty.');
                }
                $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
                $header = array_map(static fn ($column): string => strtolower(trim((string) $column)), $header);
                $requiredColumns = ['university', 'program', 'duration', 'total_fee', 'per_semester_fee', 'notes'];
                if (count($header) !== count($requiredColumns)
                    || count(array_unique($header)) !== count($requiredColumns)
                    || array_diff($requiredColumns, $header) !== []) {
                    throw new InvalidArgumentException(
                        'CSV headers must be university, program, duration, total_fee, per_semester_fee, notes.'
                    );
                }

                $connection = db();
                $connection->beginTransaction();
                $universityLookup = $connection->prepare('SELECT id FROM universities WHERE name = :name LIMIT 1');
                $insert = $connection->prepare(
                    'INSERT INTO university_fees
                        (university_id, program, duration, total_fee, per_semester_fee, notes)
                     VALUES
                        (:university_id, :program, :duration, :total_fee, :per_semester_fee, :notes)'
                );
                $dataRows = 0;
                while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                    $dataRows++;
                    $lineNumber = $dataRows + 1;
                    if ($dataRows > 500) {
                        $issues[] = 'Import stopped after 500 data rows.';
                        break;
                    }
                    if (count($row) === 1 && ($row[0] === null || trim((string) $row[0]) === '')) {
                        continue;
                    }
                    if (count($row) !== count($header)) {
                        if (count($issues) < 50) {
                            $issues[] = 'Row ' . $lineNumber . ': expected six columns.';
                        }
                        continue;
                    }
                    $values = array_combine(
                        $header,
                        array_map(static fn ($value): string => trim((string) $value), $row)
                    );
                    if (implode('', $values) === '') {
                        continue;
                    }
                    if ($values['university'] === '' || strlen($values['university']) > 200
                        || $values['program'] === '' || strlen($values['program']) > 200
                        || $values['duration'] === '' || strlen($values['duration']) > 100
                        || strlen($values['notes']) > 10000
                        || !$isValidFee($values['total_fee']) || !$isValidFee($values['per_semester_fee'])) {
                        if (count($issues) < 50) {
                            $issues[] = 'Row ' . $lineNumber . ': invalid university, program, duration, fee, or notes.';
                        }
                        continue;
                    }

                    $universityLookup->execute(['name' => $values['university']]);
                    $matchedUniversityId = $universityLookup->fetchColumn();
                    if ($matchedUniversityId === false) {
                        if (count($issues) < 50) {
                            $issues[] = 'Row ' . $lineNumber . ': university "' . $values['university'] . '" was not found.';
                        }
                        continue;
                    }
                    $insert->execute([
                        'university_id' => (int) $matchedUniversityId,
                        'program' => $values['program'],
                        'duration' => $values['duration'],
                        'total_fee' => $values['total_fee'] === '' ? null : $values['total_fee'],
                        'per_semester_fee' => $values['per_semester_fee'] === '' ? null : $values['per_semester_fee'],
                        'notes' => $values['notes'] === '' ? null : $values['notes'],
                    ]);
                    $imported++;
                }
                $connection->commit();
            } finally {
                fclose($handle);
            }

            $_SESSION['fee_import_flash'] = ['imported' => $imported, 'issues' => $issues];
            redirect_to('/admin/university-fees.php?imported=1');
        }

        throw new InvalidArgumentException('Choose a valid fee-row action.');
    } catch (PDOException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Learning Hub university fee operation failed: ' . $exception->getMessage());
        $error = 'The fee row could not be saved. Check the selected university and try again.';
    } catch (InvalidArgumentException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
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

$universityFilterValue = $_GET['university_id'] ?? '';
$universityFilter = is_string($universityFilterValue)
    ? filter_var($universityFilterValue, FILTER_VALIDATE_INT)
    : false;
$universityFilter = $universityFilter !== false && $universityFilter > 0 ? (int) $universityFilter : 0;
$programFilterValue = $_GET['program'] ?? '';
$programFilter = is_string($programFilterValue) ? trim($programFilterValue) : '';
if (strlen($programFilter) > 100) {
    $programFilter = substr($programFilter, 0, 100);
}

$editValue = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $readPostString('fee_id')
    : ($_GET['edit'] ?? '');
$editId = is_string($editValue) ? filter_var($editValue, FILTER_VALIDATE_INT) : false;
$editing = $editId !== false && $editId !== null && $editId > 0;
if ($editing) {
    $editQuery = db()->prepare(
        'SELECT id, university_id, program, duration, total_fee, per_semester_fee, notes
         FROM university_fees WHERE id = :id'
    );
    $editQuery->execute(['id' => $editId]);
    $feeToEdit = $editQuery->fetch();
    if ($feeToEdit === false) {
        render_error_page(404);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $form = [
            'id' => (string) $feeToEdit['id'],
            'university_id' => (string) $feeToEdit['university_id'],
            'program' => (string) $feeToEdit['program'],
            'duration' => (string) $feeToEdit['duration'],
            'total_fee' => $feeToEdit['total_fee'] === null ? '' : (string) $feeToEdit['total_fee'],
            'per_semester_fee' => $feeToEdit['per_semester_fee'] === null ? '' : (string) $feeToEdit['per_semester_fee'],
            'notes' => (string) ($feeToEdit['notes'] ?? ''),
        ];
    }
}

$universities = db()->query('SELECT id, name FROM universities ORDER BY name')->fetchAll();
$conditions = [];
$parameters = [];
if ($universityFilter > 0) {
    $conditions[] = 'uf.university_id = :university_id';
    $parameters['university_id'] = $universityFilter;
}
if ($programFilter !== '') {
    $conditions[] = 'uf.program LIKE :program';
    $parameters['program'] = '%' . $programFilter . '%';
}
$feeSql = 'SELECT uf.id, uf.university_id, uf.program, uf.duration, uf.total_fee,
                  uf.per_semester_fee, uf.notes, uf.updated_at, u.name AS university_name
           FROM university_fees uf
           INNER JOIN universities u ON u.id = uf.university_id'
    . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
    . ' ORDER BY u.name, uf.program, uf.id DESC';
$feeQuery = db()->prepare($feeSql);
$feeQuery->execute($parameters);
$feeRows = $feeQuery->fetchAll();
$importFlash = $_SESSION['fee_import_flash'] ?? null;
unset($_SESSION['fee_import_flash']);
$formatFee = static fn ($value): string => $value === null ? 'Not set' : '₹' . number_format((float) $value, 2);
$pageTitle = 'Manage university fees';
$activeNav = 'fees';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">FEE CATALOG</p><h1>Fee rows</h1><p class="muted">Add and maintain program fees, or import a CSV.</p></div>
    <a class="button button-secondary" href="<?= e(app_url('/admin/universities.php')) ?>">Manage universities</a>
</section>

<?php if (isset($_GET['saved'])): ?><p class="notice notice-success">Fee row saved.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="notice notice-success">Fee row deleted.</p><?php endif; ?>
<?php if (isset($_GET['imported']) && is_array($importFlash)): ?><p class="notice notice-success"><?= (int) $importFlash['imported'] ?> fee row(s) imported.</p><?php endif; ?>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (is_array($importFlash) && $importFlash['issues'] !== []): ?>
    <div class="notice notice-info"><strong>Import needs attention</strong>
        <ul class="fees-import-issues"><?php foreach ($importFlash['issues'] as $issue): ?><li><?= e($issue) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<section class="panel admin-form-panel" aria-labelledby="fee-form-title">
    <h2 id="fee-form-title"><?= $editing ? 'Edit fee row' : 'Add fee row' ?></h2>
    <?php if ($universities === []): ?>
        <p class="empty-state">Add a university before creating fee rows.</p>
    <?php else: ?>
        <form class="stack-form" method="post" action="<?= e(app_url('/admin/university-fees.php')) ?>">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="fee_id" value="<?= e($form['id']) ?>">
            <div class="admin-form-grid">
                <label>University
                    <select name="university_id" required>
                        <option value="">Choose a university</option>
                        <?php foreach ($universities as $university): ?>
                            <option value="<?= (int) $university['id'] ?>" <?= $form['university_id'] === (string) $university['id'] ? 'selected' : '' ?>><?= e($university['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Program
                    <input type="text" name="program" maxlength="200" required value="<?= e($form['program']) ?>" placeholder="e.g. MBA">
                </label>
                <label>Duration
                    <input type="text" name="duration" maxlength="100" required value="<?= e($form['duration']) ?>" placeholder="e.g. 2 years">
                </label>
                <label>Total fee (INR)
                    <input type="number" name="total_fee" min="0" max="9999999999.99" step="0.01" value="<?= e($form['total_fee']) ?>">
                </label>
                <label>Per-semester fee (INR)
                    <input type="number" name="per_semester_fee" min="0" max="9999999999.99" step="0.01" value="<?= e($form['per_semester_fee']) ?>">
                </label>
                <label class="form-field-wide">Notes
                    <textarea name="notes" rows="3" maxlength="10000"><?= e($form['notes']) ?></textarea>
                </label>
            </div>
            <div class="admin-form-actions">
                <button class="button button-primary" type="submit"><?= $editing ? 'Save fee row' : 'Add fee row' ?></button>
                <?php if ($editing): ?><a class="button button-secondary" href="<?= e(app_url($listUrl($universityFilter))) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="panel admin-form-panel" aria-labelledby="fee-import-title">
    <h2 id="fee-import-title">Import fee rows from CSV</h2>
    <p class="fees-import-template">Use these exact headers, in any order: <code>university,program,duration,total_fee,per_semester_fee,notes</code>. Each university name must already exist. Leave either fee blank if unknown. Maximum 500 rows and 2 MB.</p>
    <form class="import-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('/admin/university-fees.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="import">
        <label class="file-label">CSV file
            <input type="file" name="csv_file" accept=".csv,text/csv" required>
        </label>
        <button class="button button-primary" type="submit">Import CSV</button>
    </form>
</section>

<section class="panel fees-search-panel" aria-labelledby="fee-filter-title">
    <h2 id="fee-filter-title">Find a fee row</h2>
    <form class="fees-filter-grid" method="post" action="<?= e(app_url('/admin/university-fees.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="filter">
        <label>University
            <select name="filter_university_id">
                <option value="">All universities</option>
                <?php foreach ($universities as $university): ?>
                    <option value="<?= (int) $university['id'] ?>" <?= $universityFilter === (int) $university['id'] ? 'selected' : '' ?>><?= e($university['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Program
            <input type="search" name="filter_program" maxlength="100" value="<?= e($programFilter) ?>" placeholder="Search program">
        </label>
        <div class="fees-filter-actions">
            <button class="button button-primary" type="submit">Filter</button>
            <a href="<?= e(app_url('/admin/university-fees.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<section class="admin-list-section" aria-labelledby="fee-list-title">
    <h2 id="fee-list-title">Fee rows (<?= count($feeRows) ?>)</h2>
    <?php if ($feeRows === []): ?>
        <p class="empty-state">No fee rows match these filters. Add a row or import a CSV.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>University / program</th><th>Duration</th><th>Total fee</th><th>Per semester</th><th>Last updated</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($feeRows as $fee): ?>
                    <?php $updatedAt = new DateTimeImmutable($fee['updated_at']); ?>
                    <tr>
                        <td><strong><?= e($fee['university_name']) ?></strong><span class="table-subtext"><?= e($fee['program']) ?></span></td>
                        <td><?= e($fee['duration']) ?></td>
                        <td><?= e($formatFee($fee['total_fee'])) ?></td>
                        <td><?= e($formatFee($fee['per_semester_fee'])) ?></td>
                        <td><?= e($updatedAt->format('d M Y')) ?></td>
                        <td>
                            <div class="admin-row-actions">
                                <a class="table-action" href="<?= e(app_url('/admin/university-fees.php?edit=' . (int) $fee['id'])) ?>">Edit</a>
                                <form class="inline-form" method="post" action="<?= e(app_url('/admin/university-fees.php')) ?>" data-confirm="Delete this fee row?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="fee_id" value="<?= (int) $fee['id'] ?>">
                                    <input type="hidden" name="filter_university_id" value="<?= $universityFilter ?>">
                                    <button class="table-action table-action-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
