<?php

require_once __DIR__ . '/includes/session_bootstrap.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/config/app_config.php';

if (!isset($_SESSION['login']) || !isset($_SESSION['userid'])) {
    header('Location: index.php');
    exit;
}

include __DIR__ . '/includes/connect.php';
if (!isset($conn) || !($conn instanceof mysqli)) {
    header('Location: pre_start.php?error=server_down');
    exit;
}
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
page_guard(null, $conn);
require_once __DIR__ . '/classes/Pos/Service/PosRegisterService.php';
require_once __DIR__ . '/classes/Security/PermissionService.php';
require_once __DIR__ . '/classes/Security/PinService.php';
require_once __DIR__ . '/classes/Security/LoginThrottleService.php';

/**
 * Re-pairing requires a manager/owner PIN (docs/local_pin_auth_runbook.md).
 * users.manage alone is too narrow — preset managers do not have it.
 */
function posmain_user_can_approve_register_pair(mysqli $conn, int $userId, array $approver = []): bool
{
    if ($userId < 1) {
        return false;
    }

    $permissionService = PermissionService::forConnection($conn);
    if ($permissionService->check($userId, 'users.manage')
        || $permissionService->check($userId, 'pos.shift.force_close')
    ) {
        return true;
    }

    $roleId = (int) ($approver['userrole'] ?? 0);
    if ($roleId === 1) {
        return true;
    }

    $stmt = $conn->prepare(
        'SELECT u.usertype, r.role_key
           FROM users u
      LEFT JOIN usr_pwrs r ON r.id = u.userrole AND COALESCE(r.isdeleted, 0) != 1
          WHERE u.id = ? AND COALESCE(u.isdeleted, 0) != 1
          LIMIT 1'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ((int) ($row['usertype'] ?? 0) === 2) {
        return true;
    }

    $roleKey = strtolower(trim((string) ($row['role_key'] ?? '')));

    return in_array($roleKey, ['owner', 'manager'], true);
}

$userId = (int) ($_SESSION['userid'] ?? 0);
$tenant = (int) ($_SESSION['pos_tenant'] ?? 0);
$branch = (int) ($_SESSION['pos_branch'] ?? 0);
$registers = new PosRegisterService();
$error = '';
$success = '';

// Already paired → continue into POS entry.
try {
    $existing = $registers->resolveFromRequest($conn, $tenant, $branch);
    if ($existing) {
        header('Location: pos_barcode.php');
        exit;
    }
} catch (Throwable $ignored) {
}

$permissionService = PermissionService::forConnection($conn);
$isManager = $permissionService->check($userId, 'users.manage')
    || (int) ($_SESSION['usty'] ?? 0) === 2;
$canClaim = $permissionService->check($userId, 'pos.open') || $isManager;

$activeRegisters = $registers->tableExists($conn)
    ? $registers->findActiveRegisters($conn, $tenant, $branch)
    : [];
