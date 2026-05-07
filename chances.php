<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
<style>
    /* Modern Dashboard Styles */
    .dashboard-container {
        padding: 20px;
        background-color: #f8f9fa;
        min-height: 100vh;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: white;
        border-radius: 12px;
        padding: 25px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.08);
        text-align: center;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        border-left: 4px solid #667eea;
    }
    
    .stat-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.12);
    }
    
    .stat-card h3 {
        font-size: 2.2rem;
        margin-bottom: 8px;
        color: #2d3748;
        font-weight: 700;
    }
    
    .stat-card p {
        color: #718096;
        font-size: 1rem;
        margin: 0;
    }
    
    .add-chance-btn {
        position: fixed;
        top: 100px;
        left: 30px;
        width: 70px;
        height: 70px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        z-index: 1000;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.8rem;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .add-chance-btn:hover {
        transform: scale(1.1);
        box-shadow: 0 8px 25px rgba(102, 126, 234, 0.6);
    }
    
    /* Kanban Board Styling */
    .kanban-board {
        display: flex;
        gap: 20px;
        overflow-x: auto;
        padding: 20px 0;
        min-height: 600px;
    }
    
    .kanban-column {
        flex: 0 0 320px;
        background-color: #f8f9fa;
        border-radius: 12px;
        padding: 0;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        position: relative;
        transition: all 0.3s ease;
    }
    
    .kanban-column:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }
    
    .kanban-column.drag-over {
        background-color: #e3f2fd;
        border: 2px dashed #2196f3;
    }
    
    .column-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 12px 12px 0 0;
        padding: 20px;
        font-weight: bold;
        position: sticky;
        top: 0;
        z-index: 10;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .column-count {
        background: rgba(255, 255, 255, 0.3);
        border-radius: 20px;
        padding: 4px 12px;
        font-size: 0.9rem;
    }
    
    .kanban-cards {
        padding: 15px;
        min-height: 400px;
        max-height: 500px;
        overflow-y: auto;
    }
    
    .chance-card {
        transition: all 0.3s ease;
        border-radius: 10px;
        box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        margin-bottom: 15px;
        cursor: grab;
        position: relative;
        background: white;
        border-left: 4px solid #dee2e6;
        overflow: hidden;
    }
    
    .chance-card:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.12);
    }
    
    .chance-card.dragging {
        transform: rotate(5deg);
        opacity: 0.8;
        cursor: grabbing;
        z-index: 1000;
    }
    
    .priority-high {
        border-left: 4px solid #dc3545;
    }
    
    .priority-medium {
        border-left: 4px solid #ffc107;
    }
    
    .priority-low {
        border-left: 4px solid #28a745;
    }
    
    .card-header {
        padding: 15px 15px 10px;
        border-bottom: 1px solid #f1f1f1;
    }
    
    .card-title {
        font-weight: 600;
        font-size: 1.1rem;
        margin-bottom: 5px;
        color: #2d3748;
    }
    
    .card-body {
        padding: 10px 15px;
    }
    
    .card-footer {
        padding: 10px 15px;
        background-color: #f8f9fa;
        border-top: 1px solid #f1f1f1;
        display: flex;
        justify-content: space-between;
    }
    
    .user-badge {
        background-color: #e3f2fd;
        color: #1976d2;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.85rem;
        font-weight: 500;
    }
    
    .time-badge {
        background-color: #f5f5f5;
        color: #666;
        padding: 4px 8px;
        border-radius: 6px;
        font-size: 0.8rem;
    }
    
    .priority-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 0.8rem;
        font-weight: 500;
        margin-top: 8px;
    }
    
    .priority-high-badge {
        background-color: #ffebee;
        color: #c62828;
    }
    
    .priority-medium-badge {
        background-color: #fff8e1;
        color: #f57f17;
    }
    
    .priority-low-badge {
        background-color: #e8f5e9;
        color: #2e7d32;
    }
    
    .action-buttons {
        display: flex;
        gap: 5px;
        margin-top: 10px;
    }
    
    .action-btn {
        flex: 1;
        padding: 8px 5px;
        font-size: 0.85rem;
        border-radius: 6px;
        text-align: center;
        transition: all 0.2s ease;
    }
    
    .action-btn:hover {
        transform: translateY(-2px);
    }
    
    .btn-edit {
        background-color: #e3f2fd;
        color: #1976d2;
        border: none;
    }
    
    .btn-assign {
        background-color: #e8f5e9;
        color: #2e7d32;
        border: none;
    }
    
    .btn-delete {
        background-color: #ffebee;
        color: #c62828;
        border: none;
    }
    
    /* Modal Styling */
    .modal-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-radius: 10px 10px 0 0;
    }
    
    .modal-title {
        font-weight: 600;
    }
    
    .form-label {
        font-weight: 500;
        color: #4a5568;
        margin-bottom: 8px;
    }
    
    .form-control, .form-select {
        border-radius: 8px;
        padding: 10px 15px;
        border: 1px solid #e2e8f0;
        transition: all 0.2s ease;
    }
    
    .form-control:focus, .form-select:focus {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        border-radius: 8px;
        padding: 10px 20px;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
    }
    
    @media (max-width: 768px) {
        .kanban-board {
            flex-direction: column;
        }
        .kanban-column {
            flex: 1;
            margin-bottom: 20px;
        }
        
        .stats-grid {
            grid-template-columns: 1fr;
        }
        
        .add-chance-btn {
            top: 20px;
            left: 20px;
            width: 60px;
            height: 60px;
            font-size: 1.5rem;
        }
    }
