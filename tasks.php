<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      
    <!-- Default box -->
    <div class="card card-solid">
      
    <div class="card-header">
    <div class="row">  
      <div class="col ">
          <h1>المهمات</h1>
        </div>
        <div class="col">
              <form action="tasks.php" method="post">
              <div class="input-group">
              <select name="user" id="userSelect" class="form-control">
                <option value="">الكل</option>
                <?php
                $resusr = $conn->query("SELECT * FROM users order by id");
                while ($rowusr = $resusr->fetch_assoc()) {
                ?>
                <option value="<?= $rowusr['uname']?>"><?= $rowusr['uname']?></option>
                <?php } ?>
              </select>
              <button type="submit" class="input-group-text btn btn-secondary">فلتر</button>
              </div>
              </form>
            </div>
            
          
        
        <div class="col">
          <a class="btn btn-primary float-right" href="add_task.php" id="addNewElement">جديد</a>
                </div>
      </div>
    </div>
    <div class="card-body " id="main-card">
        <div class="row d-flex align-items-stretch">
          <?php
              if ($rowstg['show_all_tasks'] == 1 ) {
               if (isset($_POST['user']) AND $_POST['user'] > 0 ) {
              $usname = $_POST['user'];
              $usres = $conn->query("SELECT id from users where uname = '$usname'")->fetch_assoc();
              $usid = $usres['id'];

              $sqltsk = "SELECT * FROM `tasks` where (isdeleted != 1 OR isdeleted IS NULL) AND user = $usid order by crtime desc;";  
            }else{
            $sqltsk = "SELECT * FROM `tasks` where (isdeleted != 1 OR isdeleted IS NULL) order by crtime desc;";  
            }
          }elseif ($_SESSION['usty'] != 1 && $_SESSION['usty'] != 2) {
            $userid = $_SESSION['userid'];
            $sqltsk = "SELECT * FROM `tasks` where user = '$userid' AND (isdeleted != 1 OR isdeleted IS NULL)  order by crtime desc;"; 
          } else {
             $sqltsk = "SELECT * FROM `tasks` where (isdeleted != 1 OR isdeleted IS NULL) order by crtime desc;";  
          }
          
          $restsk = $conn->query($sqltsk);
          $x = 0;
          while ($rowtsk = $restsk->fetch_assoc()) {
            $x++;
          ?>

            <div class="col-lg-3 ">
              <div class="card <?php
              $userid = $rowtsk['user'];
              $rowusr = $conn->query("SELECT * FROM users where id = $userid ")->fetch_assoc();
              if ($rowusr['uname']==$_SESSION['login']) {
              echo "card-primary";
              }

              ?> tskCard">
                <div class="card-header">
                  
                  
                  <div class="row">
                    <div class="col"></div>
                  <h2 class="lead"><b><?= $rowtsk['name'] ?> _ <input type="text" name="" id="" class="form-control" value="<?= $rowtsk['phone'] ?>"></b></h2>

                  </div>

                </div>
                <div class="card-body pt-0">
                <div class="row user">                    
                <?php 
                  echo $rowusr['uname']. '_>';
                  ?>
                  <?php if ($rowtsk['urgent'] == 1) {
                        echo '<div class="bg-warning">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        </div>';
                      }
                      ?>
                      <?php if ($rowtsk['important'] == 1) {

                        ?>
                                
                          <div class="bg-danger">
                            <i class="nav-icon fas fa-exclamation"></i>
                          </div>
                        <?php } ?>
                        </div>

                
                <p class="text-muted text-sm"> <?= $rowtsk['info'] ?> </p>
                <p class="text-muted text-sm">crtime <?= $rowtsk['crtime'] ?> </p>
                  <br>
                  <div class="row">
                    <div class="col">
                      <a href="edit_task.php?id=<?= $rowtsk['id'] ?>" class="btn btn-warning text-center btn-block "><?= $lang_edit ?></a>
                    </div>
                    <div class="col">
                      <a href="#" class="btn btn-danger btn-block" data-toggle="modal" data-target="#modal-danger<?=$rowtsk['id']?>">
                        انهاء
                      </a>

                      <div class="modal fade" id="modal-danger<?=$rowtsk['id']?>">
                        <div class="modal-dialog">
                          <div class="modal-content bg-danger">
                            <div class="modal-header">
                              <h4 class="modal-title">تحذير</h4>
                              <a href="#">
                                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                  <span aria-hidden="true">&times;</span>
                                </button>
                              </a>
                            </div>
                            <div class="modal-body">
                              <form action="do/dodel_task.php?id=<?= $rowtsk['id'] ?>" method="post">
                              <input name="id" type="text" value="<?= $rowtsk['id'] ?>" hidden>
                                   <p>هل تريد بالتأكيد انهاء هذه المهمة <?=$rowtsk['id']?></p>
                                  <input type="text" name="emp_comment" id="" class="form-control" placeholder="تعليق المندوب">
                                  </div>
                                  <div class="modal-footer justify-content-between">
                                   <button type="button" class="btn btn-outline-light" data-dismiss="modal">الغاء</button>
                                   <button type="submit" class="btn btn-outline-light">></button>
                               </div>
                               </form>
                          </div>
                          <!-- /.modal-content -->
                        </div>
                        <!-- /.modal-dialog -->
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>



          <?php } ?>


        </div>
      </div>
      </div>






  <!-- /.card-body -->
</section>
</div>

<script>
  $(document).ready(function(){
    $("#userSelect").on("change", function() {
      var value = $(this).val().toLowerCase();
      $(".tskCard").each(function() {
        if ($(this).text().toLowerCase().indexOf(value) > -1) {
          $(this).show();
        } else {
          $(this).hide();
        }
      });
    });
  });
</script>


<?php include('includes/footer.php') ?>