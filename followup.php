<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>



<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">



    <div class="card">
        <div class="card-header">
            <div class="row">
                <div class="col"><h3>Follow UP</h3></div>

                <div class="col">
                    <a class="btn btn-primary btn-lg" href="add_task.php">جديد</a>
                </div>
            </div><div class="row">
                <div class="col-md-4"><input type="text" class="form-control" placeholder="filter" id="itmsearch"></div>
            </div>
        </div>
        <div class="card-body">

    <div class="table table-responsive">
        <table>
            <thead>
                <tr >
                    <th>م</th>
                    <th>تاريخ</th>
                    <th>الاسم</th>
                    <th>التليفون</th>
                    <th>تعليق المندوب</th>
                    <th>تعليق العميل</th>
                    <th>عمليات</th>
                </tr>
            </thead>
            <tbody id="itmTable">
                <?php
                $restsk = $conn->query("SELECT * FROM tasks where isdeleted = 1 order by mdtime desc ");
                $x = 0;
                while ($rowtsk = $restsk->fetch_assoc()) {
                $x++;
                ?>
                <tr class="tr1"> 
                <td><?= $x ?></td>
                    <td><?= $rowtsk['crtime'] ?></td>
                    <td><?= $rowtsk['name'] ?></td>
                    <td><?= $rowtsk['phone'] ?></td>
                    <td><?= $rowtsk['emp_comment'] ?></td>
                    <td><?= $rowtsk['cl_comment'] ?></td>
                    <td>
                        <a href="edit_task.php?id=<?= $rowtsk['id'] ?>" class="btn btn-warning btn-sm">تعديل</a>
                        <button class="btn btn-danger btn-sm" data-toggle="modal" data-target="#deltask<?= $rowtsk['id']?>" >حذف</button>
                    </td>
                </tr>




                <div class="modal fade" id="deltask<?= $rowtsk['id']?>">
            
            <div class="modal-dialog" role="document">
              <div class="modal-content bg-warning">
                <div class="modal-header">
                  <h5 class="modal-title">حذف المهمة<?= $rowtsk['id']?></h5>
                  <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                  </button>
                </div>
          <div class="modal-body">
            <p>هل تريد بالتأكيد حذف المهمة <?= $rowtsk['id'] ?></p>
            
          </div>
        
          
          <div class="modal-footer">

          <a href="do/dodel_follow.php?id=<?= $rowtsk['id']?>" class="btn btn-danger">حذف</a>

              <button type="reset" class="btn btn-secondary " data-dismiss="modal">اغلاق</button>
            
            </div>

        
        
        </div>
            </div>
                </div>








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