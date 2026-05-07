<?php 
session_start();
include('includes/connect.php');

if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

// اختر بنك للفحص
$bank_id = isset($_GET['bank_id']) ? intval($_GET['bank_id']) : 0;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص البنك</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 20px; }
        .card { box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        pre { background: #f8f9fa; padding: 10px; border-radius: 5px; font-size: 12px; }
        .sql-box { background: #e9ecef; padding: 10px; border-left: 3px solid #007bff; margin: 10px 0; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <h2 class="mb-4">🔍 فحص البنك - Debug</h2>

        <!-- اختيار البنك -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">اختر البنك</h5>
            </div>
            <div class="card-body">
                <form method="GET">
                    <div class="row">
                        <div class="col-md-6">
                            <select name="bank_id" class="form-select" required>
                                <option value="">-- اختر البنك --</option>
                                <?php
                                $banks = $conn->query("SELECT * FROM acc_head WHERE (parent_id = 124 OR code LIKE '124%') AND is_basic = 0 AND isdeleted = 0 ORDER BY aname");
                                while ($bank = $banks->fetch_assoc()) {
                                    $selected = ($bank['id'] == $bank_id) ? 'selected' : '';
                                    echo '<option value="' . $bank['id'] . '" ' . $selected . '>' . $bank['code'] . ' - ' . htmlspecialchars($bank['aname']) . '</option>';
                                }
                                ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary">فحص</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($bank_id > 0): 
            // جلب معلومات البنك
            $bank_info = $conn->query("SELECT * FROM acc_head WHERE id = $bank_id")->fetch_assoc();
        ?>

        <!-- معلومات البنك -->
        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">معلومات البنك</h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered">
                    <tr><th>ID</th><td><?= $bank_info['id'] ?></td></tr>
                    <tr><th>الكود</th><td><?= $bank_info['code'] ?></td></tr>
                    <tr><th>الاسم</th><td><?= htmlspecialchars($bank_info['aname']) ?></td></tr>
                    <tr><th>الرصيد</th><td><strong><?= number_format($bank_info['balance'], 2) ?></strong></td></tr>
                </table>
            </div>
        </div>

        <!-- العمليات من ot_head -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">العمليات من ot_head (acc1 أو acc2 = البنك)</h5>
            </div>
            <div class="card-body">
                <div class="sql-box">
                    <strong>SQL:</strong> SELECT * FROM ot_head WHERE (acc1 = <?= $bank_id ?> OR acc2 = <?= $bank_id ?>) AND isdeleted = 0 ORDER BY id DESC LIMIT 10
                </div>
                <?php
                $sql = "SELECT * FROM ot_head WHERE (acc1 = $bank_id OR acc2 = $bank_id) AND isdeleted = 0 ORDER BY id DESC LIMIT 10";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo '<div class="table-responsive"><table class="table table-sm table-bordered">';
                    echo '<thead class="table-dark"><tr>';
                    echo '<th>ID</th><th>pro_id</th><th>pro_tybe</th><th>pro_date</th><th>acc1</th><th>acc2</th><th>pro_value</th><th>info</th>';
                    echo '</tr></thead><tbody>';
                    
                    while ($row = $result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td>' . $row['id'] . '</td>';
                        echo '<td>' . $row['pro_id'] . '</td>';
                        echo '<td>' . $row['pro_tybe'] . '</td>';
                        echo '<td>' . $row['pro_date'] . '</td>';
                        echo '<td>' . $row['acc1'] . '</td>';
                        echo '<td>' . $row['acc2'] . '</td>';
                        echo '<td><strong>' . number_format($row['pro_value'], 2) . '</strong></td>';
                        echo '<td>' . htmlspecialchars($row['info']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                    echo '<div class="alert alert-info mt-2">عدد العمليات: ' . $result->num_rows . '</div>';
                } else {
                    echo '<div class="alert alert-warning">لا توجد عمليات في ot_head</div>';
                }
                ?>
            </div>
        </div>

        <!-- القيود من journal_entries -->
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0">القيود من journal_entries (account_id = البنك)</h5>
            </div>
            <div class="card-body">
                <div class="sql-box">
                    <strong>SQL:</strong> SELECT * FROM journal_entries WHERE account_id = <?= $bank_id ?> AND isdeleted = 0 ORDER BY id DESC LIMIT 20
                </div>
                <?php
                $sql = "SELECT je.*, jh.jdate, jh.details 
                        FROM journal_entries je 
                        LEFT JOIN journal_heads jh ON je.journal_id = jh.id
                        WHERE je.account_id = $bank_id AND je.isdeleted = 0 
                        ORDER BY je.id DESC LIMIT 20";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo '<div class="table-responsive"><table class="table table-sm table-bordered">';
                    echo '<thead class="table-dark"><tr>';
                    echo '<th>ID</th><th>journal_id</th><th>التاريخ</th><th>debit</th><th>credit</th><th>op_id</th><th>op2</th><th>التفاصيل</th>';
                    echo '</tr></thead><tbody>';
                    
                    $total_debit = 0;
                    $total_credit = 0;
                    
                    while ($row = $result->fetch_assoc()) {
                        $debit = floatval($row['debit']);
                        $credit = floatval($row['credit']);
                        $total_debit += $debit;
                        $total_credit += $credit;
                        
                        echo '<tr>';
                        echo '<td>' . $row['id'] . '</td>';
                        echo '<td>' . $row['journal_id'] . '</td>';
                        echo '<td>' . ($row['jdate'] ?? 'N/A') . '</td>';
                        echo '<td class="text-success"><strong>' . number_format($debit, 2) . '</strong></td>';
                        echo '<td class="text-danger"><strong>' . number_format($credit, 2) . '</strong></td>';
                        echo '<td>' . ($row['op_id'] ?? 'NULL') . '</td>';
                        echo '<td>' . ($row['op2'] ?? 'NULL') . '</td>';
                        echo '<td>' . htmlspecialchars($row['details'] ?? '') . '</td>';
                        echo '</tr>';
                    }
                    
                    echo '<tr class="table-info"><td colspan="3"><strong>الإجمالي</strong></td>';
                    echo '<td class="text-success"><strong>' . number_format($total_debit, 2) . '</strong></td>';
                    echo '<td class="text-danger"><strong>' . number_format($total_credit, 2) . '</strong></td>';
                    echo '<td colspan="3"><strong>الصافي: ' . number_format($total_debit - $total_credit, 2) . '</strong></td>';
                    echo '</tr>';
                    
                    echo '</tbody></table></div>';
                    echo '<div class="alert alert-info mt-2">عدد القيود: ' . $result->num_rows . '</div>';
                } else {
                    echo '<div class="alert alert-danger">❌ لا توجد قيود في journal_entries للبنك!</div>';
                    echo '<div class="alert alert-warning">هذا يعني أن السندات لم تُسجل في journal_entries أو account_id غير صحيح</div>';
                }
                ?>
            </div>
        </div>

        <!-- اختبار الاستعلام المستخدم في summary.php -->
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">اختبار استعلام summary.php</h5>
            </div>
            <div class="card-body">
                <?php
                $startdate = date('Y-m-01');
                $enddate = date('Y-m-d');
                ?>
                <div class="sql-box">
                    <strong>SQL:</strong>
                    <pre>SELECT oh.id, oh.pro_date, oh.pro_tybe, oh.info,
    COALESCE((SELECT SUM(debit) FROM journal_entries 
              WHERE account_id = <?= $bank_id ?> 
              AND (op_id = oh.id OR op2 = oh.id) 
              AND isdeleted = 0), 0) as my_debit,
    COALESCE((SELECT SUM(credit) FROM journal_entries 
              WHERE account_id = <?= $bank_id ?> 
              AND (op_id = oh.id OR op2 = oh.id) 
              AND isdeleted = 0), 0) as my_credit
FROM ot_head oh
WHERE (oh.acc1 = <?= $bank_id ?> OR oh.acc2 = <?= $bank_id ?>) 
AND oh.isdeleted = 0
AND oh.pro_date BETWEEN '<?= $startdate ?>' AND '<?= $enddate ?>'
ORDER BY oh.pro_date, oh.id</pre>
                </div>
                
                <?php
                $sql = "SELECT oh.id, oh.pro_date, oh.pro_tybe, oh.info,
                        COALESCE((SELECT SUM(debit) FROM journal_entries 
                                  WHERE account_id = $bank_id 
                                  AND (op_id = oh.id OR op2 = oh.id) 
                                  AND isdeleted = 0), 0) as my_debit,
                        COALESCE((SELECT SUM(credit) FROM journal_entries 
                                  WHERE account_id = $bank_id 
                                  AND (op_id = oh.id OR op2 = oh.id) 
                                  AND isdeleted = 0), 0) as my_credit
                        FROM ot_head oh
                        WHERE (oh.acc1 = $bank_id OR oh.acc2 = $bank_id) 
                        AND oh.isdeleted = 0
                        AND oh.pro_date BETWEEN '$startdate' AND '$enddate'
                        ORDER BY oh.pro_date, oh.id";
                
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    echo '<div class="table-responsive"><table class="table table-sm table-bordered">';
                    echo '<thead class="table-dark"><tr>';
                    echo '<th>ID</th><th>التاريخ</th><th>النوع</th><th>my_debit</th><th>my_credit</th><th>الملاحظات</th>';
                    echo '</tr></thead><tbody>';
                    
                    while ($row = $result->fetch_assoc()) {
                        echo '<tr>';
                        echo '<td>' . $row['id'] . '</td>';
                        echo '<td>' . $row['pro_date'] . '</td>';
                        echo '<td>' . $row['pro_tybe'] . '</td>';
                        echo '<td class="text-success"><strong>' . number_format($row['my_debit'], 2) . '</strong></td>';
                        echo '<td class="text-danger"><strong>' . number_format($row['my_credit'], 2) . '</strong></td>';
                        echo '<td>' . htmlspecialchars($row['info']) . '</td>';
                        echo '</tr>';
                    }
                    echo '</tbody></table></div>';
                } else {
                    echo '<div class="alert alert-warning">لا توجد نتائج</div>';
                }
                ?>
            </div>
        </div>

        <?php endif; ?>

        <div class="text-center mt-4">
            <a href="pos_barcode.php" class="btn btn-primary">العودة للنظام</a>
            <a href="refresh_balances.php" class="btn btn-success">تحديث الأرصدة</a>
        </div>
    </div>
</body>
</html>
