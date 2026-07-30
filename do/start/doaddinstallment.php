<?php
require_once __DIR__ . '/../../includes/api_entry_classification.php';

$conn->query("UPDATE myinstallments SET ins_case = 2  where ins_case = 1 and ins_date < NOW()")
?>
