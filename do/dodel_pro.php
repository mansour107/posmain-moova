<?php
require_once __DIR__ . '/../includes/rbac_route_guard.php';
rbac_guard_route('do/dodel_pro.php');

require_once('../classes/Inventory/InventoryInvoiceBridge.php');
$id = (int) ($_GET['id'] ?? 0);
$editpass = $_POST['editpass'] ?? '';
$pro_tybe = 0;
$op2 = 0;

if ($editpass != $edit_pass) {
    echo "تأكد من كلمة المرور ";
    die;
}else {
    $sql = "SELECT * FROM ot_head where id = $id";
    $rowop = $conn->query($sql)->fetch_assoc();
        if ($rowop) { 
        $op2 = $rowop['op2'];
        $pro_tybe = $rowop['pro_tybe'];
        }else{echo "لا توجد عمليات لهذا المعرف";}

        if ((int) $pro_tybe === 9) {
            header('Location: ../warning.php?error=pos_orders_use_cancel_or_refund');
            exit;
        }
    
        // التأكد من أن العملية مرتبطة بعمليات أخري
        if ($op2 > 0) {
        echo "توجد عمليات مرتبطة بالعملية المحددة >> برجاء مسح العملية الاساسية";
        die;
        }else{
            //مسح تفاصيل القيد للعمليه المصاحبة
            $conn->query("DELETE FROM journal_entries WHERE op2 =  '$id'");
            //مسح القيد للعملية المصاحبة
            $conn->query("DELETE FROM journal_heads WHERE op2 =  '$id'");
            //مسح العملية المصاحبة
            $sqldelot1 = "DELETE FROM ot_head WHERE op2 = '$id'";

            $conn->query($sqldelot1);
        }

    $inventoryInvoiceBridgeLines = [];
    if ($id > 0 && $pro_tybe > 0) {
        $inventoryInvoiceBridgeLines = posmainDeleteOperationInventoryBridgeLines($conn, $id);
        if ($inventoryInvoiceBridgeLines) {
            try {
                $conn->begin_transaction();
                $inventoryInvoiceBridge = new InventoryInvoiceBridge();
                $inventoryBridgeResult = $inventoryInvoiceBridge->recordInvoiceReversalLines(
                    $conn,
                    (int) $pro_tybe,
                    $id,
                    $inventoryInvoiceBridgeLines,
                    'operation_deleted',
                    [
                        'user_id' => isset($user) ? (int) $user : null,
                        'source_system' => 'legacy_dodel_pro',
                    ]
                );
                $conn->commit();
                if (!empty($inventoryBridgeResult['errors'])) {
                    error_log('Inventory operation delete bridge shadow errors: ' . json_encode($inventoryBridgeResult['errors']));
                }
            } catch (Throwable $inventoryBridgeException) {
                $conn->rollback();
                error_log('Inventory operation delete bridge shadow failure: ' . $inventoryBridgeException->getMessage());
            }
        }
    }

// مسح تفاصيل العملية 
    $conn->query("DELETE FROM fat_details where fatid = '$id'");
// مسح تفاصيل القيد
    $conn->query("DELETE FROM journal_entries where op_id = '$id'");
// مسح القيد 
    $conn->query("DELETE FROM journal_heads where op_id = '$id'");
// مسح العملية نفسها

    $conn->query("DELETE FROM ot_head where id = '$id'");
// العودة للتقرير
}
$process = "مسح عملية _ id ".$id." بواسطة ".$user ;
$conn->query("INSERT INTO process(type) VALUES ('$process')");


if ($pro_tybe == 1) {header('location:../operations_summary.php?q=receipt');}
if ($pro_tybe == 2) {header('location:../operations_summary.php?q=payment');}
if ($pro_tybe == 3) {header('location:../operations_summary.php?q=sale');}
if ($pro_tybe == 4) {header('location:../operations_summary.php?q=buy');}
if ($pro_tybe == 9) {header('location:../operations_summary.php?q=buy');}

function posmainDeleteOperationInventoryBridgeLines(mysqli $conn, int $operationId): array
{
    $stmt = $conn->prepare('
        SELECT id, item_id, qty_in, qty_out, u_val, cost_price, det_store
        FROM fat_details
        WHERE fatid = ?
          AND (isdeleted = 0 OR isdeleted IS NULL)
        ORDER BY id ASC
    ');
    $stmt->bind_param('i', $operationId);
    $stmt->execute();
    $result = $stmt->get_result();
    $lines = [];
    while ($row = $result->fetch_assoc()) {
        $lines[] = $row;
    }
    $stmt->close();

    return $lines;
}
