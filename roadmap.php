<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
<section class="content-header">
<div class="container-fluid">
    <div class="row">
        <div class="col-md-8">        
            <div class="card">
                    <div class="card-header"><h2>العمليات التمهيدية</h2></div>
                    <div class="card-body"><?php include('elements/main/data_entry.php') ?></div>
                </div>

                <div class="card">
                    <div class="card-header"><h2>الأرصدة الافتتاحية</h2></div>
                    <div class="card-body"><?php include('elements/main/start_balance.php') ?></div>
                </div>

                <div class="card">
                    <div class="card-header"><h2>العمليات اليومية</h2></div>
                    <div class="card-body"><?php include('elements/main/daily_progress.php') ?></div>
                </div>
        </div>
                <div class="col-md-4">
                <div class="card">
                    <div class="card-header"><h2>تمهيد للبرنامج</h2></div>
                    <div class="card-body">
                        <ul>
                            <li></li>
                        </ul>
                    </div>
                </div>
        </div>
    </div>




</div>    
</section>
</div>
<?php include('includes/footer.php') ?>
