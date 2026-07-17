<?php

require_once __DIR__ . '/../../config/production_profile.php';

foreach (['branch', 'cloud'] as $role) {
    $config = [
        'role' => $role,
        'inventory' => [
            'ledger_mode' => 'live',
            'legacy_mirror' => false,
        ],
        'recipe' => [
            'mode' => 'full',
            'enabled' => true,
            'shadow_ledger' => false,
            'reservations' => true,
            'consumption' => true,
            'accounting' => true,
            'availability' => true,
            'moova_sync' => false,
        ],
        'financial' => [
            'certified_mode' => true,
        ],
        'tax' => [
            'enabled' => false,
        ],
        'sync' => [
            'cloud_pull_enabled' => true,
            'cloud_to_branch_publish_enabled' => true,
        ],
    ];

    $resolved = posmain_production_profile_apply($config);
    productionSyncProfileAssert(
        empty($resolved['sync']['cloud_pull_enabled']),
        $role . ' production profile must disable automatic cloud pull'
    );
    productionSyncProfileAssert(
        empty($resolved['sync']['cloud_to_branch_publish_enabled']),
        $role . ' production profile must disable automatic reverse publish'
    );
    productionSyncProfileAssert(
        in_array('production_profile_automatic_cloud_pull_disabled', $resolved['production_profile_warnings'] ?? [], true),
        $role . ' profile must report overridden automatic pull'
    );
    productionSyncProfileAssert(
        in_array('production_profile_automatic_reverse_publish_disabled', $resolved['production_profile_warnings'] ?? [], true),
        $role . ' profile must report overridden reverse publish'
    );
}

$restoreCli = file_get_contents(__DIR__ . '/../../tools/restore_branch_from_hosted.php');
productionSyncProfileAssert(is_string($restoreCli) && $restoreCli !== '', 'manual restore CLI must remain readable');
productionSyncProfileAssert(
    strpos($restoreCli, 'cloud_pull_enabled') === false && strpos($restoreCli, 'cloud_to_branch_publish_enabled') === false,
    'manual restore CLI must remain independent from automatic reverse flags'
);
productionSyncProfileAssert(
    strpos($restoreCli, 'BranchRestoreFromHostedService') !== false,
    'guarded manual restore entrypoint must remain available'
);

echo "production-sync-profile-ok\n";

function productionSyncProfileAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
