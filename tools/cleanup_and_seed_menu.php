<?php

declare(strict_types=1);

/**
 * One-shot local cleanup: soft-delete junk/test menu rows and seed a realistic
 * restaurant + coffeeshop catalog.
 *
 * Usage:
 *   php tools/cleanup_and_seed_menu.php --apply
 *   php tools/cleanup_and_seed_menu.php --apply --json
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found\n";
    exit(1);
}

require_once __DIR__ . '/../includes/db_bootstrap.php';

$apply = in_array('--apply', $argv, true);
$asJson = in_array('--json', $argv, true);

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    fwrite(STDERR, 'Database connection unavailable: ' . $e->getMessage() . "\n");
    exit(1);
}

$categories = [
    'قهوة ساخنة',
    'قهوة باردة',
    'شاي ومشروبات ساخنة',
    'عصائر طازجة',
    'مشروبات غازية',
    'مخبوزات',
    'إفطار',
    'ساندويتشات',
    'برجر وراب',
    'باستا وبيتزا',
    'سلطات',
    'حلويات',
];

$catalog = [
    'قهوة ساخنة' => [
        ['إسبريسو', 35],
        ['إسبريسو دبل', 45],
        ['أمريكانو', 42],
        ['كابتشينو', 55],
        ['لاتيه', 58],
        ['فلات وايت', 60],
        ['كورتادو', 52],
        ['ماكياتو', 48],
        ['موكا', 65],
        ['لاتيه كراميل', 68],
        ['لاتيه فانيليا', 68],
        ['سبانيش لاتيه', 72],
        ['قهوة تركي', 40],
        ['هوت شوكليت', 55],
    ],
    'قهوة باردة' => [
        ['آيس أمريكانو', 48],
        ['آيس لاتيه', 62],
        ['آيس كابتشينو', 62],
        ['كولد برو', 65],
        ['آيس موكا', 70],
        ['آيس سبانيش لاتيه', 75],
        ['أفوجاتو', 68],
        ['فرابتشينو', 78],
    ],
    'شاي ومشروبات ساخنة' => [
        ['شاي أسود', 22],
        ['شاي أخضر', 25],
        ['شاي بالنعناع', 25],
        ['تشاي لاتيه', 55],
        ['ماتشا لاتيه', 70],
        ['شاي أعشاب', 28],
        ['ليمون زنجبيل ساخن', 30],
    ],
    'عصائر طازجة' => [
        ['عصير برتقال طازج', 45],
        ['عصير مانجو طازج', 50],
        ['عصير فراولة طازج', 50],
        ['ليمون بالنعناع', 40],
        ['عصير جوافة طازج', 45],
        ['عصير توت مشكل', 55],
        ['سموثي أفوكادو', 65],
        ['سموثي مانجو', 60],
    ],
    'مشروبات غازية' => [
        ['مياه معدنية صغير', 10],
        ['مياه معدنية كبير', 15],
        ['مياه فوارة', 20],
        ['بيبسي', 18],
        ['كوكاكولا', 18],
        ['سبرايت', 18],
        ['فانتا', 18],
        ['ريد بول', 45],
    ],
    'مخبوزات' => [
        ['كرواسون زبدة', 45],
        ['كرواسون شوكولاتة', 55],
        ['كرواسون جبن', 52],
        ['كرواسون لوز', 60],
        ['مافن توت أزرق', 48],
        ['مافن شوكولاتة', 48],
        ['سينابون', 55],
        ['خبز موز', 42],
        ['بسكويت تمر', 28],
        ['توست ساوردو', 40],
    ],
    'إفطار' => [
        ['بيض بندكت', 95],
        ['شكشوكة', 85],
        ['إفطار إنجليزي كامل', 120],
        ['توست أفوكادو', 75],
        ['طبق إفطار حلومي', 110],
        ['بان كيك بالمابل', 80],
        ['فرنش توست', 75],
        ['شوفان', 55],
    ],
    'ساندويتشات' => [
        ['كلوب ساندويتش', 95],
        ['كلوب دجاج', 90],
        ['ساندويتش تونة', 85],
        ['ساندويتش ديك رومي', 90],
        ['ساندويتش حلومي', 80],
        ['ساندويتش جبن مشوي', 65],
        ['ساندويتش BLT', 85],
    ],
    'برجر وراب' => [
        ['برجر لحم كلاسيك', 120],
        ['برجر دجاج', 105],
        ['برجر جبن', 130],
        ['راب دجاج مشوي', 95],
        ['راب فلافل', 70],
        ['راب سيزر', 90],
        ['راب شاورما لحم', 85],
        ['راب شاورما دجاج', 80],
    ],
    'باستا وبيتزا' => [
        ['بيتزا مارجريتا', 110],
        ['بيتزا بيبروني', 130],
        ['بيتزا دجاج', 135],
        ['بيني ألفريدو', 115],
        ['بيني أرابياتا', 100],
        ['سباجيتي بولونيز', 120],
        ['باستا دجاج', 125],
        ['خبز بالثوم', 40],
    ],
    'سلطات' => [
        ['سلطة سيزر', 85],
        ['سلطة سيزر بالدجاج', 105],
        ['سلطة يوناني', 90],
        ['سلطة خضراء', 70],
        ['فتوش', 75],
        ['تبولة', 65],
        ['سلطة كينوا', 95],
    ],
    'حلويات' => [
        ['تشيز كيك', 75],
        ['براوني شوكولاتة', 55],
        ['كيك جزر', 65],
        ['تيراميسو', 80],
        ['كيك شوكولاتة', 70],
        ['بسبوسة', 40],
        ['كنافة', 70],
        ['آيس كريم فانيليا', 35],
        ['آيس كريم شوكولاتة', 35],
        ['ميلك شيك فراولة', 60],
        ['ميلك شيك شوكولاتة', 60],
        ['ميلك شيك أوريو', 65],
    ],
];

function columnExists(mysqli $conn, string $table, string $column): bool
{
    $tableEsc = $conn->real_escape_string($table);
    $columnEsc = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableEsc}` LIKE '{$columnEsc}'");
    return $res !== false && $res->num_rows > 0;
}

function tableExists(mysqli $conn, string $table): bool
{
    $tableEsc = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '{$tableEsc}'");
    return $res !== false && $res->num_rows > 0;
}

$report = [
    'ok' => false,
    'dry_run' => !$apply,
    'soft_deleted_categories' => 0,
    'soft_deleted_items' => 0,
    'created_categories' => 0,
    'created_items' => 0,
    'created_item_units' => 0,
    'categories' => [],
    'sample_items' => [],
];

$unitId = 3;
$unitRes = $conn->query("SELECT id FROM myunits WHERE isdeleted = 0 ORDER BY id ASC LIMIT 1");
if ($unitRes && ($row = $unitRes->fetch_assoc())) {
    $unitId = (int) $row['id'];
}

$hasItemType = columnExists($conn, 'myitems', 'item_type');
$hasTrackStock = columnExists($conn, 'myitems', 'track_stock');
$hasIsActive = columnExists($conn, 'myitems', 'is_active');
$hasSprice = columnExists($conn, 'myitems', 'sprice');
$hasItemUnits = tableExists($conn, 'item_units');

if (!$apply) {
    $catCount = (int) ($conn->query('SELECT COUNT(*) c FROM item_group WHERE isdeleted = 0')->fetch_assoc()['c'] ?? 0);
    $itemCount = (int) ($conn->query('SELECT COUNT(*) c FROM myitems WHERE isdeleted = 0')->fetch_assoc()['c'] ?? 0);
    $report['would_soft_delete_categories'] = $catCount;
    $report['would_soft_delete_items'] = $itemCount;
    $report['would_create_categories'] = count($categories);
    $newItems = 0;
    foreach ($catalog as $items) {
        $newItems += count($items);
    }
    $report['would_create_items'] = $newItems;
    $report['ok'] = true;
    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "Dry run only. Re-run with --apply to replace menu data.\n";
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(0);
}

$conn->begin_transaction();

try {
    // Soft-delete all active categories and items (local demo DB cleanup).
    if (!$conn->query('UPDATE item_group SET isdeleted = 1 WHERE isdeleted = 0')) {
        throw new RuntimeException('Failed soft-deleting categories: ' . $conn->error);
    }
    $report['soft_deleted_categories'] = $conn->affected_rows;

    if (!$conn->query('UPDATE myitems SET isdeleted = 1 WHERE isdeleted = 0')) {
        throw new RuntimeException('Failed soft-deleting items: ' . $conn->error);
    }
    $report['soft_deleted_items'] = $conn->affected_rows;

    // Free unique iname/barcode keys held by soft-deleted rows so reseed can reuse names.
    $conn->query(
        "UPDATE myitems
         SET iname = CONCAT('__del_', id, '_', LEFT(iname, 150)),
             barcode = LEFT(CONCAT('DEL', id), 25)
         WHERE isdeleted = 1
           AND (iname NOT LIKE '__del_%' OR barcode NOT LIKE 'DEL%')"
    );

    if ($hasItemUnits) {
        $conn->query(
            'UPDATE item_units iu
             INNER JOIN myitems m ON m.id = iu.item_id
             SET iu.isdeleted = 1
             WHERE m.isdeleted = 1 AND iu.isdeleted = 0'
        );
    }

    $categoryIds = [];
    foreach ($categories as $name) {
        $nameEsc = $conn->real_escape_string($name);
        // Reuse soft-deleted row with same name if present, else insert.
        $existing = $conn->query("SELECT id FROM item_group WHERE gname = '{$nameEsc}' LIMIT 1");
        if ($existing && ($row = $existing->fetch_assoc())) {
            $id = (int) $row['id'];
            if (!$conn->query("UPDATE item_group SET isdeleted = 0, tenant = 0, branch = 0 WHERE id = {$id}")) {
                throw new RuntimeException('Failed reactivating category: ' . $conn->error);
            }
        } else {
            if (!$conn->query("INSERT INTO item_group (gname, isdeleted, tenant, branch) VALUES ('{$nameEsc}', 0, 0, 0)")) {
                throw new RuntimeException('Failed inserting category: ' . $conn->error);
            }
            $id = (int) $conn->insert_id;
        }
        $categoryIds[$name] = $id;
        $report['created_categories']++;
        $report['categories'][] = ['id' => $id, 'name' => $name];
    }

    $sku = 100001;
    foreach ($catalog as $categoryName => $items) {
        $groupId = $categoryIds[$categoryName] ?? 0;
        foreach ($items as [$itemName, $price]) {
            $barcode = (string) $sku;
            $sku++;
            $nameEsc = $conn->real_escape_string($itemName);
            $barcodeEsc = $conn->real_escape_string($barcode);
            $price = (float) $price;
            $cost = round($price * 0.4, 2);

            $cols = [
                'barcode' => "'{$barcodeEsc}'",
                'iname' => "'{$nameEsc}'",
                'price1' => (string) $price,
                'price2' => (string) $price,
                'price3' => (string) $price,
                'last_price' => (string) $price,
                'cost_price' => (string) $cost,
                'group1' => (string) $groupId,
                'itmqty' => '100',
                'info' => "'صنف قائمة مطعم / كافيه'",
                'isdeleted' => '0',
                'tenant' => '0',
                'branch' => '0',
            ];
            if ($hasSprice) {
                $cols['sprice'] = (string) $price;
            }
            if ($hasItemType) {
                $cols['item_type'] = "'sellable'";
            }
            if ($hasTrackStock) {
                $cols['track_stock'] = in_array($categoryName, ['مخبوزات', 'مشروبات غازية'], true) ? '1' : '0';
            }
            if ($hasIsActive) {
                $cols['is_active'] = '1';
            }

            $existing = $conn->query("SELECT id FROM myitems WHERE barcode = '{$barcodeEsc}' LIMIT 1");
            if ($existing && ($row = $existing->fetch_assoc())) {
                $itemId = (int) $row['id'];
                $sets = [];
                foreach ($cols as $col => $val) {
                    $sets[] = "`{$col}` = {$val}";
                }
                $sql = 'UPDATE myitems SET ' . implode(', ', $sets) . " WHERE id = {$itemId}";
                if (!$conn->query($sql)) {
                    throw new RuntimeException('Failed updating item: ' . $conn->error);
                }
            } else {
                $sql = 'INSERT INTO myitems (`' . implode('`, `', array_keys($cols)) . '`) VALUES (' . implode(', ', array_values($cols)) . ')';
                if (!$conn->query($sql)) {
                    throw new RuntimeException('Failed inserting item: ' . $conn->error);
                }
                $itemId = (int) $conn->insert_id;
            }

            if ($hasItemUnits) {
                $unitCheck = $conn->query(
                    "SELECT id FROM item_units WHERE item_id = {$itemId} AND unit_id = {$unitId} LIMIT 1"
                );
                if ($unitCheck && ($urow = $unitCheck->fetch_assoc())) {
                    $unitRowId = (int) $urow['id'];
                    $conn->query(
                        "UPDATE item_units
                         SET u_val = 1, def_sale = 1, price1 = {$price}, price2 = {$price}, price3 = {$price},
                             unit_barcode = '{$barcodeEsc}', cost_price = {$cost}, isdeleted = 0
                         WHERE id = {$unitRowId}"
                    );
                } else {
                    $conn->query(
                        "INSERT INTO item_units (item_id, unit_id, u_val, def_sale, price1, price2, price3, unit_barcode, cost_price, isdeleted, tenant, branch)
                         VALUES ({$itemId}, {$unitId}, 1, 1, {$price}, {$price}, {$price}, '{$barcodeEsc}', {$cost}, 0, 0, 0)"
                    );
                }
                $report['created_item_units']++;
            }

            $report['created_items']++;
            if (count($report['sample_items']) < 12) {
                $report['sample_items'][] = [
                    'id' => $itemId,
                    'name' => $itemName,
                    'category' => $categoryName,
                    'price' => $price,
                    'barcode' => $barcode,
                ];
            }
        }
    }

    $conn->commit();
    $report['ok'] = true;
} catch (Throwable $e) {
    $conn->rollback();
    $report['ok'] = false;
    $report['error'] = $e->getMessage();
    if ($asJson) {
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        fwrite(STDERR, $e->getMessage() . "\n");
    }
    exit(1);
}

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} else {
    echo "Menu cleanup complete.\n";
    echo "Soft-deleted categories: {$report['soft_deleted_categories']}\n";
    echo "Soft-deleted items: {$report['soft_deleted_items']}\n";
    echo "Active categories: {$report['created_categories']}\n";
    echo "Active items: {$report['created_items']}\n";
}
