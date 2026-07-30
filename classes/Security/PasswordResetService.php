<?php

require_once __DIR__ . '/../PasswordService.php';
require_once __DIR__ . '/SecurityAuditLogger.php';

/**
 * Private password-reset workflow for Commercial V1 Step 1.
 *
 * Credentials are issued only from CLI. The plaintext token is returned once to
 * the operator and never written to application logs or audit metadata.
 */
final class PasswordResetService
{
    public const DEFAULT_TTL_SECONDS = 3600;

    private SecurityAuditLogger $audit;

    public function __construct(?SecurityAuditLogger $audit = null)
    {
        $this->audit = $audit ?: new SecurityAuditLogger();
    }

    public function ensureSchema(mysqli $conn): void
    {
        $sql = "
CREATE TABLE IF NOT EXISTS password_reset_tokens (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_by VARCHAR(120) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_password_reset_token_hash (token_hash),
  KEY idx_password_reset_user (user_id),
  KEY idx_password_reset_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
        if ($conn->query($sql) === false) {
            throw new RuntimeException('PASSWORD_RESET_SCHEMA_FAILED: ' . $conn->error);
        }
    }

    /**
     * Replace legacy MD5 (and empty) password hashes with unusable modern hashes.
     * Does not derive predictable passwords.
     *
     * @return array{invalidated:int,skipped:int,user_ids:list<int>}
     */
    public function invalidateLegacyPasswordHashes(mysqli $conn, array $options = []): array
    {
        $this->ensureSchema($conn);
        $dryRun = !empty($options['dry_run']);
        $result = $conn->query('SELECT id, uname, password FROM users WHERE COALESCE(isdeleted,0) != 1 ORDER BY id ASC');
        if ($result === false) {
            throw new RuntimeException('LEGACY_PASSWORD_SCAN_FAILED: ' . $conn->error);
        }

        $invalidated = 0;
        $skipped = 0;
        $userIds = [];
        while ($row = $result->fetch_assoc()) {
            $userId = (int) $row['id'];
            $stored = (string) ($row['password'] ?? '');
            if (!PasswordService::isLegacyMd5Hash($stored) && $stored !== '') {
                $skipped++;
                continue;
            }

            $userIds[] = $userId;
            if ($dryRun) {
                $invalidated++;
                continue;
            }

            $unusable = PasswordService::hashPassword(bin2hex(random_bytes(32)));
            $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ? LIMIT 1');
            $stmt->bind_param('si', $unusable, $userId);
            if (!$stmt->execute()) {
                $stmt->close();
                throw new RuntimeException('LEGACY_PASSWORD_INVALIDATE_FAILED: ' . $conn->error);
            }
            $stmt->close();
            $invalidated++;

            $this->audit->record($conn, 'password_legacy_invalidated', [
                'user_id' => null,
                'target_type' => 'user',
                'target_id' => $userId,
                'metadata' => [
                    'source' => 'cli',
                    'uname' => (string) $row['uname'],
                    // Never include hashes or replacement secrets.
                ],
            ]);
        }

        return [
            'invalidated' => $invalidated,
            'skipped' => $skipped,
            'user_ids' => $userIds,
        ];
    }

    /**
     * @return array{user_id:int,uname:string,token:string,expires_at:string}
     */
    public function issueResetToken(mysqli $conn, string $uname, array $options = []): array
    {
        $this->ensureSchema($conn);
        $uname = trim($uname);
        if ($uname === '') {
            throw new InvalidArgumentException('USERNAME_REQUIRED');
        }

        $stmt = $conn->prepare(
            'SELECT id, uname FROM users WHERE uname = ? AND COALESCE(isdeleted,0) != 1 LIMIT 1'
        );
        $stmt->bind_param('s', $uname);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            throw new RuntimeException('USER_NOT_FOUND');
        }

        $userId = (int) $row['id'];
        $ttl = max(60, (int) ($options['ttl_seconds'] ?? self::DEFAULT_TTL_SECONDS));
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $tokenHash = hash('sha256', $token);
        $createdBy = substr(trim((string) ($options['created_by'] ?? get_current_user() ?: 'cli')), 0, 120);

        $insert = $conn->prepare(
            'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_by)
             VALUES (?, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), ?)'
        );
        $insert->bind_param('isis', $userId, $tokenHash, $ttl, $createdBy);
        if (!$insert->execute()) {
            $insert->close();
            throw new RuntimeException('PASSWORD_RESET_ISSUE_FAILED: ' . $conn->error);
        }
        $tokenId = (int) $conn->insert_id;
        $insert->close();

