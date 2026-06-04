<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This tool must be run from the command line.\n");
    exit(1);
}

$options = getopt('', ['parent:', 'dry-run', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php tools/repair_item_variant_links.php [--parent=ID] [--dry-run]\n");
    exit(0);
}

$dryRun = isset($options['dry-run']);
$parentId = isset($options['parent']) ? (int) $options['parent'] : 0;

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$conn = posmain_db_connect();
$service = new ItemVariantService();
$service->ensureSchema($conn);

if ($parentId > 0) {
    $candidateParents = [$parentId];
} else {
    $candidateParents = $service->parentIdsWithUnlinkedVariantChildren($conn);
}

$summary = [
    'parents_scanned' => 0,
    'children_linked' => 0,
    'parent_item_ids' => [],
];

foreach ($candidateParents as $candidateParentId) {
    $summary['parents_scanned']++;
    if ($dryRun) {
        $unlinked = $service->unlinkedVariantChildrenForParent($conn, (int) $candidateParentId);
        if ($unlinked) {
            $summary['parent_item_ids'][] = (int) $candidateParentId;
            $summary['children_linked'] += count($unlinked);
        }
        continue;
    }

    $repaired = $service->repairUnlinkedChildrenForParent($conn, (int) $candidateParentId);
    if ($repaired) {
        $summary['parent_item_ids'][] = (int) $candidateParentId;
        $summary['children_linked'] += count($repaired);
    }
}

$mode = $dryRun ? 'dry-run' : 'apply';
fwrite(STDOUT, "item-variant-link-repair-{$mode} parents={$summary['parents_scanned']} children={$summary['children_linked']}\n");
if ($summary['parent_item_ids']) {
    fwrite(STDOUT, 'parent_ids=' . implode(',', $summary['parent_item_ids']) . PHP_EOL);
}
