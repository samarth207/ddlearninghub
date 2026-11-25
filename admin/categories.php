<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/db.php';
require_role('admin');

$error = '';
$form = [
    'id' => '',
    'name' => '',
    'slug' => '',
    'description' => '',
    'icon' => '',
    'sort_order' => '',
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
            $form['id'] = $readPostString('category_id');
            $form['name'] = $readPostString('name');
            $form['slug'] = strtolower($readPostString('slug'));
            $form['description'] = $readPostString('description');
            $form['icon'] = $readPostString('icon');
            $form['sort_order'] = $readPostString('sort_order');

            if ($form['name'] === '' || strlen($form['name']) > 150) {
                throw new InvalidArgumentException('Enter a category name of 150 characters or fewer.');
            }
            if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $form['slug']) !== 1 || strlen($form['slug']) > 170) {
                throw new InvalidArgumentException('Use a lowercase slug with letters, numbers, and single hyphens.');
            }
            if (strlen($form['icon']) > 80) {
                throw new InvalidArgumentException('The icon value must be 80 characters or fewer.');
            }
            $sortOrder = filter_var($form['sort_order'], FILTER_VALIDATE_INT);
            if ($sortOrder === false) {
                throw new InvalidArgumentException('Sort order must be a whole number.');
            }

            $categoryId = filter_var($form['id'], FILTER_VALIDATE_INT);
            if ($form['id'] !== '' && ($categoryId === false || $categoryId < 1)) {
                throw new InvalidArgumentException('The category ID is invalid. Reload the page and try again.');
            }
            if ($categoryId !== false && $categoryId > 0) {
                $connection = db();
                $save = $connection->prepare(
                    'UPDATE categories
                     SET name = :name, slug = :slug, description = :description,
                         icon = :icon, sort_order = :sort_order
                     WHERE id = :id'
                );
                $save->execute([
                    'name' => $form['name'],
                    'slug' => $form['slug'],
                    'description' => $form['description'],
                    'icon' => $form['icon'] === '' ? null : $form['icon'],
                    'sort_order' => $sortOrder,
                    'id' => $categoryId,
                ]);
                if ($save->rowCount() === 0) {
                    $exists = $connection->prepare('SELECT id FROM categories WHERE id = :id');
                    $exists->execute(['id' => $categoryId]);
                    if ($exists->fetchColumn() === false) {
                        throw new InvalidArgumentException('That category could not be found.');
                    }
                }
            } else {
                $connection = db();
                $save = $connection->prepare(
                    'INSERT INTO categories (name, slug, description, icon, sort_order)
                     VALUES (:name, :slug, :description, :icon, :sort_order)'
                );
                $save->execute([
                    'name' => $form['name'],
                    'slug' => $form['slug'],
                    'description' => $form['description'],
                    'icon' => $form['icon'] === '' ? null : $form['icon'],
                    'sort_order' => $sortOrder,
                ]);
            }

            redirect_to('/admin/categories.php?saved=1');
        }

        if ($action === 'delete') {
            $categoryId = filter_var($readPostString('category_id'), FILTER_VALIDATE_INT);
            if ($categoryId === false || $categoryId < 1) {
                throw new InvalidArgumentException('Choose a valid category to delete.');
            }
            $connection = db();
            $delete = $connection->prepare('DELETE FROM categories WHERE id = :id');
            $delete->execute(['id' => $categoryId]);
            redirect_to('/admin/categories.php?deleted=1');
        }

        if ($action === 'move') {
            $categoryId = filter_var($readPostString('category_id'), FILTER_VALIDATE_INT);
            $direction = $readPostString('direction');
            if ($categoryId === false || $categoryId < 1 || !in_array($direction, ['up', 'down'], true)) {
                throw new InvalidArgumentException('Choose a valid category and reorder direction.');
            }

            $connection = db();
            $ids = array_map(
                'intval',
                $connection->query('SELECT id FROM categories ORDER BY sort_order, name, id')->fetchAll(PDO::FETCH_COLUMN)
            );
            $index = array_search($categoryId, $ids, true);
            $neighborIndex = $direction === 'up' ? $index - 1 : $index + 1;
            if ($index !== false && isset($ids[$neighborIndex])) {
                $swapId = $ids[$neighborIndex];
                $ids[$neighborIndex] = $categoryId;
                $ids[$index] = $swapId;

                $connection->beginTransaction();
                $updateOrder = $connection->prepare('UPDATE categories SET sort_order = :sort_order WHERE id = :id');
                foreach ($ids as $position => $id) {
                    $updateOrder->execute(['sort_order' => $position + 1, 'id' => $id]);
                }
                $connection->commit();
            }
            redirect_to('/admin/categories.php?reordered=1');
        }

        throw new InvalidArgumentException('Choose a valid category action.');
    } catch (PDOException $exception) {
        if ($connection instanceof PDO && $connection->inTransaction()) {
            $connection->rollBack();
        }
        error_log('Learning Hub category operation failed: ' . $exception->getMessage());
        $error = pdo_sql_state($exception) === '23000'
            ? 'That category name or slug is already in use, or the category still contains lessons.'
            : 'The category could not be saved. Please try again.';
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        http_response_code(400);
        $error = $exception->getMessage() === 'Invalid CSRF token.'
            ? 'Your form session expired. Reload the page and try again.'
            : $exception->getMessage();
    }
}

$editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editId !== false && $editId !== null && $editId > 0) {
    $editQuery = db()->prepare('SELECT id, name, slug, description, icon, sort_order FROM categories WHERE id = :id');
    $editQuery->execute(['id' => $editId]);
    $categoryToEdit = $editQuery->fetch();
    if ($categoryToEdit === false) {
        render_error_page(404);
    } else {
        $form = $categoryToEdit;
    }
}
if ($form['sort_order'] === '') {
    $form['sort_order'] = (string) ((int) db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM categories')->fetchColumn());
}

$categories = db()->query(
    'SELECT c.id, c.name, c.slug, c.sort_order, COUNT(l.id) AS lesson_count
     FROM categories c
     LEFT JOIN lessons l ON l.category_id = c.id
     GROUP BY c.id, c.name, c.slug, c.sort_order
     ORDER BY c.sort_order, c.name, c.id'
)->fetchAll();
$pageTitle = 'Categories';
$activeNav = 'categories';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading">
    <div><p class="eyebrow">CONTENT ORGANISATION</p><h1>Categories</h1><p class="muted">Add, edit, reorder, or remove training topics.</p></div>
</section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><p class="notice notice-success" role="status">Category saved.</p><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><p class="notice notice-success" role="status">Category deleted.</p><?php endif; ?>
<?php if (isset($_GET['reordered'])): ?><p class="notice notice-success" role="status">Category order updated.</p><?php endif; ?>

<section class="panel admin-form-panel">
    <h2><?= $form['id'] !== '' ? 'Edit category' : 'Add category' ?></h2>
    <form method="post" class="admin-form-grid">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="category_id" value="<?= e((string) $form['id']) ?>">
        <label>Name<input name="name" required maxlength="150" value="<?= e((string) $form['name']) ?>"></label>
        <label>Slug<input name="slug" required maxlength="170" pattern="[a-z0-9]+(-[a-z0-9]+)*" value="<?= e((string) $form['slug']) ?>" placeholder="e.g. hr-portal-how-tos"></label>
        <label>Description<textarea name="description" rows="2"><?= e((string) $form['description']) ?></textarea></label>
        <label>Icon (optional)<input name="icon" maxlength="80" value="<?= e((string) $form['icon']) ?>" placeholder="e.g. POSH"></label>
        <label>Sort order<input name="sort_order" type="number" step="1" required value="<?= e((string) $form['sort_order']) ?>"></label>
        <div class="admin-form-actions">
            <button class="button button-primary" type="submit"><?= $form['id'] !== '' ? 'Save changes' : 'Add category' ?></button>
            <?php if ($form['id'] !== ''): ?><a class="button button-secondary" href="<?= e(app_url('/admin/categories.php')) ?>">Cancel</a><?php endif; ?>
        </div>
    </form>
</section>

<section class="admin-list-section">
    <h2>Category order</h2>
    <?php if ($categories === []): ?>
        <p class="empty-state">No categories yet. Add one above to get started.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Category</th><th>Slug</th><th>Lessons</th><th>Order</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($categories as $index => $category): ?>
                    <tr>
                        <td><?= e($category['name']) ?></td>
                        <td><?= e($category['slug']) ?></td>
                        <td><?= (int) $category['lesson_count'] ?></td>
                        <td><?= (int) $category['sort_order'] ?></td>
                        <td class="admin-row-actions">
                            <a class="table-action" href="<?= e(app_url('/admin/categories.php?edit=' . (int) $category['id'])) ?>">Edit</a>
                            <form method="post" class="inline-form">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="move">
                                <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                <button class="table-action" type="submit" name="direction" value="up" <?= $index === 0 ? 'disabled' : '' ?> aria-label="Move <?= e($category['name']) ?> up">↑</button>
                                <button class="table-action" type="submit" name="direction" value="down" <?= $index === count($categories) - 1 ? 'disabled' : '' ?> aria-label="Move <?= e($category['name']) ?> down">↓</button>
                            </form>
                            <form method="post" class="inline-form" data-confirm="Delete this category? Categories with lessons cannot be deleted.">
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
                                <button class="table-action table-action-danger" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
