<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('employee');

$error = '';
$program = '';
$universityId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $programValue = $_POST['program'] ?? '';
        $universityValue = $_POST['university_id'] ?? '';
        if (!is_string($programValue) || !is_string($universityValue)) {
            throw new InvalidArgumentException('Choose valid fee filters.');
        }
        $program = trim($programValue);
        if (strlen($program) > 100) {
            throw new InvalidArgumentException('Program search must be 100 characters or fewer.');
        }
        if ($universityValue !== '') {
            $validatedUniversityId = filter_var($universityValue, FILTER_VALIDATE_INT);
            if ($validatedUniversityId === false || $validatedUniversityId < 1) {
                throw new InvalidArgumentException('Choose a valid university.');
            }
            $universityId = (int) $validatedUniversityId;
        }
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.'
            ? 'Your form session expired. Reload the page and try again.'
            : $exception->getMessage();
    }
}

$universities = db()->query(
    'SELECT DISTINCT u.id, u.name
     FROM universities u
     INNER JOIN university_fees uf ON uf.university_id = u.id
     ORDER BY u.name'
)->fetchAll();
$conditions = [];
$parameters = [];
if ($universityId > 0) {
    $conditions[] = 'uf.university_id = :university_id';
    $parameters['university_id'] = $universityId;
}
if ($program !== '') {
    $conditions[] = 'uf.program LIKE :program';
    $parameters['program'] = '%' . $program . '%';
}
$feeSql = 'SELECT uf.id, uf.program, uf.duration, uf.total_fee, uf.per_semester_fee,
                  uf.notes, uf.updated_at, u.name AS university_name,
                  u.id AS university_id, u.fee_structure_pdf,
                  DATEDIFF(CURRENT_DATE, DATE(uf.updated_at)) AS age_days
           FROM university_fees uf
           INNER JOIN universities u ON u.id = uf.university_id'
    . ($conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions))
    . ' ORDER BY u.name, uf.program, uf.id DESC';
$feeQuery = db()->prepare($feeSql);
$feeQuery->execute($parameters);
$feeRows = $feeQuery->fetchAll();
$formatFee = static fn ($value): string => $value === null ? 'Contact us' : '₹' . number_format((float) $value, 2);
$hasStaleFees = false;
foreach ($feeRows as $fee) {
    if ((int) $fee['age_days'] > 90) {
        $hasStaleFees = true;
        break;
    }
}
$pageTitle = 'University fees';
$activeNav = 'fees';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">QUICK FEE LOOKUP</p><h1>University fees</h1><p class="muted">Search a program or filter by university to find the latest fee information.</p></div>
</section>

<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($hasStaleFees): ?>
    <p class="notice notice-info">Some fee details are more than 90 days old. Confirm the current amount with the university before quoting it.</p>
<?php endif; ?>

