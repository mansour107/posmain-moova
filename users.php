<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
<style>
    .users-management-container {
        padding: 30px;
        background-color: #f8f9fc;
        min-height: 100vh;
    }
    
    .page-header {
        background: white;
        border-radius: 16px;
        padding: 30px;
        margin-bottom: 30px;
        box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        border: 1px solid #eef2f7;
    }
    
    .page-title {
        color: #2d3748;
        font-weight: 700;
        margin-bottom: 0;
        font-size: 2rem;
    }
    
    .action-buttons {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
    }
    
    .btn-primary-clean {
        background: #2d3748;
        border: none;
        border-radius: 10px;
        padding: 14px 28px;
        font-weight: 600;
        color: white;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        font-size: 1rem;
    }
    
    .btn-primary-clean:hover {
        background: #4a5568;
        color: white;
        text-decoration: none;
        transform: translateY(-2px);
    }
    
    .btn-light-clean {
        background: white;
        border: 2px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 28px;
        font-weight: 600;
        color: #4a5568;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        font-size: 1rem;
    }
    
    .btn-light-clean:hover {
        background: #f7fafc;
        border-color: #cbd5e0;
        color: #2d3748;
        text-decoration: none;
    }
    
    /* Clean Table Styling */
    .users-table-container {
        background: white;
        border-radius: 16px;
        box-shadow: 0 2px 20px rgba(0,0,0,0.08);
        overflow: hidden;
        border: 1px solid #eef2f7;
    }
    
    .table-header {
        background: #f8f9fc;
        padding: 25px 30px;
        border-bottom: 1px solid #eef2f7;
    }
    
    .table-title {
        margin: 0;
        font-weight: 700;
        font-size: 1.4rem;
        color: #2d3748;
    }
    
    .clean-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 1.1rem;
    }
    
    .clean-table thead {
        background-color: #f8f9fc;
    }
    
    .clean-table th {
        padding: 20px 25px;
        text-align: right;
        font-weight: 700;
        color: #2d3748;
        border-bottom: 2px solid #e2e8f0;
        font-size: 1.1rem;
    }
    
    .clean-table td {
        padding: 20px 25px;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
        color: #4a5568;
        font-size: 1.05rem;
    }
    
    .clean-table tbody tr {
        transition: background-color 0.2s ease;
    }
    
    .clean-table tbody tr:hover {
        background-color: #f8f9fc;
    }
    
    .clean-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .user-avatar {
        width: 60px;
        height: 60px;
        border-radius: 12px;
        object-fit: cover;
        border: 2px solid #eef2f7;
    }
    
    .user-name {
        font-weight: 600;
        color: #2d3748;
        font-size: 1.1rem;
    }
    
    .user-type {
        display: inline-block;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 0.95rem;
        font-weight: 500;
        background: #f7fafc;
        color: #4a5568;
        border: 1px solid #e2e8f0;
    }
    
    .action-buttons-cell {
        display: flex;
        gap: 12px;
    }
    
    .btn-edit-clean {
        background: #2d3748;
        border: none;
        border-radius: 8px;
        padding: 12px 20px;
        color: white;
        text-decoration: none;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        font-size: 1rem;
    }
    
    .btn-edit-clean:hover {
        background: #4a5568;
        color: white;
        text-decoration: none;
        transform: translateY(-1px);
    }
    
    .btn-delete-clean {
        background: #e53e3e;
        border: none;
        border-radius: 8px;
        padding: 12px 20px;
        color: white;
        text-decoration: none;
        font-weight: 600;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        font-size: 1rem;
    }
    
    .btn-delete-clean:hover {
        background: #c53030;
        color: white;
        text-decoration: none;
        transform: translateY(-1px);
    }
    
    .serial-number {
        font-weight: 700;
        color: #2d3748;
        font-size: 1.1rem;
    }
    
    /* Waiter Badge Hover Effect */
    .badge.badge-success:hover {
        background: #38a169 !important;
        transform: scale(1.05);
        box-shadow: 0 4px 12px rgba(72, 187, 120, 0.4);
    }
    
    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 80px 20px;
        color: #718096;
    }
    
    .empty-state-icon {
        font-size: 4rem;
        color: #cbd5e0;
        margin-bottom: 20px;
    }
    
    .empty-state-text {
        font-size: 1.3rem;
        margin-bottom: 10px;
        color: #4a5568;
    }
    
    /* Responsive Design */
    @media (max-width: 768px) {
        .users-management-container {
            padding: 20px;
        }
        
        .page-header {
            padding: 25px;
        }
        
        .action-buttons {
            flex-direction: column;
            width: 100%;
            margin-top: 20px;
        }
        
        .btn-primary-clean, .btn-light-clean {
            width: 100%;
            justify-content: center;
        }
        
        .clean-table {
            display: block;
            overflow-x: auto;
        }
        
        .action-buttons-cell {
            flex-direction: column;
        }
        
        .user-avatar {
            width: 50px;
            height: 50px;
        }
        
        .clean-table th,
        .clean-table td {
            padding: 15px 20px;
        }
    }
</style>

