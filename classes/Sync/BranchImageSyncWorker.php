<?php

require_once __DIR__ . '/BranchImageSyncService.php';
require_once __DIR__ . '/ItemImageSyncQueueService.php';

class BranchImageSyncWorker
{
    private BranchImageSyncService $imageSync;
    private ItemImageSyncQueueService $queueService;

    public function __construct(?BranchImageSyncService $imageSync = null, ?ItemImageSyncQueueService $queueService = null)
    {
        $this->imageSync = $imageSync ?: new BranchImageSyncService();
        $this->queueService = $queueService ?: new ItemImageSyncQueueService();
    }

    public function runOnce(mysqli $conn, array $config = [], array $options = []): array
    {
        if (!$config && function_exists('posmain_app_config')) {
            $config = posmain_app_config();
        }

        $metrics = [
            'upload' => null,
            'download' => null,
            'pending_upload' => 0,
            'pending_download' => 0,
            'skipped' => null,
        ];

        if (!$this->imageSync->isEnabled($config)) {
            $metrics['skipped'] = 'image_sync_disabled';

            return $metrics;
        }

        $identityClass = class_exists('SyncBranchIdentity') ? 'SyncBranchIdentity' : 'BranchIdentity';
        $identity = (new $identityClass())->ensure($conn, $config);
        $branchUuid = strtolower(trim((string) ($identity['branch_uuid'] ?? '')));
        if ($branchUuid === '') {
            $metrics['skipped'] = 'branch_uuid_missing';

            return $metrics;
        }

        $this->queueService->releaseStaleLocks($conn);
        $workerId = (string) ($options['worker_id'] ?? (gethostname() . '-' . getmypid() . '-img'));

        $metrics['upload'] = $this->imageSync->runUploadBatch($conn, $config, array_merge($options, [
            'worker_id' => $workerId,
        ]));
        $metrics['download'] = $this->imageSync->runDownloadBatch($conn, $config, array_merge($options, [
            'worker_id' => $workerId . '-dl',
        ]));

        $uploadCounts = $this->queueService->countByStatus($conn, $branchUuid, 'branch_to_cloud');
        $downloadCounts = $this->queueService->countByStatus($conn, $branchUuid, 'cloud_to_branch');
        $metrics['pending_upload'] = (int) ($uploadCounts['pending'] ?? 0) + (int) ($uploadCounts['failed'] ?? 0);
        $metrics['pending_download'] = (int) ($downloadCounts['pending'] ?? 0) + (int) ($downloadCounts['failed'] ?? 0);

        return $metrics;
    }
}
