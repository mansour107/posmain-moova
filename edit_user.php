<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/csrf.php';
include 'includes/connect.php';
require_admin_or_permission('users.manage', $conn);
require_once __DIR__ . '/classes/Security/RolePermissionSyncService.php';
RolePermissionSyncService::seedPresetRoles($conn);
$presetRoles = [];
$roleStmt = $conn->query("SELECT id, rollname, role_key, is_system FROM usr_pwrs WHERE COALESCE(isdeleted,0)!=1 AND role_key IN ('owner','manager','cashier','waiter','kitchen') ORDER BY FIELD(role_key,'owner','manager','cashier','waiter','kitchen')");
if ($roleStmt) {
    while ($r = $roleStmt->fetch_assoc()) {
        $presetRoles[] = $r;
    }
}
$customRoles = [];
$customStmt = $conn->query("SELECT id, rollname FROM usr_pwrs WHERE COALESCE(isdeleted,0)!=1 AND (role_key IS NULL OR role_key = '' OR role_key NOT IN ('owner','manager','cashier','waiter','kitchen')) ORDER BY id");
if ($customStmt) {
    while ($r = $customStmt->fetch_assoc()) {
        $customRoles[] = $r;
    }
}
$currentRoleId = (int) ($row['userrole'] ?? 0);
$currentIsPreset = false;
foreach ($presetRoles as $pr) {
    if ((int) $pr['id'] === $currentRoleId) {
        $currentIsPreset = true;
        break;
    }
}
include 'includes/header.php';
include 'includes/navbar.php';
include 'includes/sidebar.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id < 1) {
    header('Location: users.php');
    exit;
}
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $id);
$stmt->execute();
$res = $stmt->get_result();
$row = $res->fetch_assoc();
$stmt->close();
if (!$row) {
    header('Location: users.php');
    exit;
}

