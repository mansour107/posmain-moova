<?php include('includes/header.php') ?>
<style>
    .large{font-size:50px}
    .error-container { margin: 50px auto; max-width: 800px; }
    .error-message { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 5px; margin: 10px 0; }
    .success-message { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 5px; margin: 10px 0; }
</style>

<div class="error-container">
    <center>
        <?php
        $error = isset($_GET['error']) ? $_GET['error'] : '';
        $success = isset($_GET['success']) ? $_GET['success'] : '';
        $q = isset($_GET['q']) ? $_GET['q'] : '';
        
        if ($success) {
            switch ($success) {
                case 'deleted':
                    echo '<div class="success-message"><h2>تم حذف الفاتورة بنجاح</h2></div>';
                    break;
                default:
                    echo '<div class="success-message"><h2>تمت العملية بنجاح</h2></div>';
            }
        } elseif ($error) {
            switch ($error) {
                case 'invalid_id':
                    echo '<div class="error-message"><h2>معرف الفاتورة غير صحيح</h2><p>تأكد من صحة رقم الفاتورة المطلوب حذفها</p></div>';
                    break;
                case 'missing_password':
                    echo '<div class="error-message"><h2>كلمة المرور مطلوبة</h2><p>يجب إدخال كلمة مرور الحذف للمتابعة</p></div>';
                    break;
                case 'invalid_password':
                    echo '<div class="error-message"><h2>كلمة مرور خاطئة</h2><p>كلمة المرور المدخلة غير صحيحة، تأكد من كلمة مرور الحذف</p></div>';
                    break;
                case 'invoice_not_found':
                    echo '<div class="error-message"><h2>الفاتورة غير موجودة</h2><p>الفاتورة المطلوب حذفها غير موجودة أو تم حذفها مسبقاً</p></div>';
                    break;
                case 'delete_failed':
                    $error_msg = isset($_GET['msg']) ? $_GET['msg'] : '';
                    $invoice_id = isset($_GET['id']) ? $_GET['id'] : '';
                    echo '<div class="error-message">';
                    echo '<h2>فشل في حذف الفاتورة</h2>';
                    if ($invoice_id) echo '<p><strong>رقم الفاتورة:</strong> ' . htmlspecialchars($invoice_id) . '</p>';
                    echo '<p>حدث خطأ أثناء عملية الحذف</p>';
                    if ($error_msg) {
                        echo '<div style="background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0; color: #856404;">';
                        echo '<strong>تفاصيل الخطأ:</strong><br>' . htmlspecialchars($error_msg);
                        echo '</div>';
                    }
                    echo '</div>';
                    break;
                case 'settings_not_found':
                    echo '<div class="error-message"><h2>خطأ في إعدادات النظام</h2><p>لم يتم العثور على إعدادات النظام، تواصل مع مدير النظام</p></div>';
                    break;
                case 'related_records_exist':
                    $invoice_id = isset($_GET['id']) ? $_GET['id'] : '';
                    $details = isset($_GET['details']) ? $_GET['details'] : '';
                    echo '<div class="error-message">';
                    echo '<h2>لا يمكن حذف الفاتورة</h2>';
                    echo '<p><strong>رقم الفاتورة:</strong> ' . htmlspecialchars($invoice_id) . '</p>';
                    
                    if ($details) {
                        echo '<p><strong>العمليات المرتبطة:</strong></p>';
                        echo '<p style="background: #fff3cd; padding: 10px; border-radius: 5px; color: #856404;">' . htmlspecialchars($details) . '</p>';
                    }
                    
                    echo '<p>هذه الفاتورة مرتبطة بعمليات أخرى في النظام ولا يمكن حذفها مباشرة.</p>';
                    echo '<p><strong>الحلول المقترحة:</strong></p>';
                    echo '<ul style="text-align: right; margin: 10px 0;">';
                    echo '<li>ابحث عن العمليات المرتبطة في التقارير</li>';
                    echo '<li>قم بحذف أو تعديل هذه العمليات أولاً</li>';
                    echo '<li>ثم حاول حذف الفاتورة مرة أخرى</li>';
                    echo '</ul>';
                    
                    // إضافة زر للبحث عن العمليات المرتبطة
                    echo '<div style="margin-top: 15px;">';
                    echo '<a href="operations_summary.php" class="btn btn-info">ابحث في التقارير</a>';
                    echo '</div>';
                    echo '</div>';
                    break;
                default:
                    echo '<div class="error-message">';
                    echo '<h2>' . $lang_warningmsg . '</h2>';
                    echo '<h3>' . $lang_warningmsg1 . '</h3>';
                    echo '<p>' . $lang_warningmsg2 . '</p>';
                    echo '</div>';
            }
        } else {
            echo '<div class="error-message">';
            echo '<h2>' . $lang_warningmsg . '</h2>';
            echo '<h3>' . $lang_warningmsg1 . '</h3>';
            echo '<p>' . $lang_warningmsg2 . '</p>';
            echo '</div>';
        }
        ?>
        
        <div style="margin-top: 30px;">
            <?php if ($q): ?>
                <a class="btn btn-primary btn-lg" href="operations_summary.php?q=<?= htmlspecialchars($q) ?>">العودة للتقرير</a>
            <?php endif; ?>
            <a class="btn btn-warning btn-lg" href="dashboard.php"><?=$lang_main?></a>
        </div>
    </center>
</div>

<?php include('includes/footer.php') ?>