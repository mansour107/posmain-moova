<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<style>
.content-wrapper {
    background: #f8f9fa;
}

.card {
    border: none;
    box-shadow: 0 1px 3px rgba(0,0,0,0.06);
    border-radius: 8px;
}

.card-header {
    background: #fff;
    border-bottom: 1px solid #e9ecef;
    padding: 1rem 1.25rem;
}

.card-header h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
    color: #2c3e50;
}

.form-control {
    border-radius: 6px;
    border: 1px solid #dee2e6;
}

.form-control:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}

.btn {
    border-radius: 6px;
    font-weight: 500;
}

label {
    font-weight: 500;
    color: #495057;
    margin-bottom: 0.5rem;
}
</style>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row justify-content-center">
        <div class="col-md-6">
          <div class="card">
            <div class="card-header">
              <h3><?= $lang_addjop ?></h3>
            </div>
            <form role="form" action="do/doadd_jop.php" method="post">
              <div class="card-body">
                <div class="form-group">
                  <label><?= $lang_namejop ?></label>
                  <input name="name" type="text" class="form-control" placeholder="<?= $lang_plholder_jop ?>" required>
                </div>
                <div class="form-group">
                  <label><?= $lang_publicinfo ?></label>
                  <textarea class="form-control" name="info" rows="4" placeholder="أدخل معلومات إضافية"></textarea>
                </div>
                <button type="submit" class="btn btn-success btn-block mt-3">
                  <i class="fas fa-save mr-2"></i><?= $lang_publicconfirm ?>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<?php include('includes/footer.php') ?>