<?php include('../includes/connect.php');
require_once __DIR__ . '/../classes/Sync/OperationalSyncRecorder.php';

$id = (int) ($_GET['id'] ?? 0);

$conn->query("UPDATE employees SET isdeleted = 1 where id = $id");
if ($id > 0) {
    posmain_record_operational_row_sync($conn, 'employee', $id, 'employee_delete', 'employee.saved');
}
header('location:../employees.php');
