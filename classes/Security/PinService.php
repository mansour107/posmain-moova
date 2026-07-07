<?php

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../PasswordService.php';

class PinService
{
    private const MIN_LENGTH = 4;
    private const MAX_LENGTH = 6;
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;
    private const TERMINAL_FREEZE_ATTEMPTS = 10;
    private const TERMINAL_FREEZE_SECONDS = 900;

    /** @var list<string> */
    private const BLACKLIST = [
        '0000', '1111', '2222', '3333', '4444', '5555', '6666', '7777', '8888', '9999',
        '1234', '4321', '1212', '1010', '2580', '6969', '1122', '1313',
    ];

    public function normalizePin(string $pin): string
    {
        return preg_replace('/\D+/', '', $pin) ?? '';
    }

    public function validatePinFormat(string $pin): void
    {
        $normalized = $this->normalizePin($pin);
        $length = strlen($normalized);
        if ($length < self::MIN_LENGTH || $length > self::MAX_LENGTH) {
            throw new InvalidArgumentException('PIN_FORMAT_INVALID');
        }
        if (in_array($normalized, self::BLACKLIST, true)) {
            throw new InvalidArgumentException('PIN_BLACKLISTED');
        }
    }

    public function pinLookup(string $pin): string
    {
        $this->validatePinFormat($pin);
        $normalized = $this->normalizePin($pin);
        $secret = posmain_pin_secret();

        return hash_hmac('sha256', $normalized, $secret);
    }

    public function hashPin(string $pin): string
    {
        $this->validatePinFormat($pin);

        return PasswordService::hashPassword($this->normalizePin($pin));
    }

    public function encryptPinForOwnerReveal(string $pin): string
    {
        $this->validatePinFormat($pin);
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OPENSSL_REQUIRED');
        }
        $key = hash('sha256', posmain_pin_secret() . '|owner_pin_reveal', true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($this->normalizePin($pin), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false || strlen($tag) !== 16) {
            throw new RuntimeException('PIN_ENCRYPT_FAILED');
        }

        return 'v1:' . base64_encode($iv . $tag . $cipher);
    }

