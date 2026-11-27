<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('admin');
$connection = db();
$categories = $connection->query('SELECT id, name FROM categories ORDER BY sort_order, name')->fetchAll();
$categoryNames = [];
foreach ($categories as $category) {
    $categoryNames[(int) $category['id']] = (string) $category['name'];
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';
        $categoryValue = $_POST['category_id'] ?? '';
        if (!is_string($action) || $action !== 'filter' || !is_string($categoryValue)) {
            throw new InvalidArgumentException('Choose a valid report filter.');
        }
        $categoryId = $categoryValue === '' ? 0 : filter_var($categoryValue, FILTER_VALIDATE_INT);
        if ($categoryId === false || $categoryId < 0) {
            throw new InvalidArgumentException('Choose a valid category.');
        }
        if ($categoryId > 0 && !isset($categoryNames[(int) $categoryId])) {
            throw new InvalidArgumentException('Choose a valid category.');
        }
        $redirectPath = '/admin/reports.php';
        if ($categoryId > 0) {
            $redirectPath .= '?category_id=' . (int) $categoryId;
        }
        redirect_to($redirectPath);
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

$categoryValue = $_GET['category_id'] ?? '';
$categoryId = 0;
if (!is_string($categoryValue)) {
    http_response_code(400);
    $error = 'Choose a valid category.';
} elseif ($categoryValue !== '') {
    $validatedCategoryId = filter_var($categoryValue, FILTER_VALIDATE_INT);
    if ($validatedCategoryId === false || $validatedCategoryId < 1) {
        http_response_code(400);
        $error = 'Choose a valid category.';
    } elseif (!isset($categoryNames[(int) $validatedCategoryId])) {
        render_error_page(404);
    } else {
        $categoryId = (int) $validatedCategoryId;
    }
}

$categoryName = $categoryId > 0 ? $categoryNames[$categoryId] : 'All categories';
$lessonCountSql = 'SELECT COUNT(*) FROM lessons WHERE is_published = 1'
    . ($categoryId > 0 ? ' AND category_id = :category_id' : '');
$lessonCountQuery = $connection->prepare($lessonCountSql);
$lessonCountQuery->execute($categoryId > 0 ? ['category_id' => $categoryId] : []);
$publishedLessonCount = (int) $lessonCountQuery->fetchColumn();

$poshCountQuery = $connection->prepare(
    "SELECT COUNT(*)
     FROM lessons l
     INNER JOIN categories c ON c.id = l.category_id
     WHERE l.is_published = 1 AND c.slug = 'posh-training'"
);
$poshCountQuery->execute();
$poshLessonCount = (int) $poshCountQuery->fetchColumn();

$completedCondition = $categoryId > 0
    ? 'l.category_id = :completed_category_id'
    : '1 = 1';
$startedCondition = $categoryId > 0
    ? 'l.category_id = :started_category_id'
    : '1 = 1';
$progressSql = "
    SELECT p.user_id,
           SUM(CASE WHEN ($completedCondition) AND p.status = 'completed' THEN 1 ELSE 0 END) AS report_completed,
           SUM(CASE WHEN ($startedCondition) AND p.status = 'started' THEN 1 ELSE 0 END) AS report_started,
           SUM(CASE WHEN c.slug = 'posh-training' AND p.status = 'completed' THEN 1 ELSE 0 END) AS posh_completed,
           SUM(CASE WHEN c.slug = 'posh-training' AND p.status = 'started' THEN 1 ELSE 0 END) AS posh_started,
           MAX(CASE WHEN c.slug = 'posh-training' AND p.status = 'completed' THEN p.completed_at END) AS posh_completed_at
    FROM progress p
    INNER JOIN lessons l ON l.id = p.lesson_id AND l.is_published = 1
    INNER JOIN categories c ON c.id = l.category_id
    GROUP BY p.user_id";
$progressQuery = $connection->prepare($progressSql);
$progressParameters = [];
if ($categoryId > 0) {
    $progressParameters = [
        'completed_category_id' => $categoryId,
        'started_category_id' => $categoryId,
    ];
}
$progressQuery->execute($progressParameters);
$progressByUser = [];
foreach ($progressQuery->fetchAll() as $progressRow) {
    $progressByUser[(int) $progressRow['user_id']] = $progressRow;
}

$employeeRows = $connection->query(
    "SELECT id, name, email, emp_code, department, is_active
     FROM users
     WHERE role = 'employee'
     ORDER BY department, name"
)->fetchAll();
$completionStatus = static function (int $completed, int $started, int $total): string {
    if ($total === 0) {
        return 'No published lessons';
    }
    if ($completed >= $total) {
        return 'Complete';
    }
    return ($completed > 0 || $started > 0) ? 'In progress' : 'Not started';
};
$employees = [];
$departmentStats = [];
$poshPendingEmployees = [];
foreach ($employeeRows as $employeeRow) {
    $userId = (int) $employeeRow['id'];
    $progress = $progressByUser[$userId] ?? [];
    $completed = (int) ($progress['report_completed'] ?? 0);
    $started = (int) ($progress['report_started'] ?? 0);
    $poshCompleted = (int) ($progress['posh_completed'] ?? 0);
    $poshStarted = (int) ($progress['posh_started'] ?? 0);
    $poshStatus = $poshLessonCount === 0
        ? 'Not configured'
        : ($poshCompleted >= $poshLessonCount ? 'Completed' : ($poshStarted > 0 || $poshCompleted > 0 ? 'In progress' : 'Not started'));
    $employee = [
        'id' => $userId,
        'name' => (string) $employeeRow['name'],
        'email' => (string) $employeeRow['email'],
        'emp_code' => (string) $employeeRow['emp_code'],
        'department' => trim((string) ($employeeRow['department'] ?? '')) ?: 'Unassigned',
        'is_active' => (int) $employeeRow['is_active'] === 1,
        'completed' => $completed,
        'started' => $started,
        'total' => $publishedLessonCount,
        'percent' => $publishedLessonCount > 0 ? (int) round(($completed / $publishedLessonCount) * 100) : 0,
        'status' => $completionStatus($completed, $started, $publishedLessonCount),
        'posh_completed' => $poshCompleted,
        'posh_started' => $poshStarted,
        'posh_total' => $poshLessonCount,
        'posh_status' => $poshStatus,
        'posh_completed_at' => $poshStatus === 'Completed' ? ($progress['posh_completed_at'] ?? null) : null,
    ];
    $employees[] = $employee;

    $department = $employee['department'];
    if (!isset($departmentStats[$department])) {
        $departmentStats[$department] = [
            'department' => $department,
            'employees' => 0,
            'active_employees' => 0,
            'completed' => 0,
            'started' => 0,
            'total' => 0,
            'posh_completed' => 0,
            'posh_pending' => 0,
        ];
    }
    $departmentStats[$department]['employees']++;
    $departmentStats[$department]['active_employees'] += $employee['is_active'] ? 1 : 0;
    $departmentStats[$department]['completed'] += $completed;
    $departmentStats[$department]['started'] += $started;
    $departmentStats[$department]['total'] += $publishedLessonCount;
    $departmentStats[$department]['posh_completed'] += $poshCompleted;
    if ($poshLessonCount > 0 && $poshStatus !== 'Completed') {
        $departmentStats[$department]['posh_pending']++;
        $poshPendingEmployees[] = $employee;
    }
}

foreach ($departmentStats as &$department) {
    $department['percent'] = $department['total'] > 0
        ? (int) round(($department['completed'] / $department['total']) * 100)
        : 0;
    $department['status'] = $completionStatus(
        $department['completed'],
        $department['started'],
        $department['total']
    );
}
unset($department);
ksort($departmentStats, SORT_NATURAL | SORT_FLAG_CASE);

$overdueSql = "SELECT u.name, u.email, u.emp_code, u.department, u.is_active,
                      l.title, c.name AS category_name,
                      DATE_ADD(u.joining_date, INTERVAL l.due_in_days DAY) AS due_date,
                      DATEDIFF(CURRENT_DATE, DATE_ADD(u.joining_date, INTERVAL l.due_in_days DAY)) AS days_overdue
               FROM users u
               CROSS JOIN lessons l
               INNER JOIN categories c ON c.id = l.category_id
               LEFT JOIN progress p ON p.user_id = u.id AND p.lesson_id = l.id
               WHERE u.role = 'employee' AND u.joining_date IS NOT NULL
                 AND l.is_published = 1 AND l.is_mandatory = 1 AND l.due_in_days IS NOT NULL
                 AND (p.status IS NULL OR p.status <> 'completed')
                 AND DATE_ADD(u.joining_date, INTERVAL l.due_in_days DAY) < CURRENT_DATE"
    . ($categoryId > 0 ? ' AND l.category_id = :category_id' : '')
    . ' ORDER BY due_date, u.name, l.title';
$overdueQuery = $connection->prepare($overdueSql);
$overdueQuery->execute($categoryId > 0 ? ['category_id' => $categoryId] : []);
$overdueRows = $overdueQuery->fetchAll();

$generatedAt = date('Y-m-d H:i:s');
$exportValue = $_GET['export'] ?? '';
$safeCsvText = static function (string $value): string {
    return preg_match('/\A[\p{C}\p{Z}\s]*[=+\-@]/u', $value) === 1 ? "'" . $value : $value;
};
if ($exportValue === 'overdue') {
    if ($error !== '') {
        http_response_code(400);
        exit($error);
    }
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('The overdue CSV could not be generated.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="overdue-training-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Employee', 'Email', 'Employee code', 'Department', 'Account', 'Category', 'Lesson', 'Due date', 'Days overdue'], ',', '"', '');
    foreach ($overdueRows as $row) {
        fputcsv($output, [
            $safeCsvText((string) $row['name']), $safeCsvText((string) $row['email']),
            $safeCsvText((string) $row['emp_code']), $safeCsvText((string) $row['department']),
            (int) $row['is_active'] === 1 ? 'Active' : 'Inactive',
            $safeCsvText((string) $row['category_name']), $safeCsvText((string) $row['title']),
            $safeCsvText((string) $row['due_date']), (int) $row['days_overdue'],
        ], ',', '"', '');
    }
    fclose($output);
    exit;
}
if ($exportValue === 'csv') {
    if ($error !== '') {
        http_response_code(400);
        exit($error);
    }

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('The compliance CSV could not be generated.');
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learning-compliance-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: private, no-store');
    header('Pragma: no-cache');
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [
        'record_type',
        'report_generated_at',
        'category_filter',
        'department',
        'employee_name',
        'email',
        'employee_code',
        'account_status',
        'employee_count',
        'active_employees',
        'completed_lessons',
        'published_lessons',
        'started_lessons',
        'completion_percent',
        'completion_status',
        'posh_status',
        'posh_completed_lessons',
        'posh_published_lessons',
        'posh_completion_date',
    ], ',', '"', '');

    foreach ($employees as $employee) {
        fputcsv($output, [
            $safeCsvText('employee'),
            $safeCsvText($generatedAt),
            $safeCsvText($categoryName),
            $safeCsvText($employee['department']),
            $safeCsvText($employee['name']),
            $safeCsvText($employee['email']),
            $safeCsvText($employee['emp_code']),
            $safeCsvText($employee['is_active'] ? 'Active' : 'Inactive'),
            1,
            $employee['is_active'] ? 1 : 0,
            $employee['completed'],
            $employee['total'],
            $employee['started'],
            $employee['percent'],
            $safeCsvText($employee['status']),
            $safeCsvText($employee['posh_status']),
            $employee['posh_completed'],
            $employee['posh_total'],
            $safeCsvText((string) ($employee['posh_completed_at'] ?? '')),
        ], ',', '"', '');
    }
    foreach ($departmentStats as $department) {
        $poshStatus = $poshLessonCount === 0
            ? 'Not configured'
            : $department['posh_pending'] . ' employee(s) pending';
        fputcsv($output, [
            $safeCsvText('department'),
            $safeCsvText($generatedAt),
            $safeCsvText($categoryName),
            $safeCsvText($department['department']),
            '',
            '',
            '',
            '',
            $department['employees'],
            $department['active_employees'],
            $department['completed'],
            $department['total'],
            $department['started'],
            $department['percent'],
            $safeCsvText($department['status']),
            $safeCsvText($poshStatus),
            $department['posh_completed'],
            $poshLessonCount * $department['employees'],
            '',
        ], ',', '"', '');
    }
    fclose($output);
    exit;
}

