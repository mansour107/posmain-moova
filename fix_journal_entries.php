<?php 
session_start();
include('includes/connect.php');

// التحقق من تسجيل الدخول
if (!isset($_SESSION['userid'])) {
    header('Location: login.php');
    exit;
}

$fixed = false;
$error = '';

if (isset($_POST['fix_now'])) {
    // تغيير نوع البيانات من int إلى decimal
    $sql1 = "ALTER TABLE journal_entries MODIFY COLUMN debit DECIMAL(15,2) NOT NULL DEFAULT 0";
    $sql2 = "ALTER TABLE journal_entries MODIFY COLUMN credit DECIMAL(15,2) NOT NULL DEFAULT 0";
    
    if ($conn->query($sql1) && $conn->query($sql2)) {
        $fixed = true;
    } else {
        $error = $conn->error;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إصلاح جدول القيود</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; padding: 50px; }
        .card { box-shadow: 0 4px 6px rgba(0,0,0,0.1); max-width: 800px; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-header bg-danger text-white">
                <h3 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>مشكلة في جدول القيود المحاسبية</h3>
            </div>
            <div class="card-body">
                <?php if ($fixed): ?>
                    <div class="alert alert-success">
                        <h4><i class="fas fa-check-circle me-2"></i>تم الإصلاح بنجاح!</h4>
                        <p>تم تغيير نوع البيانات من <code>int(11)</code> إلى <code>decimal(15,2)</code></p>
                        <p>الآن يمكنك حفظ الأرقام العشرية بشكل صحيح.</p>
                        <hr>
                        <a href="pos_barcode.php" class="btn btn-success">العودة للنظام</a>
                        <a href="check_payments.php" class="btn btn-info">فحص القيود</a>
                    </div>
                <?php elseif ($error): ?>
                    <div class="alert alert-danger">
                        <h4><i class="fas fa-times-circle me-2"></i>حدث خطأ!</h4>
                        <p><?= $error ?></p>
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <h4><i class="fas fa-bug me-2"></i>المشكلة المكتشفة:</h4>
                        <p>حقول <code>debit</code> و <code>credit</code> في جدول <code>journal_entries</code> نوعها <strong>int(11)</strong></p>
                        <p>هذا يعني أنها تقبل أرقام صحيحة فقط (بدون كسور عشرية)</p>
                        <p>لذلك الأرقام مثل 55.50 أو 110.75 تتحول إلى 0</p>
                    </div>

                    <div class="alert alert-info">
                        <h4><i class="fas fa-wrench me-2"></i>الحل:</h4>
                        <p>تغيير نوع البيانات إلى <strong>decimal(15,2)</strong></p>
                        <p>هذا سيسمح بحفظ الأرقام العشرية بشكل صحيح (حتى 15 رقم مع منزلتين عشريتين)</p>
                    </div>

                    <div class="card bg-light">
                        <div class="card-body">
                            <h5>الأوامر التي سيتم تنفيذها:</h5>
                            <pre class="bg-dark text-white p-3 rounded">ALTER TABLE journal_entries MODIFY COLUMN debit DECIMAL(15,2) NOT NULL DEFAULT 0;
ALTER TABLE journal_entries MODIFY COLUMN credit DECIMAL(15,2) NOT NULL DEFAULT 0;</pre>
                        </div>
                    </div>

                    <form method="POST" class="mt-4">
                        <div class="alert alert-danger">
                            <strong>تحذير:</strong> هذا الإجراء سيغير بنية الجدول. تأكد من عمل نسخة احتياطية أولاً.
                        </div>
                        <button type="submit" name="fix_now" class="btn btn-danger btn-lg w-100">
                            <i class="fas fa-tools me-2"></i>إصلاح الآن
                        </button>
                    </form>

                    <div class="text-center mt-3">
                        <a href="pos_barcode.php" class="btn btn-secondary">إلغاء والعودة</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$fixed): ?>
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>معلومات إضافية</h5>
            </div>
            <div class="card-body">
                <h6>الفرق بين int و decimal:</h6>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>النوع</th>
                            <th>مثال</th>
                            <th>النتيجة</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>int(11)</code></td>
                            <td>55.50</td>
                            <td class="text-danger"><strong>0</strong> (يتم تجاهل الكسور)</td>
                        </tr>
                        <tr>
                            <td><code>decimal(15,2)</code></td>
                            <td>55.50</td>
                            <td class="text-success"><strong>55.50</strong> (يتم حفظها بشكل صحيح)</td>
                        </tr>
                    </tbody>
                </table>

                <div class="alert alert-success mt-3">
                    <strong>ملاحظة:</strong> بعد الإصلاح، جميع العمليات الجديدة ستحفظ الأرقام بشكل صحيح.
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
