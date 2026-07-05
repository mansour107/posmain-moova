<?php
require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/includes/page_guard.php';
require_once __DIR__ . '/includes/csrf.php';
include('includes/connect.php');
page_guard('roles.manage', $conn, true);

$roleId = (int) ($_GET['id'] ?? 0);
if ($roleId < 1) {
    header('Location: myroles.php');
    exit;
}

$stmt = $conn->prepare('SELECT id, rollname FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
$stmt->bind_param('i', $roleId);
$stmt->execute();
$roleRow = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$roleRow) {
    header('Location: myroles.php');
    exit;
}

require_once __DIR__ . '/classes/Security/RolePermissionSyncService.php';
require_once __DIR__ . '/classes/Security/PermissionService.php';
$stmtRole = $conn->prepare('SELECT * FROM usr_pwrs WHERE id = ? AND COALESCE(isdeleted, 0) != 1 LIMIT 1');
$stmtRole->bind_param('i', $roleId);
$stmtRole->execute();
$editedRoleFlags = $stmtRole->get_result()->fetch_assoc() ?: [];
$stmtRole->close();

$enabledPermissions = RolePermissionSyncService::enabledPermissionsFromRoleFlags($editedRoleFlags);
$permissionGroups = RolePermissionSyncService::permissionGroups();
$limitablePermissions = RolePermissionSyncService::limitablePermissions();
$roleLimits = (new PermissionService($conn))->roleCapabilityLimits($roleId);
$permissionMap = auth_guard_permission_map();
$saved = isset($_GET['saved']);
$isOwnerRole = (new PermissionService($conn))->isOwnerRole($roleId);
$roleKeyStmt = $conn->prepare('SELECT role_key FROM usr_pwrs WHERE id = ? LIMIT 1');
$roleKeyStmt->bind_param('i', $roleId);
$roleKeyStmt->execute();
$roleKeyRow = $roleKeyStmt->get_result()->fetch_assoc();
$roleKeyStmt->close();
$presetRoleKey = trim((string) ($roleKeyRow['role_key'] ?? ''));

include('includes/header.php');
include('includes/navbar.php');
include('includes/sidebar.php');
?>

<div class="content-wrapper p-4">
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h3 class="mb-0">صلاحيات الدور: <?= htmlspecialchars($roleRow['rollname']) ?></h3>
            <div class="d-flex gap-2">
                <a href="edit_role.php?id=<?= md5($roleId) ?>&no=<?= $roleId ?>&name=<?= urlencode($roleRow['rollname']) ?>" class="btn btn-outline-secondary btn-sm">محرر الأعمدة الكامل</a>
                <a href="myroles.php" class="btn btn-secondary btn-sm">رجوع</a>
            </div>
        </div>
        <div class="card-body">
            <?php if ($saved): ?>
                <div class="alert alert-success">تم حفظ صلاحيات الدور.</div>
            <?php endif; ?>
            <p class="text-muted">حدد مفاتيح الصلاحيات الحديثة لهذا الدور. يتم مزامنة الأعمدة التفصيلية في <code>usr_pwrs</code> تلقائياً.</p>
            <?php if ($isOwnerRole): ?>
                <div class="alert alert-info">دور المالك للقراءة فقط — لا يمكن تعديل صلاحياته.</div>
            <?php elseif ($presetRoleKey !== ''): ?>
                <form method="post" action="do/do_restore_role_preset.php" class="mb-3 d-inline"
                      onsubmit="return confirm('استعادة الإعدادات الافتراضية لدور <?= htmlspecialchars($presetRoleKey, ENT_QUOTES) ?>؟ اكتب restore للتأكيد') && (this.confirm_diff.value=prompt('اكتب restore للتأكيد')||'')==='restore';">
                    <?= csrf_input('roles_write') ?>
                    <input type="hidden" name="role_id" value="<?= (int) $roleId ?>">
                    <input type="hidden" name="role_key" value="<?= htmlspecialchars($presetRoleKey, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="confirm_diff" value="">
                    <button type="submit" class="btn btn-outline-warning btn-sm">استعادة الإعدادات الافتراضية</button>
                </form>
            <?php endif; ?>
            <form method="post" action="do/doedit_role_permissions.php">
                <?= csrf_input('roles_write') ?>
                <input type="hidden" name="role_id" value="<?= (int) $roleId ?>">
                <?php foreach ($permissionGroups as $groupLabel => $permissions): ?>
                    <h5 class="mt-4 mb-2"><?= htmlspecialchars($groupLabel) ?></h5>
                    <div class="row">
                        <?php foreach ($permissions as $permission):
                            if (!isset($permissionMap[$permission])) {
                                continue;
                            }
                            $legacyFlags = array_filter($permissionMap[$permission], static fn($f) => $f !== '__admin_only');
                            $isAdminOnly = in_array('__admin_only', $permissionMap[$permission], true);
                            $checked = in_array($permission, $enabledPermissions, true);
                            $hasLimit = in_array($permission, $limitablePermissions, true);
                            $limitRow = $roleLimits[$permission] ?? null;
                            $isUnlimited = $limitRow === null || !empty($limitRow['is_unlimited']);
                            $limitValue = $limitRow['limit_value'] ?? '';
                            ?>
                            <div class="col-md-6 col-lg-4 mb-2">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="perm_<?= htmlspecialchars($permission) ?>"
                                           name="permissions[]" value="<?= htmlspecialchars($permission) ?>"
                                           <?= $checked ? 'checked' : '' ?>
                                           <?= ($isAdminOnly || $isOwnerRole) ? 'disabled' : '' ?>>
                                    <label class="custom-control-label" for="perm_<?= htmlspecialchars($permission) ?>">
                                        <code><?= htmlspecialchars($permission) ?></code>
                                        <?php if ($isAdminOnly): ?>
                                            <small class="text-muted">(admin فقط)</small>
                                        <?php elseif ($legacyFlags !== []): ?>
                                            <br><small class="text-muted"><?= htmlspecialchars(implode(', ', $legacyFlags)) ?></small>
                                        <?php endif; ?>
                                    </label>
                                </div>
                                <?php if ($hasLimit && !$isOwnerRole): ?>
                                    <div class="ml-4 mt-1 small">
                                        <label class="d-block mb-1">حد:</label>
                                        <div class="custom-control custom-checkbox d-inline-block mr-2">
                                            <input type="checkbox" class="custom-control-input" id="unlimited_<?= htmlspecialchars($permission) ?>"
                                                   name="limit_unlimited[<?= htmlspecialchars($permission) ?>]" value="1"
                                                   <?= $isUnlimited ? 'checked' : '' ?>>
                                            <label class="custom-control-label" for="unlimited_<?= htmlspecialchars($permission) ?>">غير محدود</label>
                                        </div>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm d-inline-block" style="width:90px"
                                               name="limit_value[<?= htmlspecialchars($permission) ?>]"
                                               value="<?= htmlspecialchars((string) $limitValue, ENT_QUOTES, 'UTF-8') ?>"
                                               placeholder="قيمة">
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <button type="submit" class="btn btn-primary mt-3" <?= $isOwnerRole ? 'disabled' : '' ?>>حفظ الصلاحيات</button>
            </form>
        </div>
    </div>
</div>

<?php include('includes/footer.php'); ?>
