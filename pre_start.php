<?php
// pre_start.php - Database Setup Page
$dbhost = 'localhost';
$dbuser = 'root';
$dbpass = '';
$dbname = 'kody2';

// Check if already connected
mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($dbhost, $dbuser, $dbpass);
$db_exists = false;
if (!$conn->connect_error) {
    if ($conn->select_db($dbname)) {
        $db_exists = true;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>إعداد قاعدة البيانات | كودي 2</title>
    <!-- Local Fonts -->
    <link rel="stylesheet" href="assets/fonts/fonts.css">
    <!-- Local Font Awesome -->
    <link rel="stylesheet" href="plugins/fontawesome-free/css/all.min.css">
    <style>
        :root {
            --primary: #4f46e5;
            --primary-hover: #4338ca;
            --bg-gradient: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            --glass-bg: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Cairo', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: var(--bg-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-main);
            overflow: hidden;
        }

        .container {
            width: 100%;
            max-width: 500px;
            padding: 20px;
            position: relative;
            z-index: 10;
        }

        .setup-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 24px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            animation: slideUp 0.6s ease-out;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-container {
            width: 80px;
            height: 80px;
            background: var(--primary);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            font-size: 32px;
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.4);
        }

        h1 {
            font-size: 24px;
            margin-bottom: 8px;
            font-weight: 700;
        }

        p.subtitle {
            color: var(--text-muted);
            font-size: 14px;
            margin-bottom: 32px;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 100px;
            font-size: 13px;
            margin-bottom: 32px;
            background: rgba(239, 68, 68, 0.1);
            color: #ef4444;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .status-badge.success {
            background: rgba(34, 197, 94, 0.1);
            color: #22c55e;
            border: 1px solid rgba(34, 197, 94, 0.2);
        }

        .actions {
            display: grid;
            gap: 16px;
        }

        .btn {
            width: 100%;
            padding: 14px 24px;
            border-radius: 12px;
            border: none;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--glass-border);
            color: var(--text-main);
        }

        .btn-outline:hover {
            background: var(--glass-bg);
            border-color: var(--text-muted);
        }

        .footer-text {
            margin-top: 32px;
            font-size: 12px;
            color: var(--text-muted);
        }

        .loading-overlay {
            position: absolute;
            inset: 0;
            background: rgba(15, 23, 42, 0.8);
            backdrop-filter: blur(8px);
            border-radius: 24px;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 20;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(255, 255, 255, 0.1);
            border-left-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 16px;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Ambient Background Elements */
        .circle {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            z-index: 1;
        }

        .circle-1 {
            width: 400px;
            height: 400px;
            background: rgba(79, 70, 229, 0.15);
            top: -100px;
            right: -100px;
        }

        .circle-2 {
            width: 300px;
            height: 300px;
            background: rgba(139, 92, 246, 0.15);
            bottom: -50px;
            left: -50px;
        }

        #fileInput {
            display: none;
        }
    </style>
