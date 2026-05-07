<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<style>
  .hors-inp{
    border-style:solid !important;
    border-color:black !important;
    border-width:0px 0px 2px 2px !important; 
    display:block;
    width:100%;
  }
</style>

<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
      <div class="container-fluid">


        <?php
        if ($role['show_client_profile'] == 0) {
          echo $userErrorMassage;
        }else{ ?>







        
      <div class="bg-danger">
            <?php 
$id = $_GET['id'];

$sqlcl = "SELECT * FROM `clients`  where id = '$id' ";
$rescl = $conn->query($sqlcl);
$rowcl = $rescl->fetch_assoc();
if (!isset($rowcl['id'])) {
  ?>
<h2>لقد دخلت هذه الصفحه من مكان غير المكان المخصص .. من فضلك عدم التلاعب بالعنوان..ارجع الي 
  
<a href="dashboard.php" class="btn btn-success"><h2>الرئيسية</h2></a></h2>
<?php die; } ?>
</div>
    <div class="row mb-2">
          <div class="col-sm-6">
            <h1>معلومات البطل : <span class="bg-slate-500 text-slate-50 rounded btn"><?= $rowcl['id']?></span>
            <span class="bg-slate-600 text-slate-50 rounded btn"><?= $rowcl['id']?></span></h1>
          </div>
        </div>
      </div><!-- /.container-fluid -->
    </section>

    <form action="do/doeditclprofile.php?id=<?=$rowcl['id'] ?>" method="post" id="myForm">

    <section class="content">
      <div class="container-fluid">
       

            <!-- Profile Image -->

            <?php 

$dateOfBirth = new DateTime($rowcl['dateofbirth']);
$today = new DateTime('today');
$age = $dateOfBirth->diff($today)->y;


       if ($rowcl['gender'] == 0 && $age < 12) {
        $img = 'm1.png';
    }elseif ($rowcl['gender'] == 0 && $age > 12 &&  $age < 49) {
        $img = 'm2.png';
    }elseif ($rowcl['gender'] == 0 && $age > 49) {
        $img = 'm3.png';
    }elseif ($rowcl['gender'] == 1 && $age < 12 ) {
        $img = 'f1.png';
    }elseif ($rowcl['gender'] == 1 && $age > 12 &&  $age < 49) {
        $img = 'f2.png';
    }elseif ($rowcl['gender'] == 1 && $age > 49 ) {
        $img = 'f3.png';
    }
                ?>
              
              
              
              
              <div class="row">
                <div class="col-lg-3">
                  
              <div class="card">
              <div class="card-body box-profile">
                <div class="text-center">
                  <img id="img" onerror="this.src='assets/alt/altemprofile.png';" class="profile-user-img img-fluid img-circle"
                       src="assets/ch/<?= $img?>"
                       alt="User profile picture">
                </div>

                <h3 class="profile-username text-center"><?= $rowcl['name'] ?></h3>
                <p class="profile-username text-center">السن : <?php
                  $dateOfBirth = new DateTime($rowcl['dateofbirth']);
                  $today = new DateTime('today');
                  $age = $dateOfBirth->diff($today)->y;

                  echo $age; // Output the age
                  ?>سنه

                </p>

                
       
       
                <button class="btn bg-sky-500 btn-block" type="submit">تعديل</button>
                <br>

                <?php
                $sqlrescheck = "SELECT * from reservations where client = $id";
                $rowrescheck = $conn->query($sqlrescheck);
                if ($rowrescheck->num_rows > 0) {?>

              
                <?php }?>
              </div>
              </div>
                </div>
















                <div class="col-lg-9">

                
            <div class="card card-primary">
           
           <ul class="nav nav-pills">
             <li class="nav-item">
                 <a class="nav-link active" href="#activity" data-toggle="tab">General Data</a>
             </li>
             <li class="nav-item">
                 <a class="nav-link" href="#timeline" data-toggle="tab">Visit History</a>
             </li>
             <li class="nav-item">
                 <a class="nav-link" href="#docs" data-toggle="tab">documintations</a>
             </li>
         </ul>
 
 
 
 
         <div class="card-body">
             <div class="tab-content">
                 <!-- General Data Tab -->
                 <div class="active tab-pane" id="activity">
                     <div class="post clearfix">
                         <div class="row">
                             <div class="col-lg-4 text-lg text-cyan-700">
                                 <h2 class="text-red">بيانات شخصيه</h2>
                                 <br>
                                 <?=$lang_publicname?>: <input type="text" class="form-control" value="<?= $rowcl['name'] ?>" name="name">
                                 <br>
                                <span class=""> تليفون:</span> <input class="form-control" type="text" name="phone" value="<?= $rowcl['phone']?>">
                                 <br>
                                 تليفون المسؤول: <input class="form-control" type="text" name="ref" value="<?= $rowcl['ref']?>">
                                 <br>
                                 الميلاد: <input class="form-control" type="date" value="<?= $rowcl['dateofbirth']?>" name="dateofbirth">
                                 <br>
                                 <?=$lang_usergender?>:
                                 <select class="form-control" name="gender">
                                     <option value="0" <?= $rowcl['gender'] == 0 ? 'selected' : '' ?>>ذكر</option>
                                     <option value="1" <?= $rowcl['gender'] == 1 ? 'selected' : '' ?>>انثي</option>
                                 </select>
                                 <br>
                                 العنوان: <input type="text" name="address" class="form-control" value="<?= $rowcl['address']?>">
                                 <br>
                                 المدينه:
                                 <select name="city" class="form-control">
                                     <option value="">none</option>
                                     <?php while ($rowtwn = $restwn->fetch_assoc()): ?>
                                         <option value="<?= $rowtwn['id'] ?>" <?= $rowtwn['id'] == $rowcl['city'] ? 'selected' : '' ?>><?= $rowtwn['name'] ?></option>
                                     <?php endwhile; ?>
                                 </select>
                                 <br>
                                 <textarea class="form-control" name="info" rows="5"><?= $rowcl['info'] ?></textarea>
                             </div>
 
                             <div class="col-md-5 text-lg text-cyan-700">
                                 <h2 class="text-red">بيانات رياضية</h2>
                                 <br>
                                 <p>اسم المدرب المسؤل </p>
                                 <input type="text" class="form-control" name="diseses" value="<?= $rowcl['diseses']?>">
                                 <p>مستوي السباحة</p>
                                 <input type="text" class="form-control" name="drugs" value="<?= $rowcl['drugs']?>">
                                 <p>نوع الاشتراك</p>
                                 <input type="text" class="form-control" name="seriousdes" value="<?= $rowcl['seriousdes']?>">
                                 <p>امراض مزمنة -<i Class="text-sm text-zinc-100"> ان وجد </i></p>
                                 <input type="text" class="form-control" name="familydes" value="<?= $rowcl['familydes']?>">
                                 <p>معلومات اضافية</p>
                                 <input type="text" class="form-control" name="allergy" value="<?= $rowcl['allergy']?>">
                             </div>
 
                             <div class="col-lg-3 text-lg text-cyan-700">
                                 <h2 class="text-red">معدلات صحية</h2>
                                 <br>
                                 <p>M B I</p>
                                 <input name="diabetes" value="<?= $rowcl['diabetes'] ?>" type="number" class="form-control">
                                 <br>
                                 <p>الضغط</p>
                                 <input name="pressure" value="<?= $rowcl['pressure'] ?>" type="text" class="form-control">
                                 <br>
                                 <p>معدل التنفس</p>
                                 <input name="brate" value="<?= $rowcl['brate'] ?>" type="number" class="form-control">
                                 <br>
                                 <p>كثافة الجسم</p>
                                 <input name="temp" value="<?= $rowcl['temp'] ?>" type="number" class="form-control">
                                 <br>
                                 <p>الطول</p>
                                 <input name="height" value="<?= $rowcl['height'] ?>" type="number" class="form-control">
                                 <br>
                                 <p>الوزن</p>
                                 <input name="weight" value="<?= $rowcl['weight'] ?>" type="number" class="form-control">
                             </div>
                         </div>
                     </div>
                 </div>
 
                 <!-- Visit History Tab -->
                 <div class="tab-pane" id="timeline">
                     <div class="post clearfix">
                         <h3>تاريخ الزيارات ل<?= $rowcl['name'] ?></h3>
                         <div class="table-responsive">
                             <table id="myTable" class="table table-responsive table-hover table-striped table-center">
                                 <thead>
                                     <tr>
                                         <th>#</th>
                                         <th>تاريخ</th>
                                         <th>الكارت</th>
                                         <th>الباقي</th>
                                     </tr>
                                 </thead>
                                 <tbody>
                                 <?php
                                 $clid = $rowcl['id'];
                                 $n1r = $conn->query("SELECT name FROM clients where id = $clid")->fetch_assoc();
                                 $n1 = $n1r['name'];

                                 $resnot = $conn->query("SELECT * FROM notes where n1 = '$n1' order by id desc");
                                 $x = 0;
                                 while ($rownote = $resnot->fetch_assoc()) {
                                     $x++;
                                       ?>
                                     <tr class="rounded" style="border:2px solid black;font-size:40px !important;">
                                         <td><?= $x ?></td>
                                         <td><?= $rownote['crtime'] ?></td>
                                         <td><?= $rownote['n2'] ?></td>
                                         <td><?= $rownote['n3'] ?></td>
                                     </tr>
                                   
                             
                                 <?php } ?>
                                 </tbody>
                             </table>
                         </div>
                     </div>
                 </div>

                 <div class="tab-pane" id="docs">
                     <div class="post clearfix">

                    <button type="button" class="btn bg-sky-200" id="tash" data-toggle="modal" data-target="#addDocModal">
                      اضافه ملفات
                    </button>
<div class="row">
                            <?php
                            $resdoc = $conn->query("SELECT * FROM imgs where clprofile = $id " );
                            while ($rowdoc = $resdoc->fetch_assoc()) {
                            ?>
                            <div class="card col-sm-3" style="float:left">
                              <div class="card-body">

                              <?= $rowdoc['img_date'] ?>
                              <br>
                              <button type="button" class="" id="img-logo" data-toggle="modal" data-target="#imgModal<?= $rowdoc['id'] ?>"><img src="uploads/<?= $rowdoc['iname'] ?>" alt="" style="width:150px;height:auto"></button>  
                            </div>
                            </div>

                            
                                    <!-- Modal Element -->
                            <div class="modal fade" id="imgModal<?= $rowdoc['id'] ?>" tabindex="-1" role="dialog" aria-labelledby="imgModalLabel<?= $rowdoc['id'] ?>" aria-hidden="true">
                                <div class="modal-dialog modal-xl" role="document">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title" id="imgModalLabel<?= $rowdoc['id'] ?>">Image Preview</h5>
                                            <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </div>
                                        <div class="modal-body">
                                            <img src="uploads/<?= htmlspecialchars($rowdoc['iname'], ENT_QUOTES, 'UTF-8') ?>" alt="Document Image <?= $rowdoc['id'] ?>" class="img-fluid">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            


                            <?php } ?>
                    </div>
                  </div>
                  </div>








             </div>
         </div>
                </div>
              </div>
       



  











              <?php } ?>

    </div>
    </section>
    </form>
        </div>
    
             
    <div class="modal fade" id="modal1">
        <div class="modal-dialog">
        <form action="do/doadd_dis.php?id=<?= $rowcl['id'] ?>" method="post">  
        <div class="modal-content">
          
            <div class="modal-header">
              <h4 class="modal-title">اضافه تشخيص ل<?= $rowcl['name'] ?></h4>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">
              <textarea class="" type="text" name="diseses" placeholder="اكتب اخر تشخيص" cols="30" rows="10"></textarea>              

          </div>
            <div class="modal-footer justify-content-between">
              <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>

              <button type="submit"  class="btn btn-primary">حفظ</button>
            </div>
          </div>
          </form>
        </div>
      </div>
    
      

      
             
      
      <div class="modal fade" id="modal-2">
        <div class="modal-dialog modal-md">
          <div class="modal-content ">
          <form action="do/doadd_presc.php?id=<?= $rowcl['id']?>" method="post">
            <div class="modal-header">
              <h4 class="modal-title">اضافه روشته ل <?= $rowcl['name'] ?></h4>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
              <div class="card">
                <div class="card-body">
                  <div class="form-group">
                    <div class="label text-lg">التحاليل المطلوبه</div>
                    <input type="text" class="form-control" name="analyses">
                  </div>
                  <h1 class="text-lg">الأدوية</h1>

                  <div class="frstitem" id="frstitem">
                  
                        <select name="drug[]" id="mySelectDrug" class="select2 form-control">
                          <?php $resdrg = $conn->query("SELECT * FROM drugs order by name");
                          while ($rowdrg = $resdrg->fetch_assoc()) {
                          ?>
                        <option value="<?= $rowdrg['id'] ?>"><?= $rowdrg['name'] ?></option>
                        <?php } ?>
                      </select>
                        <input type="text" class="form-control" name="dose[]" placeholder="الجرعه">    
                      </div>
                      <div class="endline" id="endline"></div>

                      <div id="addmed" class="btn btn-secondary btn-block col-md-4">+</div>
                      
                    </div>
              </div>
            </div>
            
            <div class="modal-footer justify-content-between">
            <button type="submit" class="btn btn-lg btn-primary" id="qq">تأكيد</button>
            <button type="button" class="btn btn-light" data-dismiss="modal">Close</button>

            </div>
            </form>
          </div>
         
        </div>
        
      </div>


            
    <div class="modal fade" id="addDocModal">
        <div class="modal-dialog">
        <form action="do/doadd_docs.php?id=<?= $rowcl['id'] ?>" method="post" enctype="multipart/form-data">  
        <div class="modal-content">
          
            <div class="modal-header">
              <h4 class="modal-title"> اضافة ملفات ل<?= $rowcl['name'] ?></h4>
              <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
            <div class="modal-body">

            <div class="form-group">
              <label for="imgs">اضف أكتر من صورة</label>
              <input required type="file" name="imgs[]" id="imgs" class="form-control" multiple="multiple">
            </div> 

            
            <div class="form-group">
              <label for="date">تاريخ الوثيقة</label>
              <input type="date" name="img_date" id="img_date" class="form-control">
            </div> 
            
            اضف بعض الوثائق للملف    
          </div>
            <div class="modal-footer justify-content-between">
              <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>

              <button type="submit"  class="btn btn-primary">حفظ</button>
            </div>
          </div>
          </form>
        </div>
      </div>
      



 

  <script>
