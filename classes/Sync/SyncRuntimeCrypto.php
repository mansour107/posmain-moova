<?php

class SyncRuntimeCrypto
{
    public const ENV_KEY = 'POSMAIN_CONFIG_ENCRYPTION_KEY';
    private const PREFIX = 'v1:';
    private const CIPHER = 'aes-256-gcm';
    private const IV_BYTES = 12;
    private const TAG_BYTES = 16;

    public function available(): bool
    {
        return $this->rawKeyMaterial() !== '';
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
        $value = getenv(self::ENV_KEY);
        return $value === false ? '' : trim((string) $value);
    }
}
