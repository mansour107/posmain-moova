<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>


<style> 

.fa-flag{
  font-size: 70px;

}
.fa-calendar-day{
  font-size: 70px;

}
.fa-briefcase-medical {
  font-size: 70px;

}
.fa-business-time {
  font-size: 70px;

}

.fa-clipboard {
  font-size: 70px;

}
.fa-pen {
  font-size: 66px;

}

</style>



<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
<div class="container">


  <!-- First Row -->
  <div class="row mt-5">

    <div class="col-md-6  col-lg-4 col-sm-12">
<a href="add_order.php?id=1">
      <div class="card text-dark bg-light w mb-3 me-0" style="max-width: 25rem;height:12rem;">
       
        <div class="card-body">
            <i class="nav-icon  fas fa-flag  "></i>
          <h4 class="card-text mt-5">   <?= $lang__regularleve_request?></h4>
        </div>
      </div>
</a>
    </div>

    <div class="col-md-6  col-lg-4 col-sm-12">
<a href="add_order.php?id=2">
      <div class="card text-dark bg-light mb-3" style="max-width: 30rem;height:12rem;">
        
        <div class="card-body">
        <i class="nav-icon fas fa-calendar-day "></i>
          <h4 class="card-text mt-5">   <?= $lang_annualleave_request?></h4>
        </div>
      </div>
</a>
    </div>
    <div class="col-md-6  col-lg-4 col-sm-12">

    <a href="add_order.php?id=3">
      <div class="card text-dark bg-light mb-3" style="max-width: 30rem;height:12rem;">
       
        <div class="card-body">
        <i class="nav-icon fa-light fas fa-briefcase-medical  "></i>
          <h4 class="card-text mt-5">  <?= $lang_Sickleave_request?></h4>
        </div>
      </div>
      </a>
    </div>
  </div>
  <!-- Second Row -->
  <div class="row mt-4">
    <div class="col-md-6  col-lg-4 col-sm-12">

      <div class="card text-dark bg-light mb-3" style="max-width: 30rem;height:12rem;">
      <a href="add_order.php?id=4">
        <div class="card-body">
        <i class="nav-icon  fas fa-business-time"></i>
          <h4 class="card-text mt-5">  <?= $lang_Sickleave_request?> </h4>
        </div>
      </div>
      </a>
    </div>
    <div class="col-md-6  col-lg-4 col-sm-12">
    <a href="add_order.php?id=5">
      <div class="card text-dark bg-light mb-3" style="max-width: 30rem;height:12rem;">
        
        <div class="card-body">
        <i class="nav-icon fas fa-pen"></i>
          <h4 class="card-text mt-5"> <?=$lang_Earlydeparture_request?></h4>
        </div>
      </div>
      </a>
    </div>
    <div class="col-md-6  col-lg-4 col-sm-12">
    <a href="add_order.php?id=6">
      <div class="card text-dark bg-light mb-3" style="max-width: 30rem;height: 12rem;">
       
        <div class="card-body">
        <i class="nav-icon  fas fa-clipboard "></i>
          <h4 class="card-text mt-5"> <?=$lang_all_orders?> </h4>
        </div>
      </div>
      </a>
    </div>

  </div>

  <!-- /.content -->
</div>
</div>


<?php include('includes/footer.php') ?>