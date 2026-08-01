<?php

class PosmainUpdateMaintenance
{
    private string $flagPath;

    public function __construct(?string $flagPath = null)
    {
        $this->flagPath = $flagPath ?: dirname(__DIR__, 2) . '/var/maintenance.flag';
    }

    public function isEnabled(): bool
    {
        return is_file($this->flagPath);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function payload(): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $raw = file_get_contents($this->flagPath);
        if (!is_string($raw) || trim($raw) === '') {
            return ['enabled' => true];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : ['enabled' => true, 'message' => trim($raw)];
    }

    public function enable(array $context = []): void
    {
        $dir = dirname($this->flagPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('MAINTENANCE_FLAG_DIRECTORY_UNAVAILABLE');
        }

        $payload = array_merge([
            'enabled' => true,
            'since_utc' => gmdate('Y-m-d\TH:i:s\Z'),
        ], $context);

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $temporary = $this->flagPath . '.tmp';
        if (!is_string($json) || file_put_contents($temporary, $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('MAINTENANCE_FLAG_WRITE_FAILED');
        }
        if (!rename($temporary, $this->flagPath)) {
            @unlink($temporary);
            throw new RuntimeException('MAINTENANCE_FLAG_COMMIT_FAILED');
        }
    }

    public function disable(): void
    {
        if (is_file($this->flagPath) && !unlink($this->flagPath) && is_file($this->flagPath)) {
            throw new RuntimeException('MAINTENANCE_FLAG_REMOVE_FAILED');
        }
    }

    public function shouldBypassRequest(?string $scriptName = null): bool
    {
        $scriptName = (string) ($scriptName ?: ($_SERVER['SCRIPT_NAME'] ?? ''));
        $allowed = [
            '/api/admin/updates/start.php',
            '/api/admin/updates/status.php',
            '/api/admin/updates/check.php',
            '/api/health.php',
        ];

        foreach ($allowed as $path) {
            if (substr($scriptName, -strlen($path)) === $path) {
                return true;
            }
        }

        return false;
    }
}
