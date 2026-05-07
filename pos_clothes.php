<?php 
include('includes/pos_simple_header.php');

$posdate = date('Y-m-d', strtotime('-4 hours'));
$rowstg = $conn->query("SELECT * FROM settings WHERE id = 1")->fetch_assoc();

$success_message = '';
if(isset($_SESSION['success_message'])){
    $success_message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>

<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام نقاط البيع - الملابس</title>
    
    <link href="assets/libs/bootstrap.min.css" rel="stylesheet">
    <link href="assets/libs/fontawesome.min.css" rel="stylesheet">
    <link href="plugins/sweetalert2/sweetalert2.min.css" rel="stylesheet">
    <link href="components/pos_clothes/styles.css" rel="stylesheet">
</head>

<body>
    <?php include('components/pos_clothes/header.php'); ?>

    <?php if(!empty($success_message)): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: 'تم بنجاح!',
                text: '<?= htmlspecialchars($success_message) ?>',
                timer: 3000,
                timerProgressBar: true,
                showConfirmButton: false
            });
        });
    </script>
    <?php endif; ?>

    <div class="container-fluid py-3">
        <div class="row g-3">
            <div class="col-lg-4">
                <?php include('components/pos_clothes/order_form.php'); ?>
            </div>

            <div class="col-lg-8">
                <?php include('components/pos_clothes/items_section.php'); ?>
            </div>
        </div>
    </div>

    <?php include('components/pos_clothes/payment_modal.php'); ?>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/libs/bootstrap.bundle.min.js"></script>
    <script src="plugins/sweetalert2/sweetalert2.min.js"></script>
    <script src="components/pos_clothes/scripts.js"></script>
</body>
<style>
      input:focus {
    background-color: greenyellow !important;
    border-color: #005b39 !important;
    box-shadow: 0 0 0 0.2rem rgba(168, 252, 171, 0.25) !important;
    outline: none !important;
    transition: all 0.3s ease !important;
  }
</style>
</html>
