<nav class="main-header navbar navbar-expand-lg border-bottom py-2" style="background-color: #ffffff;">
  <div class="container-fluid d-flex justify-content-between align-items-center">
    
    <ul class="navbar-nav align-items-center gap-2 mb-0 me-auto">
      <li class="nav-item">
        <a class="nav-link text-primary fs-5" data-widget="pushmenu" href="#" role="button">
          <i class="fas fa-bars"></i>
        </a>
      </li>

      <li class="nav-item d-none d-sm-inline-block">
        <a href="index.php" class="nav-link active fw-bold"><?=$lang_sidemain?></a>
      </li>

      <li class="nav-item d-none d-sm-inline-block">
        <a href="chances.php" class="nav-link">CRM</a>
      </li>

      <?php if($role['show_users'] == 1){ ?>
      <li class="nav-item d-none d-sm-inline-block">
        <a href="users.php" class="nav-link">المستخدمين</a>
      </li>
      <?php } ?>

      <li class="nav-item dropdown d-none d-md-inline-block">
          <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
              الإدارة
          </a>
          <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
              <li><a class="dropdown-item" href="setting.php">إعدادات النظام</a></li>
              <li><a class="dropdown-item" href="about.php">بيانات الشركة</a></li>
              <?php if (($role['show_users'] ?? 0) == 1) { ?>
              <li><a class="dropdown-item" href="moova_integration.php">ربط Moova</a></li>
              <?php } ?>
              <li><hr class="dropdown-divider"></li>
              <li><a class="dropdown-item" href="roadmap.php">خطة العمل</a></li>
          </ul>
      </li>
      
    </ul>

    <div class="d-flex align-items-center gap-3 ms-auto">
      
      <button id="exportDB" class="btn btn-primary rounded-pill px-3 py-1 fw-semibold d-none d-lg-flex">
        <i class="fas fa-database me-1"></i> حفظ نسخة احتياطية
      </button>

      <button id="fullscreenBtn" class="btn btn-light rounded-circle p-2 shadow-sm border" data-bs-toggle="tooltip" data-bs-placement="bottom" title="وضع ملء الشاشة">
        <i class="fas fa-expand text-muted"></i>
      </button>

   

      <a href="do/do_logout.php" class="logout-link d-flex align-items-center px-2 py-1 rounded-pill" data-bs-toggle="tooltip" data-bs-placement="bottom" title="تسجيل الخروج">
        <i class="fas fa-sign-out-alt me-1"></i> <span class="d-none d-sm-inline-block"><?=$lang_navlogout?></span>
      </a>
    </div>
  </div>
</nav>
<script>
  document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('fullscreenBtn');
    
    // إضافة وظيفة لتغيير الأيقونة حسب حالة Fullscreen
    const updateFullscreenIcon = () => {
        if (!document.fullscreenElement) {
            btn.innerHTML = '<i class="fas fa-expand text-muted"></i>';
        } else {
            btn.innerHTML = '<i class="fas fa-compress text-primary"></i>';
        }
    };

    btn.addEventListener('click', () => {
      if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().then(updateFullscreenIcon).catch(() => {});
      } else {
        document.exitFullscreen().then(updateFullscreenIcon).catch(() => {});
      }
    });

    // Handle full-screen change events outside of button click
    document.addEventListener('fullscreenchange', updateFullscreenIcon);
    
    // Initialize tooltips (if you are using Bootstrap's JS for tooltips)
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'))
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl)
    })
  });
</script>
