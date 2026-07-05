<?php
/**
 * POS terminal unlock screen — PIN pad (when any user has PIN) or legacy password fallback.
 *
 * Expects: $login_error (optional), $pos_pin_mode (bool), $pos_legacy_fallback (bool)
 */
require_once __DIR__ . '/csrf.php';
$pos_pin_mode = !empty($pos_pin_mode);
$pos_legacy_fallback = !empty($pos_legacy_fallback);
$terminalLabel = htmlspecialchars($_SESSION['login'] ?? '', ENT_QUOTES, 'UTF-8');
$csrfPin = htmlspecialchars(csrf_token('pos_pin'), ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>فتح نقطة البيع</title>
    <link href="assets/libs/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fonts/fonts.css" rel="stylesheet">
    <link href="assets/libs/fontawesome.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            font-family: 'IBM Plex Sans Arabic', 'Inter', sans-serif;
        }
        .unlock-shell { width: min(420px, 94vw); }
        .unlock-card {
            background: #fff;
            border-radius: 20px;
            padding: 2rem 1.75rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, 0.35);
        }
        .unlock-title { font-size: 1.5rem; font-weight: 700; color: #0f172a; margin-bottom: .25rem; text-align: center; }
        .unlock-sub { color: #64748b; text-align: center; margin-bottom: 1.5rem; font-size: .95rem; }
        .pin-dots {
            display: flex; justify-content: center; gap: .75rem; margin: 1.25rem 0 1.5rem;
        }
        .pin-dot {
            width: 14px; height: 14px; border-radius: 50%;
            border: 2px solid #cbd5e1; background: #f8fafc;
        }
        .pin-dot.filled { background: #6366f1; border-color: #6366f1; }
        .pin-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: .75rem;
        }
        .pin-key {
            border: none; border-radius: 12px;
            min-height: 64px; min-width: 64px;
            padding: 1rem 0;
            font-size: 1.35rem; font-weight: 600; background: #f1f5f9; color: #0f172a;
            cursor: pointer; transition: transform .1s, background .15s;
        }
        .pin-key:hover { background: #e2e8f0; }
        .pin-key:active { transform: scale(.97); }
        .pin-key.action { background: #e0e7ff; color: #4338ca; font-size: 1rem; }
        .pin-key.enter { background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; }
        .error-box {
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
            border-radius: 10px; padding: .75rem; margin-bottom: 1rem; font-size: .9rem; text-align: center;
        }
        .legacy-panel { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px dashed #e2e8f0; }
        .legacy-panel .form-control {
            text-align: center; padding: .85rem; border-radius: 10px; border: 2px solid #e2e8f0;
        }
        .btn-legacy {
            width: 100%; margin-top: .75rem; padding: .85rem; border: none; border-radius: 10px;
            background: #334155; color: #fff; font-weight: 600;
        }
        .hidden { display: none !important; }
    </style>
</head>
<body>
<div class="unlock-shell">
    <div class="unlock-card">
        <div class="unlock-title"><i class="fas fa-lock"></i> فتح نقطة البيع</div>
        <p class="unlock-sub">الجهاز: <?= $terminalLabel ?></p>

        <?php if (!empty($login_error)): ?>
        <div class="error-box" id="posUnlockError"><?= htmlspecialchars((string) $login_error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php else: ?>
        <div class="error-box hidden" id="posUnlockError"></div>
        <?php endif; ?>

        <?php if ($pos_pin_mode): ?>
        <div id="pinPadSection">
            <p class="text-center text-muted mb-0" style="font-size:.9rem">أدخل رمز الموظف (PIN)</p>
            <div class="pin-dots" id="pinDots" aria-hidden="true">
                <?php for ($i = 0; $i < 6; $i++): ?><span class="pin-dot"></span><?php endfor; ?>
            </div>
            <div class="pin-grid" id="pinGrid">
                <?php foreach (['1','2','3','4','5','6','7','8','9','مسح','0','دخول'] as $key): ?>
                    <?php
                    $cls = 'pin-key';
                    if ($key === 'مسح') {
                        $cls .= ' action';
                    } elseif ($key === 'دخول') {
                        $cls .= ' enter';
                    }
                    ?>
                    <button type="button" class="<?= $cls ?>" data-key="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($pos_legacy_fallback || !$pos_pin_mode): ?>
        <div class="legacy-panel" id="legacyPanel" <?= $pos_pin_mode ? '' : '' ?>>
            <?php if ($pos_pin_mode): ?>
            <p class="text-center text-muted" style="font-size:.85rem">أو استخدم كلمة المرور (حتى تفعيل PIN للجميع)</p>
            <?php else: ?>
            <p class="text-center text-muted" style="font-size:.9rem">أدخل كلمة مرور المستخدم الحالي</p>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="password" name="pos_barcode" class="form-control" placeholder="كلمة المرور" autocomplete="current-password" <?= $pos_pin_mode ? '' : 'autofocus required' ?>>
                <button type="submit" class="btn-legacy"><i class="fas fa-sign-in-alt"></i> دخول</button>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($pos_pin_mode): ?>
<script>
(function () {
    const csrfToken = <?= json_encode($csrfPin, JSON_UNESCAPED_UNICODE) ?>;
    const dots = document.querySelectorAll('#pinDots .pin-dot');
    const errorBox = document.getElementById('posUnlockError');
    let buffer = '';
    const maxLen = 6;

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('hidden');
    }

    function renderDots() {
        dots.forEach(function (dot, i) {
            dot.classList.toggle('filled', i < buffer.length);
        });
    }

    function submitPin() {
        if (buffer.length < 4) {
            showError('الرمز قصير جداً');
            return;
        }
        const body = new URLSearchParams();
        body.set('pin', buffer);
        body.set('csrf_token', csrfToken);
        fetch('ajax/pos_pin_login.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken,
            },
            credentials: 'same-origin',
            body: body.toString(),
        })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (res.ok && res.j.success) {
                    window.location.href = 'pos_barcode.php';
                    return;
                }
                const code = (res.j && res.j.code) || 'PIN_INVALID';
                const messages = {
                    PIN_INVALID: 'رمز غير صحيح',
                    PIN_USER_LOCKED: 'الحساب مقفل مؤقتاً',
                    PIN_TERMINAL_FROZEN: 'الجهاز مقفل — حاول لاحقاً',
                    PIN_SECRET_MISSING: 'إعداد PIN غير مكتمل',
                };
                showError(messages[code] || code);
                buffer = '';
                renderDots();
            })
            .catch(function () {
                showError('تعذر الاتصال بالخادم');
                buffer = '';
                renderDots();
            });
    }

    document.getElementById('pinGrid').addEventListener('click', function (e) {
        const btn = e.target.closest('[data-key]');
        if (!btn) return;
        const key = btn.getAttribute('data-key');
        if (key === 'مسح') {
            buffer = buffer.slice(0, -1);
            renderDots();
            errorBox.classList.add('hidden');
            return;
        }
        if (key === 'دخول') {
            submitPin();
            return;
        }
        if (buffer.length >= maxLen) return;
        buffer += key;
        renderDots();
        errorBox.classList.add('hidden');
    });
})();
</script>
<?php endif; ?>
</body>
</html>
