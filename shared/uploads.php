<?php
/**
 * shared/uploads.php
 *
 * One place that knows how to take files off an HTML form, check they're
 * actually what they claim to be, and put them somewhere safe on disk.
 * Required by BOTH portals:
 *   - public/api/reservation_create.php  (requirement files + pictures)
 *   - admin/api/post_events.php          (event documentation photos)
 *
 * Nothing here talks to the database — the caller inserts the metadata
 * rows it gets back. That keeps the file write and the DB write separate
 * so the caller can roll one back if the other fails.
 */

/** Where uploads live: <project>/public/uploads/<bucket>/ */
const UPLOAD_BUCKET_REQUIREMENTS = 'requirements';
const UPLOAD_BUCKET_POST_EVENTS  = 'post-events';

/** Hard ceilings. Also enforced in the browser, but never trusted from there. */
const UPLOAD_MAX_BYTES     = 10485760; // 10 MB per file
const UPLOAD_MAX_FILES     = 10;       // per submission

/**
 * Real MIME type => [file extension, kind]. Detected with finfo from the
 * file's actual bytes — the browser-supplied type and the filename
 * extension are both ignored, because both are trivially faked.
 */
function upload_allowed_types(): array {
    return [
        'image/jpeg' => ['jpg',  'image'],
        'image/png'  => ['png',  'image'],
        'image/webp' => ['webp', 'image'],
        'image/gif'  => ['gif',  'image'],
        'application/pdf' => ['pdf', 'document'],
        'application/msword' => ['doc', 'document'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'document'],
        'application/vnd.ms-excel' => ['xls', 'document'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'document'],
        'text/plain' => ['txt', 'document'],
    ];
}

/** Human-readable list for error messages and form hints. */
function upload_allowed_label(): string {
    return 'JPG, PNG, WEBP, GIF, PDF, DOC, DOCX, XLS, XLSX or TXT';
}

/** Absolute path to public/uploads/<bucket>, created on first use. */
function upload_dir(string $bucket): string {
    $dir = dirname(__DIR__) . '/public/uploads/' . $bucket;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('The upload folder could not be created. Check folder permissions on public/uploads.');
    }
    return $dir;
}

/** Absolute path to one stored file. Rejects anything with a path in it. */
function upload_path(string $bucket, string $storedName): string {
    $safe = basename($storedName);
    return upload_dir($bucket) . '/' . $safe;
}

/**
 * PHP hands multi-file inputs over as parallel arrays
 * ($_FILES['x']['name'][0], ['tmp_name'][0], ...). Flip that into a plain
 * list of one-file arrays so the rest of this file can just foreach it.
 * Also accepts a single-file input, so callers don't need two code paths.
 */
function normalize_files(?array $field): array {
    if (!$field || !isset($field['name'])) return [];

    if (!is_array($field['name'])) {
        $field = array_map(fn($v) => [$v], $field);
    }

    $out = [];
    foreach (array_keys($field['name']) as $i) {
        if (($field['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue; // empty slot — the user just didn't pick a file here
        }
        $out[] = [
            'name'     => (string)($field['name'][$i] ?? ''),
            'type'     => (string)($field['type'][$i] ?? ''),
            'tmp_name' => (string)($field['tmp_name'][$i] ?? ''),
            'error'    => (int)($field['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size'     => (int)($field['size'][$i] ?? 0),
        ];
    }
    return $out;
}

/** Turns PHP's numeric upload error codes into something a student can act on. */
function upload_error_message(int $code, string $name): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return "\"$name\" is larger than the server's upload limit. Please upload a smaller file.";
        case UPLOAD_ERR_PARTIAL:
            return "\"$name\" only uploaded partially. Please try again.";
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not save the file. Please contact OSAS.';
        case UPLOAD_ERR_EXTENSION:
            return "\"$name\" was blocked by the server.";
        default:
            return "\"$name\" could not be uploaded. Please try again.";
    }
}

/**
 * Word/Excel files are often sniffed as a generic type: .docx/.xlsx come back as
 * application/zip, and .doc/.xls as application/x-ole-storage, application/CDFV2
 * or application/vnd.ms-office (it depends on the server's magic database).
 * When that happens, trust the extension ONLY if the file's real bytes agree:
 * a ZIP that really contains word/ or xl/ parts, or an OLE2 header.
 */
function refine_office_mime(string $mime, string $tmpPath, string $originalName): string {
    $generic = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream',
                'application/x-ole-storage', 'application/cdfv2', 'application/cdfv2-corrupt',
                'application/vnd.ms-office', 'application/x-msword', 'application/x-ms-office'];
    if (!in_array($mime, $generic, true)) return $mime;

    $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $head = (string) @file_get_contents($tmpPath, false, null, 0, 8);

    if (in_array($ext, ['docx', 'xlsx'], true) && str_starts_with($head, "PK\x03\x04")) {
        $part = $ext === 'docx' ? 'word/document.xml' : 'xl/workbook.xml';
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $ok  = $zip->open($tmpPath) === true && $zip->locateName($part) !== false;
            if ($zip->numFiles ?? 0) $zip->close();
            if (!$ok) return $mime;
        }
        return $ext === 'docx'
            ? 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }
    if (in_array($ext, ['doc', 'xls'], true) && str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1")) {
        return $ext === 'doc' ? 'application/msword' : 'application/vnd.ms-excel';
    }
    return $mime;
}

