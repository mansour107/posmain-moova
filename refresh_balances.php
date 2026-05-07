<?php 
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

$updated = false;

if (isset($_POST['refresh'])) {
    // تحديث الأرصدة
    $sql = "UPDATE acc_head SET balance = (
        SELECT COALESCE(SUM(journal_entries.debit) - SUM(journal_entries.credit), 0) 
        FROM journal_entries 
        WHERE journal_entries.account_id = acc_head.id 
        AND journal_entries.isdeleted = 0
    )";
    
    if ($conn->query($sql)) {
        $updated = true;
    }
}

// جلب أرصدة الصناديق والبنوك
$funds = $conn->query("SELECT * FROM acc_head WHERE is_fund = 1 AND is_basic = 0 AND isdeleted = 0 ORDER BY aname");
$banks = $conn->query("SELECT * FROM acc_head WHERE (parent_id = 124 OR code LIKE '124%') AND is_basic = 0 AND isdeleted = 0 ORDER BY aname");
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تحديث الأرصدة</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 30px; }
        .card { box-shadow: 0 2px 4px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .balance-positive { color: #28a745; font-weight: bold; }
        .balance-negative { color: #dc3545; font-weight: bold; }
        .balance-zero { color: #6c757d; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row mb-3">
            <div class="col">
                <h2><i class="fas fa-sync-alt me-2"></i>تحديث الأرصدة</h2>
            </div>
            <div class="col-auto">
                <a href="pos_barcode.php" class="btn btn-primary">العودة للنظام</a>
            </div>
        </div>

        <?php if ($updated): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i>
            <strong>تم التحديث بنجاح!</strong> تم إعادة حساب جميع الأرصدة من القيود المحاسبية.
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>معلومات</h5>
            </div>
            <div class="card-body">
                <p>هذه الصفحة تقوم بإعادة حساب أرصدة جميع الحسابات من القيود المحاسبية في جدول <code>journal_entries</code></p>
                <p><strong>الرصيد = إجمالي المدين - إجمالي الدائن</strong></p>
                <form method="POST">
                    <button type="submit" name="refresh" class="btn btn-info btn-lg">
                        <i class="fas fa-sync-alt me-2"></i>تحديث الأرصدة الآن
                    </button>
                </form>
            </div>
        </div>

        <!-- أرصدة الصناديق -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-cash-register me-2"></i>أرصدة الصناديق</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>الكود</th>
                                <th>اسم الصندوق</th>
                                <th class="text-end">الرصيد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($funds && $funds->num_rows > 0) {
                                while ($fund = $funds->fetch_assoc()) {
                                    $balance = floatval($fund['balance']);
                                    $balance_class = $balance > 0 ? 'balance-positive' : ($balance < 0 ? 'balance-negative' : 'balance-zero');
                                    ?>
                                    <tr>
                                        <td><?= $fund['code'] ?></td>
                                        <td><?= htmlspecialchars($fund['aname']) ?></td>
                                        <td class="text-end <?= $balance_class ?>">
                                            <?= number_format($balance, 2) ?> ج.م
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="3" class="text-center">لا توجد صناديق</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- أرصدة البنوك -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-university me-2"></i>أرصدة البنوك</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>الكود</th>
                                <th>اسم البنك</th>
                                <th class="text-end">الرصيد</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if ($banks && $banks->num_rows > 0) {
                                while ($bank = $banks->fetch_assoc()) {
                                    $balance = floatval($bank['balance']);
                                    $balance_class = $balance > 0 ? 'balance-positive' : ($balance < 0 ? 'balance-negative' : 'balance-zero');
                                    ?>
                                    <tr>
                                        <td><?= $bank['code'] ?></td>
                                        <td><?= htmlspecialchars($bank['aname']) ?></td>
                                        <td class="text-end <?= $balance_class ?>">
                                            <?= number_format($balance, 2) ?> ج.م
                                        </td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="3" class="text-center">لا توجد بنوك</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="alert alert-warning">
            <strong>ملاحظة:</strong> إذا كانت الأرصدة لا تزال 0.00، هذا يعني أن القيود القديمة (قبل إصلاح الجدول) كانت بقيم 0. 
            قم بعمل عملية جديدة من نظام POS وستظهر الأرصدة بشكل صحيح.
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