<section class="panel fees-search-panel" aria-labelledby="fees-filter-title">
    <h2 id="fees-filter-title">Find a program</h2>
    <form class="fees-filter-grid" method="post" action="<?= e(app_url('/learner/university-fees.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <label>Program
            <input type="search" name="program" maxlength="100" value="<?= e($program) ?>" placeholder="Search e.g. MBA, BCA, Data Science">
        </label>
        <label>University
            <select name="university_id">
                <option value="">All universities</option>
                <?php foreach ($universities as $university): ?>
                    <option value="<?= (int) $university['id'] ?>" <?= $universityId === (int) $university['id'] ? 'selected' : '' ?>><?= e($university['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <div class="fees-filter-actions">
            <button class="button button-primary" type="submit">Find fees</button>
            <a href="<?= e(app_url('/learner/university-fees.php')) ?>">Clear</a>
        </div>
    </form>
</section>

<section aria-labelledby="fees-results-title">
    <h2 id="fees-results-title">Fee details <span class="progress-summary">(<?= count($feeRows) ?>)</span></h2>
    <?php if ($feeRows === []): ?>
        <p class="empty-state">
            <?= $universities === []
                ? 'Fee information will appear here when it is added.'
                : 'No fee rows match those filters. Try another program or university.' ?>
        </p>
    <?php else: ?>
        <div class="table-wrap fees-table-wrap">
            <table class="fees-table">
                <thead><tr><th>University / program</th><th>Duration</th><th>Total fee</th><th>Per semester</th><th>Notes</th><th>Last updated</th><th>Fee structure</th></tr></thead>
                <tbody>
                <?php foreach ($feeRows as $fee): ?>
                    <?php
                    $ageDays = (int) $fee['age_days'];
                    $isStale = $ageDays > 90;
                    $updatedAt = new DateTimeImmutable($fee['updated_at']);
                    $updatedDate = $updatedAt->format('d M Y');
                    $feePdf = $fee['fee_structure_pdf'];
                    $hasFeePdf = is_string($feePdf)
                        && preg_match('~\Auploads/pdfs/[a-f0-9]{32}\.pdf\z~', $feePdf) === 1;
                    ?>
                    <tr class="<?= $isStale ? 'fee-stale-row' : '' ?>">
                        <td><?= e($fee['university_name']) ?><span class="table-subtext"><?= e($fee['program']) ?></span></td>
                        <td><?= e($fee['duration']) ?></td>
                        <td class="fee-amount"><?= e($formatFee($fee['total_fee'])) ?></td>
                        <td class="fee-amount"><?= e($formatFee($fee['per_semester_fee'])) ?></td>
                        <td><?= e((string) ($fee['notes'] ?? '')) ?></td>
                        <td>
                            <?= e($updatedDate) ?>
                            <?php if ($isStale): ?><span class="fee-stale-note">Over 90 days old. Confirm before quoting.</span><?php endif; ?>
                        </td>
                        <td><?php if ($hasFeePdf): ?><a class="fee-pdf-link" href="<?= e(app_url('/learner/download.php?' . http_build_query(['type' => 'university', 'id' => (int) $fee['university_id']]))) ?>">Download PDF</a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="fee-card-list">
            <?php foreach ($feeRows as $fee): ?>
                <?php
                $ageDays = (int) $fee['age_days'];
                $isStale = $ageDays > 90;
                $updatedAt = new DateTimeImmutable($fee['updated_at']);
                $updatedDate = $updatedAt->format('d M Y');
                $feePdf = $fee['fee_structure_pdf'];
                $hasFeePdf = is_string($feePdf)
                    && preg_match('~\Auploads/pdfs/[a-f0-9]{32}\.pdf\z~', $feePdf) === 1;
                ?>
                <article class="fee-card <?= $isStale ? 'fee-card-stale' : '' ?>">
                    <div class="fee-card-heading">
                        <strong><?= e($fee['program']) ?></strong>
                        <span><?= e($fee['university_name']) ?></span>
                    </div>
                    <div class="fee-card-values">
                        <span>Total fee<strong><?= e($formatFee($fee['total_fee'])) ?></strong></span>
                        <span>Per semester<strong><?= e($formatFee($fee['per_semester_fee'])) ?></strong></span>
                    </div>
                    <div class="fee-card-meta">Duration: <?= e($fee['duration']) ?></div>
                    <?php if (is_string($fee['notes']) && $fee['notes'] !== ''): ?><p class="fee-card-notes"><?= e($fee['notes']) ?></p><?php endif; ?>
                    <div class="fee-card-footer">
                        <span class="fee-card-meta">Updated <?= e($updatedDate) ?></span>
                        <?php if ($hasFeePdf): ?><a class="fee-pdf-link" href="<?= e(app_url('/learner/download.php?' . http_build_query(['type' => 'university', 'id' => (int) $fee['university_id']]))) ?>">Fee PDF</a><?php endif; ?>
                    </div>
                    <?php if ($isStale): ?><span class="fee-stale-note">Over 90 days old. Confirm with the university before quoting.</span><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
