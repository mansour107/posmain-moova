<?php
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require_once __DIR__ . '/../includes/db_bootstrap.php';

    try {
        $conn = posmain_db_connect();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['exists' => false, 'error' => 'Database connection failed']);
        exit;
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
