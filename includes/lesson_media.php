<?php
declare(strict_types=1);

function normalize_lesson_video_url(string $url): array
{
    $url = trim($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        throw new InvalidArgumentException('Enter a valid YouTube or Google Drive URL.');
    }

    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($scheme !== 'https') {
        throw new InvalidArgumentException('Video links must use HTTPS.');
    }

    $youtubeHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be'];
    if (in_array($host, $youtubeHosts, true)) {
        $path = (string) ($parts['path'] ?? '');
        $videoId = '';
        if ($host === 'youtu.be' || $host === 'www.youtu.be') {
            $videoId = trim($path, '/');
        } elseif (preg_match('~^/(?:embed|shorts|live)/([^/?]+)~', $path, $matches) === 1) {
            $videoId = $matches[1];
        } else {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $videoId = (string) ($query['v'] ?? '');
        }

        if (preg_match('/\A[A-Za-z0-9_-]{11}\z/', $videoId) !== 1) {
            throw new InvalidArgumentException('That YouTube link does not contain a valid video ID.');
        }

        return [
            'video_type' => 'youtube',
            'video_url' => 'https://www.youtube.com/embed/' . $videoId,
        ];
    }

    if (in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
        $path = (string) ($parts['path'] ?? '');
        $fileId = '';
        if (preg_match('~/file/d/([A-Za-z0-9_-]+)~', $path, $matches) === 1) {
            $fileId = $matches[1];
        } else {
            parse_str((string) ($parts['query'] ?? ''), $query);
            $fileId = (string) ($query['id'] ?? '');
        }

        if (preg_match('/\A[A-Za-z0-9_-]{10,100}\z/', $fileId) !== 1) {
            throw new InvalidArgumentException('That Google Drive link does not contain a valid file ID.');
        }

        return [
            'video_type' => 'drive',
            'video_url' => 'https://drive.google.com/file/d/' . $fileId . '/preview',
        ];
    }

    throw new InvalidArgumentException('Use a YouTube or Google Drive link for the lesson video.');
}

function store_pdf_uploads(array $upload, string $destination): array
{
    if (!isset($upload['name'], $upload['tmp_name'], $upload['error'], $upload['size'])
        || !is_array($upload['name']) || !is_array($upload['tmp_name'])
        || !is_array($upload['error']) || !is_array($upload['size'])) {
        throw new InvalidArgumentException('The attachment upload was not received correctly.');
    }

    $stored = [];
    try {
        foreach ($upload['name'] as $index => $originalName) {
            $error = $upload['error'][$index] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($error !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('A PDF upload failed. Check the hosting upload limit and try again.');
            }

            $temporaryPath = $upload['tmp_name'][$index] ?? '';
            if (!is_string($temporaryPath) || !is_uploaded_file($temporaryPath)) {
                throw new InvalidArgumentException('An uploaded attachment could not be verified.');
            }
            $size = filesize($temporaryPath);
            if ($size === false || $size < 1) {
                throw new InvalidArgumentException('An uploaded attachment could not be verified.');
            }
            if ($size > 10 * 1024 * 1024) {
                throw new InvalidArgumentException('Each PDF attachment must be 10 MB or smaller.');
            }
            if (!is_string($originalName) || strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pdf') {
                throw new InvalidArgumentException('Only PDF attachments are allowed.');
            }
            if (!class_exists('finfo')) {
                throw new RuntimeException('PDF validation is unavailable on this server.');
            }

            $fileInfo = new finfo(FILEINFO_MIME_TYPE);
            if ($fileInfo->file($temporaryPath) !== 'application/pdf') {
                throw new InvalidArgumentException('An attachment is not a valid PDF file.');
            }

            $fileName = bin2hex(random_bytes(16)) . '.pdf';
            $absolutePath = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;
            if (!move_uploaded_file($temporaryPath, $absolutePath)) {
                throw new RuntimeException('The server could not save an uploaded PDF.');
            }

            $title = trim(pathinfo($originalName, PATHINFO_FILENAME));
            $title = preg_replace('/[\x00-\x1F\x7F]/u', '', $title);
            if (!is_string($title) || $title === '') {
                $title = 'PDF attachment';
            }
            if (preg_match('/\A.{0,200}/us', $title, $titleMatch) === 1) {
                $title = $titleMatch[0];
            } else {
                $title = 'PDF attachment';
            }

            $stored[] = [
                'title' => $title,
                'file_path' => 'uploads/pdfs/' . $fileName,
                'absolute_path' => $absolutePath,
            ];
        }
    } catch (Throwable $exception) {
        foreach ($stored as $file) {
            if (is_file($file['absolute_path']) && !unlink($file['absolute_path'])) {
                error_log('Learning Hub could not remove an incomplete PDF upload: ' . $file['absolute_path']);
            }
        }
        throw $exception;
    }

    return $stored;
}

function store_single_pdf_upload(array $upload, string $destination): ?array
{
    foreach (['name', 'tmp_name', 'error', 'size'] as $field) {
        if (!array_key_exists($field, $upload) || is_array($upload[$field])) {
            throw new InvalidArgumentException('The PDF upload was not received correctly.');
        }
    }

    $files = store_pdf_uploads([
        'name' => [$upload['name']],
        'tmp_name' => [$upload['tmp_name']],
        'error' => [$upload['error']],
        'size' => [$upload['size']],
    ], $destination);

    return $files[0] ?? null;
}

function delete_managed_pdf(string $filePath): void
{
    if (preg_match('~\Auploads/pdfs/[a-f0-9]{32}\.pdf\z~', $filePath) !== 1) {
        return;
    }

    $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $filePath);
    if (is_file($absolutePath) && !unlink($absolutePath)) {
        error_log('Learning Hub could not remove a managed PDF: ' . $absolutePath);
    }
}
