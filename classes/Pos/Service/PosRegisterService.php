<?php

require_once __DIR__ . '/../../../config/app_config.php';
require_once __DIR__ . '/../../Security/SecurityAuditLogger.php';

class PosRegisterService
{
    public const COOKIE_NAME = 'posmain_register_token';

    public function tableExists(mysqli $conn): bool
    {
        $result = $conn->query("SHOW TABLES LIKE 'pos_registers'");

        return $result instanceof mysqli_result && $result->num_rows > 0;
    }

    public function ensureDefaultRegister(mysqli $conn, int $tenant = 0, int $branch = 0): array
    {
        if (!$this->tableExists($conn)) {
            throw new RuntimeException('POS_REGISTERS_MISSING');
        }

        $existing = $this->findActiveRegisters($conn, $tenant, $branch);
        if ($existing !== []) {
            return $existing[0];
        }

        return $this->createRegister($conn, [
            'tenant' => $tenant,
            'branch' => $branch,
            'code' => 'REG1',
            'name' => 'الصندوق 1',
            'paired_by' => (int) ($_SESSION['userid'] ?? 0) ?: null,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findActiveRegisters(mysqli $conn, int $tenant = 0, int $branch = 0): array
    {
        if (!$this->tableExists($conn)) {
            return [];
        }
        $stmt = $conn->prepare(
            'SELECT * FROM pos_registers
              WHERE tenant = ? AND branch = ? AND is_active = 1
              ORDER BY sort_order ASC, id ASC'
        );
        $stmt->bind_param('ii', $tenant, $branch);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createRegister(mysqli $conn, array $payload): array
    {
        $tenant = (int) ($payload['tenant'] ?? 0);
        $branch = (int) ($payload['branch'] ?? 0);
        $code = trim((string) ($payload['code'] ?? 'REG1'));
        $name = trim((string) ($payload['name'] ?? $code));
        $uuid = $this->uuid();
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $pairedBy = isset($payload['paired_by']) ? (int) $payload['paired_by'] : null;
        if ($pairedBy !== null && $pairedBy < 1) {
            $pairedBy = null;
        }

        $stmt = $conn->prepare(
            'INSERT INTO pos_registers
                (uuid, tenant, branch, code, name, pairing_token_hash, is_active, paired_at, paired_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), ?)'
        );
        $stmt->bind_param('siisssi', $uuid, $tenant, $branch, $code, $name, $tokenHash, $pairedBy);
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();
        if ($id < 1) {
            throw new RuntimeException('REGISTER_CREATE_FAILED');
        }

        $this->setPairingCookie($token);

        try {
            (new SecurityAuditLogger())->record($conn, 'pos_register_paired', [
                'user_id' => $pairedBy,
                'target_type' => 'pos_register',
                'target_id' => $id,
                'tenant' => $tenant,
                'branch' => $branch,
            ]);
        } catch (Throwable $ignored) {
        }

        $row = $this->registerById($conn, $id);
        $row['_pairing_token_once'] = $token;

        return $row;
    }

    public function resolveFromRequest(mysqli $conn, int $tenant = 0, int $branch = 0): ?array
    {
        if (!$this->tableExists($conn)) {
            return null;
        }
        $token = trim((string) ($_COOKIE[self::COOKIE_NAME] ?? ''));
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $stmt = $conn->prepare(
            'SELECT * FROM pos_registers
              WHERE pairing_token_hash = ?
                AND is_active = 1
                AND tenant = ?
                AND branch = ?
              LIMIT 1'
        );
        $stmt->bind_param('sii', $hash, $tenant, $branch);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }

        $id = (int) $row['id'];
        $conn->query('UPDATE pos_registers SET last_seen_at = NOW() WHERE id = ' . $id);

        return $row;
    }

    public function requirePairedRegister(mysqli $conn, int $tenant = 0, int $branch = 0): array
    {
        $register = $this->resolveFromRequest($conn, $tenant, $branch);
        if ($register) {
            return $register;
        }

        throw new RuntimeException('REGISTER_UNPAIRED');
    }

    public function revokeRegister(mysqli $conn, int $registerId, int $actorUserId = 0): void
    {
        $stmt = $conn->prepare(
            'UPDATE pos_registers
                SET is_active = 0, pairing_token_hash = NULL, updated_at = CURRENT_TIMESTAMP
              WHERE id = ?'
        );
        $stmt->bind_param('i', $registerId);
        $stmt->execute();
        $stmt->close();
        $this->clearPairingCookie();
        try {
            (new SecurityAuditLogger())->record($conn, 'pos_register_revoked', [
                'user_id' => $actorUserId ?: null,
                'target_type' => 'pos_register',
                'target_id' => $registerId,
            ]);
        } catch (Throwable $ignored) {
        }
    }

    public function setPairingCookie(string $token): void
    {
        $secure = function_exists('posmain_is_https_request') ? posmain_is_https_request() : false;
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + 60 * 60 * 24 * 365 * 5,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $token;
    }

    public function clearPairingCookie(): void
    {
        $secure = function_exists('posmain_is_https_request') ? posmain_is_https_request() : false;
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 42000,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /** @return array<string, mixed> */
    public function registerById(mysqli $conn, int $id): array
    {
        $stmt = $conn->prepare('SELECT * FROM pos_registers WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('REGISTER_NOT_FOUND');
        }

        return $row;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