$canPair = $activeRegisters === [] ? $isManager : $canClaim;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canPair) {
    require_csrf('register_pair');
    $action = trim((string) ($_POST['action'] ?? 'create'));
    try {
        if ($action === 'claim' && $activeRegisters !== []) {
            // Re-pair an existing register: requires manager/owner PIN confirmation.
            $registerId = (int) ($_POST['register_id'] ?? 0);
            $managerPin = trim((string) ($_POST['manager_pin'] ?? ''));
            $throttle = new LoginThrottleService();
            $throttleIdentity = 'register_pair:' . $tenant . ':' . $branch;
            $throttleIp = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
            if ($throttle->isBlocked($conn, $throttleIdentity, $throttleIp)) {
                throw new RuntimeException('MANAGER_PIN_RATE_LIMITED');
            }
            $pinService = new PinService();
            try {
                $approver = $pinService->findUserByPin($conn, $managerPin);
            } catch (Throwable $exception) {
                $throttle->recordFailure($conn, $throttleIdentity, $throttleIp, [
                    'max_attempts' => 5,
                    'window_seconds' => 900,
                    'lock_seconds' => 900,
                ]);
                throw new RuntimeException('MANAGER_PIN_INVALID', 0, $exception);
            }
            if (!$approver
                || $pinService->isUserLocked($approver)
                || !$pinService->verifyPin($managerPin, (string) ($approver['pin_hash'] ?? ''))
            ) {
                $throttle->recordFailure($conn, $throttleIdentity, $throttleIp, [
                    'max_attempts' => 5,
                    'window_seconds' => 900,
                    'lock_seconds' => 900,
                ]);
                if ($approver) {
                    $pinService->recordUserFailure($conn, (int) $approver['id']);
                }
                throw new RuntimeException('MANAGER_PIN_INVALID');
            }
            $approverId = (int) $approver['id'];
            if (!posmain_user_can_approve_register_pair($conn, $approverId, $approver)) {
                throw new RuntimeException('MANAGER_PIN_REQUIRED');
            }
            $throttle->recordSuccess($conn, $throttleIdentity, $throttleIp);
            $pinService->clearUserFailures($conn, $approverId);
            $target = null;
            foreach ($activeRegisters as $row) {
                if ((int) $row['id'] === $registerId) {
                    $target = $row;
                    break;
                }
            }
            if (!$target) {
                throw new RuntimeException('REGISTER_NOT_FOUND');
            }
            $token = bin2hex(random_bytes(32));
            $hash = hash('sha256', $token);
            $stmt = $conn->prepare(
                'UPDATE pos_registers
                    SET pairing_token_hash = ?, paired_at = NOW(), paired_by = ?, last_seen_at = NOW()
                  WHERE id = ?'
            );
            $stmt->bind_param('sii', $hash, $approverId, $registerId);
            $stmt->execute();
            $stmt->close();
            $registers->setPairingCookie($token);
            $_SESSION['pos_register_id'] = $registerId;
            header('Location: pos_barcode.php');
            exit;
        }

        // Fresh install / no registers yet: create REG1 and pair this browser.
        $created = $registers->ensureDefaultRegister($conn, $tenant, $branch);
        $_SESSION['pos_register_id'] = (int) $created['id'];
        header('Location: pos_barcode.php');
        exit;
    } catch (Throwable $exception) {
        $code = $exception->getMessage();
        $error = match ($code) {
            'MANAGER_PIN_INVALID', 'MANAGER_PIN_REQUIRED' => 'يلزم رمز مدير صالح لإعادة ربط الصندوق',
            'MANAGER_PIN_RATE_LIMITED' => 'محاولات كثيرة. انتظر قبل إعادة المحاولة.',
            'REGISTER_NOT_FOUND' => 'الصندوق غير موجود',
            default => 'تعذر ربط الجهاز. حاول مرة أخرى.',
        };
    }
}

