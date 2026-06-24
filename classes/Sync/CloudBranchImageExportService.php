<?php

require_once __DIR__ . '/BranchSecretProvider.php';
require_once __DIR__ . '/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/ItemImagePathService.php';

class CloudBranchImageExportService
{
    public static function exportSignatureBody(string $branchUuid, string $fileName): string
    {
        return json_encode([
            'branch_uuid' => strtolower(trim($branchUuid)),
            'file_name' => (string) ItemImagePathService::sanitizeFileName($fileName),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    public function handle(mysqli $conn, array $headers, string $branchUuid, string $fileName, array $config): array
    {
        $branchUuid = strtolower(trim($branchUuid));
        $safeName = ItemImagePathService::sanitizeFileName($fileName);
        if ($branchUuid === '' || $safeName === null) {
            return $this->jsonResponse(422, ['ok' => false, 'reason' => 'invalid_request']);
        }

        $signatureBody = self::exportSignatureBody($branchUuid, $safeName);
        $auth = (new CloudAuthService())->verifyRequest(
            new DatabaseBranchSecretProvider($conn),
            $branchUuid,
            (string) ($headers['timestamp'] ?? ''),
            (string) ($headers['nonce'] ?? ''),
            $signatureBody,
            (string) ($headers['signature'] ?? '')
        );
        if (empty($auth['ok'])) {
            return $this->jsonResponse(401, ['ok' => false, 'reason' => (string) ($auth['reason'] ?? 'unauthorized')]);
        }

        $absolutePath = ItemImagePathService::absolutePath($safeName);
        if ($absolutePath === null) {
            return $this->jsonResponse(404, ['ok' => false, 'reason' => 'file_not_found']);
        }

        $size = (int) filesize($absolutePath);
        if ($size <= 0 || $size > ItemImagePathService::maxUploadBytes($config)) {
            return $this->jsonResponse(413, ['ok' => false, 'reason' => 'file_too_large']);
        }

        return [
            'status_code' => 200,
            'stream' => true,
            'file_path' => $absolutePath,
            'file_name' => $safeName,
            'file_size' => $size,
            'file_sha256' => ItemImagePathService::fileSha256($absolutePath),
        ];
    }

    private function jsonResponse(int $statusCode, array $body): array
    {
        return [
            'status_code' => $statusCode,
            'body' => $body,
        ];
    }
}
