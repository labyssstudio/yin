<?php
// save_json.php
header('Content-Type: text/plain');

// Get the raw JSON data sent from Javascript
$jsonData = file_get_contents('php://input');

if ($jsonData) {
    // Specify the exact path to your JSON file
    $filePath = 'product_catalog.json';
    
    // Write the data directly to the file
    if (file_put_contents($filePath, $jsonData)) {
        echo "Success";
    } else {
        echo "Error: Could not write to file. Check folder permissions.";
    }
} else {
    echo "Error: No data received.";
}
?>