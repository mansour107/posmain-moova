<?php
include('../includes/connect.php'); // Adjust path as necessary
$company_name = $rowstg['company_name'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codes = $_POST['code'];
    $barcodes = $_POST['barcode'];
    $names = $_POST['iname'];
    $prices = $_POST['price'];
    $quantities = $_POST['qty'];

    echo '<html><head>';
    echo '<script src="code.js"></script>'; // Include JsBarcode
    echo '</head><body style="margin:0px;font-size:12px" onload="window.print();">';

    foreach ($codes as $index => $code) {
        $name = htmlspecialchars($names[$index]);
        $barcode = htmlspecialchars($barcodes[$index]);
        $price = htmlspecialchars($prices[$index]);
        $quantity = (int) htmlspecialchars($quantities[$index]);
    
        // Generate barcodes for each quantity
        for ($i = 0; $i < $quantity; $i++) {
            echo "<center>";
            echo "<div style='margin-bottom: 0px;height:24mm'>";
            echo "<div style='width:33mm;background: black;color:white;margin:0px;padding:0px'>$company_name</div>";
    
            echo "$name";
            echo "<br>";
            echo "<canvas id='barcode-$index-$i' width='143' height='85'></canvas>"; // Set width to 143 pixels (38 mm)
            echo "<script>
                    JsBarcode('#barcode-$index-$i', '$barcode', {
                        format: 'CODE128',
                        displayValue: false,
                        width: 1.5, // Adjust the width to fit your needs
                        height: 15, // Set to 90 pixels for 24 mm height
                        margin: 0 // Remove margins
                    });
                  </script>";
    
            echo "<br>";
            echo $barcode;
            echo "<br>";
            echo "$price LE";
            echo "</div>";
            echo "</center>";
        }
    }
    
    echo '</body></html>';
}
?>
