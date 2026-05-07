<?php
include('../../includes/connect.php');

$employee = $_POST['employee'];
$fptybe = $_POST['fptybe'];
$fpdate = $_POST['fpdate'];
$fptime = $_POST['fptime'];
$user = 'your_user_value'; // Define this as needed

$result = $conn->query("SELECT name from employees where id = '$employee'");
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $sql = "INSERT INTO `attandance`(`employee`, `fptybe`, `fpdate`, `time`, `user`) 
            VALUES ('$employee','$fptybe','$fpdate','$fptime','$user')";
    
    if ($conn->query($sql) === TRUE) {
        echo "تم ادخال البصمة للطالب " . $row['name'];
    } else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }
} else {
    echo "لا يوجد طالب بهذا الرقم";
}

$conn->close();
?>
