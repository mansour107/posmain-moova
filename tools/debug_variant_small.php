<?php

require_once __DIR__ . '/../includes/db_bootstrap.php';
require_once __DIR__ . '/../classes/Pos/Service/ItemVariantService.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$conn = posmain_db_connect();
$service = new ItemVariantService();
$service->ensureSchema($conn);

$parentId = (int) ($argv[1] ?? 987704);

$parent = $conn->query("SELECT id, iname FROM myitems WHERE id = {$parentId} LIMIT 1")->fetch_assoc();
echo 'parent: ' . json_encode($parent, JSON_UNESCAPED_UNICODE) . PHP_EOL;

$names = ['crepe - small', 'crepe - df', 'crepe - medium'];
foreach ($names as $name) {
    $stmt = $conn->prepare('SELECT id, iname, barcode, COALESCE(isdeleted, 0) AS isdeleted FROM myitems WHERE iname = ?');
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    echo $name . ' => ' . json_encode($rows, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}

$stmt = $conn->prepare('
    SELECT iv.id, iv.variant_label, iv.variant_item_id, iv.is_active, c.iname, COALESCE(c.isdeleted, 0) AS isdeleted
    FROM item_variants iv
    JOIN myitems c ON c.id = iv.variant_item_id
    WHERE iv.parent_item_id = ?
    ORDER BY iv.id
');
$stmt->bind_param('i', $parentId);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    echo 'variant_link: ' . json_encode($row, JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
$stmt->close();

echo 'variantsForParent(activeOnly=false): ' . json_encode($service->variantsForParent($conn, $parentId, false), JSON_UNESCAPED_UNICODE) . PHP_EOL;

if (method_exists($service, 'unlinkedVariantChildrenForParent')) {
    echo 'unlinked: ' . json_encode($service->unlinkedVariantChildrenForParent($conn, $parentId), JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