        $expiresStmt = $conn->prepare('SELECT expires_at FROM password_reset_tokens WHERE id = ? LIMIT 1');
        $expiresStmt->bind_param('i', $tokenId);
        $expiresStmt->execute();
        $expiresRow = $expiresStmt->get_result()->fetch_assoc();
        $expiresStmt->close();
        $expiresAt = (string) ($expiresRow['expires_at'] ?? '');

        $this->audit->record($conn, 'password_reset_issued', [
            'user_id' => null,
            'target_type' => 'user',
            'target_id' => $userId,
            'metadata' => [
                'source' => 'cli',
                'expires_at' => $expiresAt,
                'created_by' => $createdBy,
                // Never store or log the plaintext token.
            ],
        ]);

        return [
            'user_id' => $userId,
            'uname' => (string) $row['uname'],
            'token' => $token,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Consume a single-use token and set a new password.
     */
    public function completeReset(mysqli $conn, string $token, string $newPassword, array $options = []): array
    {
        $this->ensureSchema($conn);
        $token = trim($token);
        $newPassword = (string) $newPassword;
        if ($token === '' || strlen($newPassword) < 8) {
            throw new InvalidArgumentException('PASSWORD_RESET_INPUT_INVALID');
        }

        $tokenHash = hash('sha256', $token);
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare(
                'SELECT id, user_id, expires_at, used_at
                   FROM password_reset_tokens
                  WHERE token_hash = ?
                  LIMIT 1
                  FOR UPDATE'
            );
            $stmt->bind_param('s', $tokenHash);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$row) {
                throw new RuntimeException('PASSWORD_RESET_TOKEN_INVALID');
            }
            if (!empty($row['used_at'])) {
                throw new RuntimeException('PASSWORD_RESET_TOKEN_USED');
            }
            $expiryCheck = $conn->prepare(
                'SELECT 1 AS ok FROM password_reset_tokens WHERE id = ? AND expires_at > UTC_TIMESTAMP() LIMIT 1'
            );
            $tokenIdForExpiry = (int) $row['id'];
            $expiryCheck->bind_param('i', $tokenIdForExpiry);
            $expiryCheck->execute();
            $stillValid = (bool) $expiryCheck->get_result()->fetch_assoc();
            $expiryCheck->close();
            if (!$stillValid) {
                throw new RuntimeException('PASSWORD_RESET_TOKEN_EXPIRED');
            }

            $userId = (int) $row['user_id'];
            $tokenId = (int) $row['id'];
            $hash = PasswordService::hashPassword($newPassword);
            $updateUser = $conn->prepare('UPDATE users SET password = ? WHERE id = ? LIMIT 1');
            $updateUser->bind_param('si', $hash, $userId);
            if (!$updateUser->execute() || $updateUser->affected_rows !== 1) {
                $updateUser->close();
                throw new RuntimeException('PASSWORD_RESET_USER_UPDATE_FAILED');
            }
            $updateUser->close();

            $mark = $conn->prepare(
                'UPDATE password_reset_tokens SET used_at = UTC_TIMESTAMP() WHERE id = ? AND used_at IS NULL LIMIT 1'
            );
            $mark->bind_param('i', $tokenId);
            if (!$mark->execute() || $mark->affected_rows !== 1) {
                $mark->close();
                throw new RuntimeException('PASSWORD_RESET_TOKEN_CONSUME_FAILED');
            }
            $mark->close();

            $this->audit->record($conn, 'password_reset_completed', [
                'user_id' => null,
                'target_type' => 'user',
                'target_id' => $userId,
                'metadata' => [
                    'source' => (string) ($options['source'] ?? 'cli'),
                    'token_id' => $tokenId,
                ],
            ]);

            $conn->commit();

            return [
                'user_id' => $userId,
                'token_id' => $tokenId,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}
