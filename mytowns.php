<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">

            <div class="card">
                <div class="card-header  text-left" style="padding: 15px 20px;">
                    <h3 class="card-title" style="margin: 0; ">
                        <i class="fas fa-city"></i> 
                    </h3>
                </div>
                <div class="card-body" style="padding: 20px;">
                    
                    <!-- Add New Town -->
                    <div class="row mb-4">
                        <div class="col-md-6">
                            <form action="do/doadd_town.php" method="post" class="form-inline">
                                <input 
                                    type="text" 
                                    name="name" 
                                    required
                                    class="form-control mr-2" 
                                    placeholder="اسم المدينة الجديدة"
                                    style="flex: 1;"
                                >
                               
                            </form>
                        </div>
                    </div>

                    <!-- Towns Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover">
                            <thead class="bg-light">
                                <tr>
                                    <th width="80">#</th>
                                    <th>اسم المدينة</th>
                                    <th width="200" class="text-center">العمليات</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $x = 0;
                                $resgrb = $conn->query("SELECT * FROM towns WHERE isdeleted = 0 ORDER BY id DESC");
                                
                                if ($resgrb->num_rows == 0) {
                                    echo '<tr><td colspan="3" class="text-center text-muted">لا توجد مدن</td></tr>';
                                }
                                
                                while ($rowtwn = $resgrb->fetch_assoc()) {
                                    $x++;
                                ?>
                                <tr>
                                    <form action="do/doedit_town.php?id=<?= $rowtwn['id']?>" method="post" class="d-contents">
                                        <td><?= $x ?></td>
                                        <td>
                                            <input 
                                                type="text" 
                                                value="<?= htmlspecialchars($rowtwn['name']) ?>" 
                                                name="name"
                                                required
                                                class="form-control"
                                            >
                                        </td>
                                        <td class="text-center">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="fas fa-edit"></i> 
                                            </button>
                                            <a 
                                                href="do/dodel_town.php?id=<?= $rowtwn['id'] ?>" 
                                                onclick="return confirm('هل أنت متأكد من الحذف؟')"
                                                class="btn btn-sm btn-danger"
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
            </div>

        </div>
    </section>
</div>

<?php include('includes/footer.php') ?>
