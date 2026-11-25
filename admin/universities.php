<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/lesson_media.php';
require_role('admin');

$error = '';
$form = ['id' => '', 'name' => '', 'notes' => ''];
$connection = null;
$newPdf = null;
$readPostString = static function (string $key): string {
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $readPostString('action');
        $universityId = filter_var($readPostString('university_id'), FILTER_VALIDATE_INT);

        if ($action === 'delete') {
            if ($universityId === false || $universityId < 1) {
                throw new InvalidArgumentException('Choose a valid university to delete.');
            }
            $connection = db();
            $connection->beginTransaction();
            $lookup = $connection->prepare('SELECT fee_structure_pdf FROM universities WHERE id = :id FOR UPDATE');
            $lookup->execute(['id' => $universityId]);
            $university = $lookup->fetch();
            if ($university === false) {
                throw new InvalidArgumentException('That university could not be found.');
            }
            $delete = $connection->prepare('DELETE FROM universities WHERE id = :id');
            $delete->execute(['id' => $universityId]);
            $connection->commit();
            delete_managed_pdf((string) ($university['fee_structure_pdf'] ?? ''));
            redirect_to('/admin/universities.php?deleted=1');
        }

        if ($action !== 'save') {
            throw new InvalidArgumentException('Choose a valid university action.');
        }

        $form['id'] = $readPostString('university_id');
        $form['name'] = $readPostString('name');
        $form['notes'] = $readPostString('notes');
        if ($form['name'] === '' || strlen($form['name']) > 200) {
            throw new InvalidArgumentException('Enter a university name of 200 characters or fewer.');
        }
        if (strlen($form['notes']) > 10000) {
            throw new InvalidArgumentException('University notes must be 10,000 characters or fewer.');
        }
        if ($form['id'] !== '' && ($universityId === false || $universityId < 1)) {
            throw new InvalidArgumentException('The university ID is invalid. Reload the page and try again.');
        }

        $removePdf = $readPostString('remove_fee_pdf');
        if (!in_array($removePdf, ['', '0', '1'], true)) {
            throw new InvalidArgumentException('The fee PDF removal choice is invalid.');
        }
        if (isset($_FILES['fee_pdf'])) {
            if (!is_array($_FILES['fee_pdf'])) {
                throw new InvalidArgumentException('The fee PDF upload was not received correctly.');
            }
            $newPdf = store_single_pdf_upload($_FILES['fee_pdf'], __DIR__ . '/../uploads/pdfs');
        }

        $connection = db();
        $connection->beginTransaction();
        $oldPdfPath = null;
        if ($universityId !== false && $universityId > 0) {
            $lookup = $connection->prepare('SELECT fee_structure_pdf FROM universities WHERE id = :id FOR UPDATE');
            $lookup->execute(['id' => $universityId]);
            $existing = $lookup->fetch();
            if ($existing === false) {
                throw new InvalidArgumentException('That university could not be found.');
            }
            $oldPdfPath = $existing['fee_structure_pdf'];
        }

        $pdfPath = $newPdf !== null
            ? $newPdf['file_path']
            : ($removePdf === '1' ? null : $oldPdfPath);

        if ($universityId !== false && $universityId > 0) {
            $save = $connection->prepare(
                'UPDATE universities
                 SET name = :name, notes = :notes, fee_structure_pdf = :fee_structure_pdf
                 WHERE id = :id'
            );
            $save->execute([
                'name' => $form['name'],
                'notes' => $form['notes'] === '' ? null : $form['notes'],
                'fee_structure_pdf' => $pdfPath,
                'id' => $universityId,
            ]);
        } else {
            $save = $connection->prepare(
                'INSERT INTO universities (name, notes, fee_structure_pdf)
                 VALUES (:name, :notes, :fee_structure_pdf)'
            );
            $save->execute([
                'name' => $form['name'],
                'notes' => $form['notes'] === '' ? null : $form['notes'],
                'fee_structure_pdf' => $pdfPath,
            ]);
        }
        $connection->commit();

        if ($oldPdfPath !== null && $oldPdfPath !== $pdfPath) {
            delete_managed_pdf((string) $oldPdfPath);
        }
        redirect_to('/admin/universities.php?saved=1');
    } catch (PDOException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        if ($newPdf !== null) {
            delete_managed_pdf($newPdf['file_path']);
        }
        error_log('Learning Hub university operation failed: ' . $exception->getMessage());
        $error = 'The university could not be saved. Check that its name is unique and try again.';
    } catch (InvalidArgumentException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        if ($newPdf !== null) {
            delete_managed_pdf($newPdf['file_path']);
        }
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        if ($newPdf !== null) {
            delete_managed_pdf($newPdf['file_path']);
        }
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.'
            ? 'Your form session expired. Reload the page and try again.'
            : $exception->getMessage();
    }
}

$editValue = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? $readPostString('university_id')
    : ($_GET['edit'] ?? '');
