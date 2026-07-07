<?php include('includes/header.php') ?>
<?php include('includes/navbar.php') ?>
<?php include('includes/sidebar.php') ?>
<div class="content-wrapper">
  <section class="content-header">
    <div class="container-fluid">
<?php if(auth_guard_has_legacy_flag('show_main_cards', $conn)){include('elements/main/main_cards.php');} ?>
<?php if(auth_guard_has_legacy_flag('show_main_elements', $conn)){include('elements/main/main_element.php');} ?>
<?php if(auth_guard_has_legacy_flag('show_main_tables', $conn)){include('elements/main/main_tables.php');} ?>
    
      </div>                  
    </section>
  </div>
<?php include('includes/footer.php') ?>