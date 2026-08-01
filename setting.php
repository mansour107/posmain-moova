<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
include('includes/connect.php');
require_once __DIR__ . '/includes/pos_default_accounts.php';
require_once __DIR__ . '/classes/Inventory/NegativeStockSalePolicyService.php';
require_admin_or_permission('system.tools.run', $conn);

$sittingpass = $sittingpass ?? 'hadi@1234';
$settingsGateError = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $postedPass = (string) $_POST['password'];
    if (!verify_csrf_from_post_or_header('settings_gate')) {
        $_SESSION['settings_gate_flash'] = 'طلب غير صالح. حاول مرة أخرى.';
    } elseif ($postedPass !== $sittingpass) {
        $_SESSION['settings_gate_flash'] = 'كلمة المرور غير صحيحة';
    } else {
        $_SESSION['settings_gate_unlocked'] = true;
    }

    header('Location: ' . ($_SERVER['PHP_SELF'] ?? 'setting.php'));
    exit();
}

if (!empty($_SESSION['settings_gate_flash'])) {
    $settingsGateError = (string) $_SESSION['settings_gate_flash'];
    unset($_SESSION['settings_gate_flash']);
}

$settingsGateUnlocked = !empty($_SESSION['settings_gate_unlocked']);
$settingsStockStores = [];
$settingsStockResult = $conn->query("
    SELECT id, aname
    FROM acc_head
    WHERE COALESCE(isdeleted, 0) = 0
      AND COALESCE(is_stock, 0) = 1
    ORDER BY aname
    LIMIT 150
");
if ($settingsStockResult) {
    while ($settingsStockRow = $settingsStockResult->fetch_assoc()) {
        $settingsStockStores[] = $settingsStockRow;
    }
}
$settingsCurrentPosStore = (int) ($rowstg['def_pos_store'] ?? 0);
$settingsNegativeStockPolicy = (new NegativeStockSalePolicyService($appConfig ?? []))->resolve($conn);

// Mint every token used by this page while the session is still locked. The
// shared header releases the lock before rendering the body; creating tokens
// after that point can race with background requests and lose the new token.
csrf_token('settings_gate');
$systemUpdateCsrf = csrf_token('system_update');
csrf_token('settings_write');
csrf_token('sync_credentials');

include('includes/header.php');
?>

<?php if (!$settingsGateUnlocked): ?>

<div class="content-wrapper">
  <section class="content">
    <div class="container py-4">
      <div class="row justify-content-center">
        <div class="col-12 col-sm-10 col-md-6 col-lg-4">
          <div class="card card-outline card-primary shadow-sm">
            <div class="card-header text-center border-0 pt-4">
              <div class="mb-2 text-primary" style="font-size:2.5rem;"><i class="fas fa-shield-alt"></i></div>
              <h3 class="card-title font-weight-bold mb-0">إعدادات النظام</h3>
              <p class="text-muted small mb-0 mt-2">أدخل كلمة مرور الإعدادات للمتابعة</p>
            </div>
            <div class="card-body pt-0">
              <?php if ($settingsGateError !== null): ?>
              <div class="alert alert-danger" role="alert">
                <i class="fas fa-times-circle ml-1"></i>
                <?= htmlspecialchars($settingsGateError, ENT_QUOTES, 'UTF-8') ?>
              </div>
              <?php endif; ?>
              <form method="post" action="<?= htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8') ?>" autocomplete="off">
                <?= csrf_input('settings_gate') ?>
                <div class="form-group">
                  <label for="settings-gate-password">كلمة المرور</label>
                  <input type="password"
                         name="password"
                         id="settings-gate-password"
                         class="form-control form-control-lg frst"
                         required
                         autocomplete="current-password"
                         placeholder="••••••••">
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg">
                  <i class="fas fa-sign-in-alt ml-1"></i> متابعة
                </button>
              </form>
            </div>
          </div>
          <p class="text-center text-muted small mt-3 mb-0">
            <i class="fas fa-info-circle"></i> هذه الشاشة تحمي التعديلات الحساسة للنظام.
          </p>
        </div>
      </div>
    </div>
  </section>
</div>

<?php else: ?>

<?php
require_once __DIR__ . '/api/admin/updates/_bootstrap.php';
$systemUpdateAvailable = false;
$systemUpdateInstalledVersion = (string) (posmainInstalledVersion(__DIR__) ?? 'غير معروف');
require_once __DIR__ . '/classes/Sync/BranchIdentity.php';
require_once __DIR__ . '/classes/Sync/BranchPairingService.php';
require_once __DIR__ . '/classes/Sync/CloudBranchRegistryService.php';
require_once __DIR__ . '/classes/Sync/SyncRuntimeCrypto.php';
require_once __DIR__ . '/classes/Sync/SyncRuntimeDbConfigFile.php';
require_once __DIR__ . '/classes/Sync/SyncRuntimeSettings.php';

$syncRuntimeSettings = (new SyncRuntimeSettings())->loadForUi($conn, true);
$syncRuntimeFile = [];
try {
    $syncRuntimeFile = (new SyncRuntimeDbConfigFile())->load();
} catch (Throwable $ignored) {
    $syncRuntimeFile = [];
}
$syncDb = $syncRuntimeFile['database'] ?? ($appConfig['database'] ?? []);
$syncLocalEnvFiles = function_exists('posmain_branch_env_file_fallbacks') ? posmain_branch_env_file_fallbacks() : [];
$syncEnvFallback = static function (array $names, $default = '', bool $allowEmpty = false) use ($syncLocalEnvFiles) {
    if (function_exists('posmain_first_env_or_file')) {
        return posmain_first_env_or_file($names, $default, $allowEmpty, $syncLocalEnvFiles);
    }

    return $default;
};
if (!isset($syncRuntimeFile['database'])) {
    $syncDb = [
        'host' => (string) $syncEnvFallback(['POSMAIN_DB_HOST', 'POSMAIN_TEST_MYSQL_HOST', 'POSMAIN_API_DB_HOST'], (string)($syncDb['host'] ?? '127.0.0.1')),
        'port' => (int) $syncEnvFallback(['POSMAIN_DB_PORT', 'POSMAIN_TEST_MYSQL_PORT', 'POSMAIN_API_DB_PORT'], (string)($syncDb['port'] ?? 3306)),
        'name' => (string) $syncEnvFallback(['POSMAIN_DB_NAME', 'POSMAIN_TEST_MYSQL_DB', 'POSMAIN_API_DB_NAME'], (string)($syncDb['name'] ?? 'kody2')),
        'user' => (string) $syncEnvFallback(['POSMAIN_DB_USER', 'POSMAIN_TEST_MYSQL_USER', 'POSMAIN_API_DB_USER'], (string)($syncDb['user'] ?? 'root')),
        'pass' => (string) $syncEnvFallback(['POSMAIN_DB_PASS', 'POSMAIN_TEST_MYSQL_PASS', 'POSMAIN_API_DB_PASS'], (string)($syncDb['pass'] ?? ''), true),
        'charset' => (string) ($syncDb['charset'] ?? 'utf8mb4'),
    ];
}
$syncValue = static function (string $key, $default = '') use ($syncRuntimeSettings) {
    return isset($syncRuntimeSettings[$key]) ? (string) ($syncRuntimeSettings[$key]['value'] ?? '') : (string) $default;
};
$syncConfigured = static function (string $key) use ($syncRuntimeSettings): bool {
    return !empty($syncRuntimeSettings[$key]['configured']);
};
$syncEffectiveValue = static function (string $key, $default = '') use ($syncRuntimeSettings): string {
    if (
        isset($syncRuntimeSettings[$key])
        && empty($syncRuntimeSettings[$key]['is_secret'])
        && !empty($syncRuntimeSettings[$key]['configured'])
    ) {
        return (string) ($syncRuntimeSettings[$key]['value'] ?? '');
    }

    return (string) $default;
};
$syncSecretEffectiveValue = static function (string $key, $default = '') use ($syncRuntimeSettings): string {
    $value = '';
    if (
        isset($syncRuntimeSettings[$key])
        && !empty($syncRuntimeSettings[$key]['is_secret'])
        && !empty($syncRuntimeSettings[$key]['configured'])
    ) {
        $value = (string) ($syncRuntimeSettings[$key]['value'] ?? '');
    }

    return $value !== '' ? $value : (string) $default;
};
$syncSourceHint = static function (string $key, $fallbackValue = '', bool $secretFallbackConfigured = false) use ($syncConfigured): string {
    if ($syncConfigured($key)) {
        $text = 'القيمة الحالية من إعدادات الواجهة.';
    } elseif ($secretFallbackConfigured || trim((string) $fallbackValue) !== '') {
        $text = 'القيمة الحالية من env/.env/.env.branch-worker.';
    } else {
        $text = 'لا توجد قيمة حالية لهذا المتغير.';
    }

    return '<small class="form-text text-muted sync-source-hint">' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</small>';
};
$syncHelp = static function (string $text): string {
    $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    return '<i class="fas fa-question-circle text-info js-sync-help" role="button" tabindex="0" aria-label="' . $escaped . '" data-content="' . $escaped . '"></i>';
};
$syncConfigBool = static function (string $key, bool $default = false) use ($appConfig, $syncEnvFallback): bool {
    $map = [
        'POSMAIN_SYNC_OUTBOX_ENABLED' => ['sync', 'outbox_enabled'],
        'POSMAIN_BRANCH_SYNC_ENABLED' => ['sync', 'branch_sync_enabled'],
        'POSMAIN_SYNC_WORKER_ENABLED' => ['sync', 'worker_enabled'],
        'POSMAIN_MENU_SYNC_ENABLED' => ['sync', 'menu_sync_enabled'],
        'POSMAIN_CLOUD_APPLY_ENABLED' => ['sync', 'cloud_apply_enabled'],
        'POSMAIN_CLOUD_PULL_ENABLED' => ['sync', 'cloud_pull_enabled'],
        'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED' => ['sync', 'cloud_to_branch_publish_enabled'],
        'POSMAIN_MOOVA_POLLER_ENABLED' => ['sync', 'moova_poller_enabled'],
        'POSMAIN_MOOVA_APPLY_ENABLED' => ['sync', 'moova_apply_enabled'],
    ];
    $envNames = [$key];

    $envValue = $syncEnvFallback($envNames, null, true);
    if ($envValue !== null) {
        return posmain_bool($envValue, $default);
    }

    if (isset($map[$key])) {
        [$section, $name] = $map[$key];
        if (array_key_exists($name, $appConfig[$section] ?? [])) {
            return (bool) $appConfig[$section][$name];
        }
    }

    return $default;
};
$syncBool = static function (string $key, bool $default = false) use ($syncRuntimeSettings, $syncConfigured, $syncConfigBool): bool {
    if ($syncConfigured($key)) {
        $value = (string) ($syncRuntimeSettings[$key]['value'] ?? '0');
    } else {
        $value = $syncConfigBool($key, $default) ? '1' : '0';
    }
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
};
// Branch identity is per-shop DB only; never prefill from env/.env.branch-worker in Settings UI.
$syncBranchUuidEffective = $syncEffectiveValue('POSMAIN_BRANCH_UUID', '');
$syncCloudBaseUrlEffective = $syncEffectiveValue('POSMAIN_CLOUD_BASE_URL', '');
$syncBranchSecretEffective = $syncSecretEffectiveValue('POSMAIN_BRANCH_SYNC_SECRET', '');
$syncBranchSecretEffectiveConfigured = $syncBranchSecretEffective !== '';
$syncRole = strtolower(trim($syncEffectiveValue('POSMAIN_ROLE', (string)($appConfig['role'] ?? 'branch'))));
if (!in_array($syncRole, ['branch', 'cloud'], true)) {
    $syncRole = 'branch';
}
$syncIsHosted = $syncRole === 'cloud';
$syncIsLocal = $syncRole === 'branch';
$syncRouterEnabled = !empty($appConfig['router']['enabled']);
$syncBranches = (new BranchPairingService())->listHostedBranches($conn, $appConfig);
$syncStoredBranchUuid = '';
$syncRegisteredBranchUuid = '';
foreach ($syncBranches as $branchRow) {
    $candidateUuid = strtolower(trim((string) ($branchRow['branch_uuid'] ?? '')));
    if ($candidateUuid === '') {
        continue;
    }

    if (($branchRow['status'] ?? '') === 'active') {
        $syncRegisteredBranchUuid = $candidateUuid;
        break;
    }

    if ($syncRegisteredBranchUuid === '') {
        $syncRegisteredBranchUuid = $candidateUuid;
    }
}
$syncStoredIdentity = (new SyncBranchIdentity())->find($conn);
$syncStoredBranchUuid = '';
if ($syncIsLocal) {
    if ($syncConfigured('POSMAIN_BRANCH_UUID')) {
        $syncStoredBranchUuid = strtolower(trim($syncBranchUuidEffective));
    } elseif ($syncStoredIdentity) {
        $syncStoredBranchUuid = strtolower(trim((string) ($syncStoredIdentity['branch_uuid'] ?? '')));
    }
}
$syncCrypto = new SyncRuntimeCrypto();
$syncConfigEncryptionKeyEffective = $syncCrypto->currentKeyMaterial();
$syncConfigEncryptionKeyConfigured = $syncConfigEncryptionKeyEffective !== '';
$syncConfigEncryptionKeySource = $syncCrypto->keySource();
$syncCryptoAvailable = $syncCrypto->available();
$syncDefaultCloudUrl = (string) ($appConfig['public_base_url'] ?? '');
if ($syncDefaultCloudUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
    $syncDefaultCloudUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
}
?>

<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0 text-dark"><i class="fas fa-sliders-h text-primary ml-2"></i> الإعدادات العامة</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-left m-0 bg-transparent p-0">
            <li class="breadcrumb-item"><a href="dashboard.php">الرئيسية</a></li>
            <li class="breadcrumb-item active">الإعدادات</li>
          </ol>
        </div>
      </div>
      <div class="d-flex align-items-center justify-content-end mb-3">
        <div class="d-flex align-items-center flex-shrink-0">
          <span id="system-update-available-badge" class="badge badge-success ml-2" style="display:none;font-size:.75rem;padding:.4em .7em;">
            <i class="fas fa-circle" style="font-size:.5em;vertical-align:middle;"></i> تحديث جديد متاح
          </span>
          <button
            type="button"
            class="btn btn-outline-secondary btn-sm"
            id="system-update-action-btn"
            data-update-available="0"
            data-target-version=""
          >
            <i class="fas fa-search ml-1"></i> البحث عن تحديث
          </button>
        </div>
      </div>

      <div id="system-update-toast" style="display:none;position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);z-index:9999;min-width:280px;max-width:480px;"></div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">

      <form action="do/doedit_settings.php" method="post" id="settings-main-form">
        <?= csrf_input('settings_write') ?>

        <div class="card card-primary card-outline shadow-sm mb-4">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-building ml-2"></i> بيانات الشركة واللغة</h3>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-6">
                <div class="form-group">
                  <label for="companyname">اسم الشركة</label>
                  <input type="text" class="form-control" id="companyname" name="companyname"
                         value="<?= htmlspecialchars($rowstg['company_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-group">
                  <label for="companytel">هاتف الشركة</label>
                  <input type="text" class="form-control" id="companytel" name="companytel"
                         value="<?= htmlspecialchars($rowstg['company_tel'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
              <div class="col-12">
                <div class="form-group">
                  <label for="companyadd">عنوان الشركة</label>
                  <input type="text" class="form-control" id="companyadd" name="companyadd"
                         value="<?= htmlspecialchars($rowstg['company_add'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-group">
                  <label for="lang-select">لغة الواجهة</label>
                  <select class="form-control" id="lang-select" name="lang">
                    <option value="ar" <?= (($rowstg['lang'] ?? '') === 'ar') ? 'selected' : '' ?>>العربية</option>
                    <option value="en" <?= (($rowstg['lang'] ?? '') === 'en') ? 'selected' : '' ?>>English</option>
                    <option value="fr" <?= (($rowstg['lang'] ?? '') === 'fr') ? 'selected' : '' ?>>Français</option>
                    <option value="gr" <?= (($rowstg['lang'] ?? '') === 'gr') ? 'selected' : '' ?>>Deutsch</option>
                    <option value="sp" <?= (($rowstg['lang'] ?? '') === 'sp') ? 'selected' : '' ?>>Español</option>
                    <option value="trk" <?= (($rowstg['lang'] ?? '') === 'trk') ? 'selected' : '' ?>>Türkçe</option>
                    <option value="ch" <?= (($rowstg['lang'] ?? '') === 'ch') ? 'selected' : '' ?>>中文</option>
                    <option value="hn" <?= (($rowstg['lang'] ?? '') === 'hn') ? 'selected' : '' ?>>हिन्दी</option>
                    <option value="urd" <?= (($rowstg['lang'] ?? '') === 'urd') ? 'selected' : '' ?>>اردو</option>
                  </select>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-group">
                  <label for="edit_pass">كلمة مرور حماية التعديل داخل النظام</label>
                  <input type="text" class="form-control" id="edit_pass" name="edit_pass"
                         value="<?= htmlspecialchars($rowstg['edit_pass'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                  <small class="form-text text-muted">تُستخدم في بعض شاشات التعديل الحساسة.</small>
                </div>
              </div>
              <div class="col-md-6">
                <div class="form-group">
                  <label for="editpass">الترخيص / رقم إضافي</label>
                  <input type="text" class="form-control" id="editpass" name="editpass"
                         value="<?= htmlspecialchars($rowstg['lic'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-outline card-info shadow-sm mb-4">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-cash-register ml-2"></i> نقطة البيع (POS)</h3>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-lg-12">
                <div class="form-group">
                  <label for="pos_type">نوع نظام POS</label>
                  <select class="form-control" id="pos_type" name="pos_type">
                    <option value="barcode" <?= (($rowstg['pos_type'] ?? 'barcode') === 'barcode') ? 'selected' : '' ?>>POS عادي (باركود)</option>
                    <option value="clothes" <?= (($rowstg['pos_type'] ?? 'barcode') === 'clothes') ? 'selected' : '' ?>>POS ملابس</option>
                  </select>
                  <small class="form-text text-muted">يحدد نوع واجهة POS من القائمة.</small>
                </div>
              </div>
              <div class="col-lg-12">
                <div class="form-group">
                  <label for="negative_stock_sale_policy">سياسة البيع عند عدم كفاية المخزون</label>
                  <input type="hidden" name="negative_stock_sale_policy" value="allow_with_warning">
                  <input class="form-control" id="negative_stock_sale_policy" value="السماح مع تحذير وتسجيل الحدث" readonly>
                  <small class="form-text text-muted">نفاد المخزون لا يمنع البيع. العناصر المعطلة يدوياً فقط تظل ممنوعة.</small>
                </div>
              </div>
            </div>
            <hr class="my-3">
            <h5 class="text-muted mb-3"><i class="fas fa-link ml-2"></i> الحسابات الافتراضية للكاشير</h5>
            <div class="row">
              <div class="col-md-6 col-lg-4">
                <div class="form-group">
                  <label for="acc_rent">إيجار مستحق (حساب)</label>
                  <input type="number" class="form-control" id="acc_rent" name="acc_rent"
                         value="<?= htmlspecialchars((string)($rowstg['acc_rent'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
              <div class="col-md-6 col-lg-4">
                <div class="form-group">
                  <label for="def_pos_client">عميل الكاشير الافتراضي</label>
                  <input type="number" class="form-control" id="def_pos_client" name="def_pos_client"
                         value="<?= htmlspecialchars((string)($rowstg['def_pos_client'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
              <div class="col-md-6 col-lg-4">
                <div class="form-group">
                  <label for="def_pos_store">مخزن الكاشير الافتراضي</label>
                  <?php if ($settingsStockStores): ?>
                    <select class="form-control" id="def_pos_store" name="def_pos_store" required>
                      <option value="">اختر مخزن الكاشير</option>
                      <?php foreach ($settingsStockStores as $settingsStockStore): ?>
                        <option value="<?= (int) ($settingsStockStore['id'] ?? 0) ?>" <?= $settingsCurrentPosStore === (int) ($settingsStockStore['id'] ?? 0) ? 'selected' : '' ?>>
                          <?= htmlspecialchars((string) ($settingsStockStore['aname'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($settingsCurrentPosStore > 0 && !posmain_resolve_default_account_id($conn, $settingsCurrentPosStore, 'is_stock = 1')): ?>
                      <small class="form-text text-warning">المخزن المحفوظ حاليا غير صالح. اختر مخزنا نشطا من القائمة.</small>
                    <?php else: ?>
                      <small class="form-text text-muted">يحدد المخزن التشغيلي الوحيد للمخزون والكاشير.</small>
                    <?php endif; ?>
                  <?php else: ?>
                    <input type="number" class="form-control" id="def_pos_store" name="def_pos_store"
                           value="<?= htmlspecialchars((string)($rowstg['def_pos_store'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                    <small class="form-text text-muted">لا توجد حسابات مخزون نشطة لاختيارها.</small>
                  <?php endif; ?>
                </div>
              </div>
              <div class="col-md-6 col-lg-4">
                <div class="form-group">
                  <label for="def_pos_employee">موظف الكاشير الافتراضي</label>
                  <input type="number" class="form-control" id="def_pos_employee" name="def_pos_employee"
                         value="<?= htmlspecialchars((string)($rowstg['def_pos_employee'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
              <div class="col-md-6 col-lg-4">
                <div class="form-group">
                  <label for="def_pos_fund">صندوق الكاشير الافتراضي</label>
                  <input type="number" class="form-control" id="def_pos_fund" name="def_pos_fund"
                         value="<?= htmlspecialchars((string)($rowstg['def_pos_fund'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-outline card-secondary shadow-sm mb-4">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-palette ml-2"></i> الألوان</h3>
          </div>
          <div class="card-body">
            <div class="row">
              <div class="col-md-4">
                <div class="form-group">
                  <label for="bodycolor">لون خلفية المحتوى</label>
                  <input type="color" class="form-control form-control-sm p-1" style="height:42px;" id="bodycolor" name="bodycolor"
                         value="<?= htmlspecialchars($rowstg['bodycolor'] ?? '#f0fdfa', ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-group">
                  <label for="nav-background">لون الشريط العلوي</label>
                  <input type="color" class="form-control form-control-sm p-1" style="height:42px;" id="nav-background" name="nav-background"
                         value="<?= htmlspecialchars($rowstg['bodycolor'] ?? '#ffffff', ENT_QUOTES, 'UTF-8') ?>">
                  <small class="form-text text-muted">حفظ مستقبلي عبر النظام إن وُجد دعم في القاعدة.</small>
                </div>
              </div>
              <div class="col-md-4">
                <div class="form-group">
                  <label for="side-background">لون الشريط الجانبي</label>
                  <input type="color" class="form-control form-control-sm p-1" style="height:42px;" id="side-background" name="side-background"
                         value="<?= htmlspecialchars($rowstg['bodycolor'] ?? '#343a40', ENT_QUOTES, 'UTF-8') ?>">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card card-outline card-teal shadow-sm mb-4" id="sync-credentials-card" data-sync-role="<?= htmlspecialchars($syncRole, ENT_QUOTES, 'UTF-8') ?>" data-stored-branch-uuid="<?= htmlspecialchars($syncStoredBranchUuid, ENT_QUOTES, 'UTF-8') ?>">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-sync-alt ml-2"></i> إعدادات المزامنة</h3>
            <div class="card-tools">
              <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-minus"></i></button>
            </div>
          </div>
          <div class="card-body">
            <?= csrf_input('sync_credentials', 'sync_csrf_token') ?>
            <?php if ($syncCryptoAvailable): ?>
              <div class="alert alert-success py-2">
                <i class="fas fa-key ml-2"></i>
                <code>POSMAIN_CONFIG_ENCRYPTION_KEY</code> مفعّل، ويمكن حفظ الأسرار مشفرة.
                <?= $syncHelp('This instance encryption key can come from env or the protected runtime key file. If it changes after secrets are saved, stored secrets cannot be decrypted until they are re-saved with the new key.') ?>
              </div>
            <?php endif; ?>

            <style>
              #sync-credentials-card .sync-section {
                border: 1px solid rgba(33, 37, 41, 0.10);
                border-radius: 8px;
                padding: 1rem;
                margin-bottom: 1rem;
              }
              #sync-credentials-card .sync-section-shared { background-color: rgba(23, 162, 184, 0.10); }
              #sync-credentials-card .sync-section-local { background-color: rgba(40, 167, 69, 0.10); }
              #sync-credentials-card .sync-section-hosted { background-color: rgba(0, 123, 255, 0.10); }
              #sync-credentials-card .sync-section-debug { background-color: rgba(108, 117, 125, 0.10); }
              #sync-credentials-card .sync-section-header {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 0.75rem;
                border-bottom: 1px solid rgba(33, 37, 41, 0.08);
                padding-bottom: 0.75rem;
                margin-bottom: 1rem;
              }
              #sync-credentials-card .sync-section-kicker {
                display: inline-block;
                font-size: 1rem;
                font-weight: 800;
                letter-spacing: 0;
                color: rgba(33, 37, 41, 0.86);
                margin-bottom: 0.25rem;
              }
              #sync-credentials-card .sync-subsection {
                background: rgba(255, 255, 255, 0.72);
                border: 1px solid rgba(33, 37, 41, 0.08);
                border-radius: 6px;
                padding: 0.9rem;
                margin-bottom: 0.9rem;
              }
              #sync-credentials-card .sync-subsection:last-child { margin-bottom: 0; }
              #sync-credentials-card .sync-switch-lg .custom-control-label::before {
                width: 2.75rem;
                height: 1.45rem;
                border-radius: 1rem;
              }
              #sync-credentials-card .sync-switch-lg .custom-control-label::after {
                width: calc(1.45rem - 4px);
                height: calc(1.45rem - 4px);
                border-radius: 50%;
              }
              #sync-credentials-card .sync-switch-lg .custom-control-input:checked ~ .custom-control-label::after {
                transform: translateX(-1.3rem);
              }
              #sync-credentials-card .sync-status-badge {
                display: inline-block;
                font-size: 0.75rem;
                font-weight: 700;
                letter-spacing: 0.04em;
                text-transform: uppercase;
                border-radius: 999px;
                padding: 0.2rem 0.65rem;
              }
              #sync-credentials-card .sync-status-badge-ok {
                background: rgba(40, 167, 69, 0.18);
                color: #1e7e34;
              }
              #sync-credentials-card .sync-status-badge-bad {
                background: rgba(220, 53, 69, 0.16);
                color: #bd2130;
              }
              #sync-credentials-card .sync-ltr-value,
              #sync-credentials-card code.sync-ltr-value {
                direction: ltr;
                unicode-bidi: isolate;
                display: inline-block;
                text-align: left;
              }
              #sync-credentials-card #sync-cloud-branches-table td.sync-ltr-value {
                direction: ltr;
                unicode-bidi: isolate;
                text-align: left;
              }
              #sync-credentials-card .sync-status-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
                gap: 0.75rem;
              }
              #sync-credentials-card .sync-status-card {
                background: rgba(255, 255, 255, 0.72);
                border: 1px solid rgba(0, 0, 0, 0.06);
                border-radius: 0.5rem;
                padding: 0.75rem 0.9rem;
              }
              #sync-credentials-card .sync-status-card h6 {
                font-size: 0.78rem;
                text-transform: uppercase;
                letter-spacing: 0.05em;
                color: rgba(33, 37, 41, 0.62);
                margin-bottom: 0.35rem;
              }
              #sync-credentials-card .sync-status-card p {
                margin-bottom: 0;
                font-size: 0.9rem;
              }
            </style>

            <section class="sync-section mb-3" id="sync-status-section">
              <div class="sync-section-header">
                <div>
                  <span class="sync-section-kicker">Status</span>
                  <h5 class="mb-1">Pairing &amp; sync health</h5>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary js-refresh-sync-status" dir="ltr">
                  <i class="fas fa-sync-alt mr-1"></i> Refresh
                </button>
              </div>
              <div id="sync-status-panel" class="sync-subsection">
                <div class="text-muted small">Loading pairing and worker status...</div>
              </div>
            </section>

            <div id="sync-local-form">
              <input type="hidden" name="action" value="save_local">

              <section class="sync-section sync-section-shared" id="sync-shared-section">
                <div class="sync-section-header">
                  <div>
                    <span class="sync-section-kicker">Shared data</span>
                    <h5 class="mb-1"><i class="fas fa-layer-group text-info ml-2"></i> البيانات المشتركة بين المحلي والمستضاف</h5>
                  </div>
                </div>

                <div class="sync-subsection">
                  <div class="row">
                    <div class="col-12" id="sync-config-encryption-fields">
                      <div class="form-group">
                        <label>POSMAIN_CONFIG_ENCRYPTION_KEY <?= $syncHelp('Local bootstrap key for this running instance. Local and hosted do not need the same key. Keep it stable on the same instance; changing it makes previously encrypted values unreadable until they are saved again.') ?></label>
                        <div class="input-group">
                          <input type="password" class="form-control" name="POSMAIN_CONFIG_ENCRYPTION_KEY" value="<?= htmlspecialchars($syncConfigEncryptionKeyEffective, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" placeholder="<?= $syncConfigEncryptionKeyConfigured ? 'Current encryption key is hidden' : 'Generate or enter a key before saving secrets' ?>">
                          <div class="input-group-append">
                            <button type="button"
                                    class="btn btn-outline-secondary js-generate-config-key"
                                    data-target="#sync-config-encryption-fields [name='POSMAIN_CONFIG_ENCRYPTION_KEY']"
                                    data-confirm-if-filled="1"
                                    data-confirm-message="Regenerating the encryption key can make previously encrypted database passwords and sync secrets unreadable until they are saved again with the new key. Continue?"
                                    dir="ltr">Generate key</button>
                            <button type="button" class="btn btn-outline-secondary js-toggle-secret-visibility" data-target="#sync-config-encryption-fields [name='POSMAIN_CONFIG_ENCRYPTION_KEY']" aria-label="Show encryption key">
                              <i class="fas fa-eye"></i>
                            </button>
                          </div>
                        </div>
                        <small class="form-text text-muted sync-source-hint">
                          <?php if ($syncConfigEncryptionKeySource === 'env'): ?>
                            القيمة الحالية من env/.env.
                          <?php elseif ($syncConfigEncryptionKeySource !== ''): ?>
                            القيمة الحالية من ملف مفتاح التشغيل: <code><?= htmlspecialchars($syncConfigEncryptionKeySource, ENT_QUOTES, 'UTF-8') ?></code>
                          <?php else: ?>
                            لا توجد قيمة حالية لهذا المتغير.
                          <?php endif; ?>
                        </small>
                      </div>
                    </div>
                    <div class="col-12" id="sync-shared-identity-fields">
                      <div class="form-group">
                        <label>POSMAIN_BRANCH_UUID <?= $syncHelp('Stable unique ID for this branch only. The same value must be used by the local branch and allowed on the hosted POS.') ?></label>
                        <div class="input-group">
                          <input type="text" class="form-control sync-ltr-value" name="POSMAIN_BRANCH_UUID" dir="ltr" value="<?= htmlspecialchars($syncBranchUuidEffective, ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= $syncIsLocal ? '' : 'Generate or enter before registering a branch' ?>" <?= $syncIsLocal ? 'required' : '' ?>>
                          <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary js-generate-uuid" data-target="#sync-shared-identity-fields [name='POSMAIN_BRANCH_UUID']" data-confirm-if-filled="1" dir="ltr"><?= trim($syncBranchUuidEffective) !== '' ? 'Regenerate' : 'Generate UUID' ?></button>
                          </div>
                        </div>
                        <?= $syncSourceHint('POSMAIN_BRANCH_UUID', '') ?>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="form-group mb-0">
                        <label>POSMAIN_BRANCH_SYNC_SECRET <?= $syncHelp('HMAC signing secret shared by this local branch and its hosted POS. It must be unique per shop and never shared between shops.') ?></label>
                        <div class="input-group">
                          <input type="password" class="form-control" name="POSMAIN_BRANCH_SYNC_SECRET" value="<?= htmlspecialchars($syncBranchSecretEffective, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" placeholder="<?= $syncBranchSecretEffectiveConfigured ? 'Current secret is hidden' : 'Required before saving' ?>">
                          <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary js-generate-secret" data-target="#sync-shared-section [name='POSMAIN_BRANCH_SYNC_SECRET']" data-confirm-if-filled="1" dir="ltr">Generate secret</button>
                            <button type="button" class="btn btn-outline-secondary js-toggle-secret-visibility" data-target="#sync-shared-section [name='POSMAIN_BRANCH_SYNC_SECRET']" aria-label="Show sync secret">
                              <i class="fas fa-eye"></i>
                            </button>
                          </div>
                        </div>
                        <?= $syncSourceHint('POSMAIN_BRANCH_SYNC_SECRET', '') ?>
                      </div>
                    </div>
                  </div>
                </div>
              </section>

              <?php if ($syncIsLocal): ?>
              <section class="sync-section sync-section-local" id="sync-local-section">
                <div class="sync-section-header">
                  <div>
                    <span class="sync-section-kicker">Local data</span>
                  </div>
                </div>

                <div class="sync-subsection">
                  <h6 class="font-weight-bold mb-2">الاتصال بالسحابة</h6>
                  <div class="row">
                    <div class="col-md-8 mb-4">
                      <div class="form-group">
                        <label>POSMAIN_CLOUD_BASE_URL <?= $syncHelp('Hosted POS base URL for this shop, for example https://shop1.example.com.') ?></label>
                        <input type="url" class="form-control" name="POSMAIN_CLOUD_BASE_URL" value="<?= htmlspecialchars($syncCloudBaseUrlEffective, ENT_QUOTES, 'UTF-8') ?>" required>
                        <?= $syncSourceHint('POSMAIN_CLOUD_BASE_URL', '') ?>
                      </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end mb-4 flex-wrap">
                      <button type="button" class="btn btn-outline-info mb-3 mr-2 js-sync-test-cloud" data-form="#sync-local-form" dir="ltr">
                        <i class="fas fa-cloud mr-1"></i> Test cloud connection
                      </button>
                      <button type="button" class="btn btn-outline-primary mb-3 mr-2 js-sync-push-data" data-form="#sync-local-form" dir="ltr">
                        <i class="fas fa-upload mr-1"></i> Sync all data to hosted
                      </button>
                      <button type="button" class="btn btn-outline-warning mb-3 js-sync-restore-hosted" data-form="#sync-local-form" dir="ltr">
                        <i class="fas fa-clipboard-check mr-1"></i> Preview hosted restore
                      </button>
                    </div>
                  </div>

                  <?php
                  $localCoreToggleKeys = [
                      'POSMAIN_SYNC_OUTBOX_ENABLED',
                      'POSMAIN_BRANCH_SYNC_ENABLED',
                      'POSMAIN_SYNC_WORKER_ENABLED',
                      'POSMAIN_MENU_SYNC_ENABLED',
                  ];
                  $localCoreSyncEnabled = true;
                  foreach ($localCoreToggleKeys as $toggleKey) {
                      $localCoreSyncEnabled = $localCoreSyncEnabled && $syncBool($toggleKey, true);
                  }
                  ?>
                  <div class="d-flex flex-wrap align-items-center justify-content-between pt-3 border-top">
                    <div class="mb-3 mb-md-0">
                      <strong>Enable local branch sync</strong>
                    </div>
                    <div class="custom-control custom-switch sync-switch-lg mb-0">
                      <input type="checkbox" class="custom-control-input js-local-sync-master" id="local-branch-sync-master" <?= $localCoreSyncEnabled ? 'checked' : '' ?>>
                      <label class="custom-control-label" for="local-branch-sync-master"></label>
                    </div>
                  </div>
                  <button type="button" class="btn btn-link px-0 mt-3 js-toggle-local-sync-advanced" aria-expanded="false" aria-controls="local-sync-advanced-settings" dir="ltr">
                    Advanced settings
                  </button>
                </div>

                <div id="local-sync-advanced-settings" class="sync-subsection" style="display:none;">
                  <h6 class="font-weight-bold mb-3">Advanced local sync settings</h6>
                  <div class="row">
                    <?php
                    $localToggles = [
                        'POSMAIN_SYNC_OUTBOX_ENABLED' => ['تسجيل أحداث outbox', true, 'Records POS changes into the local sync outbox so they can be sent to the cloud.', true],
                        'POSMAIN_BRANCH_SYNC_ENABLED' => ['تفعيل مزامنة الفرع', true, 'Enables sending local branch events to the hosted POS.', true],
                        'POSMAIN_SYNC_WORKER_ENABLED' => ['تشغيل عامل المزامنة', true, 'Enables the background sync worker on the local machine.', true],
                        'POSMAIN_MENU_SYNC_ENABLED' => ['مزامنة المنيو', true, 'Sends menu item and price changes through sync when enabled.', true],
                    ];
                    foreach ($localToggles as $toggleKey => [$label, $default, $help, $controlledByMaster]):
                    ?>
                    <div class="col-md-4">
                      <div class="custom-control custom-switch mb-3">
                        <input type="hidden" name="<?= htmlspecialchars($toggleKey, ENT_QUOTES, 'UTF-8') ?>" value="0">
                        <input type="checkbox" class="custom-control-input <?= $controlledByMaster ? 'js-local-sync-core-toggle' : '' ?>" id="local-<?= htmlspecialchars(strtolower($toggleKey), ENT_QUOTES, 'UTF-8') ?>" name="<?= htmlspecialchars($toggleKey, ENT_QUOTES, 'UTF-8') ?>" value="1" <?= $syncBool($toggleKey, $default) ? 'checked' : '' ?>>
                        <label class="custom-control-label" for="local-<?= htmlspecialchars(strtolower($toggleKey), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?> <?= $syncHelp($help) ?></label>
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                  <input type="hidden" name="POSMAIN_CLOUD_PULL_ENABLED" value="0">
                  <div class="alert alert-light border mb-0">
                    Generic hosted-to-branch pull is disabled. Recovery is manual and uses the guarded CLI dry-run, backup, empty-database and reconciliation workflow.
                  </div>
                </div>

                <span class="sync-action-result text-muted"></span>
              </section>
              <?php endif; ?>
            </div>

            <?php if ($syncIsHosted): ?>
            <section class="sync-section sync-section-hosted" id="sync-hosted-section">
              <div class="sync-section-header">
                <div>
                  <span class="sync-section-kicker">Hosted data</span>
                </div>
              </div>

              <div id="sync-cloud-form" class="sync-subsection">
                <input type="hidden" name="action" value="save_cloud">
                <?php
                $hostedSyncToggleKeys = ['POSMAIN_CLOUD_APPLY_ENABLED'];
                $hostedSyncEnabled = true;
                foreach ($hostedSyncToggleKeys as $toggleKey) {
                    $hostedSyncEnabled = $hostedSyncEnabled && $syncBool($toggleKey, true);
                }
                ?>
                <div class="border rounded px-3 py-3 mb-3 bg-white">
                  <div class="d-flex flex-wrap align-items-center justify-content-between">
                    <div class="mb-2 mb-md-0">
                      <strong>Enable hosted sync</strong>
                      <span class="text-muted d-block small">يشغّل استقبال أحداث الفروع والسماح بتبادل التحديثات من النسخة المستضافة.</span>
                    </div>
                    <div class="custom-control custom-switch sync-switch-lg mb-0">
                      <input type="checkbox" class="custom-control-input js-hosted-sync-master" id="hosted-sync-master" <?= $hostedSyncEnabled ? 'checked' : '' ?>>
                      <label class="custom-control-label" for="hosted-sync-master"></label>
                    </div>
                  </div>
                  <?php foreach ($hostedSyncToggleKeys as $toggleKey): ?>
                    <input type="hidden" class="js-hosted-sync-core-toggle" name="<?= htmlspecialchars($toggleKey, ENT_QUOTES, 'UTF-8') ?>" value="<?= $hostedSyncEnabled ? '1' : '0' ?>">
                  <?php endforeach; ?>
                  <input type="hidden" name="POSMAIN_CLOUD_PULL_ENABLED" value="0">
                  <input type="hidden" name="POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED" value="0">
                </div>
                <span class="sync-action-result text-muted"></span>
              </div>

              <?php if ($syncRouterEnabled): ?>
              <div class="sync-subsection">
                <h6 class="font-weight-bold mb-2">Provision new shop database</h6>
                <p class="text-muted small mb-3">When router mode is enabled, you can create a new hosted shop database automatically during pairing.</p>
                <div class="custom-control custom-checkbox mb-3">
                  <input type="checkbox" class="custom-control-input" id="provision-new-shop" name="provision_new_shop" value="1">
                  <label class="custom-control-label" for="provision-new-shop">Create a new shop database on save/pair</label>
                </div>
                <div class="row">
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Shop slug</label>
                      <input type="text" class="form-control" name="provision_shop_slug" placeholder="shop-alpha">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Shop display name</label>
                      <input type="text" class="form-control" name="provision_shop_name" placeholder="Alpha Shop">
                    </div>
                  </div>
                  <div class="col-md-4">
                    <div class="form-group">
                      <label>Database name (optional)</label>
                      <input type="text" class="form-control" name="provision_db_name" placeholder="posmain_shop_alpha">
                    </div>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <div class="sync-subsection">
                <h6 class="font-weight-bold mb-2">تسجيل فرع جديد على النسخة المستضافة</h6>
                <p class="text-muted small mb-3">يُضاف الفرع إلى الجدول أدناه فقط عند الضغط على هذا الزر بعد إنشاء UUID والسر من الحقول المشتركة أعلاه.</p>
                <button type="button" class="btn btn-primary js-register-hosted-branch" dir="ltr">
                  <i class="fas fa-link mr-1"></i> Register branch on hosted POS
                </button>
                <span class="sync-action-result text-muted ml-2"></span>
              </div>

              <div class="sync-subsection">
                <h6 class="font-weight-bold mb-3">الفروع المسجلة على النسخة المستضافة</h6>
                <div class="table-responsive">
                  <table class="table table-sm table-striped mb-0" id="sync-cloud-branches-table" dir="ltr">
                    <thead><tr><th>Branch UUID</th><th>الحالة</th><th>سر مشفر</th><th>آخر ظهور</th><th>آخر تحديث</th></tr></thead>
                    <tbody>
                      <?php foreach ($syncBranches as $branchRow): ?>
                        <tr>
                          <td><code class="sync-ltr-value"><?= htmlspecialchars($branchRow['branch_uuid'], ENT_QUOTES, 'UTF-8') ?></code></td>
                          <td><?= htmlspecialchars($branchRow['status'], ENT_QUOTES, 'UTF-8') ?></td>
                          <td><?= !empty($branchRow['has_encrypted_secret']) ? 'نعم' : 'لا' ?></td>
                          <td class="sync-ltr-value"><?= htmlspecialchars($branchRow['last_seen_at'], ENT_QUOTES, 'UTF-8') ?></td>
                          <td class="sync-ltr-value"><?= htmlspecialchars($branchRow['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </section>
            <?php endif; ?>

            <section class="sync-section sync-section-debug" id="sync-debug-section">
              <button type="button" class="btn btn-outline-secondary btn-block text-left js-toggle-sync-debug" aria-expanded="false" aria-controls="sync-debug-panel" dir="ltr">
                <i class="fas fa-tools mr-1"></i> Debug
              </button>
              <div id="sync-debug-panel" class="mt-3" style="display:none;">
                <div id="sync-debug-form">
                  <div class="sync-subsection">
                    <h6 class="font-weight-bold mb-2"><i class="fas fa-database text-secondary ml-2"></i> قاعدة بيانات هذه النسخة</h6>
                    <p class="text-muted small mb-3">هذه قيم تشغيل متقدمة تُقرأ تلقائياً عادةً من السيرفر. افتحها فقط للاختبار أو تغيير الإعدادات.</p>
                    <div class="row">
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Host <?= $syncHelp('Database host used by this running POS instance. On a local branch this is usually 127.0.0.1; on Railway it can be the Railway internal MySQL host.') ?></label>
                          <input type="text" class="form-control" name="db_host" value="<?= htmlspecialchars((string)($syncDb['host'] ?? '127.0.0.1'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                      </div>
                      <div class="col-md-2">
                        <div class="form-group">
                          <label>Port <?= $syncHelp('MySQL/MariaDB port. Default is 3306; local test stacks may use 3307.') ?></label>
                          <input type="number" class="form-control" name="db_port" value="<?= htmlspecialchars((string)($syncDb['port'] ?? '3306'), ENT_QUOTES, 'UTF-8') ?>" min="1" max="65535" required>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="form-group">
                          <label>Database <?= $syncHelp('Database name used by this POS instance.') ?></label>
                          <input type="text" class="form-control" name="db_name" value="<?= htmlspecialchars((string)($syncDb['name'] ?? 'kody2'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                      </div>
                      <div class="col-md-3">
                        <div class="form-group">
                          <label>User <?= $syncHelp('Database user. It needs read and write access to POS and sync tables.') ?></label>
                          <input type="text" class="form-control" name="db_user" value="<?= htmlspecialchars((string)($syncDb['user'] ?? 'root'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                      </div>
                      <div class="col-md-4">
                        <div class="form-group">
                          <label>Password <?= $syncHelp('Database password for this running POS instance. It is filled when the server can read it from env or the encrypted runtime file.') ?></label>
                          <div class="input-group">
                            <input type="password" class="form-control" name="db_pass" value="<?= htmlspecialchars((string)($syncDb['pass'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" placeholder="<?= !empty($syncDb['pass']) ? 'Current password is hidden' : 'اتركها فارغة فقط إذا كان المستخدم بدون كلمة مرور' ?>">
                            <div class="input-group-append">
                              <button type="button" class="btn btn-outline-secondary js-toggle-secret-visibility" data-target="#sync-debug-form [name='db_pass']" aria-label="Show database password">
                                <i class="fas fa-eye"></i>
                              </button>
                            </div>
                          </div>
                          <small class="form-text text-muted">محلياً تكون كلمة مرور قاعدة الفرع، وعلى الاستضافة تكون كلمة مرور قاعدة Railway/الاستضافة.</small>
                        </div>
                      </div>
                      <div class="col-md-2">
                        <div class="form-group">
                          <label>Charset <?= $syncHelp('Database connection charset. Use utf8mb4 for Arabic text and symbols.') ?></label>
                          <input type="text" class="form-control" name="db_charset" value="<?= htmlspecialchars((string)($syncDb['charset'] ?? 'utf8mb4'), ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                      </div>
                      <div class="col-md-6 d-flex align-items-end">
                        <button type="button" class="btn btn-outline-primary mb-3 js-sync-test-db" data-form="#sync-debug-form" dir="ltr">
                          <i class="fas fa-plug mr-1"></i> Test database connection
                        </button>
                      </div>
                    </div>
                  </div>

                  <div class="sync-subsection">
                    <h6 class="font-weight-bold mb-2"><i class="fas fa-server text-secondary ml-2"></i> Build hosting env values</h6>
                    <p class="text-muted small mb-3">اختياري: يحول قيم قاعدة البيانات أعلاه إلى متغيرات بيئة يمكن نسخها عند تجهيز Railway/الاستضافة.</p>
                    <button type="button" class="btn btn-outline-secondary mb-3 js-export-hosted-env" data-form="#sync-debug-form" dir="ltr">
                      <i class="fas fa-server mr-1"></i> Build hosting env values
                    </button>
                    <div class="form-group mb-0">
                      <label>Generated hosting env block <?= $syncHelp('This uses the database fields in Debug. Copy it into Railway or the hosting provider only when configuring the hosted POS.') ?></label>
                      <textarea class="form-control" id="hosted-env-output" rows="7" readonly></textarea>
                    </div>
                  </div>
                  <span class="sync-action-result text-muted"></span>
                </div>
              </div>
            </section>
          </div>
        </div>

        <div class="card card-outline card-warning shadow-sm mb-4">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-eye ml-2"></i> ظهور القوائم في الشريط الجانبي</h3>
            <div class="card-tools">
              <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-minus"></i></button>
            </div>
          </div>
          <div class="card-body p-0">
            <div class="table-responsive">
              <table class="table table-hover table-striped mb-0">
                <thead class="thead-light">
                  <tr>
                    <th style="width:50%">القائمة</th>
                    <th style="width:25%">الظهور (1 = ظاهر، 0 = مخفي)</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td><i class="fas fa-key text-warning ml-2"></i> التأجير</td>
                    <td><input type="number" name="showrent" class="form-control form-control-sm" min="0" max="1" step="1"
                               value="<?= htmlspecialchars((string)($rowstg['showrent'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                  </tr>
                  <tr>
                    <td><i class="fas fa-clinic-medical text-info ml-2"></i> العيادات</td>
                    <td><input type="number" name="showclinc" class="form-control form-control-sm" min="0" max="1" step="1"
                               value="<?= htmlspecialchars((string)($rowstg['showclinc'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                  </tr>
                  <tr>
                    <td><i class="fas fa-users text-primary ml-2"></i> الموارد البشرية</td>
                    <td><input type="number" name="showhr" class="form-control form-control-sm" min="0" max="1" step="1"
                               value="<?= htmlspecialchars((string)($rowstg['showhr'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                  </tr>
                  <tr>
                    <td><i class="fas fa-bolt text-warning ml-2"></i> Pulse (تقييم لحظي)</td>
                    <td><input type="number" name="showpulse" class="form-control form-control-sm" min="0" max="1" step="1"
                               value="<?= htmlspecialchars((string)($rowstg['showpulse'] ?? '1'), ENT_QUOTES, 'UTF-8') ?>"></td>
                  </tr>
                  <tr>
                    <td><i class="fas fa-user-check text-success ml-2"></i> زيارات العملاء</td>
                    <td><input type="number" name="show_customer_visits" class="form-control form-control-sm" min="0" max="1" step="1"
                               value="<?= htmlspecialchars((string)($rowstg['show_customer_visits'] ?? '1'), ENT_QUOTES, 'UTF-8') ?>"></td>
                  </tr>
                  <tr>
                    <td><i class="fas fa-user-check text-success ml-2"></i> الحضور</td>
                    <td><input type="number" name="showatt" class="form-control form-control-sm" min="0" max="1" step="1"
                               value="<?= htmlspecialchars((string)($rowstg['showatt'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                  </tr>
                  <tr>
                    <td><i class="fas fa-money-bill-wave text-secondary ml-2"></i> المرتبات</td>
                    <td><input type="number" name="showpayroll" class="form-control form-control-sm" min="0" max="1" step="1"
                               value="<?= htmlspecialchars((string)($rowstg['showpayroll'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>"></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="card card-outline card-success shadow-sm mb-4">
          <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
            <div class="mb-2 mb-md-0">
              <strong><i class="fas fa-save ml-2"></i> حفظ جميع التغييرات</strong>
              <span class="text-muted d-block small">بعد الحفظ ستنتقل إلى لوحة التحكم.</span>
              <span class="text-muted d-block small" id="settings-global-save-result"></span>
            </div>
            <button type="submit" class="btn btn-success btn-lg px-5">
              <i class="fas fa-check ml-2"></i> تأكيد الحفظ
            </button>
          </div>
        </div>

      </form>

    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const syncCard = document.getElementById('sync-credentials-card');
  if (!syncCard || typeof $ === 'undefined') {
    return;
  }

  if ($.fn.popover) {
    const $syncHelp = $('.js-sync-help');
    $syncHelp.popover({
      container: 'body',
      content: function () { return this.getAttribute('data-content') || ''; },
      placement: 'top',
      title: 'Help',
      trigger: 'manual'
    });
    $syncHelp.on('mouseenter focus', function () {
      $(this).popover('show');
    }).on('mouseleave blur', function () {
      if (!this.getAttribute('data-click-open')) {
        $(this).popover('hide');
      }
    }).on('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      const isOpen = this.getAttribute('data-click-open') === '1';
      $syncHelp.not(this).removeAttr('data-click-open').popover('hide');
      if (isOpen) {
        this.removeAttribute('data-click-open');
        $(this).popover('hide');
      } else {
        this.setAttribute('data-click-open', '1');
        $(this).popover('show');
      }
    });
    $(document).on('click', function () {
      $syncHelp.removeAttr('data-click-open').popover('hide');
    });
  }

  syncCard.querySelectorAll('.js-toggle-secret-visibility').forEach(function (button) {
    button.addEventListener('click', function () {
      const selector = button.getAttribute('data-target');
      const input = selector ? syncCard.querySelector(selector) : null;
      if (!input) {
        return;
      }

      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      button.setAttribute('aria-label', showing ? 'Show sync secret' : 'Hide sync secret');
      const icon = button.querySelector('i');
      if (icon) {
        icon.className = showing ? 'fas fa-eye' : 'fas fa-eye-slash';
      }
    });
  });

  const localSyncMaster = syncCard.querySelector('.js-local-sync-master');
  const localSyncAdvanced = syncCard.querySelector('#local-sync-advanced-settings');
  const localSyncAdvancedButton = syncCard.querySelector('.js-toggle-local-sync-advanced');
  const localSyncCoreToggles = Array.prototype.slice.call(syncCard.querySelectorAll('.js-local-sync-core-toggle'));
  const syncDebugPanel = syncCard.querySelector('#sync-debug-panel');
  const syncDebugButton = syncCard.querySelector('.js-toggle-sync-debug');

  function setLocalSyncCoreToggles(enabled) {
    localSyncCoreToggles.forEach(function (input) {
      input.checked = enabled;
    });
  }

  function updateLocalSyncMaster() {
    if (!localSyncMaster || !localSyncCoreToggles.length) {
      return;
    }
    localSyncMaster.checked = localSyncCoreToggles.every(function (input) {
      return input.checked;
    });
  }

  async function persistLocalSyncToggles() {
    if (!$syncLocalForm.length) {
      return;
    }

    try {
      const response = await ajaxSyncPromise(formData($syncLocalForm, 'save_local_sync_toggles'));
      initialLocalSyncSignature = payloadSignature(formData($syncLocalForm));
      showResult($syncLocalForm, response.message || 'Local sync toggles were saved.', true);
    } catch (xhr) {
      const message = (xhr.responseJSON && xhr.responseJSON.message) || 'Unable to save local sync toggles.';
      showResult($syncLocalForm, message, false);
    }
  }

  if (localSyncMaster) {
    localSyncMaster.addEventListener('change', function () {
      setLocalSyncCoreToggles(localSyncMaster.checked);
      void persistLocalSyncToggles();
    });
  }

  localSyncCoreToggles.forEach(function (input) {
    input.addEventListener('change', function () {
      updateLocalSyncMaster();
      void persistLocalSyncToggles();
    });
  });

  if (localSyncAdvancedButton && localSyncAdvanced) {
    localSyncAdvancedButton.addEventListener('click', function () {
      const isOpen = localSyncAdvanced.style.display !== 'none';
      localSyncAdvanced.style.display = isOpen ? 'none' : '';
      localSyncAdvancedButton.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    });
  }

  if (syncDebugButton && syncDebugPanel) {
    syncDebugButton.addEventListener('click', function () {
      const isOpen = syncDebugPanel.style.display !== 'none';
      syncDebugPanel.style.display = isOpen ? 'none' : '';
      syncDebugButton.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
    });
  }

  const hostedSyncMaster = syncCard.querySelector('.js-hosted-sync-master');
  const hostedSyncCoreToggles = Array.prototype.slice.call(syncCard.querySelectorAll('.js-hosted-sync-core-toggle'));

  function setHostedSyncCoreToggles(enabled) {
    hostedSyncCoreToggles.forEach(function (input) {
      input.value = enabled ? '1' : '0';
    });
  }

  if (hostedSyncMaster) {
    setHostedSyncCoreToggles(hostedSyncMaster.checked);
    hostedSyncMaster.addEventListener('change', function () {
      setHostedSyncCoreToggles(hostedSyncMaster.checked);
    });
  }

  let initialDebugSignature = null;
  let initialBranchSyncSecretValue = null;

  function csrfPayload() {
    const token = syncCard.querySelector('input[name="sync_csrf_token"]');
    if (!token || !token.value) {
      return {};
    }

    return {
      csrf_token: token.value,
      sync_csrf_token: token.value,
    };
  }

	  function formData($form, actionOverride) {
	    const data = $form.find(':input').serializeArray();
	    const payload = {};
	    data.forEach(function (entry) { payload[entry.name] = entry.value; });
	    if ($form.attr('id') === 'sync-local-form') {
	      const debugPayload = syncDebugPayload();
	      Object.assign(payload, debugPayload);
	      if (initialDebugSignature !== null && payloadSignature(debugPayload) !== initialDebugSignature) {
	        payload.POSMAIN_DB_CONFIG_DIRTY = '1';
	      }
	    }
	    if ($form.attr('id') === 'sync-cloud-form') {
	      Object.assign(payload, syncConfigKeyPayload());
	    }
	    const branchSecretInput = syncCard.querySelector('#sync-shared-section [name="POSMAIN_BRANCH_SYNC_SECRET"]');
	    if (
	      branchSecretInput
	      && initialBranchSyncSecretValue !== null
	      && branchSecretInput.value !== initialBranchSyncSecretValue
	    ) {
	      payload.POSMAIN_BRANCH_SYNC_SECRET_DIRTY = '1';
	    }
	    Object.assign(payload, csrfPayload());
	    if (actionOverride) {
	      payload.action = actionOverride;
	    }
	    return payload;
	  }

	  function syncConfigKeyPayload() {
	    const payload = {};
	    $('#sync-config-encryption-fields :input').serializeArray().forEach(function (entry) {
	      payload[entry.name] = entry.value;
	    });
	    return payload;
	  }

	  function syncDebugPayload() {
	    const payload = {};
	    $('#sync-debug-form :input').serializeArray().forEach(function (entry) {
	      payload[entry.name] = entry.value;
	    });
	    return payload;
	  }

	  function syncSharedIdentityPayload() {
	    const payload = {};
	    $('#sync-shared-section [name="POSMAIN_BRANCH_UUID"], #sync-shared-section [name="POSMAIN_BRANCH_SYNC_SECRET"], #sync-local-section [name="POSMAIN_CLOUD_BASE_URL"]').serializeArray().forEach(function (entry) {
	      payload[entry.name] = entry.value;
	    });
	    return payload;
	  }

	  function syncHostedIdentityPayload() {
	    const payload = Object.assign({}, syncSharedIdentityPayload(), syncConfigKeyPayload());
	    const secretInput = syncCard.querySelector('#sync-shared-section [name="POSMAIN_BRANCH_SYNC_SECRET"]');
	    if (
	      secretInput
	      && initialBranchSyncSecretValue !== null
	      && secretInput.value !== initialBranchSyncSecretValue
	      && secretInput.value.trim() !== ''
	    ) {
	      payload.POSMAIN_BRANCH_SYNC_SECRET_DIRTY = '1';
	    }
	    return payload;
	  }

	  function buildHostedBranchRegistrationPayload(options) {
	    const payload = Object.assign(
	      { action: 'pair_hosted_branch', branch_status: 'active' },
	      syncConfigKeyPayload(),
	      syncSharedIdentityPayload(),
	      syncHostedProvisionPayload(),
	      csrfPayload()
	    );
	    if (options && options.replaceExisting) {
	      payload.replace_existing_branches = '1';
	    }
	    if (
	      branchSecretInput
	      && initialBranchSyncSecretValue !== null
	      && branchSecretInput.value !== initialBranchSyncSecretValue
	      && branchSecretInput.value.trim() !== ''
	    ) {
	      payload.POSMAIN_BRANCH_SYNC_SECRET_DIRTY = '1';
	    }
	    return payload;
	  }

  function syncHostedProvisionPayload() {
    const payload = {};
    $('#sync-cloud-form :input').serializeArray().forEach(function (entry) {
      if (entry.name === 'provision_new_shop' || entry.name.indexOf('provision_') === 0) {
        payload[entry.name] = entry.value;
      }
    });
    return payload;
  }

  function syncStatusPayload() {
    const payload = Object.assign({ action: 'pairing_status' }, syncSharedIdentityPayload(), csrfPayload());
    const role = syncCard.getAttribute('data-sync-role') || 'branch';
    if (role === 'cloud') {
      Object.assign(payload, syncConfigKeyPayload(), syncHostedProvisionPayload());
    }
    return payload;
  }

  function escapeHtml(value) {
    return String(value == null ? '' : value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderSyncStatusPanel(dashboard) {
    const panel = document.getElementById('sync-status-panel');
    if (!panel || !dashboard) {
      return;
    }

    const pairingOk = !!dashboard.pairing_ok;
    const badgeClass = pairingOk ? 'sync-status-badge-ok' : 'sync-status-badge-bad';
    const badgeLabel = pairingOk ? 'Paired' : 'Not paired';
    const remote = dashboard.remote || {};
    const identity = dashboard.identity || {};
    const observability = dashboard.observability || {};
    const worker = dashboard.worker || {};
    const outbox = observability.outbox || {};
    const cloudPull = observability.cloud_pull || {};
    const cloudQueue = observability.cloud_queue || {};
    const shopDb = remote.shop_db_name || identity.shop_db_name || '';
    const lastSeen = remote.last_seen_at || identity.last_seen_at || observability.last_seen_at || '';
    const workerHealthy = worker.worker_enabled ? !!worker.healthy : true;
    const workerBadgeClass = workerHealthy ? 'sync-status-badge-ok' : 'sync-status-badge-bad';
    const workerBadgeLabel = !worker.worker_enabled ? 'Disabled' : (workerHealthy ? 'Healthy' : 'Needs attention');

    let html = '<div class="d-flex align-items-center justify-content-between flex-wrap mb-3" dir="ltr">';
    html += '<div><span class="sync-status-badge ' + badgeClass + '">' + badgeLabel + '</span>';
    html += ' <span class="text-muted small ml-2">' + escapeHtml(dashboard.pairing_message || '') + '</span></div>';
    html += '</div>';

    html += '<div class="sync-status-grid mb-2" dir="ltr">';
    if (shopDb) {
      html += '<div class="sync-status-card"><h6>Hosted shop database</h6><p><code class="sync-ltr-value" dir="ltr">' + escapeHtml(shopDb) + '</code></p></div>';
    }
    if (lastSeen) {
      html += '<div class="sync-status-card"><h6>Last seen</h6><p>' + escapeHtml(lastSeen) + '</p></div>';
    }
    if (remote.sync_schema_ready != null) {
      html += '<div class="sync-status-card"><h6>Sync schema</h6><p>' + (remote.sync_schema_ready ? 'Ready' : 'Pending (' + escapeHtml(remote.schema_pending_count || 0) + ')') + '</p></div>';
    }
    if (outbox.dead_rows != null || outbox.retryable_due != null) {
      html += '<div class="sync-status-card"><h6>Outbox</h6><p>Due: ' + escapeHtml(outbox.retryable_due || 0) + ', dead: ' + escapeHtml(outbox.dead_rows || 0);
      if (outbox.last_success_at) {
        html += '<br><span class="text-muted small">Last success: ' + escapeHtml(outbox.last_success_at) + '</span>';
      }
      html += '</p></div>';
    }
    if (cloudPull && cloudPull.created_at) {
      html += '<div class="sync-status-card"><h6>Cloud pull worker</h6><p>' + escapeHtml(cloudPull.status || '') + '<br><span class="text-muted small">' + escapeHtml(cloudPull.created_at) + '</span></p></div>';
    }
    if (cloudQueue && (cloudQueue.pending != null || cloudQueue.dead != null)) {
      html += '<div class="sync-status-card"><h6>Cloud queue</h6><p>Pending: ' + escapeHtml(cloudQueue.pending || 0) + ', dead: ' + escapeHtml(cloudQueue.dead || 0) + '</p></div>';
    }
    if (worker && Object.keys(worker).length) {
      html += '<div class="sync-status-card"><h6>Background worker</h6><p><span class="sync-status-badge ' + workerBadgeClass + '">' + workerBadgeLabel + '</span>';
      if (worker.process && worker.process.message) {
        html += '<br><span class="text-muted small">' + escapeHtml(worker.process.message) + '</span>';
      }
      if (worker.recommended_command) {
        html += '<br><code class="small d-block mt-1">' + escapeHtml(worker.recommended_command) + '</code>';
      }
      html += '</p></div>';
    }
    html += '</div>';

    panel.innerHTML = html;
  }

  function loadSyncStatusPanel() {
    const panel = document.getElementById('sync-status-panel');
    if (!panel) {
      return;
    }
    panel.innerHTML = '<div class="text-muted small">Loading pairing and worker status...</div>';
    ajaxSync(syncStatusPayload()).done(function (response) {
      if (response && response.dashboard) {
        renderSyncStatusPanel(response.dashboard);
      } else {
        panel.innerHTML = '<div class="text-danger small">Unable to load sync status.</div>';
      }
    }).fail(function () {
      panel.innerHTML = '<div class="text-danger small">Unable to load sync status.</div>';
    });
  }

  syncCard.querySelectorAll('.js-refresh-sync-status').forEach(function (button) {
    button.addEventListener('click', loadSyncStatusPanel);
  });

  function showResult($form, message, ok) {
    let $target = $form.find('.sync-action-result').first();
    if (!$target.length) {
      $target = $form.closest('.tab-pane, .card-body').find('.sync-action-result').first();
    }
    $target.removeClass('text-muted text-danger text-success').addClass(ok ? 'text-success' : 'text-danger').text(message || '');
  }

  function ajaxSync(payload) {
    const headers = {};
    const tokenInput = syncCard.querySelector('input[name="sync_csrf_token"]');
    if (tokenInput && tokenInput.value) {
      headers['X-CSRF-Token'] = tokenInput.value;
    }

    return $.ajax({
      url: 'ajax/sync_credentials.php',
      type: 'POST',
      data: payload,
      dataType: 'json',
      headers: headers
    });
  }

  function ajaxSyncPromise(payload) {
    return new Promise(function (resolve, reject) {
      ajaxSync(payload).done(function (response) {
        if (response && response.ok === false) {
          reject({ responseJSON: response });
          return;
        }
        resolve(response || {});
      }).fail(function (xhr) {
        reject(xhr);
      });
    });
  }

  function payloadSignature(payload) {
    return JSON.stringify(Object.keys(payload).filter(function (key) {
      return key !== 'csrf_token';
    }).sort().map(function (key) {
      return [key, payload[key]];
    }));
  }

  function targetInput(button) {
    const selector = button.getAttribute('data-target');
    const form = button.closest('form');
    return form ? form.querySelector(selector) : document.querySelector(selector);
  }

  function confirmSyncIdentityChanges() {
    const branchUuidInput = syncCard.querySelector('#sync-shared-section [name="POSMAIN_BRANCH_UUID"]');
    const secretInput = syncCard.querySelector('#sync-shared-section [name="POSMAIN_BRANCH_SYNC_SECRET"]');
    const storedUuid = (syncCard.getAttribute('data-stored-branch-uuid') || '').trim().toLowerCase();
    const currentUuid = branchUuidInput ? branchUuidInput.value.trim().toLowerCase() : '';
    const uuidChanged = storedUuid !== '' && currentUuid !== '' && currentUuid !== storedUuid;
    const secretChanged = !!(
      secretInput
      && initialBranchSyncSecretValue !== null
      && secretInput.value !== initialBranchSyncSecretValue
      && secretInput.value.trim() !== ''
    );

    if (!uuidChanged && !secretChanged) {
      return true;
    }

    const parts = [];
    if (uuidChanged) {
      parts.push('the branch UUID');
    }
    if (secretChanged) {
      parts.push('the sync secret');
    }

    return window.confirm(
      (syncRuntimeRole === 'cloud'
        ? 'You are changing ' + parts.join(' and ') + ' on the hosted POS. The previously registered branch will be disabled and the local branch must use the same values to pair again. Continue?'
        : 'You are changing ' + parts.join(' and ') + '. Cloud pairing and sync will stop working until the hosted POS is updated with the same values. Continue?')
    );
  }

  $('.js-generate-uuid').on('click', function () {
    const input = targetInput(this);
    if (
      input
      && this.getAttribute('data-confirm-if-filled') === '1'
      && input.value.trim() !== ''
      && !window.confirm('Regenerating the branch UUID will replace the current branch identity. Existing sync registration can stop working until the hosted POS is updated with the new UUID. Continue?')
    ) {
      return;
    }
    ajaxSync(Object.assign({ action: 'generate_uuid' }, csrfPayload())).done(function (response) {
      if (response.ok && input) {
        input.value = response.uuid;
      }
    });
  });

  $('.js-generate-secret').on('click', function () {
    const input = targetInput(this);
    if (
      input
      && this.getAttribute('data-confirm-if-filled') === '1'
      && input.value.trim() !== ''
      && !window.confirm('Regenerating the sync secret will break cloud pairing until the hosted POS is updated with the new secret. Continue?')
    ) {
      return;
    }
    ajaxSync(Object.assign({ action: 'generate_secret' }, csrfPayload())).done(function (response) {
      if (response.ok && input) input.value = response.secret;
    });
  });

  $('.js-generate-config-key').on('click', function () {
    const input = targetInput(this);
    const message = this.getAttribute('data-confirm-message') || 'Regenerating the encryption key can make previously encrypted secrets unreadable. Continue?';
    if (
      input
      && this.getAttribute('data-confirm-if-filled') === '1'
      && input.value.trim() !== ''
      && !window.confirm(message)
    ) {
      return;
    }
    ajaxSync(Object.assign({ action: 'generate_config_key' }, csrfPayload())).done(function (response) {
      if (response.ok && input) input.value = response.key;
    });
  });

  $('.js-sync-test-db').on('click', function () {
    const $form = $($(this).data('form'));
    ajaxSync(formData($form, 'test_db')).done(function (response) {
      showResult($form, response.message || (response.ok ? 'Test succeeded.' : 'Test failed.'), !!response.ok);
    }).fail(function (xhr) {
      showResult($form, (xhr.responseJSON && xhr.responseJSON.message) || 'Database connection test failed.', false);
    });
  });

  $('.js-register-hosted-branch').on('click', function () {
    const $button = $(this);
    const $resultHost = $button.closest('.sync-subsection');
    const payload = buildHostedBranchRegistrationPayload();
    if (!payload.POSMAIN_BRANCH_UUID || payload.POSMAIN_BRANCH_UUID.trim() === '') {
      showResult($resultHost, 'Generate or enter a branch UUID before registering on the hosted POS.', false);
      return;
    }
    if (!payload.POSMAIN_BRANCH_SYNC_SECRET || payload.POSMAIN_BRANCH_SYNC_SECRET.trim() === '') {
      showResult($resultHost, 'Generate or enter the branch sync secret before registering on the hosted POS.', false);
      return;
    }
    if (!window.confirm('Register this branch UUID on the hosted POS? Existing branches stay registered unless you replace them from the hosted tools.')) {
      return;
    }

    payload.POSMAIN_BRANCH_SYNC_SECRET_DIRTY = '1';
    $button.prop('disabled', true);
    showResult($resultHost, 'Registering branch on hosted POS...', true);
    ajaxSyncPromise(payload).then(function (response) {
      renderBranches(response.branches || []);
      showResult($resultHost, response.message || 'Branch was paired on the hosted POS.', true);
      loadSyncStatusPanel();
    }).catch(function (xhr) {
      showResult($resultHost, (xhr.responseJSON && xhr.responseJSON.message) || 'Unable to register branch on hosted POS.', false);
    }).finally(function () {
      $button.prop('disabled', false);
    });
  });

  $('.js-sync-test-cloud').on('click', function () {
    const $form = $($(this).data('form'));
    ajaxSync(formData($form, 'test_cloud')).done(function (response) {
      showResult($form, response.message || (response.ok ? 'Test succeeded.' : 'Test failed.'), !!response.ok);
    }).fail(function (xhr) {
      showResult($form, (xhr.responseJSON && xhr.responseJSON.message) || 'Cloud connection test failed.', false);
    });
  });

  function mergeQueueTotals(target, source) {
    if (!source) {
      return target;
    }
    Object.keys(source).forEach(function (key) {
      if (typeof source[key] === 'number') {
        target[key] = (target[key] || 0) + source[key];
      }
    });
    return target;
  }

  function formatSyncProgressMessage(label, percent) {
    const safePercent = Math.min(100, Math.max(0, Math.round(percent)));
    return 'Syncing ' + label + '... ' + safePercent + '%';
  }

  let bulkPushPollTimer = null;
  let bulkPushJobUuid = null;

  function bulkPushResultIsOk(job) {
    if (!job) {
      return false;
    }
    if (job.running) {
      return true;
    }
    if (job.status === 'completed') {
      return true;
    }
    if (job.status === 'failed' && (job.dispatch && job.dispatch.failed > 0)) {
      return false;
    }
    return job.status === 'failed' ? false : !!job.ok;
  }

  function bulkPushJobMessageIsFresh(job) {
    if (!job) {
      return false;
    }
    if (job.running) {
      return true;
    }
    const timestamp = job.finished_at || job.updated_at;
    if (!timestamp) {
      return false;
    }
    const parsed = Date.parse(String(timestamp).replace(' ', 'T'));
    if (Number.isNaN(parsed)) {
      return false;
    }
    return (Date.now() - parsed) <= (30 * 60 * 1000);
  }

  function shouldRestoreBulkPushJobOnLoad(job) {
    if (!job || !job.message) {
      return false;
    }
    if (!bulkPushJobMessageIsFresh(job)) {
      return false;
    }
    if (job.running) {
      return true;
    }
    if (job.status === 'completed') {
      return true;
    }
    if (job.status === 'failed' && job.message.indexOf('Sync finished') === 0) {
      return true;
    }
    return false;
  }

  function applyBulkPushJobToUi($form, job) {
    if (!$form || !job || !job.message) {
      return;
    }
    showResult($form, job.message, bulkPushResultIsOk(job));
  }

  function stopBulkPushPolling() {
    if (bulkPushPollTimer) {
      clearInterval(bulkPushPollTimer);
      bulkPushPollTimer = null;
    }
  }

  function scheduleBulkPushPolling($form) {
    if (bulkPushPollTimer) {
      return;
    }
    bulkPushPollTimer = setInterval(function () {
      pollBulkPushStatus($form);
    }, 2000);
  }

  function pollBulkPushStatus($form) {
    if (!$form || !$form.length) {
      return Promise.resolve(null);
    }

    const payload = formData($form, 'push_supported_data_status');
    if (bulkPushJobUuid) {
      payload.job_uuid = bulkPushJobUuid;
    }

    return ajaxSyncPromise(payload).then(function (response) {
      const job = response.job || null;
      if (!job) {
        stopBulkPushPolling();
        return null;
      }

      bulkPushJobUuid = job.job_uuid || bulkPushJobUuid;
      if (shouldRestoreBulkPushJobOnLoad(job) || bulkPushPollTimer) {
        applyBulkPushJobToUi($form, job);
      }

      if (job.running) {
        scheduleBulkPushPolling($form);
        return job;
      }

      stopBulkPushPolling();
      loadSyncStatusPanel();
      return job;
    }).catch(function () {
      return null;
    });
  }

  function startBulkPushBackground($form, $button) {
    const payload = formData($form, 'push_supported_data_start');
    $button.prop('disabled', true);
    showResult($form, formatSyncProgressMessage('preparing', 0), true);

    return ajaxSyncPromise(payload).then(function (response) {
      const job = response.job || null;
      if (job) {
        bulkPushJobUuid = job.job_uuid || null;
        applyBulkPushJobToUi($form, job);
        if (job.running) {
          scheduleBulkPushPolling($form);
        } else {
          loadSyncStatusPanel();
        }
      } else {
        showResult($form, response.message || 'Background sync started.', true);
        scheduleBulkPushPolling($form);
      }
      return job;
    }).catch(function (xhr) {
      const message = (xhr.responseJSON && xhr.responseJSON.message)
        || (xhr.status === 403 ? 'Session expired or invalid security token. Refresh Settings and try again.' : '')
        || 'Data sync failed to start.';
      showResult($form, message, false);
      throw xhr;
    }).finally(function () {
      $button.prop('disabled', false);
    });
  }

  async function runSupportedDataPushWithProgress($form) {
    const basePayload = formData($form);
    const queueWeight = 0.4;
    const dispatchWeight = 0.6;
    const planResponse = await ajaxSyncPromise(Object.assign({}, basePayload, { action: 'push_supported_data_plan' }));
    const plan = planResponse.plan || {};
    const phases = plan.phases || [];
    const queueRowTotal = Math.max(1, plan.queue_row_total || 1);
    let completedQueueRows = 0;
    let totalQueued = 0;
    const aggregatedQueue = {
      catalog: 0,
      tables: 0,
      orders: 0,
      queued: 0,
      skipped: 0,
      resent: 0,
    };
    const aggregatedDispatch = {
      batches: 0,
      claimed: 0,
      synced: 0,
      failed: 0,
      dead: 0,
    };

    for (let index = 0; index < phases.length; index++) {
      const phase = phases[index];
      const phaseLabel = phase.label || phase.id || 'data';
      const phaseStartPercent = (completedQueueRows / queueRowTotal) * queueWeight * 100;
      showResult($form, formatSyncProgressMessage(phaseLabel, phaseStartPercent), true);

      const phaseResponse = await ajaxSyncPromise(Object.assign({}, basePayload, {
        action: 'push_supported_data_phase',
        push_phase: phase.id,
      }));
      mergeQueueTotals(aggregatedQueue, phaseResponse.queue || {});
      totalQueued += (phaseResponse.queue && phaseResponse.queue.queued) ? phaseResponse.queue.queued : 0;
      completedQueueRows += phase.total || 0;

      const phaseDonePercent = (completedQueueRows / queueRowTotal) * queueWeight * 100;
      showResult($form, formatSyncProgressMessage(phaseLabel, phaseDonePercent), true);
    }

    const dispatchTotal = Math.max(1, totalQueued || aggregatedQueue.queued || 1);
    let syncedSoFar = 0;
    let pendingOutbox = 0;
    let dispatchDone = false;
    let dispatchSafety = 0;

    while (!dispatchDone && dispatchSafety < 5000) {
      dispatchSafety++;
      const dispatchPercent = (queueWeight * 100) + ((syncedSoFar / dispatchTotal) * dispatchWeight * 100);
      showResult($form, formatSyncProgressMessage('sending queued events to hosted', dispatchPercent), true);

      const dispatchResponse = await ajaxSyncPromise(Object.assign({}, basePayload, {
        action: 'push_supported_data_dispatch',
      }));
      const dispatch = dispatchResponse.dispatch || {};
      aggregatedDispatch.batches += dispatch.batches || 0;
      aggregatedDispatch.claimed += dispatch.claimed || 0;
      aggregatedDispatch.synced += dispatch.synced || 0;
      aggregatedDispatch.failed += dispatch.failed || 0;
      aggregatedDispatch.dead += dispatch.dead || 0;
      syncedSoFar = aggregatedDispatch.synced;
      pendingOutbox = dispatchResponse.pending_outbox || 0;
      dispatchDone = !!dispatchResponse.done;
    }

    showResult($form, formatSyncProgressMessage('finishing', 100), true);

    return {
      queue: aggregatedQueue,
      dispatch: aggregatedDispatch,
      pending_outbox: pendingOutbox,
    };
  }

  $('.js-sync-push-data').on('click', function () {
    const $form = $($(this).data('form'));
    const confirmed = window.confirm(
      'Queue all currently supported local sync data and send it to the hosted POS in the background? You can refresh this page and progress will be kept. This includes menu/items, modifiers, tables, all order history, customers, delivery clients, shop settings, Moova links, categories, inventory documents, recipes, employees, shifts, payment methods, and reference data. Credentials and sync secrets are never sent.'
    );
    if (!confirmed) {
      return;
    }

    startBulkPushBackground($form, $(this));
  });

  $('.js-sync-restore-hosted').on('click', function () {
    const $form = $($(this).data('form'));
    const confirmed = window.confirm(
      'Preview the hosted recovery plan? This is a read-only dry run. It will not change local data. Actual recovery is available only through the guarded CLI workflow on an empty replacement database.'
    );
    if (!confirmed) {
      return;
    }

    const payload = formData($form, 'restore_from_hosted');
    ajaxSync(payload).done(function (response) {
      const restore = response.restore || {};
      const safety = restore.safety || {};
      const fetched = restore.fetched || 0;
      const ready = !!safety.apply_allowed;
      const message = ready
        ? 'Dry run complete: ' + fetched + ' hosted events. The target is empty and eligible for the guarded CLI restore. No local data changed.'
        : 'Dry run complete: ' + fetched + ' hosted events. Apply is blocked because this is not an empty recovery target or generic cloud pull is enabled. No local data changed.';
      showResult($form, message, true);
    }).fail(function (xhr) {
      showResult($form, (xhr.responseJSON && xhr.responseJSON.message) || 'Hosted restore failed.', false);
    });
  });

  $('.js-export-hosted-env').on('click', function () {
    const $form = $($(this).data('form'));
    ajaxSync(formData($form, 'export_hosted_env')).done(function (response) {
      $('#hosted-env-output').val(response.env_block || '');
      showResult($form, response.ok ? 'Hosting env values were built.' : (response.message || 'Unable to build hosting env values.'), !!response.ok);
    }).fail(function (xhr) {
      showResult($form, (xhr.responseJSON && xhr.responseJSON.message) || 'Unable to build hosting env values.', false);
    });
  });

  function renderBranches(branches) {
    const $tbody = $('#sync-cloud-branches-table tbody');
    $tbody.empty();
    branches.forEach(function (branch) {
      $('<tr>')
        .append($('<td>').append($('<code>', { class: 'sync-ltr-value', dir: 'ltr' }).text(branch.branch_uuid || '')))
        .append($('<td>').text(branch.status || ''))
        .append($('<td>').text(branch.has_encrypted_secret ? 'Yes' : 'No'))
        .append($('<td>', { class: 'sync-ltr-value', dir: 'ltr' }).text(branch.last_seen_at || ''))
        .append($('<td>', { class: 'sync-ltr-value', dir: 'ltr' }).text(branch.updated_at || ''))
        .appendTo($tbody);
    });
  }

  const settingsMainForm = document.getElementById('settings-main-form');
  const $syncLocalForm = $('#sync-local-form');
  const $syncCloudForm = $('#sync-cloud-form');
  const syncRuntimeRole = syncCard.getAttribute('data-sync-role') || 'branch';
  const branchSecretInput = syncCard.querySelector('#sync-shared-section [name="POSMAIN_BRANCH_SYNC_SECRET"]');
  initialDebugSignature = payloadSignature(syncDebugPayload());
  initialBranchSyncSecretValue = branchSecretInput ? branchSecretInput.value : '';
  let initialLocalSyncSignature = $syncLocalForm.length ? payloadSignature(formData($syncLocalForm)) : '';
  const initialCloudSyncSignature = $syncCloudForm.length ? payloadSignature(formData($syncCloudForm)) : '';
  let submittingAfterSyncSave = false;

  if (settingsMainForm) {
    settingsMainForm.addEventListener('submit', async function (event) {
      if (submittingAfterSyncSave) {
        return;
      }

      const syncSaves = [];
      if (syncRuntimeRole === 'branch' && $syncLocalForm.length && payloadSignature(formData($syncLocalForm)) !== initialLocalSyncSignature) {
        syncSaves.push({ $form: $syncLocalForm, payload: formData($syncLocalForm), label: 'Local sync settings were saved.' });
      }
      if (syncRuntimeRole === 'cloud' && $syncCloudForm.length && payloadSignature(formData($syncCloudForm)) !== initialCloudSyncSignature) {
        syncSaves.push({ $form: $syncCloudForm, payload: formData($syncCloudForm), label: 'Hosted sync settings were saved.' });
      }

      if (!syncSaves.length) {
        return;
      }

      if (syncRuntimeRole === 'branch' && !confirmSyncIdentityChanges()) {
        return;
      }

      event.preventDefault();
      const submitButton = settingsMainForm.querySelector('button[type="submit"]');
      const globalResult = document.getElementById('settings-global-save-result');
      if (submitButton) {
        submitButton.disabled = true;
      }
      if (globalResult) {
        globalResult.className = 'text-muted d-block small';
        globalResult.textContent = 'Saving sync changes...';
      }

      let failedSave = null;
      try {
        for (const save of syncSaves) {
          failedSave = save;
          const response = await ajaxSyncPromise(save.payload);
          if (save.afterSave) {
            save.afterSave(response);
          }
          showResult(save.$form, response.message || save.label, true);
          loadSyncStatusPanel();
        }
        failedSave = null;
        if (globalResult) {
          globalResult.className = 'text-success d-block small';
          globalResult.textContent = 'Sync changes saved. Saving page settings...';
        }
        if (syncCard && syncRuntimeRole === 'branch') {
          const savedUuidInput = syncCard.querySelector('#sync-shared-section [name="POSMAIN_BRANCH_UUID"]');
          if (savedUuidInput) {
            syncCard.setAttribute('data-stored-branch-uuid', savedUuidInput.value.trim().toLowerCase());
          }
        }
        submittingAfterSyncSave = true;
        settingsMainForm.submit();
      } catch (xhr) {
        const message = (xhr.responseJSON && xhr.responseJSON.message) || 'Unable to save sync settings.';
        if (globalResult) {
          globalResult.className = 'text-danger d-block small';
          globalResult.textContent = message;
        }
        if (failedSave) {
          showResult(failedSave.$form, message, false);
        }
        if (submitButton) {
          submitButton.disabled = false;
        }
      }
    });
  }

  loadSyncStatusPanel();
  if ($syncLocalForm.length) {
    pollBulkPushStatus($syncLocalForm);
  }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const updateButton = document.getElementById('system-update-action-btn');
  const toast = document.getElementById('system-update-toast');
  const availableBadge = document.getElementById('system-update-available-badge');
  if (!updateButton || !toast) return;

  const csrfToken = <?= json_encode($systemUpdateCsrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let toastTimer = null;
  let polling = false;

  const phaseLabels = {
    worker_dispatch: 'تشغيل عامل التحديث',
    version_check: 'التحقق من النسخة',
    preflight: 'فحوصات الأمان',
    database_migrations_plan: 'فحص ترحيلات قواعد البيانات',
    maintenance_on: 'تفعيل وضع الصيانة',
    drain_requests: 'إنهاء الطلبات الجارية',
    backup: 'إنشاء النسخ الاحتياطية',
    code_activation: 'تحديث الكود',
    database_migrations: 'تطبيق ترحيلات قواعد البيانات',
    runtime_restart: 'إعادة تشغيل الخدمة',
    database_verification: 'التحقق من قواعد البيانات',
    release_verification: 'التحقق من نسخة الكود',
    health_check: 'فحص سلامة النظام',
    maintenance_off: 'إنهاء وضع الصيانة',
    backup_cleanup: 'حذف النسخ الاحتياطية المؤقتة',
    stale_recovery: 'بدء الاستعادة التلقائية',
    stale_recovery_dispatch: 'تشغيل عامل الاستعادة',
    rollback: 'استعادة النظام',
    failed: 'فشل التحديث',
    completed: 'اكتمل التحديث'
  };

  function showToast(message, type, statusUrl) {
    const colors = { success: '#28a745', error: '#dc3545', info: '#17a2b8', muted: '#6c757d' };
    const color = colors[type] || colors.muted;
    const card = document.createElement('div');
    card.style.cssText = 'background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.15);padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;direction:rtl;border-right:4px solid ' + color + ';';
    const icon = document.createElement('span');
    icon.style.cssText = 'color:' + color + ';font-size:1.2rem;';
    icon.textContent = type === 'success' ? '✓' : type === 'error' ? '✕' : '⟳';
    const text = document.createElement('span');
    text.style.cssText = 'flex:1;font-size:.9rem;';
    text.textContent = String(message || '');
    card.appendChild(icon);
    card.appendChild(text);
    if (statusUrl) {
      const link = document.createElement('a');
      link.href = statusUrl;
      link.target = '_blank';
      link.rel = 'noopener';
      link.style.cssText = 'color:' + color + ';font-size:.82rem;white-space:nowrap;';
      link.textContent = 'عرض الحالة';
      card.appendChild(link);
    }
    toast.replaceChildren(card);
    toast.style.display = 'block';
    if (toastTimer) clearTimeout(toastTimer);
    if (type !== 'info') {
      toastTimer = setTimeout(function () { toast.style.display = 'none'; }, 8000);
    }
  }

  function setUpdateState(available, targetVersion) {
    if (availableBadge) availableBadge.style.display = available ? 'inline-block' : 'none';
    updateButton.dataset.updateAvailable = available ? '1' : '0';
    updateButton.dataset.targetVersion = targetVersion || '';
    updateButton.innerHTML = available
      ? '<i class="fas fa-sync-alt ml-1"></i> تحديث النظام'
      : '<i class="fas fa-search ml-1"></i> البحث عن تحديث';
    updateButton.className = 'btn btn-sm ' + (available ? 'btn-primary' : 'btn-outline-secondary');
  }

  function wait(milliseconds) {
    return new Promise(function (resolve) { window.setTimeout(resolve, milliseconds); });
  }

  async function responseJson(response) {
    try {
      return await response.json();
    } catch (e) {
      return { ok: false, message: 'استجابة التحديث غير صالحة.' };
    }
  }

  async function pollUpdate(statusUrl) {
    if (!statusUrl || polling) return;
    polling = true;
    updateButton.disabled = true;
    let failures = 0;
    try {
      while (true) {
        await wait(1200);
        try {
          const response = await fetch(statusUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
          });
          const data = await responseJson(response);
          if (!response.ok || !data.ok || !data.job) {
            throw new Error(data.message || 'تعذر قراءة حالة التحديث.');
          }
          failures = 0;
          const job = data.job;
          const status = String(job.status || '');
          const phase = phaseLabels[job.phase] || job.phase || 'التحديث';
          if (status === 'completed') {
            setUpdateState(false, '');
            showToast('اكتمل تحديث النظام والتحقق منه بنجاح.', 'success');
            return;
          }
          if (status === 'failed') {
            if (job.recovery_status === 'recovered') {
              showToast('فشل التحديث، وتمت استعادة النظام والبيانات والتحقق منهما تلقائياً. راجع تفاصيل الحالة.', 'error', statusUrl);
            } else if (job.recovery_status === 'recovery_failed') {
              showToast('فشل التحديث ولم تكتمل الاستعادة. النظام ما زال في وضع الصيانة ويحتاج تدخلاً فورياً.', 'error', statusUrl);
            } else {
              showToast('فشل التحديث قبل تعديل النظام. راجع تفاصيل الحالة.', 'error', statusUrl);
            }
            return;
          }
          showToast('التحديث جارٍ: ' + phase + '…', 'info', statusUrl);
        } catch (error) {
          failures++;
          if (failures >= 20) {
            showToast('تعذر متابعة حالة التحديث. افتح تفاصيل الحالة أو أعد تحميل الصفحة.', 'error', statusUrl);
            return;
          }
          showToast('الاتصال مؤقتاً غير متاح أثناء التحديث. ستتم إعادة المحاولة…', 'info', statusUrl);
        }
      }
    } finally {
      polling = false;
      updateButton.disabled = false;
    }
  }

  async function checkForUpdates() {
    updateButton.disabled = true;
    showToast('جاري البحث عن تحديث…', 'info');
    try {
      const res = await fetch('/api/admin/updates/check.php', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await responseJson(res);
      if (data.active_job && data.active_job.id) {
        showToast('يوجد تحديث أو استعادة قيد التنفيذ. جاري متابعة الحالة…', 'info');
        pollUpdate('/api/admin/updates/status.php?id=' + encodeURIComponent(data.active_job.id));
        return;
      }
      if (!res.ok || !data.ok) {
        throw new Error(data.message || 'تعذر التحقق من التحديثات.');
      }
      if (data.update_available) {
        setUpdateState(true, data.published_version);
        const reason = data.update_reason === 'git'
          ? 'يتوفر تحديث جديد على GitHub — اضغط "تحديث النظام" لسحبه وتطبيقه.'
          : 'يتوفر تحديث جديد — النسخة ' + data.published_version + '. اضغط "تحديث النظام" لتطبيقه.';
        showToast(reason, 'success');
      } else {
        setUpdateState(false, '');
        showToast('النظام محدث. لا توجد نسخة جديدة حالياً.', 'muted');
      }
    } catch (error) {
      setUpdateState(false, '');
      showToast(error.message || 'تعذر التحقق من التحديثات.', 'error');
    } finally {
      if (!polling) updateButton.disabled = false;
    }
  }

  async function startUpdate() {
    if (!window.confirm('سيتم إنشاء نسخة احتياطية ثم إيقاف النظام مؤقتاً أثناء التحديث. هل تريد المتابعة؟')) return;
    updateButton.disabled = true;
    showToast('جاري بدء التحديث…', 'info');
    try {
      const res = await fetch('/api/admin/updates/start.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': csrfToken },
        body: JSON.stringify({ action: 'apply', target_version: updateButton.dataset.targetVersion || null }),
      });
      const data = await responseJson(res);
      if (res.status === 409 && data.status_url) {
        showToast('يوجد تحديث آخر قيد التنفيذ. جاري متابعة حالته…', 'info', data.status_url);
        pollUpdate(data.status_url);
        return;
      }
      if (!res.ok || !data.ok || !data.status_url) {
        throw new Error(data.message || 'تعذر بدء التحديث.');
      }
      showToast('بدأ التحديث. ستظهر المراحل هنا حتى اكتماله.', 'info', data.status_url);
      pollUpdate(data.status_url);
    } catch (error) {
      showToast(error.message || 'تعذر بدء التحديث.', 'error');
      if (!polling) updateButton.disabled = false;
    }
  }

  updateButton.addEventListener('click', function () {
    updateButton.dataset.updateAvailable === '1' ? startUpdate() : checkForUpdates();
  });
});
</script>

<?php endif; ?>

<?php include('includes/footer.php'); ?>
