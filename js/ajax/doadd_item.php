<?php 
include('../../includes/connect.php');
session_start();

// Ensure the user is logged in
if (!isset($_SESSION['userid'])) {
    die("Error: User not logged in.");
}
$usid = $_SESSION['userid'];

$barcode = isset($_POST['barcode']) ? $_POST['barcode'] : null;
if (is_null($barcode)) {
    $last_barcode_result = $conn->query('SELECT id FROM myitems ORDER BY id DESC LIMIT 1');
    $last_barcode = $last_barcode_result->fetch_assoc()['id'];
    $barcode = $last_barcode + 1;
}

// Collect data from POST request
$iname = $_POST['iname']; 
$code = $_POST['code'];
$name2 = $_POST['name2']; 
$group1 = $_POST['group1']; 
$group2 = $_POST['group2']; 
$info = $_POST['info']; 
$cost_price = $_POST['cost_price']; 
$price1 = $_POST['price1'];
$price2 = $_POST['price2']; 
$market_price = $_POST['market_price'];

// Prepare SQL statement for inserting into myitems
$stmt = $conn->prepare("INSERT INTO myitems (iname, name2, code, barcode, info, market_price, cost_price, price1, price2, group1, group2, user) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssssssssi", $iname, $name2, $code, $barcode, $info, $market_price, $cost_price, $price1, $price2, $group1, $group2, $usid);

if ($stmt->execute()){
    $last_id = $conn->insert_id;
    
    // Insert into item_units table
    foreach ($_POST['unit_id'] as $index => $unit_id) {
        $u_val = $_POST['u_val'][$index];
        $unit_barcode = !empty($_POST['unit_barcode'][$index]) ? $_POST['unit_barcode'][$index] : "99".$index.$_POST['unit_barcode'][0];

        $stmt_unit = $conn->prepare("INSERT INTO item_units (item_id, unit_id, u_val, unit_barcode) VALUES (?, ?, ?, ?)");
        $stmt_unit->bind_param("iiis", $last_id, $unit_id, $u_val, $unit_barcode);
        if (!$stmt_unit->execute()) {
            $stmt->close();
            $conn->close();
            die("يوجد خطأ.");
        }
        $stmt_unit->close();
    }
    
    echo "<p class='bg-success'>تم تسجيل الصنف ".$iname." بنجاح  </p>";
} else {
    $stmt->close();
    $conn->close();
    die("يوجد خطأ.");
}
?>
