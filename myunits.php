<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                
                <div class="card-header  text-right" style="padding: 15px 20px;">
                    <h3 class="m-0" style="color: white;">
                        <i class="fas fa-balance-scale"></i>  
                    </h3>
                </div>

                <div class="card-body" style="padding: 20px;">
                    
                    <!-- نموذج الإضافة -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <form action="do/doadd_unit.php" method="post" id="myForm" class="form-inline">
                                <label class="mr-2">الوحدة الجديدة:</label>
                                <input 
                                    type="text" 
                                    class="form-control mr-2" 
                                    name="uname" 
                                    pattern=".{3,}" 
                                    title="يجب أن يكون الاسم 3 حروف على الأقل" 
                                    placeholder="مثال: كيلو، قطعة، متر..."
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
                                    <th>اسم الوحدة</th>
                                    <th width="150" class="text-center">العمليات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $x = 0;
                                $resunits = $conn->query("SELECT * FROM myunits ORDER BY id ASC");
                                
                                if ($resunits->num_rows == 0) {
                                    echo '<tr><td colspan="3" class="text-center text-muted">لا توجد وحدات</td></tr>';
                                }
                                
                                while ($rowunits = $resunits->fetch_assoc()) {
                                    $x++;
                                ?>
                                <tr>
                                    <form action="do/doedit_unit.php?id=<?= $rowunits['id'] ?>" method="post" class="d-contents">
                                        <td><?= $x ?></td>
                                        <td>
                                            <input 
                                                type="text" 
                                                name="uname" 
                                                class="form-control" 
                                                value="<?= htmlspecialchars($rowunits['uname']) ?>"
                                                required
                                            >
                                        </td>
                                        <td class="text-center">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="fas fa-edit"></i> 
                                            </button>
                                            <button 
                                                type="button" 
                                                class="btn btn-sm btn-danger" 
                                                onclick="if(confirm('هل تريد حذف هذه الوحدة؟')) window.location.href='do/dodel_unit.php?id=<?= $rowunits['id'] ?>'"
                                            >
                                                <i class="fas fa-trash"></i> 
                                            </button>
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
                        إجمالي الوحدات: <strong><?= $x ?></strong>
                    </small>
                </div>

            </div>
        </div>
    </section>
</div>

<?php include('includes/footer.php') ?>