</head>
<body>
    <div class="circle circle-1"></div>
    <div class="circle circle-2"></div>

    <div class="container">
        <div class="setup-card">
            <div class="loading-overlay" id="loadingOverlay">
                <div class="spinner"></div>
                <p id="loadingText">جاري المعالجة...</p>
            </div>

            <div class="logo-container">
                <i class="fas fa-database"></i>
            </div>
            <h1>إعداد النظام</h1>
            <p class="subtitle">يبدو أن النظام غير جاهز للعمل بعد، يرجى تهيئة قاعدة البيانات</p>

            <?php if ($db_exists): ?>
                <div class="status-badge success">
                    <i class="fas fa-check-circle"></i>
                    قاعدة البيانات متصلة وجاهزة
                </div>
                <div class="actions">
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-arrow-right"></i>
                        الدخول للنظام
                    </a>
                </div>
            <?php else: ?>
                <div class="status-badge">
                    <i class="fas fa-exclamation-triangle"></i>
                    قاعدة البيانات (kody2) غير موجودة
                </div>
                <div class="actions">
                    <button class="btn btn-primary" id="btnCreateNew">
                        <i class="fas fa-plus-circle"></i>
                        بدء قاعدة بيانات جديدة (افتراضية)
                    </button>
                    <button class="btn btn-outline" id="btnRestore">
                        <i class="fas fa-file-import"></i>
                        استعادة نسخة احتياطية (SQL)
                    </button>
                </div>
                <input type="file" id="fileInput" accept=".sql">
            <?php endif; ?>

            <div class="footer-text">
                نظام كودي 2 &copy; <?= date('Y') ?> - جميع الحقوق محفوظة
            </div>
        </div>
    </div>

    <!-- Local jQuery -->
    <script src="plugins/jquery/jquery.min.js"></script>
    <!-- Local SweetAlert2 -->
    <script src="plugins/sweetalert2/sweetalert2.all.min.js"></script>
    <script>
        $(document).ready(function() {
            const $overlay = $('#loadingOverlay');
            const $loadingText = $('#loadingText');

            // Robust SweetAlert wrapper
            function showAlert(title, text, type, callback) {
                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        title: title,
                        text: text,
                        type: type,
                        icon: type, // Support both old and new
                        showCancelButton: callback ? true : false,
                        confirmButtonColor: '#4f46e5',
                        cancelButtonColor: '#d33',
                        confirmButtonText: 'موافق',
                        cancelButtonText: 'إلغاء'
                    }).then((result) => {
                        if (callback && (result.value || result.isConfirmed)) callback();
                    });
                } else if (typeof swal !== 'undefined') {
                    swal({
                        title: title,
                        text: text,
                        type: type,
                        showCancelButton: callback ? true : false,
                        confirmButtonColor: '#4f46e5',
                        confirmButtonText: 'موافق',
                        cancelButtonText: 'إلغاء',
                        closeOnConfirm: true
                    }, function(isConfirm) {
                        if (isConfirm && callback) callback();
                    });
                } else {
                    alert(title + "\n" + text);
                    if (callback) callback();
                }
            }

            function showLoading(text) {
                $loadingText.text(text);
                $overlay.css('display', 'flex').hide().fadeIn(200);
            }

            function hideLoading() {
                $overlay.fadeOut(200);
            }

            $('#btnCreateNew').on('click', function() {
                showAlert('هل أنت متأكد؟', 'سيتم إنشاء قاعدة بيانات جديدة بالكامل وحذف أي بيانات سابقة بنفس الاسم!', 'warning', function() {
                    showLoading('جاري إنشاء قاعدة البيانات...');
                    $.ajax({
                        url: 'ajax/db_setup.php',
                        type: 'POST',
                        data: { action: 'create' },
                        dataType: 'json',
                        success: function(response) {
                            hideLoading();
                            if (response.success) {
                                showAlert('نجاح!', response.message, 'success', function() {
                                    location.reload();
                                });
                            } else {
                                showAlert('خطأ!', response.message, 'error');
                            }
                        },
                        error: function() {
                            hideLoading();
                            showAlert('خطأ!', 'حدث خطأ غير متوقع في الخادم', 'error');
                        }
                    });
                });
            });

            $('#btnRestore').on('click', function() {
                $('#fileInput').click();
            });

            $('#fileInput').on('change', function() {
                const file = this.files[0];
                if (!file) return;

                const formData = new FormData();
                formData.append('backup_file', file);
                formData.append('action', 'restore');

                showLoading('جاري رفع واستعادة النسخة الاحتياطية...');
                $.ajax({
                    url: 'ajax/db_setup.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        hideLoading();
                        if (response.success) {
                            showAlert('نجاح!', response.message, 'success', function() {
                                location.reload();
                            });
                        } else {
                            showAlert('خطأ!', response.message, 'error');
                        }
                    },
                    error: function() {
                        hideLoading();
                        showAlert('خطأ!', 'حدث خطأ غير متوقع أثناء الرفع', 'error');
                    }
                });
            });
        });
    </script>
</body>
</html>
