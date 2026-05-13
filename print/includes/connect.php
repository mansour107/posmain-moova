
<?php
require_once __DIR__ . '/../../includes/db_bootstrap.php';

try {
    $conn = posmain_db_connect();
} catch (Throwable $e) {
    $reason = posmain_db_error_is_missing_database($e) ? 'reason=db_missing' : 'error=server_down';
    header("Location: ../pre_start.php?" . $reason);
    exit;
}

// Enable SQL error reporting
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// settings

$sqlstg = "SELECT * FROM `settings` WHERE 1";
$resstg = $conn->query($sqlstg);
$rowstg = $resstg->fetch_assoc();

$restwn = $conn->query("SELECT * from towns ");

// user powers
if (isset($_SESSION['usrole'])) {
$user_role_id = $_SESSION['usrole'];
$sqlrole = "SELECT * FROM `usr_pwrs` WHERE id = $user_role_id ";
$resrole = $conn->query($sqlrole);
$role = $resrole->fetch_assoc();}
