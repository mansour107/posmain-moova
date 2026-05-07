<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>تسجيل الدخول - POS</title>
    <link href="assets/libs/bootstrap.min.css" rel="stylesheet">
    <link href="assets/libs/fontawesome.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .login-box {
            background: white;
            border-radius: 16px;
            padding: 3rem 2.5rem;
            max-width: 420px;
            width: 90%;
            box-shadow: 0 10px 40px rgba(99, 102, 241, 0.2);
            text-align: center;
        }
        .barcode-icon {
            font-size: 64px;
            color: #6366F1;
            margin-bottom: 1.5rem;
            opacity: 0.9;
        }
        h2 {
            color: #1E293B;
            margin-bottom: 0.5rem;
            font-weight: 600;
            font-size: 1.75rem;
        }
        .login-box p {
            color: #64748B;
            margin-bottom: 2rem;
            font-size: 0.95rem;
        }
        .form-control {
            padding: 0.875rem 1rem;
            font-size: 1.1rem;
            text-align: center;
            border: 2px solid #E2E8F0;
            border-radius: 10px;
            letter-spacing: 1px;
            width: 100%;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        .form-control:focus {
            border-color: #6366F1;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            outline: none;
        }
        .btn-login {
            width: 100%;
            padding: 0.875rem;
            font-size: 1.1rem;
            background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3);
        }
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4);
        }
        .btn-login:active {
            transform: translateY(0);
        }
        .error {
            background: #FEE2E2;
            color: #991B1B;
            padding: 0.875rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            border: 1px solid #FECACA;
            font-size: 0.9rem;
        }
        .info-text {
            color: #94A3B8;
            margin-top: 1.25rem;
            margin-bottom: 0;
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <div class="barcode-icon">
            <i class="fas fa-barcode"></i>
        </div>
        <h2>نظام POS محمي</h2>
        <p>امسح الباركود للدخول</p>
        
        <?php if (isset($login_error)): ?>
        <div class="error">
            <i class="fas fa-exclamation-triangle"></i>
            <?= $login_error ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <input 
                type="text" 
                name="pos_barcode" 
                class="form-control" 
                placeholder="الباركود"
                autocomplete="off"
                autofocus
                required
            >
            <button type="submit" class="btn-login">
                <i class="fas fa-sign-in-alt"></i> دخول
            </button>
        </form>
        
        <p class="info-text">
            <i class="fas fa-info-circle"></i>
            استخدم قارئ الباركود أو اكتب يدوياً
        </p>
    </div>
</body>
</html>
