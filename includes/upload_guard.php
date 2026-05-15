<?php

if (!function_exists('posmain_upload_extension')) {
    function posmain_upload_extension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}

if (!function_exists('posmain_validate_image_upload')) {
    function posmain_validate_image_upload(array $file, int $maxBytes = 5242880): array
    {
        posmain_require_upload_ok($file);

        $name = (string) ($file['name'] ?? '');
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $extension = posmain_upload_extension($name);
        $allowed = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        ];

        if (!isset($allowed[$extension])) {
            throw new InvalidArgumentException('امتداد الملف غير مسموح به');
        }
        if ($size <= 0 || $size > $maxBytes) {
            throw new InvalidArgumentException('حجم الملف أكبر من المسموح');
        }
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new InvalidArgumentException('ملف الرفع غير صالح');
        }

        $imageInfo = @getimagesize($tmpName);
        if (!is_array($imageInfo) || empty($imageInfo['mime'])) {
            throw new InvalidArgumentException('الملف المحمل ليس صورة صالحة');
        }

        $mime = (string) $imageInfo['mime'];
        if ($mime !== $allowed[$extension]) {
            throw new InvalidArgumentException('نوع الصورة لا يطابق الامتداد');
        }

        return [
            'extension' => $extension === 'jpeg' ? 'jpg' : $extension,
            'mime' => $mime,
            'size' => $size,
            'tmp_name' => $tmpName,
        ];
    }
}

if (!function_exists('posmain_store_image_upload')) {
    function posmain_store_image_upload(array $file, string $targetDir, string $prefix = 'upload', int $maxBytes = 5242880): string
    {
        $validated = posmain_validate_image_upload($file, $maxBytes);
        $tmpName = $validated['tmp_name'];
        if (!is_uploaded_file($tmpName)) {
            throw new InvalidArgumentException('ملف الرفع غير صالح');
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            throw new RuntimeException('تعذر إنشاء مجلد الرفع');
        }

        $newName = posmain_upload_server_filename($prefix, $validated['extension']);
        $target = rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $newName;
        if (!move_uploaded_file($tmpName, $target)) {
            throw new RuntimeException('فشل في رفع الملف');
        }

        return $newName;
    }
}

if (!function_exists('posmain_validate_spreadsheet_upload')) {
    function posmain_validate_spreadsheet_upload(array $file, int $maxBytes = 10485760): array
    {
        posmain_require_upload_ok($file);

        $name = (string) ($file['name'] ?? '');
        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        $extension = posmain_upload_extension($name);
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            throw new InvalidArgumentException('امتداد ملف الأصناف غير مسموح به');
        }
        if ($size <= 0 || $size > $maxBytes) {
            throw new InvalidArgumentException('حجم ملف الأصناف أكبر من المسموح');
        }
        if ($tmpName === '' || !is_file($tmpName)) {
            throw new InvalidArgumentException('ملف الأصناف غير صالح');
        }

        return [
            'extension' => $extension,
            'size' => $size,
            'tmp_name' => $tmpName,
        ];
    }
}

if (!function_exists('posmain_upload_server_filename')) {
    function posmain_upload_server_filename(string $prefix, string $extension): string
    {
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix);
        $prefix = trim((string) $prefix, '_');
        if ($prefix === '') {
            $prefix = 'upload';
        }

        return $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(8)) . '.' . strtolower($extension);
    }
}

if (!function_exists('posmain_require_upload_ok')) {
    function posmain_require_upload_ok(array $file): void
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_OK) {
            return;
        }

        throw new InvalidArgumentException(posmain_upload_error_message($error));
    }
}

if (!function_exists('posmain_upload_error_message')) {
    function posmain_upload_error_message(int $error): string
    {
        switch ($error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'حجم الملف أكبر من المسموح';
            case UPLOAD_ERR_PARTIAL:
                return 'لم يكتمل رفع الملف';
            case UPLOAD_ERR_NO_FILE:
                return 'لم يتم اختيار ملف';
            default:
                return 'فشل في رفع الملف';
        }
    }
}
