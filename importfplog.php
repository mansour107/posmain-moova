<?php include('includes/header.php')?>
<?php include('includes/navbar.php')?>
<?php include('includes/sidebar.php')?>
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <section class="content-header">
        <div class="container-fluid">
            <h2>استيراد الملفات</h2>
        <div class="card card-secondary">
            <div class="card-header">الخطوه الاولي</div>
            <div class="card-body">
                <form action="do/doimportfp.php" method="post" enctype= multipart/form-data>
                <div class="form">
                    <p>يمكنك تحميل ملقات اكسيل فقط</p>
                    
                <input required class='form-control' type="file" name="sheet" id="">
                <select required class='form-control' name="basma_model" id="">
                    <option value="zkt">zkteco</option>
                    <option value="advision">advision</option>
                    <option value="hikvision">hikvision</option>

                </select>
                <button class='form-control btn btn-info' type="submit">تحميل</button>
                    </form>
                </div>


            </div>
        </div>
    
        </div>
    </section>
</div>






<?php include('includes/footer.php')?>
