<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                
                <div class="card-header  text-right" style="padding: 15px 20px;">
                    <h3 class="m-0" style="color: white;">
                        <i class="fas fa-layer-group"></i> 
                    </h3>
                </div>

                <div class="card-body" style="padding: 20px;">
                    
                    <!-- نموذج الإضافة -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <form action="do/doadd_group.php" method="post" class="form-inline">
                                <label class="mr-2">المجموعة الجديدة:</label>
                                <input 
                                    type="text" 
                                    class="form-control mr-2" 
                                    name="gname" 
                                    placeholder="ادخل مجموعة جديدة"
                                    required
                                    style="flex: 1;"
                                >
                             
                            </form>
                        </div>
                    </div>

                    <!-- الجدول -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="bg-light">
                                <tr>
                                    <th width="80">#</th>
                                    <th>اسم المجموعة</th>
                                    <th width="150" class="text-center">العمليات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $x = 0;
                                $resgrb = $conn->query("SELECT * FROM item_group WHERE isdeleted = 0 ORDER BY id ASC");
                                
                                if ($resgrb->num_rows == 0) {
                                    echo '<tr><td colspan="3" class="text-center text-muted">لا توجد مجموعات</td></tr>';
                                }
                                
                                while ($rowgrb = $resgrb->fetch_assoc()) {
                                    $x++;
                                ?>
                                <tr>
                                    <form action="do/doedit_group.php?id=<?= $rowgrb['id'] ?>" method="post" class="d-contents">
                                        <td><?= $x ?></td>
                                        <td>
                                            <input 
                                                type="text" 
                                                name="gname" 
                                                class="form-control" 
                                                value="<?= htmlspecialchars($rowgrb['gname']) ?>"
                                                required
                                            >
                                        </td>
                                        <td class="text-center">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="fas fa-edit"></i> 
                                            </button>
                                            <a 
                                                href="do/dodel_group.php?id=<?= $rowgrb['id'] ?>" 
                                                class="btn btn-sm btn-danger"
                                                onclick="return confirm('هل تريد حذف هذه المجموعة؟')"
                                            >
                                                <i class="fas fa-trash"></i> 
                                            </a>
                                        </td>
                                    </form>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>

                </div>

                <div class="card-footer">
                    <small class="text-muted">
                        <i class="fas fa-info-circle"></i> 
                        إجمالي المجموعات: <strong><?= $x ?></strong>
                    </small>
                </div>

            </div>
        </div>
    </section>
</div>

<?php include('includes/footer.php') ?>
