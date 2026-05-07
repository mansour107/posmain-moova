<?php

require '../vendor/autoload.php'; // Adjust the path if needed

use PhpOffice\PhpSpreadsheet\IOFactory;
include('../inclludes/connect.php');
if (isset($_POST['submit'])) {
    $excelFile = $_FILES['excelFile']['tmp_name'];
    
    // Load the Excel file
    $spreadsheet = IOFactory::load($excelFile);
    $worksheet = $spreadsheet->getActiveSheet();

    // Iterate through each row in the Excel sheet
    foreach ($worksheet->getRowIterator() as $row) {
        $rowData = [];
        foreach ($row->getCellIterator() as $cell) {
            $rowData[] = $cell->getValue();
        }

        // Assuming the Excel columns are: A (column 0), B (column 1), C (column 2), etc.
        $columnAValue = $rowData[0];
        $columnBValue = $rowData[1];
        // ... repeat for other columns

        // Insert data into the MySQL database
        $query = "INSERT INTO your_table_name (column_a, column_b) VALUES (?, ?)";
        $statement = $mysqli->prepare($query);
        $statement->bind_param("ss", $columnAValue, $columnBValue); // Adjust data types as needed

        if (!$statement->execute()) {
            echo "Error: " . $statement->error;
        }

        $statement->close();
    }

    echo "Import completed.";
}

$mysqli->close();

?>
