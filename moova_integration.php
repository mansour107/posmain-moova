<?php include('includes/header.php'); ?>
<?php include('includes/navbar.php'); ?>
<?php include('includes/sidebar.php'); ?>
<?php
require_once('classes/MoovaPosIntegration.php');
require_once('includes/auth_guard.php');
require_once('includes/csrf.php');
require_once('classes/Security/SecurityAuditLogger.php');
require_once('classes/Sync/SyncRuntimeSettings.php');

MoovaPosIntegration::ensureSchema($conn);

$moovaUserId = (int) ($_SESSION['userid'] ?? 0);
$moovaScope = MoovaPosIntegration::getCurrentUserScope($conn, $moovaUserId);
$canManageMoova = MoovaPosIntegration::userCanManageIntegration($conn, $moovaUserId)
    || auth_guard_has_permission('moova.manage', $conn);
$activeMoovaLink = $moovaScope ? MoovaPosIntegration::findActiveLinkForScope($conn, $moovaScope) : null;

$moovaCsrf = csrf_token('moova_integration');
$moovaSyncCsrf = csrf_token('sync_credentials');

$defaultWidgetUrl = $activeMoovaLink['widget_url'] ?? 'https://withmoova.com/pos-widget';
$visibleDeviceToken = ($canManageMoova && $activeMoovaLink) ? (string) ($activeMoovaLink['moova_device_token'] ?? '') : '';
$moovaSyncSettings = (new SyncRuntimeSettings())->loadForUi($conn, true);
$moovaSyncBool = static function (string $key, bool $default = false) use ($moovaSyncSettings): bool {
    if (isset($moovaSyncSettings[$key]) && !empty($moovaSyncSettings[$key]['configured'])) {
        return in_array(strtolower(trim((string) $moovaSyncSettings[$key]['value'])), ['1', 'true', 'yes', 'on'], true);
    }

    $envNames = [$key];

    $envFiles = function_exists('posmain_branch_env_file_fallbacks') ? posmain_branch_env_file_fallbacks() : [];
    $envValue = function_exists('posmain_first_env_or_file')
        ? posmain_first_env_or_file($envNames, null, true, $envFiles)
        : getenv($key);
    if ($envValue !== false && $envValue !== null && trim((string) $envValue) !== '') {
        return function_exists('posmain_bool')
            ? posmain_bool($envValue, $default)
            : in_array(strtolower(trim((string) $envValue)), ['1', 'true', 'yes', 'on'], true);
    }

    return $default;
};

