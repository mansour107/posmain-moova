<?php

require_once __DIR__ . '/../MoovaPosIntegration.php';
require_once __DIR__ . '/MoovaPosMenuReconcileService.php';

class MoovaCatalogSyncWorker
{
    public function runOnce(mysqli $conn, array $config = [], array $options = []): array
    {
        MoovaPosIntegration::ensureSchema($conn);
        if (!defined('MOOVA_MENU_SYNC_LIBRARY_ONLY')) {
            define('MOOVA_MENU_SYNC_LIBRARY_ONLY', true);
        }
        require_once __DIR__ . '/../../ajax/moova_menu_sync_payload.php';

        $fingerprint = (string) (moova_menu_sync_fingerprint($conn)['fingerprint'] ?? '');
        $links = $conn->query("SELECT * FROM moova_pos_shop_links WHERE status = 'active' ORDER BY id ASC");
        $queued = 0;
        while ($link = $links->fetch_assoc()) {
            $last = (string) ($link['last_catalog_fingerprint'] ?? '');
            if ($fingerprint !== '' && ($last === '' || !hash_equals($last, $fingerprint))) {
                MoovaPosIntegration::enqueueCatalogSync($conn, $link, $fingerprint);
                $queued++;
            }
        }

        $conn->query("UPDATE moova_catalog_sync_outbox SET state = 'pending', available_at = NOW() WHERE state = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $limit = max(1, min(50, (int) ($options['batch_size'] ?? 10)));
        $rows = $conn->query("
            SELECT q.*, l.*, q.id AS queue_id, l.id AS link_id
            FROM moova_catalog_sync_outbox q
            INNER JOIN moova_pos_shop_links l ON l.id = q.shop_link_id AND l.status = 'active'
            WHERE q.state = 'pending' AND q.available_at <= NOW()
            ORDER BY q.available_at ASC, q.id ASC
            LIMIT {$limit}
        ");

        $processed = 0;
        $synced = 0;
        $failed = 0;
        while ($row = $rows->fetch_assoc()) {
            $queueId = (int) $row['queue_id'];
            $claim = $conn->prepare("UPDATE moova_catalog_sync_outbox SET state = 'processing' WHERE id = ? AND state = 'pending'");
            $claim->bind_param('i', $queueId);
            $claim->execute();
            $claimed = $claim->affected_rows === 1;
            $claim->close();
            if (!$claimed) {
                continue;
            }

            $processed++;
            try {
                $result = (new MoovaPosMenuReconcileService())->reconcile(
                    $conn,
                    $row,
                    MoovaPosIntegration::deviceTokenForLink($row),
                    $this->publicOrigin(),
                    'posmain_catalog_worker'
                );
            } catch (Throwable $e) {
                $result = ['ok' => false, 'reason' => 'worker_exception', 'message' => $e->getMessage()];
            }
            MoovaPosIntegration::recordCatalogSyncResult($conn, (int) $row['link_id'], $fingerprint, $result);
            if (!empty($result['ok'])) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return [
            'ok' => $failed === 0,
            'fingerprint' => $fingerprint,
            'queued' => $queued,
            'processed' => $processed,
            'synced' => $synced,
            'failed' => $failed,
        ];
    }

    private function publicOrigin(): string
    {
        foreach (['POSMAIN_MOOVA_POS_PUBLIC_ORIGIN', 'POSMAIN_PUBLIC_ORIGIN', 'POS_PUBLIC_URL'] as $name) {
            $value = getenv($name);
            if ($value !== false && trim((string) $value) !== '') {
                return rtrim(trim((string) $value), '/');
            }
        }
        return 'http://localhost';
    }
}
