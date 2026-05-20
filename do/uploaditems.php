<?php 
include_once '../includes/connect.php'; 
require_once __DIR__ . '/../includes/upload_guard.php';
require_once __DIR__ . '/../classes/Sync/MenuItemSyncRecorder.php';

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx; 
 
    if (!isset($_FILES['file'])) {
        header('location:../myitems.php?status=err');
        exit;
    }

    try {
        $validatedUpload = posmain_validate_spreadsheet_upload($_FILES['file']);
    } catch (Throwable $exception) {
        header('location:../myitems.php?status=err');
        exit;
    }

    if(is_uploaded_file($validatedUpload['tmp_name'])){
 
            $reader = $validatedUpload['extension'] === 'xlsx' ? new Xlsx() : new Xls();
            $spreadsheet = $reader->load($validatedUpload['tmp_name']);
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
                    $existing = $prevResult->fetch_assoc();
                    $itemId = (int) ($existing['id'] ?? 0);
                    // Update member data in the database 
                    $conn->query("UPDATE myitems SET iname='$iname',code='$code',barcode='$barcode',cost_price='$cost_price',price1='$price1',price2='$price2',isdeleted='0' WHERE id = " . $itemId);
                }else{ 
                    // Insert member data in the database 
                    $conn->query("INSERT INTO myitems( iname, code, barcode, cost_price, price1, price2) VALUES ('$iname', '$code', '$barcode', '$cost_price', '$price1', '$price2')"); 
                    $itemId = (int) $conn->insert_id;
                } 
                posmain_record_menu_item_sync($conn, $itemId, 'item_upload');
            } 
             
        }else{ 
            $qstring = '?status=err'; 
        } 
 
header('location:../myitems.php');
?>
