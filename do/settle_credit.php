<?php
include('../includes/connect.php');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    
        // Fetch Invoice Details
        $row = $conn->query("SELECT * FROM ot_head WHERE id = $id")->fetch_assoc();
        
        if ($row) {
            $amount = $row['jal_amount'];
            $customer_acc = $row['acc1']; // Assuming acc1 holds the customer account ID for Sales/POS
            $pro_id_ref = $row['pro_id'];
            $emp_id = $row['emp_id'];
            $usid = $_SESSION['userid'] ?? 1;
            $date = date('Y-m-d');
            $full_date = date('Y-m-d H:i A');
            
            // Assume Main Safe Account ID is 1 (Or you might need to fetch it from settings/users)
            // Ideally, this should come from a configuration. For now, defaulting to 1 or checking if there's a 'fund_id' in session/settings
            $safe_acc = 51; // Common default for Safe, or we can query it.
            // Let's try to find a "Safe" account if 51 is not guaranteed.
            // Simple query to find the first cash account:
            $safe_res = $conn->query("SELECT id FROM acc_head WHERE aname LIKE '%خزينة%' OR aname LIKE '%صندوق%' LIMIT 1");
            if ($safe_res && $safe_res->num_rows > 0) {
                $safe_acc = $safe_res->fetch_assoc()['id'];
            }
            
            $conn->begin_transaction();
            try {
                // 1. Create Receipt Voucher (سند قبض) - pro_tybe = 1
                $stmt = $conn->prepare(
                    "INSERT INTO ot_head (
                        pro_tybe, is_journal, journal_tybe, info, pro_date, 
                        emp_id, acc1, acc2, pro_value, fat_net, cost_center, profit, user, op2
                    ) VALUES (1, 1, 1, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)"
                );
                
                $info_text = "تسوية مديونية / سداد آجل فاتورة رقم " . $pro_id_ref;
                $stmt->bind_param("sssiiddii", 
                    $info_text, $date, $emp_id, $safe_acc, $customer_acc, 
                    $amount, $amount, $usid, $id
                );
                $stmt->execute();
                $receipt_id = $conn->insert_id;
                $stmt->close();
                
                // 2. Journal Entry (قيد يومية)
                // Get next Journal ID
                $res_jid = $conn->query("SELECT MAX(journal_id) as max_id FROM journal_heads");
                $row_jid = $res_jid->fetch_assoc();
                $journal_id = ($row_jid['max_id'] ?? 0) + 1;
                
                // Header
                $stmt = $conn->prepare("INSERT INTO journal_heads (journal_id, op_id, total, jdate, details, user, op2) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $j_details = "سند قبض تسوية فاتورة " . $pro_id_ref;
                $stmt->bind_param("idsssii", $journal_id, $receipt_id, $amount, $date, $j_details, $usid, $id);
                $stmt->execute();
                $j_head_id = $conn->insert_id;
                $stmt->close();
                
                // Debit Safe (من ح/ الخزينة)
                $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, ?, 0, 0, ?)");
                $stmt->bind_param("iidi", $j_head_id, $safe_acc, $amount, $id);
                $stmt->execute();
                $stmt->close();
                
                // Credit Customer (إلى ح/ العميل)
                $stmt = $conn->prepare("INSERT INTO journal_entries (journal_id, account_id, debit, credit, tybe, op2) VALUES (?, ?, 0, ?, 1, ?)");
                $stmt->bind_param("iidi", $j_head_id, $customer_acc, $amount, $id);
                $stmt->execute();
                $stmt->close();
                
                // 3. Update Original Invoice
                $note_append = "\n- تم سداد الأجل ($amount) بتاريخ " . $full_date;
                $stmt = $conn->prepare("UPDATE ot_head SET jal_amount = 0, jal_notes = CONCAT(COALESCE(jal_notes, ''), ?) WHERE id = ?");
                $stmt->bind_param("si", $note_append, $id);
                $stmt->execute();
                $stmt->close();
                
                $conn->commit();
                header("Location: ../operations_summary.php?success=settled&q=all");
                
            } catch (Exception $e) {
                $conn->rollback();
                header("Location: ../operations_summary.php?error=sql&q=all");
            }
        } else {
             header("Location: ../operations_summary.php?error=invalid_id&q=all");
        }
} else {
    header("Location: ../operations_summary.php");
}
?>
