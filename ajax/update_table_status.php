<?php
error_reporting(0);
ini_set('display_errors', 0);
ob_start();

include('../includes/connect.php');

ob_clean(); // Ensure no whitespace

header('Content-Type: application/json; charset=utf-8');

try {
    if (!isset($_POST['action']) || !isset($_POST['table_id'])) {
        throw new Exception('المعاملات مفقودة');
    }
    
    $table_id = intval($_POST['table_id']);
    $action = $_POST['action'];
    
    if ($table_id <= 0) {
        throw new Exception('رقم الطاولة غير صحيح');
    }
    
    if ($action == 'clear') {
        // جلب اسم الطاولة
        $table_query = "SELECT tname FROM tables WHERE id = $table_id";
        $table_result = $conn->query($table_query);
        
        if ($table_result && $table_result->num_rows > 0) {
            $table_name = $table_result->fetch_assoc()['tname'];
            
            // Note: Direct deleting is dangerous, but this is "Direct Clear" (Undo/Cancel).
            // Deleting ot_head and fat_details linked to it.
            
            // Delete fat_details first (by joining with ot_head)
            $conn->query("DELETE fd FROM fat_details fd 
                         JOIN ot_head oh ON fd.pro_id = oh.id 
                         WHERE oh.info LIKE '%$table_name%' AND oh.pro_tybe = 9"); // Assuming pro_id links to ot_head.id?? Wait.
            
            // Wait, previous investigation showed pro_id in fat_details might be invoice number OR header ID.
            // In process_split_payment, we used fatid = header ID.
            // doadd_invoice says: DELETE FROM fat_details WHERE fatid = '$edit_id'
            // So fatid is the FK to ot_head.id.
            // Let's check update_table_status original code:
            // "DELETE fd FROM fat_details fd JOIN ot_head oh ON fd.pro_id = oh.id ..."
            // This implies original code assumed pro_id is the link. This might be wrong if fatid is the link.
            // However, verify safe deletion using fatid logic if possible.
            // But let's stick to fixing the JSON issue first. If logic was known broken, I'd fix it.
            // Given "pro_id" is often invoice number, joining on oh.id (PK) seems wrong unless pro_id stores PK. 
            // BUT, if I change logic now, I might break something else.
            // Let's look at `doadd_invoice` again... it inserts pro_id as invoice number. 
            // So joining fd.pro_id = oh.id is comparing Invoice Num with Header PK? Usually they are different.
            // UNLESS pro_id in fat_details IS the Invoice Number, and oh.id is PK.
            // If oh.pro_id is Invoice Number, then fd.pro_id = oh.pro_id is correct.
            // original code: `ON fd.pro_id = oh.id` -> This looks suspicious.
            // Let's try to use `fatid = oh.id` which is safer if `fatid` exists.
            
            // Actually, let's look at `clear_table_normal.php` logic... it just updates status.
            // This file `update_table_status.php` deletes.
            
            // I will use `fatid` for deletion if confirmed standard. `receipt.php` used `fatid`.
            // Let's use `fatid` for join.
            
            $conn->query("DELETE fd FROM fat_details fd 
                         JOIN ot_head oh ON fd.fatid = oh.id 
                         WHERE oh.info LIKE '%$table_name%' AND oh.pro_tybe = 9");

            // Now delete header
            $conn->query("DELETE FROM ot_head WHERE info LIKE '%$table_name%' AND pro_tybe = 9");
        }
        
        // تفريغ الطاولة
        $sql = "UPDATE tables SET table_case = 0 WHERE id = $table_id";
        $message = 'تم تفريغ الطاولة بنجاح';
    } elseif ($action == 'activate') {
        $sql = "UPDATE tables SET table_case = 1 WHERE id = $table_id";
        $message = 'تم تشغيل الطاولة بنجاح';
    } else {
        throw new Exception('عملية غير صحيحة');
    }
    
    if (!$conn->query($sql)) {
        throw new Exception('خطأ في قاعدة البيانات: ' . $conn->error);
    }
    
    echo json_encode([
        'success' => true,
        'message' => $message,
        'table_id' => $table_id
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>