$pendingPoshCount = count($poshPendingEmployees);
$overallCompleted = array_sum(array_column($employees, 'completed'));
$overallTotal = count($employees) * $publishedLessonCount;
$overallPercent = $overallTotal > 0 ? (int) round(($overallCompleted / $overallTotal) * 100) : 0;
$statusClass = static function (string $status): string {
    if ($status === 'Complete' || $status === 'Completed') {
        return 'report-status-complete';
    }
    if ($status === 'In progress') {
        return 'report-status-progress';
    }
    if ($status === 'Not started') {
        return 'report-status-pending';
    }
    return 'report-status-neutral';
};
$pageTitle = 'Compliance reports';
$activeNav = 'reports';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div>
        <p class="eyebrow">COMPLIANCE RECORD</p>
        <h1>Training completion reports</h1>
        <p class="muted">Completion is measured against published lessons. POSH status always checks all published lessons in POSH Training.</p>
    </div>
</section>

<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if ($categoryId > 0 && $publishedLessonCount === 0): ?><p class="notice notice-info">There are no published lessons in <?= e($categoryName) ?>.</p><?php endif; ?>
<?php if ($poshLessonCount === 0): ?><p class="notice notice-error">POSH compliance cannot be evaluated: no lessons are currently published in the POSH Training category.</p><?php endif; ?>
<p class="report-snapshot">Report generated <?= e($generatedAt) ?>. Active and inactive employee accounts are included; account status is shown for context.</p>