?>
<style>
    .edit-user-container {
        padding: 30px;
        background-color: #f8f9fc;
        min-height: 100vh;
    }
    
    .edit-user-card {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        border: 1px solid #eef2f7;
        overflow: hidden;
    }
    
    .card-header-clean {
        background: #f8f9fc;
        padding: 25px 30px;
        border-bottom: 1px solid #eef2f7;
    }
    
    .card-title-clean {
        margin: 0;
        font-weight: 700;
        font-size: 1.5rem;
        color: #2d3748;
    }
    
    .card-body-clean {
        padding: 30px;
    }
    
    .form-container {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
        max-width: 1000px;
    }
    
    .form-group-clean {
        margin-bottom: 0;
    }
    
    .form-label-clean {
        display: block;
        font-weight: 600;
        color: #2d3748;
        margin-bottom: 10px;
        font-size: 1rem;
    }
    
    .form-control-clean {
        width: 100%;
        padding: 14px 16px;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        font-size: 1rem;
        transition: all 0.3s ease;
        background: white;
    }
    
    .form-control-clean:focus {
        border-color: #2d3748;
        outline: none;
        box-shadow: 0 0 0 3px rgba(45, 55, 72, 0.1);
    }
    
    .form-text-clean {
        display: block;
        margin-top: 8px;
        font-size: 0.9rem;
        color: #718096;
    }
    
    .file-upload-container {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    
    .file-upload-label {
        background: #f8f9fc;
        border: 2px dashed #cbd5e0;
        border-radius: 10px;
        padding: 25px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
        font-weight: 600;
        color: #4a5568;
    }
    
    .file-upload-label:hover {
        border-color: #2d3748;
        background: #f7fafc;
    }
    
    .file-input {
        display: none;
    }
    
    .current-image {
        text-align: center;
        margin-top: 15px;
    }
    
    .current-image img {
        width: 120px;
        height: 120px;
        border-radius: 12px;
        object-fit: cover;
        border: 3px solid #eef2f7;
    }
    
    .btn-submit-clean {
        background: #2d3748;
        border: none;
        border-radius: 10px;
        padding: 16px 32px;
        font-weight: 600;
        color: white;
        font-size: 1.1rem;
        cursor: pointer;
        transition: all 0.3s ease;
        width: 100%;
        max-width: 200px;
    }
    
    .btn-submit-clean:hover {
        background: #4a5568;
        transform: translateY(-2px);
    }
    
    .card-footer-clean {
        padding: 25px 30px;
        background: #f8f9fc;
        border-top: 1px solid #eef2f7;
        text-align: left;
    }
    
    .password-match-error {
        color: #e53e3e;
        font-size: 0.9rem;
        margin-top: 5px;
        display: none;
    }
    
    @media (max-width: 768px) {
        .edit-user-container {
            padding: 20px;
        }
        
        .card-body-clean {
            padding: 20px;
        }
        
        .form-container {
            grid-template-columns: 1fr;
            gap: 20px;
        }
        
        .btn-submit-clean {
            max-width: 100%;
        }
    }
</style>
<style>
.role-cards { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .75rem; }
.role-card { border: 2px solid #e2e8f0; border-radius: 12px; padding: .75rem; cursor: pointer; text-align: center; transition: .15s; }
.role-card.selected { border-color: #6366f1; background: #eef2ff; }
.role-card input { display: none; }
.shift-shortcut-chip { display: inline-block; margin: .25rem; padding: .35rem .75rem; border-radius: 999px; border: 1px solid #cbd5e0; cursor: pointer; font-size: .85rem; }
.shift-shortcut-chip.active { background: #6366f1; color: #fff; border-color: #6366f1; }
</style>

<div class="edit-user-container container">
    <div class="edit-user-card">
        <div class="card-header-clean">
            <h3 class="card-title-clean">
                <i class="fas fa-user-edit mr-2"></i>
                تعديل المستخدم
            </h3>
        </div>
        
        <form role="form" enctype="multipart/form-data" action="do/doedit_user.php?id=<?= $row['id'] ?>" method="post" autocomplete="off">
            <?= csrf_input('users_write') ?>
            <div class="card-body-clean">
                <div class="form-container">
                    <!-- اسم المستخدم -->
                    <div class="form-group-clean">
                        <label class="form-label-clean" for="uname">
                            <i class="fas fa-user mr-2"></i>
                            <?= $lang_username ?>
                        </label>
                        <input value="<?= htmlspecialchars($row['uname'], ENT_QUOTES, 'UTF-8') ?>" name="uname" type="text" class="form-control-clean" 
                               id="uname" placeholder="اكتب اسم المستخدم" required>
                    </div>

                    <div class="form-group-clean">
                        <label class="form-label-clean" for="display_name">الاسم المعروض</label>
                        <input value="<?= htmlspecialchars((string) ($row['display_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" name="display_name" type="text" class="form-control-clean" id="display_name" placeholder="اسم الموظف على الشاشة">
                    </div>

                    <div class="form-group-clean">
                        <label class="form-label-clean" for="phone">الهاتف</label>
                        <input value="<?= htmlspecialchars((string) ($row['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" name="phone" type="text" class="form-control-clean" id="phone" placeholder="05xxxxxxxx">
                    </div>

                    <div class="form-group-clean">
                        <label class="form-label-clean" for="pin">رمز PIN جديد</label>
                        <div class="input-group">
                            <input name="pin" type="password" inputmode="numeric" class="form-control-clean" id="pin" placeholder="4-6 أرقام — اتركه فارغاً للإبقاء" autocomplete="new-password" maxlength="6">
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" id="regeneratePinBtn">توليد</button>
                            </div>
                        </div>
                        <small id="pinAvailabilityMsg" class="form-text-clean"></small>
                        <input type="hidden" name="generate_pin" id="generatePinFlag" value="0">
                        <?php if (!empty($row['pin_set_at'])): ?>
                        <div class="custom-control custom-checkbox mt-2">
                            <input type="checkbox" class="custom-control-input" id="clear_pin" name="clear_pin" value="1">
                            <label class="custom-control-label" for="clear_pin">إزالة PIN</label>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-group-clean" style="grid-column: 1 / -1;">
                        <label class="form-label-clean"><i class="fas fa-user-tag mr-2"></i>دور المستخدم</label>
                        <div class="role-cards" id="roleCards">
                            <?php foreach ($presetRoles as $pr): ?>
                                <label class="role-card<?= (int) $pr['id'] === $currentRoleId ? ' selected' : '' ?>" data-role-id="<?= (int) $pr['id'] ?>">
                                    <input type="radio" name="userrole" value="<?= (int) $pr['id'] ?>" <?= (int) $pr['id'] === $currentRoleId ? 'checked' : '' ?>>
                                    <strong><?= htmlspecialchars($pr['rollname']) ?></strong>
                                    <div class="small text-muted"><?= htmlspecialchars((string) ($pr['role_key'] ?? '')) ?></div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <details class="mt-2" <?= !$currentIsPreset && $currentRoleId > 0 ? 'open' : '' ?>>
                            <summary class="text-muted">دور مخصص</summary>
                            <select class="form-control-clean mt-2" id="customRoleSelect">
                                <option value="">— اختر —</option>
                                <?php foreach ($customRoles as $cr): ?>
                                    <option value="<?= (int) $cr['id'] ?>" <?= (int) $cr['id'] === $currentRoleId ? 'selected' : '' ?>><?= htmlspecialchars($cr['rollname']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </details>
                    </div>

                    <?php if(isset($role) && isset($role['edit_user_passwords']) && $role['edit_user_passwords'] == 1): ?>
                    <!-- كلمة المرور الجديدة -->
                    <div class="form-group-clean">
                        <label class="form-label-clean" for="password">
                            <i class="fas fa-lock mr-2"></i>
                            كلمة المرور الجديدة / الباركود
                        </label>
                        <input name="password" type="password" class="form-control-clean" id="password"
                               placeholder="اترك فارغاً إذا كنت لا تريد تغيير كلمة المرور">
                        <span class="form-text-clean">اترك هذا الحقل فارغاً إذا كنت لا تريد تغيير كلمة المرور. للويترز: استخدم هذا الحقل كباركود</span>
                    </div>

                    <!-- تأكيد كلمة المرور -->
                    <div class="form-group-clean">
                        <label class="form-label-clean" for="confirm_password">
                            <i class="fas fa-lock mr-2"></i>
                            تأكيد كلمة المرور
                        </label>
                        <input name="confirm_password" type="password" class="form-control-clean" id="confirm_password"
                               placeholder="تأكيد كلمة المرور الجديدة">
                        <div class="password-match-error" id="passwordError">
                            <i class="fas fa-exclamation-circle mr-1"></i>
                            كلمات المرور غير متطابقة
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- صلاحيات إضافية للمستخدم -->
                    <?php
                    require_once __DIR__ . '/classes/Security/UserPermissionGrantService.php';
                    $grantService = new UserPermissionGrantService();
                    $userOverrides = $grantService->tableExists($conn)
                        ? $grantService->activeOverridesForUser($conn, (int) $row['id'])
                        : [];
                    $permissionCatalog = array_keys(auth_guard_permission_map());
                    ?>
                    <div class="form-group-clean" style="grid-column: 1 / -1;">
                        <h4 class="form-label-clean"><i class="fas fa-shield-alt mr-2"></i>صلاحيات إضافية (تجاوز الدور)</h4>
                        <form action="do/doedit_user_permissions.php" method="post" class="border rounded p-3">
                            <?= csrf_input('users_write') ?>
                            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                            <div class="custom-control custom-switch mb-3">
                                <input type="checkbox" class="custom-control-input" id="permission_mode" name="permission_mode" value="role_with_overrides"
                                    <?= (($row['permission_mode'] ?? 'role_only') === 'role_with_overrides') ? 'checked' : '' ?>>
                                <label class="custom-control-label" for="permission_mode">تفعيل تجاوزات الصلاحيات لهذا المستخدم</label>
                            </div>
                            <div class="mb-3">
                                <span class="text-muted small d-block mb-1">اختصارات شائعة:</span>
                                <span class="shift-shortcut-chip<?= (($userOverrides['pos.shift.open'] ?? '') === 'grant') ? ' active' : '' ?>" data-grant="pos.shift.open" data-label="يفتح الشيفت">يفتح الشيفت</span>
                                <span class="shift-shortcut-chip<?= (($userOverrides['pos.shift.close'] ?? '') === 'grant') ? ' active' : '' ?>" data-grant="pos.shift.close" data-label="يغلق الشيفت">يغلق الشيفت</span>
                            </div>
                            <div class="row">
                                <?php foreach ($permissionCatalog as $permKey): ?>
                                <div class="col-md-4 mb-2">
                                    <small class="d-block"><code><?= htmlspecialchars($permKey) ?></code></small>
                                    <label class="mr-2"><input type="checkbox" name="grant[]" value="<?= htmlspecialchars($permKey) ?>" <?= (($userOverrides[$permKey] ?? '') === 'grant') ? 'checked' : '' ?>> منح</label>
                                    <label><input type="checkbox" name="deny[]" value="<?= htmlspecialchars($permKey) ?>" <?= (($userOverrides[$permKey] ?? '') === 'deny') ? 'checked' : '' ?>> منع</label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="submit" class="btn btn-outline-primary mt-2">حفظ تجاوزات الصلاحيات</button>
                        </form>
                    </div>

                    <!-- خيار الويتر -->
                    <div class="form-group-clean" style="grid-column: 1 / -1;">
                        <div class="custom-control custom-switch" style="padding-top: 10px;">
                            <input type="checkbox" class="custom-control-input" id="is_waiter" name="is_waiter" value="1"
                                   <?= (isset($row['is_waiter']) && $row['is_waiter'] == 1) ? 'checked' : '' ?>>
                            <label class="custom-control-label" for="is_waiter" style="font-size: 1.1rem; font-weight: 600;">
                                <i class="fas fa-user-tie"></i> هذا المستخدم ويتر
                            </label>
                        </div>
                        <small class="form-text text-muted" style="margin-top: 8px; display: block;">
                            <i class="fas fa-info-circle"></i> الويترز يمكنهم تسجيل الدخول بالباركود في صفحة POS الخاصة بهم
                        </small>
                    </div>

                    <!-- رفع الصورة -->
                    <div class="form-group-clean">
                        <label class="form-label-clean">
                            <i class="fas fa-image mr-2"></i>
                            صورة المستخدم
                        </label>
                        <div class="file-upload-container">
                            <label for="img" class="file-upload-label">
                                <i class="fas fa-cloud-upload-alt fa-2x mb-3"></i>
                                <br>
                                <?= $lang_image_upload ?>
                                <br>
                                <small class="text-muted">انقر لاختيار صورة</small>
                            </label>
                            <input type="file" name="img" id="img" class="file-input" accept="image/*">
                            
                            <!-- عرض الصورة الحالية -->
                            <?php if(!empty($row['img'])): ?>
                            <div class="current-image">
                                <p class="form-text-clean">الصورة الحالية:</p>
                                <img src="uploads/<?= $row['img'] ?>" alt="صورة المستخدم الحالية" 
                                     onerror="this.style.display='none'">
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="card-footer-clean">
                <button type="submit" class="btn-submit-clean">
                    <i class="fas fa-save mr-2"></i>
                    حفظ التغييرات
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const passwordField = document.getElementById('password');
        const confirmPasswordField = document.getElementById('confirm_password');
        const passwordError = document.getElementById('passwordError');
        const form = document.querySelector('form');
        const fileInput = document.getElementById('img');
        const fileUploadLabel = document.querySelector('.file-upload-label');

        // التحقق من تطابق كلمات المرور
        function validatePasswords() {
            if (passwordField && confirmPasswordField) {
                if (passwordField.value !== '' || confirmPasswordField.value !== '') {
                    if (passwordField.value !== confirmPasswordField.value) {
                        passwordError.style.display = 'block';
                        confirmPasswordField.style.borderColor = '#e53e3e';
                        return false;
                    } else {
                        passwordError.style.display = 'none';
                        confirmPasswordField.style.borderColor = '#2d3748';
                        return true;
                    }
                }
            }
            return true;
        }

        if (passwordField && confirmPasswordField) {
            passwordField.addEventListener('input', validatePasswords);
            confirmPasswordField.addEventListener('input', validatePasswords);
        }

        // إظهار اسم الملف عند اختياره
        fileInput.addEventListener('change', function(e) {
            if (this.files && this.files[0]) {
                const fileName = this.files[0].name;
                fileUploadLabel.innerHTML = `
                    <i class="fas fa-check-circle fa-2x mb-3 text-success"></i>
                    <br>
                    تم اختيار الملف
                    <br>
                    <small class="text-success">${fileName}</small>
                `;
                fileUploadLabel.style.borderColor = '#38a169';
            }
        });

        form.addEventListener('submit', function(e) {
            if (!validatePasswords()) {
                e.preventDefault();
                passwordError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        });

        const cards = document.querySelectorAll('.role-card');
        const customSelect = document.getElementById('customRoleSelect');
        const editForm = document.querySelector('.edit-user-card > form');
        function selectCard(card) {
            cards.forEach(function (c) { c.classList.remove('selected'); });
            if (card) { card.classList.add('selected'); }
            if (customSelect) { customSelect.value = ''; }
        }
        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                const radio = card.querySelector('input[type=radio]');
                if (radio) { radio.checked = true; selectCard(card); }
            });
        });
        if (customSelect && editForm) {
            customSelect.addEventListener('change', function () {
                if (!customSelect.value) { return; }
                cards.forEach(function (c) {
                    c.classList.remove('selected');
                    c.querySelector('input').checked = false;
                });
                let hidden = document.getElementById('customRoleRadio');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'radio';
                    hidden.name = 'userrole';
                    hidden.id = 'customRoleRadio';
                    hidden.style.display = 'none';
                    editForm.appendChild(hidden);
                }
                hidden.value = customSelect.value;
                hidden.checked = true;
            });
            if (customSelect.value && !document.querySelector('.role-card input:checked')) {
                customSelect.dispatchEvent(new Event('change'));
            }
        }

        const excludeUserId = <?= (int) $row['id'] ?>;
        const pinInput = document.getElementById('pin');
        const pinMsg = document.getElementById('pinAvailabilityMsg');
        const genFlag = document.getElementById('generatePinFlag');
        const regenBtn = document.getElementById('regeneratePinBtn');
        if (regenBtn && pinInput) {
            regenBtn.addEventListener('click', function () {
                let p = String(Math.floor(1000 + Math.random() * 9000));
                pinInput.value = p;
                pinInput.type = 'text';
                if (genFlag) { genFlag.value = '1'; }
                checkPinAvailable(p);
            });
            let pinTimer = null;
            function checkPinAvailable(pin) {
                if (!pin || pin.length < 4) { if (pinMsg) pinMsg.textContent = ''; return; }
                clearTimeout(pinTimer);
                pinTimer = setTimeout(function () {
                    fetch('ajax/pin_available.php?pin=' + encodeURIComponent(pin) + '&exclude_user_id=' + excludeUserId, { credentials: 'same-origin' })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            if (!pinMsg) { return; }
                            if (j.available === true) {
                                pinMsg.textContent = '✓ الرمز متاح';
                                pinMsg.style.color = '#38a169';
                            } else if (j.available === false) {
                                pinMsg.textContent = '✗ الرمز مستخدم أو غير صالح';
                                pinMsg.style.color = '#e53e3e';
                            }
                        });
                }, 300);
            }
            pinInput.addEventListener('input', function () {
                checkPinAvailable(pinInput.value);
                if (genFlag) { genFlag.value = '0'; }
            });
        }

        document.querySelectorAll('.shift-shortcut-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                const key = chip.getAttribute('data-grant');
                const grantInput = document.querySelector('input[name="grant[]"][value="' + key + '"]');
                const denyInput = document.querySelector('input[name="deny[]"][value="' + key + '"]');
                const modeInput = document.getElementById('permission_mode');
                if (modeInput) { modeInput.checked = true; }
                if (grantInput) {
                    grantInput.checked = !grantInput.checked;
                    if (denyInput && grantInput.checked) { denyInput.checked = false; }
                    chip.classList.toggle('active', grantInput.checked);
                }
            });
        });
    });
</script>

<?php include 'includes/footer.php'; ?>
