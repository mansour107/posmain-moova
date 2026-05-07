<?php 
include_once '../includes/connect.php'; 

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx; 
 
    // Allowed mime types 
    $excelext = array('xls', 'xlsx' , 'application/vnd.ms-excel '); 
     $filename= $_FILES['file']['name'];
     $extarr = explode(".",$filename);
     $extentions= ["xls","xslx"];
    // Validate whether selected file is a Excel file 
    if(in_array($extarr[1], $excelext)){ 
        
 
        // If the file is uploaded 
        if(is_uploaded_file($_FILES['file']['tmp_name'])){
 
            $reader = new Xls(); 
            $spreadsheet = $reader->load($_FILES['file']['tmp_name']); 
            $worksheet = $spreadsheet->getActiveSheet();  
            $worksheet_arr = $worksheet->toArray(); 


 
            // Remove header row 
            unset($worksheet_arr[0]); 
            foreach($worksheet_arr as $row){ 
                $iname = $row[0]; 
                $code = $row[1]; 
                $barcode = $row[2]; 
                $cost_price = $row[3]; 
                $price1 = $row[4]; 
                $price2 = $row[5]; 
                $qty = $row[6];
                // Check whether member already exists in the database with the same email 
                $prevQuery = "SELECT id FROM myitems WHERE iname = '".$iname."'"; 
                $prevResult = $conn->query($prevQuery); 
                 
                if($prevResult->num_rows > 0){
                    // Update member data in the database 
                    $conn->query("UPDATE myitems SET iname='$iname',code='$code',barcode='$barcode',cost_price='$cost_price',price1='$price1',price2='$price2',isdeleted='0'"); 
                }else{ 
                    // Insert member data in the database 
                    $conn->query("INSERT INTO myitems( iname, code, barcode, cost_price, price1, price2) VALUES ('$iname', '$code', '$barcode', '$cost_price', '$price1', '$price2')"); 
                } 
            } 
             
        }else{ 
            $qstring = '?status=err'; 
        } 
    }
 
header('location:../myitems.php');
?>