<section class="panel report-filter-panel" aria-labelledby="report-filter-title">
    <h2 id="report-filter-title">Filter by category</h2>
    <form class="report-filter-form" method="post" action="<?= e(app_url('/admin/reports.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="filter">
        <label for="report-category">Category</label>
        <select id="report-category" name="category_id">
            <option value="">All categories</option>
            <?php foreach ($categories as $category): ?>
                <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="button button-primary" type="submit">Apply filter</button>
        <?php if ($categoryId > 0): ?><a href="<?= e(app_url('/admin/reports.php')) ?>">Clear filter</a><?php endif; ?>
    </form>
</section>

<div class="stat-grid report-stat-grid">
    <div class="stat-card"><strong><?= count($employees) ?></strong><span>Employee accounts</span></div>
    <div class="stat-card"><strong><?= $overallPercent ?>%</strong><span><?= e($categoryName) ?> completion (<?= $overallCompleted ?> / <?= $overallTotal ?>)</span></div>
    <div class="stat-card"><strong><?= $poshLessonCount > 0 ? $pendingPoshCount : '—' ?></strong><span><?= $poshLessonCount > 0 ? 'Employees not complete in POSH' : 'POSH status unavailable' ?></span></div>
</div>

<section class="admin-list-section" aria-labelledby="overdue-report-title">
    <div class="section-title-row">
        <h2 id="overdue-report-title">Overdue mandatory training</h2>
        <a class="button button-primary" href="<?= e(app_url('/admin/reports.php?export=overdue' . ($categoryId > 0 ? '&category_id=' . $categoryId : ''))) ?>">Export overdue CSV</a>
    </div>
    <p class="muted">Includes active and inactive employees with a joining date and incomplete published mandatory lessons whose due date has passed.</p>
    <?php if ($overdueRows === []): ?>
        <p class="empty-state">No overdue mandatory training found for this filter.</p>
    <?php else: ?>
        <div class="table-wrap"><table>
            <thead><tr><th>Employee</th><th>Department</th><th>Account</th><th>Category</th><th>Lesson</th><th>Due date</th><th>Days overdue</th></tr></thead>
            <tbody><?php foreach ($overdueRows as $row): ?>
                <tr>
                    <td><?= e((string) $row['name']) ?><span class="table-subtext"><?= e((string) $row['emp_code']) ?> · <?= e((string) $row['email']) ?></span></td>
                    <td><?= e((string) $row['department']) ?></td>
                    <td><?= (int) $row['is_active'] === 1 ? 'Active' : 'Inactive' ?></td>
                    <td><?= e((string) $row['category_name']) ?></td>
                    <td><?= e((string) $row['title']) ?></td>
                    <td><?= e((string) $row['due_date']) ?></td>
                    <td><?= (int) $row['days_overdue'] ?></td>
                </tr>
            <?php endforeach; ?></tbody>
        </table></div>
    <?php endif; ?>
