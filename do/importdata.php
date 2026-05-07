<?php 
 
// Load the database configuration file 
include_once '../includes/connect.php'; 
 
// Include PhpSpreadsheet library autoloader 
require_once '../vendor/autoload.php'; 
use PhpOffice\PhpSpreadsheet\Reader\Xlsx; 
 
if(isset($_POST['importSubmit'])){ 
     
    // Allowed mime types 
    $excelMimes = array('text/xls', 'text/xlsx', 'application/excel', 'application/vnd.msexcel', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); 
     
    // Validate whether selected file is a Excel file 
    if(!empty($_FILES['file']['name']) && in_array($_FILES['file']['type'], $excelMimes)){ 
         
        // If the file is uploaded 
        if(is_uploaded_file($_FILES['file']['tmp_name'])){ 
            $reader = new Xlsx(); 
            $spreadsheet = $reader->load($_FILES['file']['tmp_name']); 
            $worksheet = $spreadsheet->getActiveSheet();  
            $worksheet_arr = $worksheet->toArray(); 
 
            // Remove header row 
            unset($worksheet_arr[0]); 
 
            foreach($worksheet_arr as $row){ 
                $name = $row[0]; 
                $last_name = $row[1]; 
                $email = $row[2]; 
                $phone = $row[3]; 
                $status = $row[4]; 
 
                // Check whether member already exists in the database with the same email 
                $prevQuery = "SELECT id FROM test WHERE name = '".$name."'"; 
                $prevResult = $conn->query($prevQuery); 
                 
                if($prevResult->num_rows > 0){ 
                    // Update member data in the database 
                    $conn->query("UPDATE test SET first_name = '".$name."', last_name = '".$last_name."', email = '".$email."', phone = '".$phone."', status = '".$status."', modified = NOW() WHERE email = '".$email."'"); 
                }else{ 
                    // Insert member data in the database 
                    $conn->query("INSERT INTO test (first_name, last_name, email, phone, status, created, modified) VALUES ('".$first_name."', '".$last_name."', '".$email."', '".$phone."', '".$status."', NOW(), NOW())"); 
                } 
            } 
             
            $qstring = '?status=succ';echo "<pre>"; print_r($worksheet_arr); echo "</pre>";
        }else{ 
            $qstring = '?status=err'; 
        } 
    }else{ 
        $qstring = '?status=invalid_file'; 
    } 
} 
 
 
?>