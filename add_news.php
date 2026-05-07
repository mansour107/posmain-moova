<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
    <section class="content-header">
        <div class="container-fluid">
           <div class="card card-primary col-md-8">
           <div class="card-header">
            <h3 class="hadi-wonder"> خبر جديد</h3>
           </div>
           <form action="do/doadd_news.php" method="post" enctype='multipart/form-data'>
           <div class="card-body">
            
           <div class="form-group col-md-10">
                <label for="title">عنوان الخبر</label>
                <input type="text" class="form-control" name="title">
            </div>
            
           <div class="form-group col-md-10">
                <label for="img" class="btn btn-secondary">اختار صورة</label>
                <input id="img" type="file" class="form-control" name="img" >
            </div>

            <div class="form-group col-md-12">
                <label for="content">عنوان الخبر</label>
                <textarea  id="summernote" class="" name="content">
              </textarea>
            </div>            </div>

            <div class="form-group col-md-10">
                <label for="tags">tags</label>
                <input type="text" class="form-control" name="tags">
            </div>

            <button type="submit" class="btn btn-primary btn-block">حفظ</button>


           </div>
           
           </form>




        </div>
        </div>
    </section>
</div>





<script>
  $(function () {
    // Summernote
    $('#summernote').summernote()

    // CodeMirror
    CodeMirror.fromTextArea(document.getElementById("codeMirrorDemo"), {
      mode: "htmlmixed",
      theme: "monokai"
    });
  })
</script>
<?php include('includes/footer.php') ?>
