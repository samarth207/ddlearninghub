<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/templates.php';
$user = require_role('employee');
$types = template_types();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $type = $_POST['type'] ?? '';
        $search = $_POST['q'] ?? '';
        $tag = $_POST['tag'] ?? '';
        if (!is_string($type) || !isset($types[$type]) || !is_string($search) || strlen(trim($search)) > 150 || !is_string($tag) || strlen($tag) > 100) {
            throw new InvalidArgumentException('Choose a valid type and search of at most 150 bytes.');
        }
        redirect_to('/learner/library.php?' . http_build_query(['type' => $type, 'q' => trim($search), 'tag' => $tag]));
    } catch (InvalidArgumentException | RuntimeException $exception) {
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.' ? 'Your form session expired. Reload and try again.' : $exception->getMessage();
    }
}
$type = $_GET['type'] ?? 'call_script';
$search = $_GET['q'] ?? '';
$tag = $_GET['tag'] ?? '';
if (!is_string($type) || !isset($types[$type]) || !is_string($search) || strlen($search) > 150 || !is_string($tag) || strlen($tag) > 100) {
    http_response_code(400);
    exit('Invalid library filter.');
}
$search = trim($search);
$tag = trim($tag);
$query = db()->prepare(
    "SELECT t.id, t.tags
     FROM templates t JOIN users u ON u.id = ?
     WHERE t.type = ? AND t.is_published = 1
       AND (t.department IS NULL OR TRIM(t.department) = '' OR t.department = u.department)"
);
$query->execute([$user['id'], $type]);
$availableTags = [];
$matchingIds = [];
foreach ($query->fetchAll() as $record) {
    $itemTags = array_values(array_filter(array_map('trim', explode(',', (string) $record['tags'])), static fn (string $value): bool => $value !== ''));
    foreach ($itemTags as $itemTag) {
        $availableTags[$itemTag] = $itemTag;
    }
    if ($tag !== '' && in_array($tag, $itemTags, true)) { $matchingIds[] = (int) $record['id']; }
}
$items = [];
if ($tag === '' || $matchingIds !== []) {
    $sql = "SELECT t.id, t.title, t.body, DATE(t.updated_at) AS updated_date,
                   DATEDIFF(CURRENT_DATE, DATE(t.updated_at)) > 90 AS outdated
            FROM templates t JOIN users u ON u.id = ?
            WHERE t.type = ? AND t.is_published = 1
              AND (t.department IS NULL OR TRIM(t.department) = '' OR t.department = u.department)";
    $parameters = [$user['id'], $type];
    if ($tag !== '') {
        $sql .= ' AND t.id IN (' . implode(',', array_fill(0, count($matchingIds), '?')) . ')';
        $parameters = array_merge($parameters, $matchingIds);
    }
    if ($search !== '') {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
        $sql .= " AND (t.title LIKE ? ESCAPE '!' OR t.body LIKE ? ESCAPE '!' OR t.tags LIKE ? ESCAPE '!')";
        array_push($parameters, $like, $like, $like);
    }
    $query = db()->prepare($sql . ' ORDER BY t.title, t.id');
    $query->execute($parameters);
    $items = $query->fetchAll();
    foreach ($items as &$item) {
        preg_match_all('/\{([A-Za-z][A-Za-z0-9_]{0,49})\}/', (string) $item['body'], $matches);
        $item['placeholders'] = array_values(array_unique($matches[1]));
    }
    unset($item);
}
natcasesort($availableTags);
$pageTitle = 'Scripts, templates & FAQ';
$activeNav = 'library';
require __DIR__ . '/../includes/header.php';
?>
<script src="<?= e(app_url('/assets/js/library.js')) ?>" defer></script>
<section class="page-heading"><div><p class="eyebrow">SALES &amp; COUNSELLING</p><h1>Scripts, templates &amp; FAQ</h1><p class="muted">Check current information before sharing with a student.</p></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<nav class="library-tabs" aria-label="Library types"><?php foreach ($types as $value => $label): ?><a class="button <?= $value === $type ? 'button-primary' : 'button-secondary' ?>" <?= $value === $type ? 'aria-current="page"' : '' ?> href="<?= e(app_url('/learner/library.php?' . http_build_query(['type' => $value]))) ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
<section class="panel library-filters"><form method="post" class="search-form"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="type" value="<?= e($type) ?>"><input type="hidden" name="tag" value="<?= e($tag) ?>"><label>Search title, body or tags<input name="q" maxlength="150" value="<?= e($search) ?>"></label><button class="button button-primary">Search</button></form>
<?php if ($availableTags !== []): ?><nav class="library-tags" aria-label="Filter by tag"><a class="button <?= $tag === '' ? 'button-primary' : 'button-secondary' ?>" href="<?= e(app_url('/learner/library.php?' . http_build_query(['type' => $type, 'q' => $search]))) ?>" <?= $tag === '' ? 'aria-current="page"' : '' ?>>All tags</a><?php foreach ($availableTags as $itemTag): ?><a class="button <?= $tag === $itemTag ? 'button-primary' : 'button-secondary' ?>" href="<?= e(app_url('/learner/library.php?' . http_build_query(['type' => $type, 'q' => $search, 'tag' => $itemTag]))) ?>" <?= $tag === $itemTag ? 'aria-current="page"' : '' ?>><?= e($itemTag) ?></a><?php endforeach; ?></nav><?php endif; ?></section>
<noscript><p class="notice notice-info">Enable JavaScript to fill placeholders and use the copy button. You can select and copy the text manually.</p></noscript>
<?php if ($items === []): ?><p class="empty-state">No matching published items for this type.</p><?php else: ?><div class="library-list"><?php foreach ($items as $item): ?>
<?php if ($type === 'faq'): ?><details class="panel library-card" data-library-card><summary><?= e((string) $item['title']) ?><span class="library-meta library-faq-meta">Updated on <?= e((string) $item['updated_date']) ?><?php if ((int) $item['outdated'] === 1): ?> <span class="training-badge badge-pending">May be outdated</span><?php endif; ?></span></summary><?php else: ?><article class="panel library-card" data-library-card><h2><?= e((string) $item['title']) ?></h2><p class="library-meta">Updated on <?= e((string) $item['updated_date']) ?><?php if ((int) $item['outdated'] === 1): ?> <span class="training-badge badge-pending">May be outdated</span><?php endif; ?></p><?php endif; ?>
<?php if ($item['placeholders'] !== []): ?><div class="library-inputs"><?php foreach ($item['placeholders'] as $placeholder): ?><label><?= e(str_replace('_', ' ', ucfirst($placeholder))) ?><input type="text" data-placeholder="<?= e($placeholder) ?>" autocomplete="off"></label><?php endforeach; ?></div><?php endif; ?>
<div class="library-preview" data-library-preview><?= e((string) $item['body']) ?></div><div class="library-copy-row"><button type="button" class="button button-secondary" data-library-copy hidden>Copy</button><span data-copy-status role="status" aria-live="polite"></span></div>
<?php if ($type === 'faq'): ?></details><?php else: ?></article><?php endif; ?>
<?php endforeach; ?></div><?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
