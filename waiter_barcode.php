<?php
/**
 * صفحة عرض وطباعة باركود الويتر
 * Waiter Barcode Display and Print Page
 */

include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
include('includes/connect.php');

// التحقق من وجود معرف المستخدم
if (!isset($_GET['id'])) {
    header('Location: users.php');
    exit;
}

$user_id = intval($_GET['id']);

// جلب بيانات الويتر
$stmt = $conn->prepare("SELECT id, uname, password, is_waiter FROM users WHERE id = ? AND is_waiter = 1 AND isdeleted = 0");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "<script>alert('الويتر غير موجود أو تم حذفه'); window.location.href='users.php';</script>";
    exit;
}

$waiter = $result->fetch_assoc();
$stmt->close();
?>

<div class="content-wrapper">
    <!-- مكتبة JsBarcode لإنشاء الباركود -->
    <script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
    
    <style>
        .barcode-page {
            padding: 30px;
        }
        
        .barcode-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 2px 20px rgba(0,0,0,0.08);
            padding: 30px;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .page-title {
            text-align: center;
            color: #2d3748;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
        }
        
        .waiter-name {
            text-align: center;
            font-size: 1.5rem;
            font-weight: 600;
            color: #667eea;
            margin-bottom: 30px;
        }
        
        .alert-simple {
            background: #fff7e6;
            border: 1px solid #ffd591;
            color: #d46b08;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .barcode-input-section {
            background: #f8f9fc;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .barcode-input-section label {
            display: block;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 10px;
            font-size: 1rem;
        }
        
        .barcode-input-section input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 1.1rem;
            text-align: center;
            letter-spacing: 2px;
        }
        
        .barcode-input-section input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .btn-generate {
            background: #48bb78;
            color: white;
            width: 100%;
            margin-top: 15px;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        
        .btn-generate:hover {
            background: #38a169;
        }
        
        .btn-save {
            background: #ed8936;
            color: white;
            width: 100%;
            margin-top: 10px;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }
        
        .btn-save:hover {
            background: #dd6b20;
        }
        
        .barcode-display {
            background: white;
            padding: 30px;
            border-radius: 10px;
            border: 3px dashed #667eea;
            text-align: center;
            margin-bottom: 20px;
            display: none;
        }
        
        .barcode-display.show {
            display: block;
        }
        
        #barcode {
            margin: 20px auto;
            max-width: 100%;
        }
        
        .barcode-text {
            font-size: 1.3rem;
            font-weight: bold;
            color: #667eea;
            margin-top: 15px;
            letter-spacing: 2px;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .btn-action {
            padding: 12px 30px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-print {
            background: #667eea;
            color: white;
        }
        
        .btn-print:hover {
            background: #5568d3;
        }
        
        .btn-back {
            background: #e2e8f0;
            color: #4a5568;
        }
        
        .btn-back:hover {
            background: #cbd5e0;
        }
        
        /* Print Styles */
        @media print {
            .content-wrapper {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .barcode-page {
                padding: 20px;
            }
            
            .barcode-card {
                box-shadow: none;
                border: none;
            }
            
            .action-buttons, 
            .btn-back, 
            .alert-simple, 
            .barcode-input-section,
            .page-title {
                display: none !important;
            }
            
            .waiter-name {
                font-size: 2rem;
                margin-bottom: 30px;
                color: #000;
            }
            
            .barcode-display {
                border: none;
                padding: 20px;
            }
        }
    </style>

    <div class="barcode-page">
        <div class="barcode-card">
            <h1 class="page-title">
                <i class="fas fa-barcode"></i>
                باركود الويتر
            </h1>
            
            <div class="waiter-name">
                <i class="fas fa-user-tie"></i>
                <?= htmlspecialchars($waiter['uname']) ?>
            </div>
            
            <div class="alert-simple">
                <i class="fas fa-info-circle"></i>
                أدخل رقم الباركود أدناه ثم اضغط "إنشاء الباركود"
            </div>
            
            <div class="barcode-input-section">
                <label for="barcodeInput">
                    <i class="fas fa-keyboard"></i>
                    رقم الباركود:
                </label>
                <input type="text" 
                       id="barcodeInput" 
                       placeholder="مثال: 1234"
                       value="USER<?= $waiter['id'] ?>">
                <button class="btn-generate" onclick="generateBarcode()">
                    <i class="fas fa-sync-alt"></i>
                    إنشاء الباركود
                </button>
                <button class="btn-save" onclick="updatePassword()">
                    <i class="fas fa-save"></i>
                    حفظ الباركود في قاعدة البيانات
                </button>
            </div>
            
            <div class="barcode-display" id="barcodeSection">
                <svg id="barcode"></svg>
                <div class="barcode-text" id="barcodeText"></div>
            </div>
            
            <div class="action-buttons">
                <button class="btn-action btn-print" onclick="window.print()">
                    <i class="fas fa-print"></i>
                    طباعة
                </button>
                <button class="btn-action btn-back" onclick="window.location.href='users.php'">
                    <i class="fas fa-arrow-right"></i>
                    العودة
                </button>
            </div>
        </div>
    </div>

    <script>
        // إنشاء الباركود تلقائياً عند تحميل الصفحة
        window.addEventListener('DOMContentLoaded', function() {
            generateBarcode();
        });
        
        // دالة إنشاء الباركود
        function generateBarcode() {
            const barcodeValue = document.getElementById('barcodeInput').value.trim();
            
            if (!barcodeValue) {
                alert('الرجاء إدخال رقم الباركود');
                return;
            }
            
            try {
                // إنشاء الباركود
                JsBarcode("#barcode", barcodeValue, {
                    format: "CODE128",
                    width: 3,
                    height: 100,
                    displayValue: true,
                    fontSize: 20,
                    margin: 10,
                    background: "#ffffff",
                    lineColor: "#000000"
                });
                
                // عرض النص
                document.getElementById('barcodeText').textContent = barcodeValue;
                
                // إظهار قسم الباركود
                document.getElementById('barcodeSection').classList.add('show');
                
            } catch (error) {
                alert('حدث خطأ في إنشاء الباركود: ' + error.message);
            }
        }
        
        // السماح بإنشاء الباركود عند الضغط على Enter
        document.getElementById('barcodeInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                generateBarcode();
            }
        });
        
        // دالة لتحديث الباسورد في قاعدة البيانات
        function updatePassword() {
            const barcodeValue = document.getElementById('barcodeInput').value.trim();
            const userId = <?= $waiter['id'] ?>;
            
            if (!barcodeValue) {
                alert('الرجاء إدخال رقم الباركود أولاً');
                return;
            }
            
            if (confirm('هل تريد تحديث باركود هذا الويتر؟')) {
                fetch('do/update_waiter_barcode.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        user_id: userId,
                        barcode: barcodeValue
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('تم تحديث الباركود بنجاح!');
                        generateBarcode();
                    } else {
                        alert('حدث خطأ: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('حدث خطأ في الاتصال');
                    console.error('Error:', error);
                });
            }
        }
    </script>
</div>

<?php include('includes/footer.php'); ?>
