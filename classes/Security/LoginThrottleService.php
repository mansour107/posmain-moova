<?php

class LoginThrottleService
{
    public function isBlocked(mysqli $conn, string $username, string $ip, array $options = []): bool
    {
        $row = $this->find($conn, $username, $ip);
        if (!$row || empty($row['locked_until'])) {
            return false;
        }

        return strtotime((string) $row['locked_until']) > time();
    }

    public function recordFailure(mysqli $conn, string $username, string $ip, array $options = []): array
    {
        $maxAttempts = max(1, (int) ($options['max_attempts'] ?? 5));
        $windowSeconds = max(1, (int) ($options['window_seconds'] ?? 900));
        $lockSeconds = max(1, (int) ($options['lock_seconds'] ?? 900));
        $username = $this->normalizeUsername($username);
        $ip = $this->normalizeIp($ip);
        $usernameHash = $this->usernameHash($username);
        $userAgent = $this->truncate((string) ($options['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')), 255);
        $now = time();
        $nowSql = date('Y-m-d H:i:s', $now);

        $row = $this->findByHash($conn, $usernameHash, $ip);
        if ($row) {
            $lastFailedAt = strtotime((string) ($row['last_failed_at'] ?? '')) ?: 0;
            $attemptCount = ($lastFailedAt > 0 && $lastFailedAt >= ($now - $windowSeconds))
                ? ((int) $row['attempt_count'] + 1)
                : 1;
            $firstFailedAt = $attemptCount === 1 ? $nowSql : (string) $row['first_failed_at'];
            $lockedUntil = $attemptCount >= $maxAttempts ? date('Y-m-d H:i:s', $now + $lockSeconds) : ($row['locked_until'] ?? null);

            $stmt = $conn->prepare("
                UPDATE failed_login_attempts
                   SET username = ?,
                       user_agent = ?,
                       attempt_count = ?,
                       first_failed_at = ?,
                       last_failed_at = ?,
                       locked_until = ?
                 WHERE id = ?
            ");
            $id = (int) $row['id'];
            $stmt->bind_param('ssisssi', $username, $userAgent, $attemptCount, $firstFailedAt, $nowSql, $lockedUntil, $id);
            $stmt->execute();
            $stmt->close();

            return $this->findByHash($conn, $usernameHash, $ip) ?: [];
        }

        $attemptCount = 1;
        $lockedUntil = $attemptCount >= $maxAttempts ? date('Y-m-d H:i:s', $now + $lockSeconds) : null;
        $stmt = $conn->prepare("
            INSERT INTO failed_login_attempts (
                username_hash, username, ip, user_agent, attempt_count, first_failed_at, last_failed_at, locked_until
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('ssssisss', $usernameHash, $username, $ip, $userAgent, $attemptCount, $nowSql, $nowSql, $lockedUntil);
        $stmt->execute();
        $stmt->close();

        return $this->findByHash($conn, $usernameHash, $ip) ?: [];
    }

    public function clear(mysqli $conn, string $username, string $ip): void
    {
        $usernameHash = $this->usernameHash($this->normalizeUsername($username));
        $ip = $this->normalizeIp($ip);

        $stmt = $conn->prepare("
            DELETE FROM failed_login_attempts
             WHERE username_hash = ?
               AND ip = ?
        ");
        $stmt->bind_param('ss', $usernameHash, $ip);
        $stmt->execute();
        $stmt->close();
    }

    public function recordSuccess(mysqli $conn, string $username, string $ip): void
    {
        $this->clear($conn, $username, $ip);
    }

    private function find(mysqli $conn, string $username, string $ip): ?array
    {
        return $this->findByHash($conn, $this->usernameHash($this->normalizeUsername($username)), $this->normalizeIp($ip));
    }

    private function findByHash(mysqli $conn, string $usernameHash, string $ip): ?array
    {
        $stmt = $conn->prepare("
            SELECT *
              FROM failed_login_attempts
             WHERE username_hash = ?
               AND ip = ?
             LIMIT 1
        ");
        $stmt->bind_param('ss', $usernameHash, $ip);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }

    private function normalizeUsername(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            $username = 'unknown';
        }

        return $this->truncate(strtolower($username), 191);
    }

    private function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        return $this->truncate($ip !== '' ? $ip : 'unknown', 64);
    }

    private function usernameHash(string $username): string
    {
        return hash('sha256', $username);
    }

    private function truncate(string $value, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $limit, 'UTF-8');
        }

        return substr($value, 0, $limit);
    }
}
