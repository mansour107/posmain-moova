<?php

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/classes/Sync/CloudLegacyPosMirrorService.php');

cloudLegacyPosMirrorCategoryAssert(
    strpos($service, "'Synced Category '") === false,
    'menu mirror must not invent placeholder category names'
);
cloudLegacyPosMirrorCategoryAssert(
    strpos($service, 'if ($categoryName === null)') !== false,
    'menu mirror should skip category upsert when no source category name is present'
);

echo "cloud-legacy-pos-mirror-category-contract-ok\n";

function cloudLegacyPosMirrorCategoryAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
