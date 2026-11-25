<?php
declare(strict_types=1);

function send_private_pdf(string $storedPath, string $downloadName): void
{
    if (preg_match('/\A[a-z0-9][a-z0-9._-]{0,100}\.pdf\z/i', $downloadName) !== 1
        || preg_match('~\Auploads/pdfs/[a-f0-9]{32}\.pdf\z~', $storedPath) !== 1) {
        render_error_page(404);
    }

    $applicationRoot = dirname(__DIR__);
    $uploadsRoot = realpath($applicationRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'pdfs');
    $absolutePath = realpath(
        $applicationRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath)
    );
    if ($uploadsRoot === false || $absolutePath === false) {
        render_error_page(404);
    }
    $allowedPrefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($absolutePath, $allowedPrefix, strlen($allowedPrefix)) !== 0 || !is_file($absolutePath)) {
        render_error_page(404);
    }
    if (!is_readable($absolutePath)) {
        error_log('Learning Hub could not read a requested PDF: ' . $absolutePath);
        http_response_code(500);
        exit('The PDF could not be downloaded.');
    }

    $file = fopen($absolutePath, 'rb');
    if ($file === false) {
        error_log('Learning Hub could not open a requested PDF: ' . $absolutePath);
        http_response_code(500);
        exit('The PDF could not be downloaded.');
    }
    $fileInfo = fstat($file);
    if (!is_array($fileInfo) || !isset($fileInfo['size'])) {
        fclose($file);
        error_log('Learning Hub could not determine the size of a requested PDF: ' . $absolutePath);
        http_response_code(500);
        exit('The PDF could not be downloaded.');
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Content-Length: ' . (int) $fileInfo['size']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    fpassthru($file);
    fclose($file);
    exit;
}