</section>

<section class="admin-list-section" aria-labelledby="department-report-title">
    <h2 id="department-report-title">Completion by department</h2>
    <?php if ($departmentStats === []): ?>
        <p class="empty-state">No employee accounts are available for this report.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Department</th><th>Employees</th><th>Active accounts</th><th>Completed / required</th><th>Completion</th><th>Status</th><th>POSH pending</th></tr></thead>
                <tbody>
                <?php foreach ($departmentStats as $department): ?>
                    <tr>
                        <td><?= e($department['department']) ?></td>
                        <td><?= (int) $department['employees'] ?></td>
                        <td><?= (int) $department['active_employees'] ?></td>
                        <td><?= (int) $department['completed'] ?> / <?= (int) $department['total'] ?></td>
                        <td><?= (int) $department['percent'] ?>%</td>
                        <td><span class="report-status <?= e($statusClass($department['status'])) ?>"><?= e($department['status']) ?></span></td>
                        <td><?= $poshLessonCount > 0 ? (int) $department['posh_pending'] : 'Not configured' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-list-section" aria-labelledby="employee-report-title">
    <div class="section-title-row">
        <h2 id="employee-report-title">Completion by employee</h2>
        <a class="button button-primary" href="<?= e(app_url('/admin/reports.php?export=csv' . ($categoryId > 0 ? '&category_id=' . $categoryId : ''))) ?>">Export compliance CSV</a>
    </div>
    <?php if ($employees === []): ?>
        <p class="empty-state">No employee accounts are available for this report.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Employee</th><th>Department</th><th>Account</th><th>Completed / required</th><th>Started</th><th>Completion</th><th>POSH status</th><th>POSH completion date</th></tr></thead>
                <tbody>
                <?php foreach ($employees as $employee): ?>
                    <?php $poshCompletedAt = $employee['posh_completed_at'] !== null
                        ? new DateTimeImmutable($employee['posh_completed_at'])
                        : null; ?>
                    <tr>
                        <td><?= e($employee['name']) ?><span class="table-subtext"><?= e($employee['emp_code']) ?> · <?= e($employee['email']) ?></span></td>
                        <td><?= e($employee['department']) ?></td>
                        <td><?= $employee['is_active'] ? 'Active' : 'Inactive' ?></td>
                        <td><?= (int) $employee['completed'] ?> / <?= (int) $employee['total'] ?></td>
                        <td><?= (int) $employee['started'] ?></td>
                        <td>
                            <span class="report-status <?= e($statusClass($employee['status'])) ?>"><?= e($employee['status']) ?></span>
                            <?php if ($employee['total'] > 0): ?><span class="table-subtext"><?= (int) $employee['percent'] ?>%</span><?php endif; ?>
                        </td>
                        <td><span class="report-status <?= e($statusClass($employee['posh_status'])) ?>"><?= e($employee['posh_status']) ?></span><span class="table-subtext"><?= (int) $employee['posh_completed'] ?> / <?= (int) $employee['posh_total'] ?> lessons</span></td>
                        <td><?= $poshCompletedAt !== null ? e($poshCompletedAt->format('d M Y')) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<section class="admin-list-section" aria-labelledby="posh-report-title">
    <h2 id="posh-report-title">Employees who have not completed POSH Training</h2>
    <p class="muted">An employee is complete only after completing every currently published lesson in the POSH Training category.</p>
    <?php if ($poshLessonCount === 0): ?>
        <p class="empty-state">Publish at least one POSH Training lesson before checking compliance.</p>
    <?php elseif ($employees === []): ?>
        <p class="empty-state">No employee accounts are available to evaluate.</p>
    <?php elseif ($poshPendingEmployees === []): ?>
        <p class="notice notice-success">All employee accounts have completed the published POSH Training lessons.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Employee</th><th>Department</th><th>Account</th><th>POSH lessons complete</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($poshPendingEmployees as $employee): ?>
                    <tr>
                        <td><?= e($employee['name']) ?><span class="table-subtext"><?= e($employee['emp_code']) ?> · <?= e($employee['email']) ?></span></td>
                        <td><?= e($employee['department']) ?></td>
                        <td><?= $employee['is_active'] ? 'Active' : 'Inactive' ?></td>
                        <td><?= (int) $employee['posh_completed'] ?> / <?= (int) $employee['posh_total'] ?></td>
                        <td><span class="report-status <?= e($statusClass($employee['posh_status'])) ?>"><?= e($employee['posh_status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
