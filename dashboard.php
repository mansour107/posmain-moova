<?php
include('includes/header.php');
require_once __DIR__ . '/includes/page_guard.php';
page_guard(null, $conn);
include('includes/navbar.php');
include('includes/sidebar.php');
?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
<?php if(auth_guard_has_permission('erp.dashboard.main_cards', $conn)){include('elements/main/main_cards.php');} ?>
<?php if(auth_guard_has_permission('erp.dashboard.main_elements', $conn)){include('elements/main/main_element.php');} ?>
<?php if(auth_guard_has_permission('erp.dashboard.main_tables', $conn)){include('elements/main/main_tables.php');} ?>
    
      </div>                  
    </section>
  </div>
<?php include('includes/footer.php') ?>
