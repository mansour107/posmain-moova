<?php
include '../../includes/connect.php';

// Start error logging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

try {
    $kbi_ids = $_POST['kbi_id'];
    $count = count($kbi_ids);
    
    for ($i = 0; $i < $count; $i++) {
        $kbi_id = $kbi_ids[$i];
        $kbi_rate = $_POST['kbi_rate'][$i];
        $kbi_sum = $_POST['kbi_sum'][$i];
        $kbi_weight = $_POST['kbi_weight'][$i];
        
        $query = "UPDATE emp_kbis SET kbi_rate = '$kbi_rate', kbi_sum = '$kbi_sum', kbi_weight = '$kbi_weight' WHERE id = '$kbi_id'";
        $result = $conn->query($query);
        
        if (!$result) {
            throw new Exception("Database query failed: " . $conn->error);
        }
    }
    echo "Update successful";
} catch (Exception $e) {
    error_log("Error in update_kbi.php: " . $e->getMessage());
    echo "An error occurred. Please check the error log for details.";
}
?>
