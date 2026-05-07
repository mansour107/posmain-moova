<?php
session_start();
require '../vendor/autoload.php'; // Adjust the path if needed

use PhpOffice\PhpSpreadsheet\IOFactory;

if (!isset($_FILES['sheet'])) {
echo "the file is empty";
}else{
    $sheet = $_FILES['sheet']['tmp_name'];

    $sheetnamearr = $_FILES['sheet']['name'];
    $sheetname = explode(".", $sheetnamearr);


    $sheetext = end($sheetname);
    $ext = array('xlsx','xls');


    $sheetsize = $_FILES['sheet']['size'];

    if (!in_array($sheetext , $ext)){
    echo "الملف غير صالح تأكد من الامتداد الصحيح";die;
    }
    if ($sheetsize > 20000000){
    echo "الملف غير صالح تأكد من المساحه او ارجع للمطورين؟";die;
    }
    
    if ($sheetext == 'xls'){
    echo "الملف غير صالح .. الملفات من نوع قديم ..برجاء الرجوع للدعم الفني";die;
    }




    $spreadsheet = IOFactory::load($sheet);
    $worksheet = $spreadsheet->getActiveSheet();

    $columnMapping = ['AC-No' => 0, 'NO' => 1,  'Name' => 2,'Time' => 3 ,  'State' => 4,  'New State' => 5,   'Exception' => 6,   'Operation' => 7, ];
    
    // Establish the MySQL database connection
        include('../includes/connect.php');
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Iterate through each row in the Excel sheet (start from row 2 to skip header)
    foreach ($worksheet->getRowIterator(2) as $row) {
        $rowData = [];
        foreach ($row->getCellIterator() as $cell) {
            $rowData[] = $cell->getValue();
        }

        // Extract data using column names
        $basmaid = $rowData[$columnMapping['AC-No']];
        $dateTimeStr = $rowData[$columnMapping['Time']];


        // get the employee name & id 
        $rowemp = $conn->query("SELECT * FROM employees where basma_id = '$basmaid' ")->fetch_assoc();
        if ($rowemp === null) {
        echo "الموظف ".$name." ليس موجود في قاعدة البيانات ";
        }else{
        $empname = $rowemp['id'];

        // get the shift proprties
        $shiftid = $rowemp['shift'];
        $rowshft = $conn->query("SELECT * FROM shifts where id = $shiftid")->fetch_assoc();
        $strttime = $rowshft['shiftstart'];
        $endtime = $rowshft['shiftend'];


        // Convert date string to a proper MySQL format (assuming the input is in m/d/Y h:i A format)
        list($datePart, $timePart) = explode(' ', $dateTimeStr, 2);
        // Format the date and time parts
        $formattedDate = date('Y-m-d', strtotime($datePart));
        $formattedTime = date('H:i', strtotime($timePart));
        $userid = $_SESSION['userid'];
    
        if ($formattedTime > $strttime && $formattedTime < $endtime ) {
            $instart = $rowshft['instart'];
            $inend = $rowshft['inend'];
            $outstart = $rowshft['outstart'];
            $outend = $rowshft['outend'];
            if ($formattedTime > $instart && $formattedTime < $inend) {
                $fptype = '1';
            }elseif ($formattedTime > $outstart && $formattedTime < $outend) {
                $fptype = '2';
            }else{$fptype = '5';}
        
        // Insert data into the MySQL database
        $sqlimp = "INSERT INTO  attandance ( employee ,  fptybe ,  fpdate ,  time ,  user ,  fromwhere ) VALUES ('$empname','$fptype','$formattedDate','$formattedTime','$userid','1')";}
        // Use $formattedDate for the date field
        if ($conn->query($sqlimp) !== TRUE) {
            // echo "Error: " . $conn->error;
        }
    }
}

    echo "Import completed.";

    $conn->close(); // Close the database connection
}
?>
