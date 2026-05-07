<?php
include('../includes/connect.php');

$recostSuccess = false;

try {
    $checkColumn = $conn->query("SHOW COLUMNS FROM myitems LIKE 'manual_price_edit'");
    if ($checkColumn && $checkColumn->num_rows == 0) {
        if (!$conn->query("ALTER TABLE myitems ADD COLUMN manual_price_edit TINYINT(1) DEFAULT 0")) {
            throw new RuntimeException('alter_column');
        }
    }

    $res = $conn->query("SELECT * FROM fat_details WHERE isdeleted = 0 ORDER BY crtime");
    if ($res === false) {
        throw new RuntimeException('select_details');
    }

    while ($row = $res->fetch_assoc()) {
        $curqty = $row['qty_in'];
        $curprice = $row['price'];
        $crtime = $row['crtime'];
        $item = $row['item_id'];
        $id = $row['id'];

        $manualEditCheck = $conn->query("SELECT manual_price_edit FROM myitems WHERE id = '$item'");
        if ($manualEditCheck && $manualEditCheck->num_rows > 0) {
            $manualData = $manualEditCheck->fetch_assoc();
            if (isset($manualData['manual_price_edit']) && $manualData['manual_price_edit'] == 1) {
                continue;
            }
        }

        $oldqtyRow = $conn->query("SELECT SUM(qty_in) - SUM(qty_out) AS old_qty 
                    FROM fat_details 
                    WHERE isdeleted = 0 
                    AND item_id = '$item'
                    AND crtime < '$crtime'")->fetch_assoc();
        $oldqty = $oldqtyRow['old_qty'] ?? 0;

        $oldpriceRow = $conn->query("SELECT cost_price FROM fat_details WHERE isdeleted = 0 AND pro_tybe = 4 AND item_id = '$item' AND crtime < '$crtime' order by crtime desc")->fetch_assoc();
        $oldprice = $oldpriceRow['cost_price'] ?? 0;

        $totalQty = (float) $curqty + (float) $oldqty;
        if ($totalQty != 0.0) {
            $new_cost = (($curprice * $curqty + $oldprice * $oldqty) / $totalQty);
        } else {
            $new_cost = (float) $curprice;
        }

        if ($row['pro_tybe'] == 4) {
            if ($oldqty == 0) {
                if (!$conn->query("UPDATE fat_details set cost_price = '$curprice' where id = '$id'")) {
                    throw new RuntimeException('update_purchase');
                }
            } else {
                if (!$conn->query("UPDATE fat_details set cost_price = '$new_cost' where id = '$id'")) {
                    throw new RuntimeException('update_purchase_avg');
                }
            }
        }

        if ($row['pro_tybe'] == 3 || $row['pro_tybe'] == 9) {
            $profit = $row['qty_out'] * ($row['price'] - $oldprice);
            if ($oldqty == 0) {
                if (!$conn->query("UPDATE fat_details set cost_price = 0, profit = $profit where id = '$id'")) {
                    throw new RuntimeException('update_sale_zero');
                }
            } else {
                if (!$conn->query("UPDATE fat_details set profit = $profit, cost_price = $new_cost where id = '$id'")) {
                    throw new RuntimeException('update_sale');
                }
            }
        }
    }

    $recostSuccess = true;
} catch (Throwable $e) {
    $recostSuccess = false;
}

header('Location: ../myitems.php?recost=' . ($recostSuccess ? 'ok' : 'fail'));
exit;