<div class="users-management-container container">
    <?php if(isset($role) && is_array($role) && $role['show_users'] == 1){ ?>
    
    <!-- Page Header -->
    <div class="page-header">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h1 class="page-title">
                    <i class="fas fa-users mr-3"></i><?=$lang_users?>
                </h1>
            </div>
            <div class="col-md-6">
                <div class="action-buttons">
                    <a href="myroles.php" class="btn-light-clean">
                        <i class="fas fa-user-shield"></i>
                        أدوار المستخدمين
                    </a>
                    <a href="add_user.php" class="btn-primary-clean">
                        <i class="fas fa-plus-circle"></i>
                        <?=$lang_add_new?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Users Table -->
    <div class="users-table-container">
        <div class="table-header">
            <h3 class="table-title">
                <i class="fas fa-list mr-2"></i>
                قائمة المستخدمين
            </h3>
        </div>
        
        <div class="table-responsive">
            <table class="clean-table">
                <thead>
                    <tr>
                        <th width="80">#</th>
                        <th><?=$lang_username?></th>
                        <th width="120">نوع الحساب</th>
                        <th><?=$lang_userimage?></th>
                        <th width="250"><?=$lang_useroperations?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sql = "SELECT * FROM `users` order by id desc";
                    $res = $conn->query($sql);
                    
                    if($res->num_rows > 0) {
                        $x = 0;
                        while ($row = $res->fetch_assoc()) {
                            $x++;
                    ?>
                    <tr>
                        <td>
                            <span class="serial-number"><?php echo $x ?></span>
                        </td>
                        <td>
                            <div class="user-name"><?= $row['uname'] ?></div>
                        </td>
                        <td>
                            <?php if(isset($row['is_waiter']) && $row['is_waiter'] == 1): ?>
                                <a href="waiter_barcode.php?id=<?= $row['id'] ?>" 
                                   class="badge badge-success" 
                                   style="font-size: 0.95rem; padding: 8px 12px; text-decoration: none; cursor: pointer;"
                                   title="اضغط لعرض وطباعة الباركود">
                                    <i class="fas fa-user-tie"></i> ويتر
                                    <i class="fas fa-barcode ml-1"></i>
                                </a>
                            <?php else: ?>
                                <span class="badge badge-secondary" style="font-size: 0.95rem; padding: 8px 12px;">
                                    <i class="fas fa-user"></i> مستخدم
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <img class="user-avatar" src="uploads/<?= $row['img'] ?>" alt="<?= $row['uname'] ?>" 
                                 onerror="this.src='data:image/svg+xml;base64,PHN2ZyB3aWR0aD0iNjAiIGhlaWdodD0iNjAiIHZpZXdCb3g9IjAgMCA2MCA2MCIgZmlsbD0ibm9uZSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIj4KPHJlY3Qgd2lkdGg9IjYwIiBoZWlnaHQ9IjYwIiByeD0iMTIiIGZpbGw9IiNGM0Y0RjYiLz4KPHBhdGggZD0iTTMwIDM0QzM0LjQxODMgMzQgMzggMzAuNDE4MyAzOCAyNkMzOCAyMS41ODE3IDM0LjQxODMgMTggMzAgMThDMjUuNTgxNyAxOCAyMiAyMS41ODE3IDIyIDI2QzIyIDMwLjQxODMgMjUuNTgxNyAzNCAzMCAzNFoiIGZpbGw9IiNDQkNEQ0YiLz4KPHBhdGggZD0iTTQyIDQwQzQyIDQ0LjQxODMgMzguNDE4MyA0OCAzNCA0OEgyNkMyMS41ODE3IDQ4IDE4IDQ0LjQxODMgMTggNDBDMzYgNDAgNDIgNDAgNDIgNDBaIiBmaWxsPSIjQ0JDRENGIi8+Cjwvc3ZnPgo='">
                        </td>
                        <td>
                            <div class="action-buttons-cell">
                                <a class="btn-edit-clean" href="edit_user.php?id=<?= $row['id']?>">
                                    <i class="fas fa-edit"></i>
                                    
                                </a>
                                <a class="btn-delete-clean" href="do/do_deluser.php?id=<?= $row['id'] ?>" 
                                   onclick="return confirm('هل أنت متأكد من رغبتك في حذف المستخدم <?= $row['uname'] ?>؟')">
                                    <i class="fas fa-trash"></i>
                                    
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php 
                        }
                    } else { 
                    ?>
                    <tr>
                        <td colspan="6">
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-users-slash"></i>
                                </div>
                                <div class="empty-state-text">لا توجد مستخدمين</div>
                                <p>يمكنك إضافة مستخدم جديد بالنقر على زر "إضافة جديد"</p>
                            </div>
                        </td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <?php } else { ?>
    <!-- Access Denied Message -->
    <div class="access-denied-container mt-5">
        <div class="alert alert-danger text-center p-4">
            <i class="fas fa-exclamation-triangle fa-2x mb-3"></i>
            <h4>صلاحية غير كافية</h4>
            <p class="mb-0"><?= $userErrorMassage ?></p>
        </div>
    </div>
    <?php } ?>
</div>
</div>

<script>
$(document).ready(function() {
    // Confirm delete action
    $('.btn-delete-clean').on('click', function(e) {
        if(!confirm('هل أنت متأكد من رغبتك في حذف هذا المستخدم؟')) {
            e.preventDefault();
        }
    });
});
</script>

<?php include('includes/footer.php') ?>