<?php

function upload_image(array $file, string $subdir, int $maxKB = 2048): ?string
{
    $r = upload_image_ex($file, $subdir, $maxKB);
    return $r['ok'] ? $r['path'] : null;
}

function upload_image_ex(array $file, string $subdir, int $maxKB = 2048): array
{
    $code = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($code === UPLOAD_ERR_NO_FILE)
        return ['ok' => false, 'error' => 'no file selected'];
    if ($code !== UPLOAD_ERR_OK)
        return ['ok' => false, 'error' => 'upload error code ' . $code];
    if (($file['size'] ?? 0) > $maxKB * 1024)
        return ['ok' => false, 'error' => 'file too large'];

    $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext]))
        return ['ok' => false, 'error' => 'bad extension'];

    if (!function_exists('finfo_open'))
        return ['ok' => false, 'error' => 'fileinfo extension missing'];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if ($mime !== $allowed[$ext])
        return ['ok' => false, 'error' => 'mime mismatch'];

    $dir = PUBLIC_PATH . '/uploads/' . trim($subdir, '/');
    if (!is_dir($dir) && !mkdir($dir, 0775, true))
        return ['ok' => false, 'error' => 'mkdir failed'];
    if (!is_writable($dir))
        return ['ok' => false, 'error' => 'dir not writable'];

    $name = bin2hex(random_bytes(16)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name))
        return ['ok' => false, 'error' => 'move failed'];

    return ['ok' => true, 'path' => '/uploads/' . trim($subdir, '/') . '/' . $name];
}
