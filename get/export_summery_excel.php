<?php
$sql = "SELECT * FROM acc_head";
$result = $conn->query($sql);

// Generate Excel file
// This example uses PHPExcel library, you may need to install it via Composer
// Include PHPExcel library
require_once 'PHPExcel/Classes/PHPExcel.php';

$objPHPExcel = new PHPExcel();
$objPHPExcel->getActiveSheet()->setTitle('acc_head');

$rowCount = 1;
while ($row = $result->fetch_assoc()) {
    $col = 0;
    foreach ($row as $key => $value) {
        $objPHPExcel->getActiveSheet()->setCellValueByColumnAndRow($col, $rowCount, $value);
        $col++;
    }
    $rowCount++;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="summery.xlsx"');
header('Cache-Control: max-age=0');
$objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
$objWriter->save('php://output');

$conn->close();
?>