$csrf = htmlspecialchars(csrf_token('register_pair'), ENT_QUOTES, 'UTF-8');
$showPinStep = $error !== '' && $activeRegisters !== [] && $canPair;
$selectedRegisterId = (int) ($_POST['register_id'] ?? (($activeRegisters[0]['id'] ?? 0)));
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ربط الصندوق</title>
    <link href="assets/libs/bootstrap.min.css" rel="stylesheet">
    <link href="assets/fonts/fonts.css" rel="stylesheet">
    <?php require __DIR__ . '/includes/pin_pad_styles.php'; ?>
    <style>
        body {
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(145deg, #0f172a 0%, #1e293b 55%, #312e81 100%);
            font-family: 'IBM Plex Sans Arabic', 'Inter', sans-serif;
        }
        .pair-shell { width: min(460px, 94vw); }
        .pair-card {
            background: #fff;
            border-radius: 20px;
            padding: 1.75rem;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .35);
        }
        .pair-title { font-size: 1.4rem; font-weight: 700; margin: 0 0 .35rem; text-align: center; }
        .pair-sub { color: #64748b; text-align: center; margin-bottom: 1.25rem; }
        .error-box {
            background: #fef2f2; color: #991b1b; border: 1px solid #fecaca;
            border-radius: 10px; padding: .75rem; margin-bottom: 1rem; text-align: center;
        }
        .reg-list { display: grid; gap: .65rem; margin-bottom: 1rem; }
        .reg-item {
            border: 1px solid #e2e8f0; border-radius: 12px; padding: .85rem 1rem;
            display: flex; justify-content: space-between; align-items: center;
        }
        .btn-pair {
            width: 100%; border: 0; border-radius: 12px; padding: .9rem 1rem;
            background: linear-gradient(135deg, #6366f1, #8b5cf6); color: #fff; font-weight: 700;
        }
        .pair-back {
            display: block;
            width: 100%;
            margin-top: .85rem;
            border: 0;
            background: transparent;
            color: #94a3b8;
            font-weight: 600;
            cursor: pointer;
        }
        .pair-back:hover { color: #e2e8f0; }
        .is-hidden { display: none !important; }
    </style>
</head>
<body>
<?php if (!$canPair): ?>
<div class="pair-shell">
    <div class="pair-card">
        <h1 class="pair-title">ربط هذا الجهاز بصندوق</h1>
        <p class="pair-sub">اطلب من المالك أو المدير ربط هذا الجهاز.</p>
    </div>
</div>
<?php elseif ($activeRegisters === []): ?>
<div class="pair-shell">
    <div class="pair-card">
        <h1 class="pair-title">ربط هذا الجهاز بصندوق</h1>
        <p class="pair-sub">لا يمكن البيع قبل ربط المتصفح بصندوق معتمد في الفرع.</p>
        <?php if ($error !== ''): ?>
            <div class="error-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
            <input type="hidden" name="action" value="create">
            <button type="submit" class="btn-pair">إنشاء وربط الصندوق الأول</button>
        </form>
    </div>
</div>
<?php else: ?>
<div class="pair-shell<?= $showPinStep ? ' is-hidden' : '' ?>" id="pairSelectStep">
    <div class="pair-card">
        <h1 class="pair-title">ربط هذا الجهاز بصندوق</h1>
        <p class="pair-sub">لا يمكن البيع قبل ربط المتصفح بصندوق معتمد في الفرع.</p>
        <div class="reg-list" id="regList">
            <?php foreach ($activeRegisters as $index => $reg): ?>
                <?php $regId = (int) $reg['id']; ?>
                <label class="reg-item">
                    <span>
                        <strong><?= htmlspecialchars((string) $reg['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                        <small class="text-muted d-block"><?= htmlspecialchars((string) $reg['code'], ENT_QUOTES, 'UTF-8') ?></small>
                    </span>
                    <input type="radio" name="register_pick" value="<?= $regId ?>"
                        <?= $regId === $selectedRegisterId || ($selectedRegisterId < 1 && $index === 0) ? 'checked' : '' ?>>
                </label>
            <?php endforeach; ?>
        </div>
        <button type="button" class="btn-pair" id="showPinBtn">متابعة بموافقة المدير</button>
    </div>
</div>

<div class="<?= $showPinStep ? '' : 'is-hidden' ?>" id="pairPinStep">
    <?php
    $pinPadId = 'registerPairPinPad';
    $pinPadCsrf = csrf_token('register_pair');
    $pinPadEndpoint = '';
    $pinPadTitle = 'موافقة المدير';
    $pinPadSubtitle = 'أدخل رمز المدير المكوّن من 4 أرقام';
    $pinPadError = $error !== '' ? $error : null;
    $pinPadDigits = 4;
    $pinPadMode = 'login';
    $pinPadExtraFields = '';
    require __DIR__ . '/includes/pin_pad_fragment.php';
    ?>
    <button type="button" class="pair-back" id="pairPinBack">رجوع لاختيار الصندوق</button>
</div>

<form method="post" id="claimForm" class="is-hidden" autocomplete="off">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="claim">
    <input type="hidden" name="register_id" id="claim_register_id" value="<?= (int) $selectedRegisterId ?>">
    <input type="hidden" name="manager_pin" id="manager_pin" value="">
</form>

<script src="js/pin_pad.js"></script>
<script>
(function () {
    var selectStep = document.getElementById('pairSelectStep');
    var pinStep = document.getElementById('pairPinStep');
    var showPinBtn = document.getElementById('showPinBtn');
    var backBtn = document.getElementById('pairPinBack');
    var claimForm = document.getElementById('claimForm');
    var claimRegisterId = document.getElementById('claim_register_id');
    var managerPin = document.getElementById('manager_pin');

    function selectedRegisterId() {
        var picked = document.querySelector('input[name="register_pick"]:checked');
        return picked ? picked.value : (claimRegisterId ? claimRegisterId.value : '');
    }

    function showPinStep() {
        if (claimRegisterId) claimRegisterId.value = selectedRegisterId();
        if (selectStep) selectStep.classList.add('is-hidden');
        if (pinStep) pinStep.classList.remove('is-hidden');
    }

    function showSelectStep() {
        if (pinStep) pinStep.classList.add('is-hidden');
        if (selectStep) selectStep.classList.remove('is-hidden');
        if (window.registerPairPinPadApi && typeof window.registerPairPinPadApi.reset === 'function') {
            window.registerPairPinPadApi.reset();
        }
    }

    if (showPinBtn) {
        showPinBtn.addEventListener('click', showPinStep);
    }
    if (backBtn) {
        backBtn.addEventListener('click', showSelectStep);
    }

    if (window.PosmainPinPad && claimForm && managerPin) {
        window.registerPairPinPadApi = window.PosmainPinPad.init('registerPairPinPad', {
            onSubmit: function (pin) {
                if (claimRegisterId) claimRegisterId.value = selectedRegisterId();
                managerPin.value = pin;
                claimForm.submit();
                // Stay busy until navigation; form POST handles success/error.
                return new Promise(function () {});
            }
        });
    }
})();
</script>
<?php endif; ?>
</body>
</html>
