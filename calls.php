<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <section class="content-header">
        <div class="container-fluid">
            <div class="card">
                <div class="card-header">
                    <div class="row">
                        <div class="col">
                        <h3>ادارة المكالمات</h3>
                        </div>
                        <div class="col">
                            <a class="btn btn-primary right" href="add_call.php">مكالمة جديدة</a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table table-responsive">
                        <table id="myTable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>الموضوع</th>
                                    <th>العميل</th>
                                    <th>نوع المكالمة</th>
                                    <th>وقت المكالمة</th>
                                    <th>مدة المكالمة</th>
                                    <th>تعليق الموظف</th>
                                    <th>محتوي المكالمة</th>
                                    <th>المكالمة القادمة</th>
                                    <th>تعليق المراجع</th>
                                    <th>تقييم المكالمة</th>
                                </tr>
                            </thead>
                            <tbody>
                            
                                <tr>
                                    <td>#</td>
                                    <td>الموضوع</td>
                                    <td>العميل</td>
                                    <td>نوع المكالمة</td>
                                    <td>وقت المكالمة</td>
                                    <td>مدة المكالمة</td>
                                    <td>تعليق الموظف</td>
                                    <td>محتوي المكالمة</td>
                                    <td>المكالمة القادمة</td>
                                    <td>تعليق المراجع</td>
                                    <td>تقييم المكالمة</td>
                                </tr>
    
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th>#</th>
                                    <th>الموضوع</th>
                                    <th>العميل</th>
                                    <th>نوع المكالمة</th>
                                    <th>وقت المكالمة</th>
                                    <th>مدة المكالمة</th>
                                    <th>تعليق الموظف</th>
                                    <th>محتوي المكالمة</th>
                                    <th>المكالمة القادمة</th>
                                    <th>تعليق المراجع</th>
                                    <th>تقييم المكالمة</th>
                                </tr>
                            </tfoot>

                        </table>
                    </div>

                </div>
                <div class="card-footer">

                </div>
            </div>





        </div>
    </section>
</div>




<?php include('includes/footer.php') ?>
