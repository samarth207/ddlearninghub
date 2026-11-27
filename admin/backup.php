<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/backup.php';
require_role('admin');

$connection = db();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf_token($_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';
        if (!is_string($action) || !in_array($action, ['database', 'uploads'], true)) {
            throw new InvalidArgumentException('Choose a valid backup.');
        }
        if ($action === 'uploads' && !class_exists('ZipArchive')) {
            throw new InvalidArgumentException('ZIP support is unavailable on this server.');
        }
        session_write_close();
        if (function_exists('set_time_limit')) { set_time_limit(60); }
        $path = backup_temp_file();
        if ($action === 'database') {
            create_database_backup($connection, $path);
        } else {
            create_uploads_backup($path);
        }
        $file = fopen($path, 'rb');
        if ($file === false) { throw new RuntimeException('Cannot open the generated backup.'); }
        try {
            $info = fstat($file);
            if (!is_array($info) || !isset($info['size']) || $info['size'] < 1) {
                throw new RuntimeException('Cannot verify the generated backup.');
            }
            if ($action === 'database') {
                $connection->exec(
                    "INSERT INTO settings (setting_key, setting_value)
                     VALUES ('last_database_backup', DATE_FORMAT(UTC_TIMESTAMP(), '%Y-%m-%d %H:%i:%s'))
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
                );
            }
            $filename = $action === 'database' ? 'learninghub-' . gmdate('Y-m-d') . '.sql' : 'learninghub-uploads-' . gmdate('Y-m-d') . '.zip';
            header('Content-Type: ' . ($action === 'database' ? 'application/sql' : 'application/zip'));
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (int) $info['size']);
            header('Cache-Control: private, no-store, max-age=0, no-transform');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');
            $sent = fpassthru($file);
            if ($sent !== (int) $info['size']) {
                error_log('Learning Hub backup download was interrupted.');
            }
        } finally {
            fclose($file);
        }
        exit;
    } catch (PDOException $exception) {
        error_log('Learning Hub backup failed: ' . $exception->getMessage());
        http_response_code(500);
        $error = 'The backup could not be generated. Check the server error log.';
    } catch (InvalidArgumentException $exception) {
        http_response_code(400);
        $error = $exception->getMessage();
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'Invalid CSRF token.') {
            error_log('Learning Hub backup failed: ' . $exception->getMessage());
        }
        http_response_code($exception->getMessage() === 'Invalid CSRF token.' ? 400 : 500);
        $error = $exception->getMessage() === 'Invalid CSRF token.'
            ? 'Your form session expired. Reload and try again.'
            : 'The backup could not be generated. Check the server error log and backup instructions.';
    }
}
$query = $connection->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
$query->execute(['last_database_backup']);
$lastBackup = $query->fetchColumn();
$pageTitle = 'Backups';
$activeNav = 'backup';
require __DIR__ . '/../includes/header.php';
?>
<section class="page-heading"><div><p class="eyebrow">ADMINISTRATION</p><h1>Backups</h1><p class="muted">Keep database and uploads backups together in a secure location.</p></div></section>
<?php if ($error !== ''): ?><p class="notice notice-error" role="alert"><?= e($error) ?></p><?php endif; ?>
<section class="panel"><h2>Database</h2><p>Last database backup generated: <?= $lastBackup === false ? 'Never' : e((string) $lastBackup) ?><?= $lastBackup === false ? '' : ' (UTC)' ?></p><p class="muted">This records generation, not whether your browser saved the file. Verify downloads and test restores.</p>
<form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="database"><button class="button button-primary">Download database backup</button></form></section>
<section class="panel learner-section"><h2>Uploaded files</h2><?php if (class_exists('ZipArchive')): ?><p>Download the uploads folder separately from the database backup.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="uploads"><button class="button button-secondary">Download uploads ZIP</button></form><?php else: ?><p class="notice notice-info">ZIP downloads are unavailable because PHP ZipArchive is not installed. Back up the uploads folder using your hosting file manager or FTP.</p><?php endif; ?></section>
<?php require __DIR__ . '/../includes/footer.php'; ?>
