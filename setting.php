<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
include('includes/connect.php');
require_admin_or_permission('system.tools.run', $conn);
include('includes/header.php');

$sittingpass = $sittingpass ?? 'hadi@1234';
$postedPass = isset($_POST['password']) ? (string) $_POST['password'] : null;
if ($postedPass !== null && !verify_csrf_from_post_or_header('settings_gate')) {
    $postedPass = '';
}
?>

<?php if ($postedPass === null): ?>

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

<?php elseif ($postedPass !== $sittingpass): ?>

<div class="content-wrapper">
  <section class="content">
    <div class="container py-5">
      <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
          <div class="alert alert-danger shadow-sm text-center mb-0">
            <i class="fas fa-times-circle fa-2x mb-3 d-block"></i>
            <h4 class="alert-heading">كلمة المرور غير صحيحة</h4>
            <p class="mb-3">لا يمكن فتح صفحة الإعدادات دون كلمة المرور الصحيحة.</p>
            <a href="setting.php" class="btn btn-outline-danger">
              <i class="fas fa-redo ml-1"></i> إعادة المحاولة
            </a>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php else: ?>

<?php
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
$syncLocalEnvFiles = function_exists('posmain_sync_local_env_files') ? posmain_sync_local_env_files() : [];
$syncEnvFallback = static function (array $names, $default = '', bool $allowEmpty = false) use ($syncLocalEnvFiles) {
    if (function_exists('posmain_first_env_or_file')) {
        return posmain_first_env_or_file($names, $default, $allowEmpty, $syncLocalEnvFiles);
    }

    return $default;
};
if (!isset($syncRuntimeFile['database'])) {
    $syncDb = [
        'host' => (string) $syncEnvFallback(['POSMAIN_SYNC_DB_HOST', 'POSMAIN_DB_HOST', 'POSMAIN_TEST_MYSQL_HOST', 'POSMAIN_API_DB_HOST'], (string)($syncDb['host'] ?? '127.0.0.1')),
        'port' => (int) $syncEnvFallback(['POSMAIN_SYNC_DB_PORT', 'POSMAIN_DB_PORT', 'POSMAIN_TEST_MYSQL_PORT', 'POSMAIN_API_DB_PORT'], (string)($syncDb['port'] ?? 3306)),
        'name' => (string) $syncEnvFallback(['POSMAIN_SYNC_DB_NAME', 'POSMAIN_DB_NAME', 'POSMAIN_TEST_MYSQL_DB', 'POSMAIN_API_DB_NAME'], (string)($syncDb['name'] ?? 'kody2')),
        'user' => (string) $syncEnvFallback(['POSMAIN_SYNC_DB_USER', 'POSMAIN_DB_USER', 'POSMAIN_TEST_MYSQL_USER', 'POSMAIN_API_DB_USER'], (string)($syncDb['user'] ?? 'root')),
        'pass' => (string) $syncEnvFallback(['POSMAIN_SYNC_DB_PASS', 'POSMAIN_DB_PASS', 'POSMAIN_TEST_MYSQL_PASS', 'POSMAIN_API_DB_PASS'], (string)($syncDb['pass'] ?? ''), true),
        'charset' => (string) $syncEnvFallback(['POSMAIN_DB_CHARSET'], (string)($syncDb['charset'] ?? 'utf8mb4')),
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
    if ($key === 'POSMAIN_SYNC_OUTBOX_ENABLED') {
        $envNames[] = 'POSMAIN_ENABLE_SYNC_OUTBOX';
    } elseif ($key === 'POSMAIN_BRANCH_SYNC_ENABLED') {
        $envNames[] = 'POSMAIN_ENABLE_CLOUD_SYNC';
    } elseif ($key === 'POSMAIN_MOOVA_APPLY_ENABLED') {
        $envNames[] = 'POSMAIN_ENABLE_MOOVA_QUEUED_APPLY';
    }

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
$syncBool = static function (string $key, bool $default = false) use ($syncValue, $syncConfigBool): bool {
    $value = $syncValue($key, $syncConfigBool($key, $default) ? '1' : '0');
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
};
$syncBranchUuidFallback = (string) $syncEnvFallback(['POSMAIN_BRANCH_UUID'], (string)($appConfig['branch']['uuid'] ?? ''));
$syncCloudBaseUrlFallback = (string) $syncEnvFallback(['POSMAIN_CLOUD_BASE_URL'], (string)($appConfig['branch']['cloud_base_url'] ?? ''));
$syncBranchSecretFallback = (string) $syncEnvFallback(['POSMAIN_BRANCH_SYNC_SECRET'], (string)($appConfig['sync']['branch_secret'] ?? ''), true);
$syncBranchUuidEffective = $syncEffectiveValue('POSMAIN_BRANCH_UUID', $syncBranchUuidFallback);
$syncCloudBaseUrlEffective = $syncEffectiveValue('POSMAIN_CLOUD_BASE_URL', $syncCloudBaseUrlFallback);
$syncBranchSecretEffective = $syncSecretEffectiveValue('POSMAIN_BRANCH_SYNC_SECRET', $syncBranchSecretFallback);
$syncBranchSecretEffectiveConfigured = $syncBranchSecretEffective !== '';
$syncRole = strtolower(trim($syncEffectiveValue('POSMAIN_ROLE', (string)($appConfig['role'] ?? 'branch'))));
if (!in_array($syncRole, ['branch', 'cloud'], true)) {
    $syncRole = 'branch';
}
$syncIsHosted = $syncRole === 'cloud';
$syncIsLocal = $syncRole === 'branch';
$syncBranches = (new CloudBranchRegistryService())->listBranches($conn);
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
      <div class="alert alert-warning alert-dismissible fade show border-0 shadow-sm">
        <button type="button" class="close" data-dismiss="alert" aria-label="إغلاق">&times;</button>
        <i class="fas fa-exclamation-triangle ml-2"></i>
        <strong>تنبيه:</strong> التعديل في هذه القائمة يؤثر على سلوك النظام بالكامل. راجع القيم قبل الحفظ.
      </div>
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
                  <input type="number" class="form-control" id="def_pos_store" name="def_pos_store"
                         value="<?= htmlspecialchars((string)($rowstg['def_pos_store'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>">
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

        <div class="card card-outline card-teal shadow-sm mb-4" id="sync-credentials-card" data-sync-role="<?= htmlspecialchars($syncRole, ENT_QUOTES, 'UTF-8') ?>">
          <div class="card-header">
            <h3 class="card-title"><i class="fas fa-sync-alt ml-2"></i> إعدادات المزامنة</h3>
            <div class="card-tools">
              <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-minus"></i></button>
            </div>
          </div>
          <div class="card-body">
            <?= csrf_input('sync_credentials') ?>
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
            </style>

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
                          <input type="text" class="form-control" name="POSMAIN_BRANCH_UUID" value="<?= htmlspecialchars($syncBranchUuidEffective, ENT_QUOTES, 'UTF-8') ?>" required>
                          <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary js-generate-uuid" data-target="#sync-shared-identity-fields [name='POSMAIN_BRANCH_UUID']" data-confirm-if-filled="1" dir="ltr"><?= trim($syncBranchUuidEffective) !== '' ? 'Regenerate' : 'Generate UUID' ?></button>
                          </div>
                        </div>
                        <?= $syncSourceHint('POSMAIN_BRANCH_UUID', $syncBranchUuidEffective) ?>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="form-group mb-0">
                        <label>POSMAIN_BRANCH_SYNC_SECRET <?= $syncHelp('HMAC signing secret shared by this local branch and its hosted POS. It must be unique per shop and never shared between shops.') ?></label>
                        <div class="input-group">
                          <input type="password" class="form-control" name="POSMAIN_BRANCH_SYNC_SECRET" value="<?= htmlspecialchars($syncBranchSecretEffective, ENT_QUOTES, 'UTF-8') ?>" autocomplete="off" placeholder="<?= $syncBranchSecretEffectiveConfigured ? 'Current secret is hidden' : 'Required before saving' ?>">
                          <div class="input-group-append">
                            <button type="button" class="btn btn-outline-secondary js-generate-secret" data-target="#sync-shared-section [name='POSMAIN_BRANCH_SYNC_SECRET']" dir="ltr">Generate secret</button>
                            <button type="button" class="btn btn-outline-secondary js-toggle-secret-visibility" data-target="#sync-shared-section [name='POSMAIN_BRANCH_SYNC_SECRET']" aria-label="Show sync secret">
                              <i class="fas fa-eye"></i>
                            </button>
                          </div>
                        </div>
                        <?= $syncSourceHint('POSMAIN_BRANCH_SYNC_SECRET', $syncBranchSecretEffective, $syncBranchSecretFallback !== '') ?>
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
                        <?= $syncSourceHint('POSMAIN_CLOUD_BASE_URL', $syncCloudBaseUrlEffective) ?>
                      </div>
                    </div>
                    <div class="col-md-4 d-flex align-items-end mb-4">
                      <button type="button" class="btn btn-outline-info mb-3 js-sync-test-cloud" data-form="#sync-local-form" dir="ltr">
                        <i class="fas fa-cloud mr-1"></i> Test cloud connection
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
                        'POSMAIN_CLOUD_PULL_ENABLED' => ['سحب أحداث السحابة', true, 'Allows the local branch to receive cloud-to-branch updates.', false],
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
                $hostedSyncToggleKeys = [
                    'POSMAIN_CLOUD_APPLY_ENABLED',
                    'POSMAIN_CLOUD_PULL_ENABLED',
                    'POSMAIN_CLOUD_TO_BRANCH_PUBLISH_ENABLED',
                ];
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
                </div>
                <span class="sync-action-result text-muted"></span>
              </div>

              <div class="sync-subsection">
                <h6 class="font-weight-bold mb-3">الفروع المسجلة على النسخة المستضافة</h6>
                <div class="table-responsive">
                  <table class="table table-sm table-striped mb-0" id="sync-cloud-branches-table">
                    <thead><tr><th>Branch UUID</th><th>الحالة</th><th>سر مشفر</th><th>آخر ظهور</th><th>آخر تحديث</th></tr></thead>
                    <tbody>
                      <?php foreach ($syncBranches as $branchRow): ?>
                        <tr>
                          <td><code><?= htmlspecialchars($branchRow['branch_uuid'], ENT_QUOTES, 'UTF-8') ?></code></td>
                          <td><?= htmlspecialchars($branchRow['status'], ENT_QUOTES, 'UTF-8') ?></td>
                          <td><?= !empty($branchRow['has_encrypted_secret']) ? 'نعم' : 'لا' ?></td>
                          <td><?= htmlspecialchars($branchRow['last_seen_at'], ENT_QUOTES, 'UTF-8') ?></td>
                          <td><?= htmlspecialchars($branchRow['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
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

  if (localSyncMaster) {
    localSyncMaster.addEventListener('change', function () {
      setLocalSyncCoreToggles(localSyncMaster.checked);
    });
  }

  localSyncCoreToggles.forEach(function (input) {
    input.addEventListener('change', updateLocalSyncMaster);
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

  function csrfPayload() {
    const token = syncCard.querySelector('input[name="csrf_token"]');
    return token ? { csrf_token: token.value } : {};
  }

	  function formData($form, actionOverride) {
	    const data = $form.find(':input').serializeArray();
	    const payload = {};
	    data.forEach(function (entry) { payload[entry.name] = entry.value; });
	    if ($form.attr('id') === 'sync-local-form') {
	      Object.assign(payload, syncDebugPayload());
	    }
	    if ($form.attr('id') === 'sync-cloud-form') {
	      Object.assign(payload, syncConfigKeyPayload());
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

  function syncBranchRegistrationPayload() {
    const payload = Object.assign(
      { action: 'register_cloud_branch', branch_status: 'active' },
      syncConfigKeyPayload(),
      syncSharedIdentityPayload(),
      csrfPayload()
    );
    if (!payload.POSMAIN_BRANCH_UUID || !payload.POSMAIN_BRANCH_SYNC_SECRET) {
      return null;
    }
    return payload;
  }

  function showResult($form, message, ok) {
    let $target = $form.find('.sync-action-result').first();
    if (!$target.length) {
      $target = $form.closest('.tab-pane, .card-body').find('.sync-action-result').first();
    }
    $target.removeClass('text-muted text-danger text-success').addClass(ok ? 'text-success' : 'text-danger').text(message || '');
  }

  function ajaxSync(payload) {
    return $.ajax({
      url: 'ajax/sync_credentials.php',
      type: 'POST',
      data: payload,
      dataType: 'json'
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

  $('.js-sync-test-cloud').on('click', function () {
    const $form = $($(this).data('form'));
    ajaxSync(formData($form, 'test_cloud')).done(function (response) {
      showResult($form, response.message || (response.ok ? 'Test succeeded.' : 'Test failed.'), !!response.ok);
    }).fail(function (xhr) {
      showResult($form, (xhr.responseJSON && xhr.responseJSON.message) || 'Cloud connection test failed.', false);
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
        .append($('<td>').append($('<code>').text(branch.branch_uuid || '')))
        .append($('<td>').text(branch.status || ''))
        .append($('<td>').text(branch.has_encrypted_secret ? 'Yes' : 'No'))
        .append($('<td>').text(branch.last_seen_at || ''))
        .append($('<td>').text(branch.updated_at || ''))
        .appendTo($tbody);
    });
  }

  const settingsMainForm = document.getElementById('settings-main-form');
  const $syncLocalForm = $('#sync-local-form');
  const $syncCloudForm = $('#sync-cloud-form');
  const syncRuntimeRole = syncCard.getAttribute('data-sync-role') || 'branch';
  const initialLocalSyncSignature = $syncLocalForm.length ? payloadSignature(formData($syncLocalForm)) : '';
  const initialCloudSyncSignature = $syncCloudForm.length ? payloadSignature(formData($syncCloudForm)) : '';
  const initialBranchRegistrationSignature = syncBranchRegistrationPayload() ? payloadSignature(syncBranchRegistrationPayload()) : '';
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
      const branchRegistrationPayload = syncBranchRegistrationPayload();
      if (
        syncRuntimeRole === 'cloud'
        && branchRegistrationPayload
        && payloadSignature(branchRegistrationPayload) !== initialBranchRegistrationSignature
      ) {
        syncSaves.push({
          $form: $syncCloudForm.length ? $syncCloudForm : $syncLocalForm,
          payload: branchRegistrationPayload,
          label: 'Allowed branch was registered on hosted POS.',
          afterSave: function (response) {
            renderBranches(response.branches || []);
          }
        });
      }

      if (!syncSaves.length) {
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
        }
        failedSave = null;
        if (globalResult) {
          globalResult.className = 'text-success d-block small';
          globalResult.textContent = 'Sync changes saved. Saving page settings...';
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
});
</script>

<?php endif; ?>

<?php include('includes/footer.php'); ?>
