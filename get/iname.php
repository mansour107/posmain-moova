<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Database connection details
    $servername = "localhost"; // Change this to your database server
    $username = "username"; // Change this to your database username
    $password = "password"; // Change this to your database password
    $dbname = "hrms"; // Change this to your database name

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);

    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }

    // Retrieve the value from the POST request
    $inputValue = $_POST['iname'];

    // Prepare SQL statement to check if the value exists in the table
    $stmt = $conn->prepare("SELECT COUNT(*) AS count FROM myitems WHERE iname = ?");
    $stmt->bind_param("s", $inputValue);
    $stmt->execute();
    $result = $stmt->get_result();

    // Fetch the result
    $row = $result->fetch_assoc();
    $exists = $row['count'] > 0;

    // Close statement
    $stmt->close();

    // Close connection
    $conn->close();

    // Return JSON response
    echo json_encode(['exists' => $exists]);
}
?>
