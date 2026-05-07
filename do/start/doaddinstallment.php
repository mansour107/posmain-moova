<?php 
$conn->query("UPDATE myinstallments SET ins_case = 2  where ins_case = 1 and ins_date < NOW()")
?>