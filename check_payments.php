<?php 
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 10;
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فحص القيود المحاسبية</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .table-responsive {
            max-height: 600px;
            overflow-y: auto;
        }
        .debit {
            color: #28a745;
            font-weight: bold;
        }
        .credit {
            color: #dc3545;
            font-weight: bold;
        }
        .journal-header {
            background: #e9ecef;
            padding: 10px;
            margin-top: 10px;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row mb-3">
            <div class="col">
                <h2><i class="fas fa-search-dollar me-2"></i>فحص القيود المحاسبية</h2>
            </div>
            <div class="col-auto">
                <a href="pos_barcode.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-2"></i>العودة للنظام
                </a>
            </div>
        </div>

        <!-- آخر العمليات من ot_head -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-file-invoice me-2"></i>آخر <?= $limit ?> عملية</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>ID</th>
                                <th>رقم العملية</th>
                                <th>النوع</th>
                                <th>التاريخ</th>
                                <th>الحساب 1</th>
                                <th>الحساب 2</th>
                                <th>القيمة</th>
                                <th>الملاحظات</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $sql = "SELECT oh.*, 
                                    a1.aname as acc1_name, 
                                    a2.aname as acc2_name
                                    FROM ot_head oh
                                    LEFT JOIN acc_head a1 ON oh.acc1 = a1.id
                                    LEFT JOIN acc_head a2 ON oh.acc2 = a2.id
                                    ORDER BY oh.id DESC 
                                    LIMIT $limit";
                            $result = $conn->query($sql);
                            
                            if ($result && $result->num_rows > 0) {
                                while ($row = $result->fetch_assoc()) {
                                    $type_name = '';
                                    switch($row['pro_tybe']) {
                                        case 1: $type_name = 'سند قبض'; break;
                                        case 2: $type_name = 'سند دفع'; break;
                                        case 3: $type_name = 'مبيعات'; break;
                                        case 4: $type_name = 'مشتريات'; break;
                                        case 9: $type_name = 'كاشير'; break;
                                        default: $type_name = 'نوع ' . $row['pro_tybe'];
                                    }
                                    ?>
                                    <tr>
                                        <td><?= $row['id'] ?></td>
                                        <td><strong><?= $row['pro_id'] ?></strong></td>
                                        <td><span class="badge bg-info"><?= $type_name ?></span></td>
                                        <td><?= $row['pro_date'] ?></td>
                                        <td><?= $row['acc1_name'] ?: 'غير محدد' ?> (<?= $row['acc1'] ?>)</td>
                                        <td><?= $row['acc2_name'] ?: 'غير محدد' ?> (<?= $row['acc2'] ?>)</td>
                                        <td><strong><?= number_format($row['pro_value'], 2) ?></strong></td>
                                        <td><?= htmlspecialchars($row['info']) ?></td>
                                    </tr>
                                    <?php
                                }
                            } else {
                                echo '<tr><td colspan="8" class="text-center">لا توجد عمليات</td></tr>';
                            }
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- آخر القيود المحاسبية -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>آخر <?= $limit ?> قيد محاسبي</h5>
            </div>
            <div class="card-body">
                <?php
                $sql = "SELECT jh.*, 
                        GROUP_CONCAT(
                            CONCAT(
                                ah.aname, ' (', ah.code, ')',
                                ' - مدين: ', je.debit,
                                ' - دائن: ', je.credit
                            ) SEPARATOR ' | '
                        ) as entries_summary
                        FROM journal_heads jh
                        LEFT JOIN journal_entries je ON jh.id = je.journal_id
                        LEFT JOIN acc_head ah ON je.account_id = ah.id
                        GROUP BY jh.id
                        ORDER BY jh.id DESC 
                        LIMIT $limit";
                $result = $conn->query($sql);
                
                if ($result && $result->num_rows > 0) {
                    while ($row = $result->fetch_assoc()) {
                        ?>
                        <div class="journal-header">
                            <div class="row">
                                <div class="col-md-2">
                                    <strong>رقم القيد:</strong> <?= $row['journal_id'] ?>
                                </div>
                                <div class="col-md-2">
                                    <strong>التاريخ:</strong> <?= $row['jdate'] ?>
                                </div>
                                <div class="col-md-2">
                                    <strong>الإجمالي:</strong> <?= number_format($row['total'], 2) ?>
                                </div>
                                <div class="col-md-6">
                                    <strong>التفاصيل:</strong> <?= htmlspecialchars($row['details']) ?>
                                </div>
                            </div>
                        </div>
                        
                        <table class="table table-sm table-bordered mb-3">
                            <thead class="table-light">
                                <tr>
                                    <th>الحساب</th>
                                    <th>الكود</th>
                                    <th class="text-end">مدين</th>
                                    <th class="text-end">دائن</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $entries_sql = "SELECT je.*, ah.aname, ah.code 
                                               FROM journal_entries je
                                               LEFT JOIN acc_head ah ON je.account_id = ah.id
                                               WHERE je.journal_id = " . $row['id'];
                                $entries_result = $conn->query($entries_sql);
                                
                                if ($entries_result && $entries_result->num_rows > 0) {
                                    while ($entry = $entries_result->fetch_assoc()) {
                                        ?>
                                        <tr>
                                            <td><?= $entry['aname'] ?: 'غير محدد' ?></td>
                                            <td><?= $entry['code'] ?></td>
                                            <td class="text-end debit">
                                                <?= $entry['debit'] > 0 ? number_format($entry['debit'], 2) : '-' ?>
                                            </td>
                                            <td class="text-end credit">
                                                <?= $entry['credit'] > 0 ? number_format($entry['credit'], 2) : '-' ?>
                                            </td>
                                        </tr>
                                        <?php
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                        <?php
                    }
                } else {
                    echo '<p class="text-center">لا توجد قيود محاسبية</p>';
                }
                ?>
            </div>
        </div>

        <!-- POST Data Debug -->
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="fas fa-bug me-2"></i>آخر بيانات POST المُرسلة</h5>
            </div>
            <div class="card-body">
                <p class="text-muted">افتح ملف error log في السيرفر لرؤية البيانات المُرسلة</p>
                <div class="alert alert-info">
                    <strong>ملاحظة:</strong> البيانات تُسجل في error log عند كل عملية حفظ
                </div>
            </div>
        </div>

        <!-- تغيير عدد السجلات -->
        <div class="card">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-auto">
                        <label class="col-form-label">عدد السجلات:</label>
                    </div>
                    <div class="col-auto">
                        <select name="limit" class="form-select">
                            <option value="5" <?= $limit == 5 ? 'selected' : '' ?>>5</option>
                            <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                            <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">تحديث</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
