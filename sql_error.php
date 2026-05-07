<?php
// منع عرض أي أخطاء PHP خام
error_reporting(0);

// جلب كود الخطأ إذا تم تمريره (اختياري)
$error_code = isset($_GET['code']) ? htmlspecialchars($_GET['code']) : 'DB_ERR';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>خطأ في النظام</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            direction: rtl;
        }

        .error-container {
            background: rgba(255, 255, 255, 0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 50px 40px;
            max-width: 550px;
            width: 90%;
            text-align: center;
            box-shadow: 0 25px 50px rgba(0, 0, 0, 0.4);
            animation: fadeInUp 0.6s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .error-icon {
            width: 90px;
            height: 90px;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 25px;
            box-shadow: 0 10px 30px rgba(231, 76, 60, 0.4);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { box-shadow: 0 10px 30px rgba(231, 76, 60, 0.4); }
            50% { box-shadow: 0 10px 40px rgba(231, 76, 60, 0.7); }
        }

        .error-icon svg {
            width: 45px;
            height: 45px;
            fill: white;
        }

        .error-title {
            color: #ffffff;
            font-size: 26px;
            font-weight: 700;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }

        .error-subtitle {
            color: #e74c3c;
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 20px;
            background: rgba(231, 76, 60, 0.1);
            padding: 6px 16px;
            border-radius: 20px;
            display: inline-block;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .error-message {
            color: rgba(255, 255, 255, 0.75);
            font-size: 15px;
            line-height: 1.8;
            margin-bottom: 30px;
        }

        .divider {
            border: none;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin: 25px 0;
        }

        .contact-box {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 25px;
        }

        .contact-box p {
            color: rgba(255, 255, 255, 0.6);
            font-size: 13px;
            margin-bottom: 10px;
        }

        .contact-box .vendor-name {
            color: #3498db;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .contact-box .vendor-info {
            color: rgba(255, 255, 255, 0.5);
            font-size: 12px;
        }

        .error-code {
            color: rgba(255, 255, 255, 0.25);
            font-size: 11px;
            font-family: monospace;
            margin-bottom: 20px;
        }

        .btn-back {
            display: inline-block;
            background: linear-gradient(135deg, #3498db, #2980b9);
            color: white;
            text-decoration: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            box-shadow: 0 5px 15px rgba(52, 152, 219, 0.3);
        }

        .btn-back:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(52, 152, 219, 0.5);
            color: white;
            text-decoration: none;
        }

        .warning-strip {
            background: linear-gradient(90deg, transparent, rgba(231, 76, 60, 0.15), transparent);
            border-top: 1px solid rgba(231, 76, 60, 0.2);
            border-bottom: 1px solid rgba(231, 76, 60, 0.2);
            padding: 10px;
            margin-bottom: 25px;
            color: rgba(255, 200, 200, 0.7);
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="error-container">

        <!-- أيقونة الخطأ -->
        <div class="error-icon">
            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
        </div>

        <!-- العنوان -->
        <h1 class="error-title">حدث خطأ في النظام</h1>
        <span class="error-subtitle">⚠️ خطأ في قاعدة البيانات</span>

        <!-- شريط التحذير -->
        <div class="warning-strip">
            هذا الخطأ لا يؤثر على بياناتك — يرجى التواصل مع الدعم الفني
        </div>

        <!-- الرسالة -->
        <p class="error-message">
            واجه النظام مشكلة أثناء معالجة طلبك.<br>
            يرجى <strong style="color: #fff;">عدم تكرار العملية</strong> والتواصل مع الدعم الفني لحل المشكلة في أقرب وقت.
        </p>

        <hr class="divider">

        <!-- بيانات التواصل -->
        <div class="contact-box">
            <p>للدعم الفني والمساعدة تواصل مع</p>
            <div class="vendor-name">KODY</div>
            <div class="vendor-info">فريق الدعم الفني — متاح على مدار الساعة</div>
        </div>

        <!-- كود الخطأ -->
        <div class="error-code">Error Reference: <?= $error_code ?> — <?= date('Y-m-d H:i') ?></div>

        <!-- زر الرجوع -->
        <a href="javascript:history.back()" class="btn-back">← الرجوع للصفحة السابقة</a>

    </div>
</body>
</html>