    public function decryptPinForOwnerReveal(string $payload): ?string
    {
        if ($payload === '' || strpos($payload, 'v1:') !== 0 || !function_exists('openssl_decrypt')) {
            return null;
        }
        $raw = base64_decode(substr($payload, 3), true);
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $key = hash('sha256', posmain_pin_secret() . '|owner_pin_reveal', true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function revealPinForOwner(array $row): ?string
    {
        $pinEnc = trim((string) ($row['pin_enc'] ?? ''));
        if ($pinEnc === '') {
            return null;
        }

        return $this->decryptPinForOwnerReveal($pinEnc);
    }

    public function verifyPin(string $pin, string $storedHash): bool
    {
        if ($storedHash === '') {
            return false;
        }

        return PasswordService::verifyPassword($this->normalizePin($pin), $storedHash);
    }

    public function anyActiveUserHasPin(mysqli $conn): bool
    {
        if (!$this->pinColumnsExist($conn)) {
            return false;
        }

        $result = $conn->query(
            "SELECT 1 FROM users
              WHERE COALESCE(isdeleted, 0) != 1
                AND pin_set_at IS NOT NULL
                AND pin_hash IS NOT NULL
              LIMIT 1"
        );

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function isTerminalFrozen(mysqli $conn, string $ip): bool
    {
        if (!class_exists('LoginThrottleService', false)) {
            require_once __DIR__ . '/LoginThrottleService.php';
        }

        return (new LoginThrottleService())->isBlocked($conn, $this->terminalThrottleKey($ip), $ip);
    }

    public function recordTerminalFailure(mysqli $conn, string $ip): void
    {
        if (!class_exists('LoginThrottleService', false)) {
            require_once __DIR__ . '/LoginThrottleService.php';
        }

        (new LoginThrottleService())->recordFailure($conn, $this->terminalThrottleKey($ip), $ip, [
            'max_attempts' => self::TERMINAL_FREEZE_ATTEMPTS,
            'lock_seconds' => self::TERMINAL_FREEZE_SECONDS,
            'window_seconds' => self::TERMINAL_FREEZE_SECONDS,
        ]);
    }

    public function clearTerminalFailures(mysqli $conn, string $ip): void
    {
        if (!class_exists('LoginThrottleService', false)) {
            require_once __DIR__ . '/LoginThrottleService.php';
        }

        (new LoginThrottleService())->clear($conn, $this->terminalThrottleKey($ip), $ip);
    }

    public function isUserLocked(array $userRow): bool
    {
        if (empty($userRow['pin_locked_until'])) {
            return false;
        }

        return strtotime((string) $userRow['pin_locked_until']) > time();
    }

    public function recordUserFailure(mysqli $conn, int $userId): void
    {
        if ($userId < 1 || !$this->pinColumnsExist($conn)) {
            return;
        }

        $stmt = $conn->prepare(
            'SELECT failed_pin_attempts, pin_lockout_count FROM users WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return;
        }

        $attempts = (int) ($row['failed_pin_attempts'] ?? 0) + 1;
        $lockedUntil = null;
        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockoutCount = max(0, (int) ($row['pin_lockout_count'] ?? 0));
            $lockSeconds = self::LOCK_SECONDS * (2 ** min($lockoutCount, 4));
            $lockedUntil = date('Y-m-d H:i:s', time() + $lockSeconds);
            $attempts = 0;
        }

        $stmt = $conn->prepare(
            'UPDATE users
                SET failed_pin_attempts = ?,
                    pin_locked_until = ?,
                    pin_lockout_count = pin_lockout_count + ?
              WHERE id = ?'
        );
        $lockIncrement = $lockedUntil !== null ? 1 : 0;
        $stmt->bind_param('isii', $attempts, $lockedUntil, $lockIncrement, $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function clearUserFailures(mysqli $conn, int $userId): void
    {
        if ($userId < 1 || !$this->pinColumnsExist($conn)) {
            return;
        }

        $stmt = $conn->prepare(
            'UPDATE users
                SET failed_pin_attempts = 0,
                    pin_locked_until = NULL
              WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function generateAvailablePin(mysqli $conn, int $forUserId = 0): string
    {
        for ($i = 0; $i < 30; $i++) {
            $pin = (string) random_int(1000, 9999);
            try {
                posmain_pin_secret();
                $this->validatePinFormat($pin);
                $existing = $this->findUserByPin($conn, $pin);
                if (!$existing || ($forUserId > 0 && (int) ($existing['id'] ?? 0) === $forUserId)) {
                    return $pin;
                }
            } catch (Throwable) {
                continue;
            }
        }

        throw new RuntimeException('PIN_GENERATE_FAILED');
    }

    public function findUserByPin(mysqli $conn, string $pin): ?array
    {
        if (!$this->pinColumnsExist($conn)) {
            return null;
        }

        $lookup = $this->pinLookup($pin);
        $stmt = $conn->prepare(
            "SELECT id, uname, display_name, pin_hash, pin_locked_until, failed_pin_attempts, userrole, is_waiter
               FROM users
              WHERE pin_lookup = ?
                AND COALESCE(isdeleted, 0) != 1
                AND pin_set_at IS NOT NULL
              LIMIT 1"
        );
        $stmt->bind_param('s', $lookup);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    public function setPinForUser(mysqli $conn, int $userId, string $pin): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('USER_ID_REQUIRED');
        }
        if (!$this->pinColumnsExist($conn)) {
            throw new RuntimeException('PIN_SCHEMA_MISSING');
        }

        $this->validatePinFormat($pin);
        $lookup = $this->pinLookup($pin);
        $hash = $this->hashPin($pin);
        $now = date('Y-m-d H:i:s');
        $pinEnc = null;
        if ($this->pinEncColumnExists($conn)) {
            try {
                $pinEnc = $this->encryptPinForOwnerReveal($pin);
            } catch (Throwable) {
                $pinEnc = null;
            }
        }

        if ($pinEnc !== null) {
            $stmt = $conn->prepare(
                'UPDATE users
                    SET pin_hash = ?,
                        pin_lookup = ?,
                        pin_set_at = ?,
                        pin_enc = ?,
                        failed_pin_attempts = 0,
                        pin_locked_until = NULL
                  WHERE id = ?
                    AND COALESCE(isdeleted, 0) != 1'
            );
            $stmt->bind_param('ssssi', $hash, $lookup, $now, $pinEnc, $userId);
        } else {
            $stmt = $conn->prepare(
                'UPDATE users
                    SET pin_hash = ?,
                        pin_lookup = ?,
                        pin_set_at = ?,
                        failed_pin_attempts = 0,
                        pin_locked_until = NULL
                  WHERE id = ?
                    AND COALESCE(isdeleted, 0) != 1'
            );
            $stmt->bind_param('sssi', $hash, $lookup, $now, $userId);
        }
        try {
            $stmt->execute();
        } catch (mysqli_sql_exception $exception) {
            $stmt->close();
            if ((int) ($exception->getCode() ?? 0) === 1062
                || stripos($exception->getMessage(), 'uq_users_pin_lookup') !== false) {
                throw new RuntimeException('PIN_ALREADY_IN_USE', 0, $exception);
            }
            throw $exception;
        }
        if ($stmt->affected_rows < 1) {
            $stmt->close();
            throw new RuntimeException('USER_NOT_FOUND');
        }
        $stmt->close();
    }

    public function clearPinForUser(mysqli $conn, int $userId): void
    {
        if ($userId < 1 || !$this->pinColumnsExist($conn)) {
            return;
        }

        $clearEnc = $this->pinEncColumnExists($conn) ? ', pin_enc = NULL' : '';
        $stmt = $conn->prepare(
            'UPDATE users
                SET pin_hash = NULL,
                    pin_lookup = NULL,
                    pin_set_at = NULL' . $clearEnc . ',
                    failed_pin_attempts = 0,
                    pin_locked_until = NULL
              WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function terminalThrottleKey(string $ip): string
    {
        return 'pin_terminal:' . trim($ip);
    }

    private function pinEncColumnExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'pin_enc'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    private function pinColumnsExist(mysqli $conn): bool
    {
        $result = $conn->query("SHOW COLUMNS FROM users LIKE 'pin_hash'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