</style>

<div class="dashboard-container container">
    <!-- Statistics Dashboard -->
    <div class="stats-grid">
        <div class="stat-card">
            <?php 
            $total_chances = $conn->query("SELECT COUNT(*) as total FROM tasks WHERE isdeleted = 0")->fetch_assoc()['total'];
            ?>
            <h3><?= $total_chances ?></h3>
            <p>إجمالي الفرص</p>
        </div>
        <div class="stat-card">
            <?php 
            $high_priority = $conn->query("SELECT COUNT(*) as total FROM tasks WHERE isdeleted = 0 AND important = 2")->fetch_assoc()['total'];
            ?>
            <h3><?= $high_priority ?></h3>
            <p>فرص مهمة جداً</p>
        </div>
        <div class="stat-card">
            <?php 
            $today_chances = $conn->query("SELECT COUNT(*) as total FROM tasks WHERE isdeleted = 0 AND DATE(crtime) = CURDATE()")->fetch_assoc()['total'];
            ?>
            <h3><?= $today_chances ?></h3>
            <p>فرص اليوم</p>
        </div>
        <div class="stat-card">
            <?php 
            $active_types = $conn->query("SELECT COUNT(DISTINCT ch_tybe) as total FROM tasks WHERE isdeleted = 0")->fetch_assoc()['total'];
            ?>
            <h3><?= $active_types ?></h3>
            <p>أنواع نشطة</p>
        </div>
    </div>

    <!-- Add Chance Button -->
    <button id="addChanceBtn" type="button" class="add-chance-btn" data-toggle="modal" data-target="#addchance">
        +
    </button>

    <!-- Kanban Board -->
    <div class="kanban-board">
        <?php
        $sqlchatyb = "SELECT * FROM chances_tybes";
        $reschatyb = $conn->query($sqlchatyb);
        while ($rowchatyb = $reschatyb->fetch_assoc()) {
            $tybid = $rowchatyb['id'];
            $sqlcha = "SELECT * FROM tasks where ch_tybe = $tybid AND isdeleted = 0 ";
            $rescha = $conn->query($sqlcha);
            $count = $rescha->num_rows;
        ?>
        <div class="kanban-column">
            <div class="column-header">
                <h5><?= $rowchatyb['cname'] ?></h5>
                <span class="column-count"><?= $count ?></span>
            </div>
            <div class="kanban-cards">
                <?php 
                while ($rowcha = $rescha->fetch_assoc()) {
                    $usid = $rowcha['user'];
                    $rowusr = $conn->query("SELECT * FROM users where id = $usid")->fetch_assoc();
                    
                    // Determine priority class and badge
                    $priority_class = '';
                    $priority_badge = '';
                    $priority_text = '';
                    
                    if ($rowcha['important'] == 0) {
                        $priority_class = 'priority-low';
                        $priority_badge = 'priority-low-badge';
                        $priority_text = 'غير مهم';
                    } else if ($rowcha['important'] == 1) {
                        $priority_class = 'priority-medium';
                        $priority_badge = 'priority-medium-badge';
                        $priority_text = 'مهم';
                    } else if ($rowcha['important'] == 2) {
                        $priority_class = 'priority-high';
                        $priority_badge = 'priority-high-badge';
                        $priority_text = 'مهم جداً';
                    }
                ?>
                <div class="chance-card <?= $priority_class ?>">
                    <div class="card-header">
                        <div class="card-title"><?= $rowcha['name'] ?></div>
                        <span class="user-badge"><?= $rowusr['uname'] ?></span>
                    </div>
                    <div class="card-body">
                        <div class="time-badge"><?= $rowcha['crtime'] ?></div>
                        <div class="priority-badge <?= $priority_badge ?>"><?= $priority_text ?></div>
                    </div>
                    <div class="card-footer">
                        <div class="action-buttons">
                            <button type="button" class="action-btn btn-edit" data-toggle="modal" data-target="#editchance<?= $rowcha['id']?>">
                                تعديل
                            </button>
                            <a href="edit_task.php?id=<?= $rowcha['id'] ?>" class="action-btn btn-assign">
                                تعهيد
                            </a>
                            <button type="button" class="action-btn btn-delete" data-toggle="modal" data-target="#delchance<?= $rowcha['id']?>">
                                حذف
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Edit Modal -->
                <div class="modal fade" id="editchance<?= $rowcha['id']?>">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">تعديل الفرصة <?= $rowcha['id']?></h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <form action="do/doedit_chance.php?id=<?= $rowcha['id']?>" method="post">
                                <div class="modal-body">
                                    <div class="form-group">
                                        <label class="form-label">اسم العميل</label>
                                        <input value="<?= $rowcha['name']?>" name="name" type="text" class="form-control" placeholder="اسم العميل" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">تليفون العميل</label>
                                        <input value="<?= $rowcha['phone']?>" name="phone" type="text" class="form-control" placeholder="تليفون العميل" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">مستوى الأولوية</label>
                                        <select name="important" class="form-control" required>
                                            <option <?= $rowcha['important'] == 0 ? 'selected' : '' ?> value="0">غير مهم</option>
                                            <option <?= $rowcha['important'] == 1 ? 'selected' : '' ?> value="1">مهم</option>
                                            <option <?= $rowcha['important'] == 2 ? 'selected' : '' ?> value="2">مهم جداً</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">نوع الفرصة</label>
                                        <select name="ch_tybe" class="form-control" required>
                                            <?php 
                                            $restybe = $conn->query("SELECT * FROM chances_tybes order by id");
                                            while ($rowtybe = $restybe->fetch_assoc()) {
                                            ?>
                                                <option <?= $rowtybe['id'] == $rowcha['ch_tybe'] ? 'selected' : '' ?> value="<?= $rowtybe['id'] ?>"><?= $rowtybe['cname']?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label">المسؤول</label>
                                        <select name="user" class="form-control" required>
                                            <?php 
                                            $resuser = $conn->query("SELECT * FROM users order by uname");
                                            while ($rowuser = $resuser->fetch_assoc()) {
                                            ?>
                                                <option <?= $rowuser['id'] == $rowcha['user'] ? 'selected' : '' ?> value="<?= $rowuser['id'] ?>"><?= $rowuser['uname']?></option>
                                            <?php } ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-primary">حفظ التغييرات</button>
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                
                <!-- Delete Modal -->
                <div class="modal fade" id="delchance<?= $rowcha['id']?>">
                    <div class="modal-dialog" role="document">
                        <div class="modal-content">
                            <div class="modal-header bg-danger text-white">
                                <h5 class="modal-title">حذف الفرصة <?= $rowcha['id']?></h5>
                                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                                    <span aria-hidden="true">&times;</span>
                                </button>
                            </div>
                            <form action="do/dodel_task.php?id=<?= $rowcha['id']?>" method="post">
                                <div class="modal-body">
                                    <p>يرجى التأكد من رغبتك في حذف هذه الفرصة</p>
                                    <input value="<?= $rowcha['id']?>" name="id" type="hidden">
                                    <div class="form-group">
                                        <label class="form-label">تعليق الحذف</label>
                                        <input value="<?= $rowcha['emp_comment']?>" name="emp_comment" type="text" class="form-control" placeholder="تعليق المندوب">
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="submit" class="btn btn-danger">تأكيد الحذف</button>
                                    <button type="button" class="btn btn-secondary" data-dismiss="modal">إلغاء</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>
    </div>
</div>

<!-- Add Chance Modal -->
<div class="modal fade" id="addchance">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-plus-circle"></i> إضافة فرصة جديدة</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <form action="do/doadd_chance.php" method="post" id="addChanceForm">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="name" class="form-label"><i class="fas fa-user"></i> اسم العميل</label>
                                <input name="name" id="name" type="text" class="form-control" placeholder="اسم العميل" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="phone" class="form-label"><i class="fas fa-phone"></i> رقم الهاتف</label>
                                <input name="phone" id="phone" type="tel" class="form-control" placeholder="رقم الهاتف" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="important" class="form-label"><i class="fas fa-flag"></i> مستوى الأولوية</label>
                                <select name="important" class="form-control" id="important" required>
                                    <option value="0">غير مهم</option>
                                    <option value="1">مهم</option>
                                    <option value="2">مهم جداً</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="tybe" class="form-label"><i class="fas fa-tags"></i> نوع الفرصة</label>
                                <select name="tybe" class="form-control" id="tybe" required>
                                    <?php 
                                    $restybe = $conn->query("SELECT * FROM chances_tybes order by cname");
                                    while ($rowtybe = $restybe->fetch_assoc()) {
                                    ?>
                                        <option value="<?= $rowtybe['id'] ?>"><?= $rowtybe['cname']?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="user" class="form-label"><i class="fas fa-user-tie"></i> المسؤول</label>
                                <select name="user" class="form-control" id="user" required>
                                    <?php 
                                    $resuser = $conn->query("SELECT * FROM users order by uname");
                                    while ($rowuser = $resuser->fetch_assoc()) {
                                    ?>
                                        <option value="<?= $rowuser['id'] ?>"><?= $rowuser['uname']?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cdate" class="form-label"><i class="fas fa-calendar"></i> تاريخ الفرصة</label>
                                <input name="cdate" id="cdate" type="date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> حفظ الفرصة</button>
                    <button type="button" class="btn btn-secondary" data-dismiss="modal"><i class="fas fa-times"></i> إلغاء</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    // Focus on name field when add modal opens
    $('#addchance').on('shown.bs.modal', function () {
        $('#name').focus();
    });
    
    // Form validation
    $('#addChanceForm').on('submit', function(e) {
        var name = $('#name').val().trim();
        var phone = $('#phone').val().trim();
        
        if (!name) {
            alert('يرجى إدخال اسم العميل');
            e.preventDefault();
            return false;
        }
        
        if (!phone) {
            alert('يرجى إدخال رقم الهاتف');
            e.preventDefault();
            return false;
        }
    });
    
    // Smooth animations for cards
    $('.chance-card').hover(
        function() { $(this).addClass('shadow-lg'); },
        function() { $(this).removeClass('shadow-lg'); }
    );
});
</script>

</div>

<?php include('includes/footer.php') ?>