$(document).ready(function(){
  $("#addmed").click(function(){
    $line = $("#frstitem").html();
    $("#endline").before($line);
  })});
</script>



<script>
    $(function() {
        let startTime, endTime;

        function formatTime(date) {
            let hours = date.getHours().toString().padStart(2, '0');
            let minutes = date.getMinutes().toString().padStart(2, '0');
            return hours + ':' + minutes;
        }

        $('#startButton').click(function() {
            const id = new URLSearchParams(window.location.search).get('id'); // Extracting 'id' from URL
            startTime = new Date();
           $start2 =  $('#startTime').val(formatTime(startTime));
            
            $.ajax({
                url: 'js/ajax/insert_time.php?q=0&id=' + id,
                type: 'POST',
                data: {
                    start_time: formatTime(startTime),
                    end_time: formatTime(startTime), // Initially setting end_time to start_time
                },
                success: function(response) {
                    $('#response').html(response);
                },
                error: function(xhr, status, error) {
                    $('#response').html('Error: ' + error);
                }
            });
        });
    });
</script>
<script>
  $(function() {
        let startTime, endTime;

        function formatTime(date) {
            let hours = date.getHours().toString().padStart(2, '0');
            let minutes = date.getMinutes().toString().padStart(2, '0');
            return hours + ':' + minutes;
        }

  $('#endButton').click(function() {
    const id = new URLSearchParams(window.location.search).get('id'); // Extracting 'id' from URL
    endTime = new Date();
    $('#endTime').val(formatTime(endTime));
    
    $.ajax({
        url: 'js/ajax/insert_time.php?q=1&id=' + id,
        type: 'POST',
        data: {
            end_time: formatTime(endTime),
            start_time: $('#startTime').val() // Corrected to get the value of start time
        },
        success: function(response) {
            $('#response').html(response);
            console.log(response); // Logging the response from the server
        },
        error: function(xhr, status, error) {
            $('#response').html('Error: ' + error);
        }
    });
});
  })
</script>



        <script>
        $(document).ready(function() {
                if (event.key === 'Enter') {
                    event.preventDefault();
                }
            });
        
        </script>

        
<script>
      document.getElementById('img_date').valueAsDate = new Date();
</script>

<?php include('includes/footer.php') ?>