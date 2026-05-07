<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>

<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
      <div class="row">
        <div class="col-md-6">
          <div class="card card-primary">
            <div class="card-header">
              <h3 class="card-title">تغيير كلمة المرور</h3>
            </div>
            
            <form role="form" action="do/dochange_password.php" method="post" autocomplete="off">
              <div class="card-body">
                <div class="form-group">
                  <label for="current_password">كلمة المرور الحالية</label>
                  <input name="current_password" type="password" class="form-control" id="current_password" placeholder="أدخل كلمة المرور الحالية" required>
                </div>
                
                <div class="form-group">
                  <label for="new_password">كلمة المرور الجديدة</label>
                  <input name="new_password" type="password" class="form-control" id="new_password" placeholder="أدخل كلمة المرور الجديدة" required minlength="6">
                  <small class="form-text text-muted">يجب أن تكون كلمة المرور 6 أحرف على الأقل</small>
                </div>
                
                <div class="form-group">
                  <label for="confirm_new_password">تأكيد كلمة المرور الجديدة</label>
                  <input name="confirm_new_password" type="password" class="form-control" id="confirm_new_password" placeholder="أعد إدخال كلمة المرور الجديدة" required>
                </div>
              </div>
              
              <div class="card-footer">
                <button type="submit" class="btn btn-primary">تغيير كلمة المرور</button>
                <a href="dashboard.php" class="btn btn-secondary">إلغاء</a>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const newPasswordField = document.getElementById('new_password');
  const confirmPasswordField = document.getElementById('confirm_new_password');
  const form = document.querySelector('form');
  
  function validatePasswords() {
    if (newPasswordField.value !== confirmPasswordField.value) {
      confirmPasswordField.setCustomValidity('كلمات المرور غير متطابقة');
      return false;
    } else {
      confirmPasswordField.setCustomValidity('');
      return true;
    }
  }
  
  newPasswordField.addEventListener('input', validatePasswords);
  confirmPasswordField.addEventListener('input', validatePasswords);
  
  form.addEventListener('submit', function(e) {
    if (!validatePasswords()) {
      e.preventDefault();
      alert('يرجى التأكد من تطابق كلمات المرور');
    }
  });
});
</script>

<?php include('includes/footer.php') ?>
