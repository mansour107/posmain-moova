<?php

require_once __DIR__ . '/../../config/app_config.php';
require_once __DIR__ . '/../PasswordService.php';

class PinService
{
    private const PIN_LENGTH = 4;
    private const MAX_ATTEMPTS = 5;
    private const LOCK_SECONDS = 900;
    private const TERMINAL_FREEZE_ATTEMPTS = 10;
    private const TERMINAL_FREEZE_SECONDS = 900;
    public const BOOTSTRAP_PIN = '0000';

    public function normalizePin(string $pin): string
    {
        // Strict: do not strip malformed characters for validation callers.
        // Lookup/hash still use digits-only only after format validation passes.
        return preg_replace('/\D+/', '', $pin) ?? '';
    }

    /**
     * @param array{allow_bootstrap?: bool} $options
     */
    public function validatePinFormat(string $pin, array $options = []): void
    {
        // Format only: any exact 4-digit PIN is allowed (no strength blacklist).
        // $options retained for call-site compatibility (e.g. allow_bootstrap).
        if (!preg_match('/^\d{' . self::PIN_LENGTH . '}$/', $pin)) {
            throw new InvalidArgumentException('PIN_FORMAT_INVALID');
        }
    }

    /**
     * @param array{allow_bootstrap?: bool} $options
     */
    public function pinLookup(string $pin, array $options = []): string
    {
        $this->validatePinFormat($pin, $options);
        $secret = posmain_pin_secret();

        return hash_hmac('sha256', $pin, $secret);
    }

    /**
     * @param array{allow_bootstrap?: bool} $options
     */
    public function hashPin(string $pin, array $options = []): string
    {
        $this->validatePinFormat($pin, $options);

        return PasswordService::hashPassword($pin);
    }

    public function encryptPinForOwnerReveal(string $pin): string
    {
        // Reversible PIN storage is retired. Keep method for compatibility but refuse.
        throw new RuntimeException('PIN_REVEAL_DISABLED');
    }

    public function decryptPinForOwnerReveal(string $payload): ?string
    {
        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function revealPinForOwner(array $row): ?string
    {
        return null;
    }

    public function verifyPin(string $pin, string $storedHash): bool
    {
        if ($storedHash === '' || !preg_match('/^\d{' . self::PIN_LENGTH . '}$/', $pin)) {
            return false;
        }

        return PasswordService::verifyPassword($pin, $storedHash);
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
        for ($i = 0; $i < 60; $i++) {
            $pin = str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
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
        $cols = 'id, uname, display_name, pin_hash, pin_locked_until, failed_pin_attempts, userrole, is_waiter';
        if ($this->columnExists($conn, 'pin_must_change')) {
            $cols .= ', pin_must_change';
        }
        if ($this->columnExists($conn, 'auth_version')) {
            $cols .= ', auth_version';
        }
        $stmt = $conn->prepare(
            "SELECT {$cols}
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

    /**
     * Install bootstrap PIN 0000 for owner. Private bootstrap-only path.
     */
    public function setBootstrapPinForOwner(mysqli $conn, int $userId, string $pin = self::BOOTSTRAP_PIN): void
    {
        if ($pin !== self::BOOTSTRAP_PIN) {
            throw new InvalidArgumentException('BOOTSTRAP_PIN_INVALID');
        }
        $this->setPinForUser($conn, $userId, $pin, [
            'allow_bootstrap' => true,
            'must_change' => true,
            'bump_auth_version' => true,
            'clear_pin_enc' => true,
        ]);
    }

    /**
     * @param array{
     *   allow_bootstrap?: bool,
     *   must_change?: bool,
     *   bump_auth_version?: bool,
     *   clear_pin_enc?: bool
     * } $options
     */
    public function setPinForUser(mysqli $conn, int $userId, string $pin, array $options = []): void
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('USER_ID_REQUIRED');
        }
        if (!$this->pinColumnsExist($conn)) {
            throw new RuntimeException('PIN_SCHEMA_MISSING');
        }

        $allowBootstrap = !empty($options['allow_bootstrap']);
        $this->validatePinFormat($pin, ['allow_bootstrap' => $allowBootstrap]);
        $lookup = $this->pinLookup($pin, ['allow_bootstrap' => $allowBootstrap]);
        $hash = $this->hashPin($pin, ['allow_bootstrap' => $allowBootstrap]);
        $now = date('Y-m-d H:i:s');
        $mustChange = array_key_exists('must_change', $options) ? (!empty($options['must_change']) ? 1 : 0) : 0;
        $bumpAuth = !array_key_exists('bump_auth_version', $options) || !empty($options['bump_auth_version']);

        $sets = [
            'pin_hash = ?',
            'pin_lookup = ?',
            'pin_set_at = ?',
            'failed_pin_attempts = 0',
            'pin_locked_until = NULL',
        ];
        $types = 'sss';
        $values = [$hash, $lookup, $now];

        if ($this->columnExists($conn, 'pin_enc')) {
            $sets[] = 'pin_enc = NULL';
        }
        if ($this->columnExists($conn, 'pin_must_change')) {
            $sets[] = 'pin_must_change = ?';
            $types .= 'i';
            $values[] = $mustChange;
        }
        if ($this->columnExists($conn, 'pin_changed_at') && !$mustChange) {
            $sets[] = 'pin_changed_at = ?';
            $types .= 's';
            $values[] = $now;
        }
        if ($bumpAuth && $this->columnExists($conn, 'auth_version')) {
            $sets[] = 'auth_version = auth_version + 1';
        }

        $sql = 'UPDATE users SET ' . implode(', ', $sets)
            . ' WHERE id = ? AND COALESCE(isdeleted, 0) != 1';
        $types .= 'i';
        $values[] = $userId;

        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$values);
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

        $sets = [
            'pin_hash = NULL',
            'pin_lookup = NULL',
            'pin_set_at = NULL',
            'failed_pin_attempts = 0',
            'pin_locked_until = NULL',
        ];
        if ($this->columnExists($conn, 'pin_enc')) {
            $sets[] = 'pin_enc = NULL';
        }
        if ($this->columnExists($conn, 'pin_must_change')) {
            $sets[] = 'pin_must_change = 0';
        }
        if ($this->columnExists($conn, 'auth_version')) {
            $sets[] = 'auth_version = auth_version + 1';
        }

        $stmt = $conn->prepare(
            'UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    public function bumpAuthVersion(mysqli $conn, int $userId): void
    {
        if ($userId < 1 || !$this->columnExists($conn, 'auth_version')) {
            return;
        }
        $stmt = $conn->prepare(
            'UPDATE users SET auth_version = auth_version + 1 WHERE id = ?'
        );
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->close();
    }

    private function terminalThrottleKey(string $ip): string
    {
        return 'pin_terminal:' . trim($ip);
    }

    private function pinColumnsExist(mysqli $conn): bool
    {
        return $this->columnExists($conn, 'pin_hash');
    }

    private function columnExists(mysqli $conn, string $column): bool
    {
        $column = $conn->real_escape_string($column);
        $result = $conn->query("SHOW COLUMNS FROM users LIKE '{$column}'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }
}