$editId = is_string($editValue) ? filter_var($editValue, FILTER_VALIDATE_INT) : false;
$editing = $editId !== false && $editId !== null && $editId > 0;
$editingUniversity = null;
if ($editing) {
    $lookup = db()->prepare(
        'SELECT id, name, notes, fee_structure_pdf FROM universities WHERE id = :id'
    );
    $lookup->execute(['id' => $editId]);
    $editingUniversity = $lookup->fetch();
    if ($editingUniversity === false) {
        render_error_page(404);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $form = [
            'id' => (string) $editingUniversity['id'],
            'name' => (string) $editingUniversity['name'],
            'notes' => (string) ($editingUniversity['notes'] ?? ''),
        ];
    }
}

$universities = db()->query(
    'SELECT u.id, u.name, u.notes, u.fee_structure_pdf, COUNT(uf.id) AS fee_count
     FROM universities u
     LEFT JOIN university_fees uf ON uf.university_id = u.id
     GROUP BY u.id, u.name, u.notes, u.fee_structure_pdf
     ORDER BY u.name'
)->fetchAll();
$saved = isset($_GET['saved']);
$deleted = isset($_GET['deleted']);
$pageTitle = 'Manage universities';
$activeNav = 'universities';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">FEE CATALOG</p><h1>Universities</h1><p class="muted">Maintain university details and the official fee-structure PDF.</p></div>
    <a class="button button-secondary" href="<?= e(app_url('/admin/university-fees.php')) ?>">Manage fee rows</a>
</section>

<?php if ($saved): ?><p class="notice notice-success">University details saved.</p><?php endif; ?>
<?php if ($deleted): ?><p class="notice notice-success">University and its fee rows deleted.</p><?php endif; ?>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>

<section class="panel admin-form-panel" aria-labelledby="university-form-title">
    <h2 id="university-form-title"><?= $editing ? 'Edit university' : 'Add university' ?></h2>
    <form class="stack-form" method="post" enctype="multipart/form-data" action="<?= e(app_url('/admin/universities.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="university_id" value="<?= e($form['id']) ?>">
        <div class="admin-form-grid">
            <label>University name
                <input type="text" name="name" maxlength="200" required value="<?= e($form['name']) ?>">
            </label>
            <label class="file-label">Fee-structure PDF <span class="form-help">Optional. PDF only, maximum 10 MB.</span>
                <input type="file" name="fee_pdf" accept="application/pdf,.pdf">
            </label>
            <label class="form-field-wide">Notes
                <textarea name="notes" rows="3" maxlength="10000"><?= e($form['notes']) ?></textarea>
            </label>
            <?php if ($editing && $editingUniversity !== null && is_string($editingUniversity['fee_structure_pdf'])
                && preg_match('~\Auploads/pdfs/[a-f0-9]{32}\.pdf\z~', $editingUniversity['fee_structure_pdf']) === 1): ?>
                <div class="form-field-wide">
                    <a href="<?= e(app_url('/admin/download.php?' . http_build_query(['type' => 'university', 'id' => (int) $editingUniversity['id']]))) ?>">Download current fee-structure PDF</a>
                    <label class="attachment-choice">
                        <input type="checkbox" name="remove_fee_pdf" value="1">
                        Remove current PDF (a newly uploaded PDF will replace it)
                    </label>
                </div>
            <?php endif; ?>
        </div>
        <div class="admin-form-actions">
            <button class="button button-primary" type="submit"><?= $editing ? 'Save university' : 'Add university' ?></button>
            <?php if ($editing): ?><a class="button button-secondary" href="<?= e(app_url('/admin/universities.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</section>

<section class="admin-list-section" aria-labelledby="universities-list-title">
    <h2 id="universities-list-title">University list</h2>
    <?php if ($universities === []): ?>
        <p class="empty-state">No universities have been added yet.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>University</th><th>Fee rows</th><th>Fee PDF</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($universities as $university): ?>
                    <tr>
                        <td><?= e($university['name']) ?><?php if ($university['notes']): ?><span class="table-subtext"><?= e($university['notes']) ?></span><?php endif; ?></td>
                        <td><?= (int) $university['fee_count'] ?></td>
                        <td>
                            <?php if (is_string($university['fee_structure_pdf'])
                                && preg_match('~\Auploads/pdfs/[a-f0-9]{32}\.pdf\z~', $university['fee_structure_pdf']) === 1): ?>
                                <a href="<?= e(app_url('/admin/download.php?' . http_build_query(['type' => 'university', 'id' => (int) $university['id']]))) ?>">Download PDF</a>
                            <?php else: ?>
                                <span class="muted">Not uploaded</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="admin-row-actions">
                                <a class="table-action" href="<?= e(app_url('/admin/universities.php?edit=' . (int) $university['id'])) ?>">Edit</a>
                                <a class="table-action" href="<?= e(app_url('/admin/university-fees.php?university_id=' . (int) $university['id'])) ?>">Manage fees</a>
                                <form class="inline-form" method="post" action="<?= e(app_url('/admin/universities.php')) ?>" data-confirm="Delete this university and all of its fee rows?">
                                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="university_id" value="<?= (int) $university['id'] ?>">
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
