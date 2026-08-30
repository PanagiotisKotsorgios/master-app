<?php

/** Shared document metadata and file handling for school-managed athlete files. */

function athleteDocumentTypes(): array
{
    return [
        'delta'   => ['label' => 'Δελτίο Αθλητή',         'icon' => 'fa-id-card',    'color' => '#e63946'],
        'dan'     => ['label' => 'Πιστοποιητικό Dan',     'icon' => 'fa-medal',      'color' => '#f0a500'],
        'belt'    => ['label' => 'Πιστοποίηση Ζώνης',     'icon' => 'fa-award',      'color' => '#8b5cf6'],
        'medical' => ['label' => 'Ιατρικό Πιστοποιητικό', 'icon' => 'fa-heart-pulse','color' => '#22c55e'],
        'other'   => ['label' => 'Άλλο',                  'icon' => 'fa-file',       'color' => '#64748b'],
    ];
}

function normaliseAthleteDocumentDate(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') return null;

    $date = DateTime::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException('Μη έγκυρη ημερομηνία εγγράφου.');
    }
    return $value;
}

/**
 * Validate and store a school-uploaded athlete document.
 * Returns the relative path, byte size and verified MIME type.
 */
function storeAthleteDocumentUpload(array $file, int $athleteId, string $type): array
{
    $allowedMimes = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
    ];
    $maxBytes = 8 * 1024 * 1024;

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Επιλέξτε αρχείο για ανέβασμα.');
    }

    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new RuntimeException('Το αρχείο μεταφόρτωσης δεν είναι έγκυρο.');
    }

    $size = (int)(filesize($tmpPath) ?: 0);
    if ($size <= 0 || $size > $maxBytes) {
        throw new RuntimeException('Το αρχείο πρέπει να είναι έως 8 MB.');
    }

    $mime = '';
    if ($tmpPath !== '' && class_exists('finfo')) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpPath);
    } elseif ($tmpPath !== '' && function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($tmpPath);
    }
    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException('Επιτρέπονται μόνο PDF, JPG, PNG ή WEBP.');
    }

    $safeType = array_key_exists($type, athleteDocumentTypes()) ? $type : 'other';
    $relativeDir = 'uploads/athletes/' . $athleteId . '/docs';
    $absoluteDir = dirname(__DIR__) . '/' . $relativeDir;

    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('Αδυναμία δημιουργίας φακέλου εγγράφων.');
    }
    if (!is_writable($absoluteDir)) {
        throw new RuntimeException('Ο φάκελος εγγράφων δεν είναι εγγράψιμος.');
    }

    $fileName = $safeType . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $allowedMimes[$mime];
    $absolutePath = $absoluteDir . '/' . $fileName;
    if (!move_uploaded_file($tmpPath, $absolutePath)) {
        throw new RuntimeException('Το ανέβασμα του εγγράφου απέτυχε.');
    }

    return [
        'file_path' => $relativeDir . '/' . $fileName,
        'file_size' => $size,
        'mime_type' => $mime,
    ];
}

function deleteAthleteDocumentFile(string $relativePath, int $athleteId): void
{
    $normalised = str_replace('\\', '/', ltrim($relativePath, '/'));
    $allowedPrefix = 'uploads/athletes/' . $athleteId . '/docs/';
    if (!str_starts_with($normalised, $allowedPrefix) || str_contains($normalised, '..')) return;

    $absolutePath = dirname(__DIR__) . '/' . $allowedPrefix . basename($normalised);
    if (is_file($absolutePath)) @unlink($absolutePath);
}
