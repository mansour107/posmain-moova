<?php

require_once __DIR__ . '/BranchSecretProvider.php';

class CloudAuthService
{
    const DEFAULT_TIMESTAMP_WINDOW_SECONDS = 300;

    public function verifyRequest(
        BranchSecretProvider $secrets,
        string $branchUuid,
        string $timestamp,
        string $nonce,
        string $rawBody,
        string $signature,
        ?int $now = null,
        int $timestampWindowSeconds = self::DEFAULT_TIMESTAMP_WINDOW_SECONDS
    ): array {
        $secret = $secrets->getSecretForBranch($branchUuid);
        if ($secret === null || $secret === '') {
            return $this->reject('branch_inactive_or_secret_missing');
        }

        if (trim($nonce) === '') {
            return $this->reject('nonce_required');
        }

        if (trim($signature) === '') {
            return $this->reject('signature_required');
        }

        $requestTime = $this->parseTimestamp($timestamp);
        if ($requestTime === null) {
            return $this->reject('invalid_timestamp');
        }

        $now = $now ?? time();
        if (abs($now - $requestTime) > $timestampWindowSeconds) {
            return $this->reject('timestamp_out_of_window');
        }

        $expected = self::sign($secret, $timestamp, $nonce, $rawBody);
        if (!hash_equals($expected, $signature)) {
            return $this->reject('signature_mismatch');
        }

        return [
            'ok' => true,
            'branch_uuid' => $branchUuid,
            'reason' => 'ok',
        ];
    }

    public static function sign(string $secret, string $timestamp, string $nonce, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp . "\n" . $nonce . "\n" . $rawBody, $secret);
    }

    private function parseTimestamp(string $timestamp): ?int
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return null;
        }

        if (ctype_digit($timestamp)) {
            return (int) $timestamp;
        }

        $parsed = strtotime($timestamp);
        return $parsed === false ? null : $parsed;
    }

    private function reject(string $reason): array
    {
        return [
            'ok' => false,
            'reason' => $reason,
        ];
    }
}
