<?php

class ItemImagePathService
{
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    public static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    public static function uploadsDir(): string
    {
        return self::projectRoot() . '/uploads';
    }

    public static function sanitizeFileName(string $fileName): ?string
    {
        $fileName = trim(str_replace('\\', '/', $fileName));
        $fileName = basename($fileName);
        if ($fileName === '' || preg_match('/\.(php|phtml|phar|htaccess)$/i', $fileName)) {
            return null;
        }

        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return null;
        }

        return $fileName;
    }

    public static function absolutePath(string $fileName): ?string
    {
        $safeName = self::sanitizeFileName($fileName);
        if ($safeName === null) {
            return null;
        }

        $uploadsDir = self::uploadsDir();
        $candidate = $uploadsDir . '/' . $safeName;
        if (!is_file($candidate)) {
            return null;
        }

        $realUploads = realpath($uploadsDir);
        $realFile = realpath($candidate);
        if ($realUploads === false || $realFile === false) {
            return null;
        }

        if (!str_starts_with($realFile, $realUploads . DIRECTORY_SEPARATOR) && $realFile !== $realUploads) {
            return null;
        }

        return $realFile;
    }

    public static function fileSha256(string $absolutePath): ?string
    {
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $hash = @hash_file('sha256', $absolutePath);

        return is_string($hash) && strlen($hash) === 64 ? $hash : null;
    }

    public static function maxUploadBytes(array $config = []): int
    {
        $configured = (int) ($config['sync']['image_sync_max_upload_bytes'] ?? 10485760);

        return max(1024, min(52428800, $configured));
    }
}
