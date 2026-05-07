<?php include('includes/header.php') ?>
<?php include('includes/sidebar.php') ?>
<?php include('includes/navbar.php') ?>

<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">

        <?php if ($role['show_attandance'] == 1) { ?>
            <div class="card card-secondary">
                <div class="card-header">
                    <h3 class="">ادارة الاذونات</h3>
                </div>
            </div>






            <?php }else{echo $userErrorMassage;} ?>
        </div>
    </section>
</div>
<?php include('includes/footer.php') ?>