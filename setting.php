<?php include('includes/header.php'); ?>

<?php
$sittingpass = $sittingpass ?? 'hadi@1234';
$postedPass = isset($_POST['password']) ? (string) $_POST['password'] : null;
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
              <div class="col-lg-6">
                <div class="form-group">
                  <label for="pos_type">نوع نظام POS</label>
                  <select class="form-control" id="pos_type" name="pos_type">
                    <option value="barcode" <?= (($rowstg['pos_type'] ?? 'barcode') === 'barcode') ? 'selected' : '' ?>>POS عادي (باركود)</option>
                    <option value="clothes" <?= (($rowstg['pos_type'] ?? 'barcode') === 'clothes') ? 'selected' : '' ?>>POS ملابس</option>
                  </select>
                  <small class="form-text text-muted">يحدد نوع واجهة POS من القائمة.</small>
                </div>
              </div>
              <div class="col-lg-6">
                <div class="form-group mb-0">
                  <label class="d-block">حماية POS بكلمة مرور</label>
                  <div class="custom-control custom-switch pt-1">
                    <input type="checkbox" class="custom-control-input" id="pos_has_password" name="pos_has_password" value="1"
                           <?= (!empty($rowstg['pos_has_password'])) ? 'checked' : '' ?>>
                    <label class="custom-control-label" for="pos_has_password">طلب مسح باركود قبل فتح POS</label>
                  </div>
                  <small class="form-text text-muted">عند التفعيل يُطلب التحقق قبل استخدام الكاشير.</small>
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

<?php endif; ?>

<?php include('includes/footer.php'); ?>
