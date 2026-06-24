<?php

require_once __DIR__ . '/BranchSecretProvider.php';
require_once __DIR__ . '/DatabaseBranchSecretProvider.php';
require_once __DIR__ . '/CloudAuthService.php';
require_once __DIR__ . '/ItemImagePathService.php';

class CloudBranchImageReceiveService
{
    public function handle(mysqli $conn, array $headers, array $metadata, array $file, array $config): array
    {
        $branchUuid = strtolower(trim((string) ($headers['branch_uuid'] ?? '')));
        $auth = (new CloudAuthService())->verifyRequest(
            new DatabaseBranchSecretProvider($conn),
            $branchUuid,
            (string) ($headers['timestamp'] ?? ''),
            (string) ($headers['nonce'] ?? ''),
            (string) ($metadata['raw'] ?? ''),
            (string) ($headers['signature'] ?? '')
        );
        if (empty($auth['ok'])) {
            return $this->response(401, ['ok' => false, 'reason' => (string) ($auth['reason'] ?? 'unauthorized')]);
        }

        $imgsId = (int) ($metadata['imgs_id'] ?? 0);
        $itemId = (int) ($metadata['item_id'] ?? 0);
        $fileName = ItemImagePathService::sanitizeFileName((string) ($metadata['file_name'] ?? ''));
        $fileSize = max(0, (int) ($metadata['file_size'] ?? 0));
        $fileSha256 = strtolower(trim((string) ($metadata['file_sha256'] ?? '')));
        if ($imgsId <= 0 || $itemId <= 0 || $fileName === null || strlen($fileSha256) !== 64) {
            return $this->response(422, ['ok' => false, 'reason' => 'invalid_metadata']);
        }

        if (empty($file['tmp_name']) || !is_uploaded_file((string) $file['tmp_name'])) {
            return $this->response(422, ['ok' => false, 'reason' => 'file_missing']);
        }

        $uploadedSize = (int) ($file['size'] ?? 0);
        if ($uploadedSize <= 0 || $uploadedSize > ItemImagePathService::maxUploadBytes($config)) {
            return $this->response(413, ['ok' => false, 'reason' => 'file_too_large']);
        }

        $uploadedHash = @hash_file('sha256', (string) $file['tmp_name']);
        if (!is_string($uploadedHash) || !hash_equals($fileSha256, $uploadedHash)) {
            return $this->response(422, ['ok' => false, 'reason' => 'hash_mismatch']);
        }

        $uploadsDir = ItemImagePathService::uploadsDir();
        if (!is_dir($uploadsDir) && !@mkdir($uploadsDir, 0755, true) && !is_dir($uploadsDir)) {
            return $this->response(500, ['ok' => false, 'reason' => 'uploads_dir_unavailable']);
        }

        $target = $uploadsDir . '/' . $fileName;
        if (is_file($target)) {
            $existingHash = ItemImagePathService::fileSha256($target);
            if ($existingHash !== null && hash_equals($fileSha256, $existingHash)) {
                $this->upsertImgsRow($conn, $imgsId, $itemId, $fileName, $uploadedSize);

                return $this->response(200, ['ok' => true, 'reason' => 'already_present', 'file_name' => $fileName]);
            }
        }

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            return $this->response(500, ['ok' => false, 'reason' => 'save_failed']);
        }

        $this->upsertImgsRow($conn, $imgsId, $itemId, $fileName, $uploadedSize);

        return $this->response(200, [
            'ok' => true,
            'reason' => 'saved',
            'file_name' => $fileName,
            'file_sha256' => $fileSha256,
        ]);
    }

    private function upsertImgsRow(mysqli $conn, int $imgsId, int $itemId, string $fileName, int $fileSize): void
    {
        if (!$this->tableExists($conn, 'imgs')) {
            return;
        }

        $stmt = $conn->prepare('SELECT id FROM imgs WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $imgsId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            $stmt = $conn->prepare('UPDATE imgs SET iname = ?, itemid = ?, size = ?, isdeleted = 0 WHERE id = ?');
            $stmt->bind_param('siii', $fileName, $itemId, $fileSize, $imgsId);
            $stmt->execute();
            $stmt->close();

            return;
        }

        $stmt = $conn->prepare('INSERT INTO imgs (id, iname, itemid, size, isdeleted) VALUES (?, ?, ?, ?, 0)');
        $stmt->bind_param('isii', $imgsId, $fileName, $itemId, $fileSize);
        $stmt->execute();
        $stmt->close();
    }

    private function tableExists(mysqli $conn, string $table): bool
    {
        $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
        $stmt->bind_param('s', $table);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row !== null;
    }

    private function response(int $statusCode, array $body): array
    {
        return [
            'status_code' => $statusCode,
            'body' => $body,
        ];
    }
}
