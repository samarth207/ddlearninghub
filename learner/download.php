<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_role('employee');
require_once __DIR__ . '/../includes/pdf_download.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('Method not allowed.');
}

$type = $_GET['type'] ?? '';
$idValue = $_GET['id'] ?? '';
$id = is_string($idValue) ? filter_var($idValue, FILTER_VALIDATE_INT) : false;
if (!is_string($type) || $id === false || $id < 1) {
    render_error_page(404);
}

if ($type === 'attachment') {
    $query = db()->prepare(
        'SELECT a.file_path
         FROM attachments a
         INNER JOIN lessons l ON l.id = a.lesson_id
         WHERE a.id = :id AND l.is_published = 1'
    );
    $query->execute(['id' => $id]);
    $record = $query->fetch();
    if ($record === false || !is_string($record['file_path'] ?? null)) {
        render_error_page(404);
    }
    send_private_pdf($record['file_path'], 'lesson-attachment-' . $id . '.pdf');
}

if ($type === 'university') {
    $query = db()->prepare('SELECT fee_structure_pdf FROM universities WHERE id = :id');
    $query->execute(['id' => $id]);
    $record = $query->fetch();
    if ($record === false || !is_string($record['fee_structure_pdf'] ?? null)) {
        render_error_page(404);
    }
    send_private_pdf($record['fee_structure_pdf'], 'university-fee-structure-' . $id . '.pdf');
}

render_error_page(404);
