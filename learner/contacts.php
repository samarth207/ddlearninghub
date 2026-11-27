<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_role('employee');
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $search = $_POST['q'] ?? '';
        if (!is_string($search) || strlen(trim($search)) > 150) { throw new InvalidArgumentException('Search must be 150 bytes or fewer.'); }
        redirect_to('/learner/contacts.php?q=' . rawurlencode(trim($search)));
    } catch (InvalidArgumentException | RuntimeException $exception) {
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$search = $_GET['q'] ?? '';
if (!is_string($search) || strlen($search) > 150) { http_response_code(400); exit('Invalid search.'); }
$search = trim($search);
$like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
$query = db()->prepare("SELECT name, role, department, email, phone FROM contacts WHERE name LIKE ? ESCAPE '!' OR role LIKE ? ESCAPE '!' OR department LIKE ? ESCAPE '!' OR email LIKE ? ESCAPE '!' OR phone LIKE ? ESCAPE '!' ORDER BY sort_order, name, id");
$query->execute([$like, $like, $like, $like, $like]);
$contacts = $query->fetchAll();
$holidays = db()->query('SELECT name, holiday_date, holiday_date >= CURRENT_DATE AS upcoming FROM holidays ORDER BY holiday_date < CURRENT_DATE, holiday_date, id')->fetchAll();
$pageTitle = 'Contacts & holidays';
$activeNav = 'contacts';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">USEFUL INFORMATION</p><h1>Contacts &amp; holidays</h1></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="panel"><h2>Find a contact</h2><form method="post" class="search-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><label>Search by name, role, department, email or phone<input name="q" maxlength="150" value="<?= e($search) ?>"></label><button class="button button-primary">Search</button></form>
<?php if ($contacts === []): ?><p class="empty-state">No matching contacts.</p><?php else: ?><div class="card-grid"><?php foreach ($contacts as $contact): ?><article class="lesson-card"><h3><?= e((string) $contact['name']) ?></h3><p><?= e((string) $contact['role']) ?><?= $contact['department'] ? ' · ' . e((string) $contact['department']) : '' ?></p><?php if ($contact['email']): ?><p><?= e((string) $contact['email']) ?></p><?php endif; ?><?php if ($contact['phone']): ?><p><?= e((string) $contact['phone']) ?></p><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
<section class="learner-section"><h2>Holidays</h2><?php if ($holidays === []): ?><p class="empty-state">No holidays have been added.</p><?php else: ?><div class="card-grid"><?php foreach ($holidays as $holiday): ?><article class="lesson-card"><h3><?= e((string) $holiday['name']) ?></h3><p><?= e((string) $holiday['holiday_date']) ?></p><?php if ((int) $holiday['upcoming'] === 1): ?><span class="training-badge badge-pending">Upcoming</span><?php endif; ?></article><?php endforeach; ?></div><?php endif; ?></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