if ($visibleDeviceToken !== '') {
    try {
        (new SecurityAuditLogger())->record($conn, 'moova_device_token_viewed', [
            'user_id' => $moovaUserId,
            'tenant' => (int) ($moovaScope['tenant'] ?? 0),
            'branch' => (int) ($moovaScope['branch'] ?? 0),
            'target_type' => 'moova_pos_shop_link',
            'target_id' => (int) ($activeMoovaLink['id'] ?? 0),
            'metadata' => [
                'moova_branch_id' => (string) ($activeMoovaLink['moova_branch_id'] ?? ''),
                'device_token_last4' => (string) ($activeMoovaLink['moova_device_token_last4'] ?? substr($visibleDeviceToken, -4)),
            ],
        ]);
    } catch (Throwable $ignored) {
        error_log('[Moova POS] token view audit failed: ' . $ignored->getMessage());
    }
}
?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0 text-dark"><i class="fas fa-plug text-primary ml-2"></i> ربط Moova بالـ POS</h1>
        </div>
        <div class="col-sm-6">
          <ol class="breadcrumb float-sm-left m-0 bg-transparent p-0">
            <li class="breadcrumb-item"><a href="dashboard.php">الرئيسية</a></li>
            <li class="breadcrumb-item active">Moova Integration</li>
          </ol>
        </div>
      </div>
    </div>
  </section>

  <section class="content">
    <div class="container-fluid">
      <?php if (!$canManageMoova): ?>
        <div class="alert alert-danger shadow-sm">
          <i class="fas fa-lock ml-2"></i>
          ليس لديك صلاحية إدارة ربط Moova. افتح الصفحة بحساب مدير.
        </div>
      <?php elseif (!$moovaScope): ?>
        <div class="alert alert-danger shadow-sm">
          <i class="fas fa-exclamation-triangle ml-2"></i>
          لا يمكن تحديد الفرع/المؤسسة لهذا المستخدم، لذلك لن يتم تفعيل الربط.
        </div>
      <?php else: ?>
        <div class="row">
          <div class="col-lg-8">
            <div class="card card-primary card-outline shadow-sm">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-link ml-2"></i> بيانات الربط</h3>
              </div>
              <div class="card-body">
                <div id="moovaIntegrationAlert" class="alert d-none" role="alert"></div>

                <form id="moovaIntegrationForm" autocomplete="off">
                  <input type="hidden" id="moovaCsrf" value="<?= htmlspecialchars($moovaCsrf, ENT_QUOTES, 'UTF-8') ?>">

                  <div class="form-group">
                    <label for="moovaDeviceToken">Moova Device Token <?= $activeMoovaLink ? '' : '<span class="text-danger">*</span>' ?></label>
                    <input type="text" class="form-control" id="moovaDeviceToken"
                           value="<?= htmlspecialchars($visibleDeviceToken, ENT_QUOTES, 'UTF-8') ?>"
                           placeholder="الصق التوكن من صفحة Moova Admin"
                           dir="ltr" spellcheck="false" <?= $activeMoovaLink ? '' : 'required' ?>>
                    <small class="form-text text-muted">هذا التوكن يحدد فرع Moova المرتبط بهذا الـ POS وسيظل ظاهرا هنا بعد الحفظ.</small>
                  </div>

                  <div class="row">
                    <div class="col-md-8">
                      <div class="form-group">
                        <label for="moovaWidgetUrl">Widget URL <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="moovaWidgetUrl" required
                               value="<?= htmlspecialchars((string)$defaultWidgetUrl, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="https://withmoova.com/pos-widget">
                      </div>
                    </div>
                    <div class="col-md-4">
                      <div class="form-group">
                        <label for="moovaLocale">لغة الودجت</label>
                        <select class="form-control" id="moovaLocale">
                          <?php $locale = (string)($activeMoovaLink['locale'] ?? 'ar'); ?>
                          <option value="ar" <?= $locale === 'ar' ? 'selected' : '' ?>>العربية</option>
                          <option value="en" <?= $locale === 'en' ? 'selected' : '' ?>>English</option>
                        </select>
                      </div>
                    </div>
                  </div>

                  <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
                    <button type="submit" class="btn btn-primary px-4" id="moovaSaveBtn">
                      <i class="fas fa-save ml-1"></i> حفظ الربط
                    </button>
                    <?php if ($activeMoovaLink): ?>
                      <button type="button" class="btn btn-outline-danger px-4 mr-2" id="moovaDisconnectBtn">
                        <i class="fas fa-unlink ml-1"></i> إلغاء الربط
                      </button>
                    <?php endif; ?>
                    <a class="btn btn-outline-secondary px-4 mr-2" href="pos_barcode.php">
                      <i class="fas fa-cash-register ml-1"></i> فتح شاشة البيع
                    </a>
                  </div>
                </form>
              </div>
            </div>

            <div class="card card-secondary card-outline shadow-sm">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-sync-alt ml-2"></i> إعدادات مزامنة Moova</h3>
              </div>
              <div class="card-body">
                <form id="moovaSyncSettingsForm" autocomplete="off">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($moovaSyncCsrf, ENT_QUOTES, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="save_moova">
                  <div class="custom-control custom-switch mb-3">
                    <input type="hidden" name="POSMAIN_MOOVA_POLLER_ENABLED" value="0">
                    <input type="checkbox" class="custom-control-input" id="moovaPollerEnabled" name="POSMAIN_MOOVA_POLLER_ENABLED" value="1" <?= $moovaSyncBool('POSMAIN_MOOVA_POLLER_ENABLED', true) ? 'checked' : '' ?>>
                    <label class="custom-control-label" for="moovaPollerEnabled">Poll Moova events from cloud</label>
                    <small class="form-text text-muted">خاص بمتابعة أحداث Moova عندما تكون المزامنة السحابية مفعلة.</small>
                  </div>
                  <div class="custom-control custom-switch mb-3">
                    <input type="hidden" name="POSMAIN_MOOVA_APPLY_ENABLED" value="0">
                    <input type="checkbox" class="custom-control-input" id="moovaApplyEnabled" name="POSMAIN_MOOVA_APPLY_ENABLED" value="1" <?= $moovaSyncBool('POSMAIN_MOOVA_APPLY_ENABLED', true) ? 'checked' : '' ?>>
                    <label class="custom-control-label" for="moovaApplyEnabled">Apply Moova through worker</label>
                    <small class="form-text text-muted">فعله فقط إذا كان هذا الـ POS هو المكان الذي سيطبق أوامر Moova داخل شاشة الكاشير.</small>
                  </div>
                  <button type="submit" class="btn btn-success px-4" id="moovaSyncSaveBtn">
                    <i class="fas fa-save ml-1"></i> حفظ إعدادات Moova
                  </button>
                </form>
              </div>
            </div>
          </div>

          <div class="col-lg-4">
            <div class="card card-info card-outline shadow-sm">
              <div class="card-header">
                <h3 class="card-title"><i class="fas fa-store ml-2"></i> فرع الـ POS الحالي</h3>
              </div>
              <div class="card-body">
                <dl class="row mb-0">
                  <dt class="col-5">Tenant</dt>
                  <dd class="col-7"><?= (int)$moovaScope['tenant'] ?></dd>
                  <dt class="col-5">Branch</dt>
                  <dd class="col-7"><?= (int)$moovaScope['branch'] ?></dd>
                  <dt class="col-5">الحالة</dt>
                  <dd class="col-7">
                    <?php if ($activeMoovaLink): ?>
                      <span class="badge badge-success px-3 py-2">متصل</span>
                    <?php else: ?>
                      <span class="badge badge-secondary px-3 py-2">غير متصل</span>
                    <?php endif; ?>
                  </dd>
                  <?php if ($activeMoovaLink): ?>
                    <dt class="col-5">Token</dt>
                    <dd class="col-7 text-break" dir="ltr"><?= htmlspecialchars($visibleDeviceToken, ENT_QUOTES, 'UTF-8') ?></dd>
                    <dt class="col-5">آخر تعديل</dt>
                    <dd class="col-7"><?= htmlspecialchars((string)$activeMoovaLink['updated_at'], ENT_QUOTES, 'UTF-8') ?></dd>
                  <?php endif; ?>
                </dl>
              </div>
            </div>

            <div class="alert alert-warning shadow-sm">
              <i class="fas fa-shield-alt ml-2"></i>
              أي طلب من Moova لن يقبل إلا إذا كان Device Token مطابقا لهذا الربط، وكان الكاشير داخل نفس Tenant وBranch.
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const form = document.getElementById('moovaIntegrationForm');
  const syncForm = document.getElementById('moovaSyncSettingsForm');
  const alertBox = document.getElementById('moovaIntegrationAlert');
  const saveBtn = document.getElementById('moovaSaveBtn');
  const syncSaveBtn = document.getElementById('moovaSyncSaveBtn');
  const disconnectBtn = document.getElementById('moovaDisconnectBtn');

  function showAlert(type, message) {
    if (!alertBox) return;
    alertBox.className = 'alert alert-' + type;
    alertBox.textContent = message;
    alertBox.classList.remove('d-none');
  }

  function payload() {
    return {
      csrf: document.getElementById('moovaCsrf').value,
      deviceToken: document.getElementById('moovaDeviceToken').value.trim(),
      widgetUrl: document.getElementById('moovaWidgetUrl').value.trim(),
      locale: document.getElementById('moovaLocale').value
    };
  }

  async function postJson(url, body) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: JSON.stringify(body)
    });
    const data = await response.json().catch(function () { return {}; });
    if (!response.ok || !(data.success || data.ok)) {
      throw new Error(data.message || 'حدث خطأ أثناء الحفظ');
    }
    return data;
  }

  async function postFormJson(url, formElement) {
    const response = await fetch(url, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: new URLSearchParams(new FormData(formElement))
    });
    const data = await response.json().catch(function () { return {}; });
    if (!response.ok || !data.ok) {
      throw new Error(data.message || 'حدث خطأ أثناء الحفظ');
    }
    return data;
  }

  if (form) {
    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      saveBtn.disabled = true;
      try {
        const result = await postJson('ajax/moova_save_integration.php', payload());
        let syncMessage = ' وسيحاول الودجت مزامنة المنيو عند فتح شاشة البيع.';
        if (result.autoSync && result.autoSync.ok) {
          syncMessage = result.autoSync.message
            ? ' ' + result.autoSync.message
            : ' وتمت مزامنة المنيو من الـ POS إلى متجر Moova.';
        } else if (result.autoSync && result.autoSync.attempted) {
          const parts = [];
          if (result.autoSync.syncMode) {
            parts.push('وضع ' + result.autoSync.syncMode);
          }
          if (result.autoSync.phase) {
            parts.push('مرحلة ' + result.autoSync.phase);
          }
          if (result.autoSync.statusCode) {
            parts.push('HTTP ' + result.autoSync.statusCode);
          }
          if (result.autoSync.message) {
            parts.push(result.autoSync.message);
          }
          syncMessage = parts.length
            ? ' ' + parts.join(' — ')
            : ' تم حفظ الربط لكن مزامنة المنيو لم تكتمل.';
        }
        const syncNeedsAttention = result.autoSync && result.autoSync.attempted && !result.autoSync.ok;
        showAlert(syncNeedsAttention ? 'warning' : 'success', 'تم حفظ الربط بنجاح.' + syncMessage);
        setTimeout(function () { window.location.reload(); }, 10000);
      } catch (error) {
        showAlert('danger', error.message);
      } finally {
        saveBtn.disabled = false;
      }
    });
  }

  if (syncForm) {
    syncForm.addEventListener('submit', async function (event) {
      event.preventDefault();
      syncSaveBtn.disabled = true;
      try {
        const result = await postFormJson('ajax/sync_credentials.php', syncForm);
        showAlert('success', result.message || 'تم حفظ إعدادات Moova.');
      } catch (error) {
        showAlert('danger', error.message);
      } finally {
        syncSaveBtn.disabled = false;
      }
    });
  }

  if (disconnectBtn) {
    disconnectBtn.addEventListener('click', async function () {
      if (!confirm('هل تريد إلغاء ربط Moova بهذا الفرع؟')) {
        return;
      }
      disconnectBtn.disabled = true;
      try {
        await postJson('ajax/moova_disconnect_integration.php', {
          csrf: document.getElementById('moovaCsrf').value
        });
        showAlert('success', 'تم إلغاء الربط.');
        setTimeout(function () { window.location.reload(); }, 900);
      } catch (error) {
        showAlert('danger', error.message);
      } finally {
        disconnectBtn.disabled = false;
      }
    });
  }
});
</script>

<?php include('includes/footer.php'); ?>