/** Best-effort MIME sniffing; falls back to the extension if finfo is off. */
function detect_mime(string $tmpPath, string $originalName): string {
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = finfo_file($finfo, $tmpPath);
            finfo_close($finfo);
            if ($mime) return refine_office_mime(strtolower($mime), $tmpPath, $originalName);
        }
    }
    if (function_exists('mime_content_type')) {
        $mime = mime_content_type($tmpPath);
        if ($mime) return refine_office_mime(strtolower($mime), $tmpPath, $originalName);
    }
    // Last resort. Only used on servers with neither finfo nor mime_content_type.
    $byExt = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf',
        'txt' => 'text/plain',
    ];
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (isset($byExt[$ext])) return $byExt[$ext];
    return refine_office_mime('application/octet-stream', $tmpPath, $originalName);
}

/**
 * Validates and moves every uploaded file into public/uploads/<bucket>/.
 *
 * Returns a list of metadata rows ready to INSERT:
 *   ['kind','original_name','stored_name','mime_type','size_bytes']
 *
 * Throws RuntimeException with a message meant for the end user if anything
 * is wrong. On a partial failure it cleans up whatever it already wrote, so
 * the caller never ends up with orphaned files on disk.
 *
 * @param array $files   Output of normalize_files()
 * @param array $options ['images_only' => bool, 'min' => int, 'max' => int]
 */
function store_uploads(array $files, string $bucket, array $options = []): array {
    $imagesOnly = (bool)($options['images_only'] ?? false);
    $min        = (int)($options['min'] ?? 0);
    $max        = (int)($options['max'] ?? UPLOAD_MAX_FILES);

    if (count($files) < $min) {
        throw new RuntimeException($min === 1
            ? 'Please attach at least one file.'
            : "Please attach at least $min files.");
    }
    if (count($files) > $max) {
        throw new RuntimeException("You can upload at most $max files at a time.");
    }
    if (!$files) return [];

    $allowed = upload_allowed_types();
    $dir = upload_dir($bucket);
    $stored = [];
    $written = [];

    try {
        foreach ($files as $file) {
            $name = $file['name'] !== '' ? $file['name'] : 'file';

            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(upload_error_message($file['error'], $name));
            }
            if (!is_uploaded_file($file['tmp_name'])) {
                // Not a real POST upload — refuse rather than copy an arbitrary server path.
                throw new RuntimeException("\"$name\" was not received properly. Please try again.");
            }
            if ($file['size'] <= 0) {
                throw new RuntimeException("\"$name\" is empty.");
            }
            if ($file['size'] > UPLOAD_MAX_BYTES) {
                $mb = round(UPLOAD_MAX_BYTES / 1048576);
                throw new RuntimeException("\"$name\" is bigger than {$mb}MB. Please compress it and try again.");
            }

            $mime = detect_mime($file['tmp_name'], $name);
            if (!isset($allowed[$mime])) {
                throw new RuntimeException("\"$name\" isn't a supported file type. Allowed: " . upload_allowed_label() . '.');
            }

            [$ext, $kind] = $allowed[$mime];
            if ($imagesOnly && $kind !== 'image') {
                throw new RuntimeException("\"$name\" is not an image. Event documentation must be pictures (JPG, PNG, WEBP or GIF).");
            }
            if ($kind === 'image' && @getimagesize($file['tmp_name']) === false) {
                // Claims to be an image but isn't decodable — likely something disguised.
                throw new RuntimeException("\"$name\" looks damaged or isn't a real image file.");
            }

            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            $dest = $dir . '/' . $storedName;

            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                throw new RuntimeException("\"$name\" could not be saved on the server. Please try again.");
            }
            @chmod($dest, 0644);
            $written[] = $dest;

            $stored[] = [
                'kind'          => $kind,
                'original_name' => mb_substr($name, 0, 250),
                'stored_name'   => $storedName,
                'mime_type'     => $mime,
                'size_bytes'    => (int)$file['size'],
            ];
        }
    } catch (Throwable $e) {
        foreach ($written as $path) @unlink($path); // don't leave half an upload behind
        throw $e;
    }

    return $stored;
}

/** Deletes files previously written by store_uploads(). Used to undo a failed transaction. */
function delete_uploads(array $storedRows, string $bucket): void {
    foreach ($storedRows as $row) {
        $name = is_array($row) ? ($row['stored_name'] ?? '') : (string)$row;
        if ($name === '') continue;
        @unlink(upload_path($bucket, $name));
    }
}

/** "2.4 MB" / "812 KB" — for the file lists in both UIs. */
function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024) . ' KB';
    return $bytes . ' B';
}

/**
 * Streams a stored file to the browser. Images are sent inline so they can
 * be shown in an <img>; everything else is sent as an attachment so a
 * malicious .txt/.doc can never be rendered as HTML in our own origin.
 */
function stream_upload(string $bucket, string $storedName, string $originalName, string $mime): void {
    $path = upload_path($bucket, $storedName);
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo 'File not found on the server.';
        exit;
    }

    $isImage = str_starts_with($mime, 'image/');
    $disposition = $isImage ? 'inline' : 'attachment';
    $fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $originalName) ?: 'download';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; style-src \'unsafe-inline\'');
    header("Content-Disposition: $disposition; filename=\"$fallback\"; filename*=UTF-8''" . rawurlencode($originalName));
    header('Cache-Control: private, max-age=600');

    readfile($path);
    exit;
}