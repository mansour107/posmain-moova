<?php

class SyncRuntimeCrypto
{
    public const ENV_KEY = 'POSMAIN_CONFIG_ENCRYPTION_KEY';
    public const KEY_FILE_ENV = 'POSMAIN_CONFIG_ENCRYPTION_KEY_FILE';
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public static function defaultKeyFile(): string
    {
        $configured = getenv(self::KEY_FILE_ENV);
        if ($configured !== false && trim((string) $configured) !== '') {
            return trim((string) $configured);
        }

        return dirname(__DIR__, 2) . '/var/posmain-config-encryption.key';
    }

    public static function generateKeyMaterial(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    public function available(): bool
    {
        return $this->rawKeyMaterial() !== '';
    }

    public function currentKeyMaterial(): string
    {
        return $this->rawKeyMaterial();
    }

    public function keySource(): string
    {
        $path = self::defaultKeyFile();
        if (is_file($path) && is_readable($path) && trim((string) file_get_contents($path)) !== '') {
            return $path;
        }

        return getenv(self::ENV_KEY) !== false ? 'env' : '';
    }

    public function saveKeyMaterial(string $material, ?string $path = null): string
    {
        $material = trim($material);
        if ($material === '') {
            throw new InvalidArgumentException(self::ENV_KEY . ' cannot be empty.');
        }

        if (strpos($material, 'base64:') === 0) {
            $decoded = base64_decode(substr($material, 7), true);
            if ($decoded === false || $decoded === '') {
                throw new InvalidArgumentException(self::ENV_KEY . ' base64 value is invalid.');
            }
        }

        $path = $path ?: self::defaultKeyFile();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create config encryption key directory.');
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $material . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write config encryption key file.');
        }
        @chmod($tmp, 0600);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to replace config encryption key file.');
        }
        @chmod($path, 0600);

        putenv(self::ENV_KEY . '=' . $material);
        $_ENV[self::ENV_KEY] = $material;

        return $path;
    }

    public function encrypt(string $plaintext): string
    {
        $key = $this->key();
        $iv = random_bytes(self::IV_BYTES);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false || strlen($tag) !== self::TAG_BYTES) {
            throw new RuntimeException('Unable to encrypt sync runtime secret.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        if (strpos($payload, self::PREFIX) !== 0) {
            throw new RuntimeException('Unsupported sync runtime secret format.');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_BYTES + self::TAG_BYTES) {
            throw new RuntimeException('Invalid sync runtime secret payload.');
        }

        $iv = substr($raw, 0, self::IV_BYTES);
        $tag = substr($raw, self::IV_BYTES, self::TAG_BYTES);
        $ciphertext = substr($raw, self::IV_BYTES + self::TAG_BYTES);
        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Unable to decrypt sync runtime secret.');
        }

        return $plaintext;
    }

    private function key(): string
    {
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL is required for sync runtime encryption.');
        }

        $material = $this->rawKeyMaterial();
        if ($material === '') {
            throw new RuntimeException(self::ENV_KEY . ' is required before saving sync credentials.');
        }

        if (strpos($material, 'base64:') === 0) {
            $decoded = base64_decode(substr($material, 7), true);
            if ($decoded !== false && $decoded !== '') {
                $material = $decoded;
            }
        }

        return hash('sha256', $material, true);
    }

    private function rawKeyMaterial(): string
    {
        $path = self::defaultKeyFile();
        if (is_file($path) && is_readable($path)) {
            $value = trim((string) file_get_contents($path));
            if ($value !== '') {
                return $value;
            }
        }

        $value = getenv(self::ENV_KEY);
        return $value === false ? '' : trim((string) $value);
    }
}
