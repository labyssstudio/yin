<?php
// save_json.php
header('Content-Type: application/json');

// Define directory paths based on your project structure
$uploadDir = __DIR__ . '/product uploads/';
$jsonFilePath = __DIR__ . '/product_catalog.json';

// Ensure the 'product uploads' directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// -------------------------------------------------------------
// 1. RAW JSON INPUT HANDLING (php://input)
// Handles Base64 image data sent directly inside JSON
// -------------------------------------------------------------
$rawInput = file_get_contents('php://input');

if (!empty($rawInput)) {
    $newData = json_decode($rawInput, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        
        /**
         * Recursively searches the JSON data for Base64 image strings,
         * saves them as actual files in 'product uploads/', and replaces
         * the Base64 string in the JSON with the local image path.
         */
        function processBase64Images(&$item, $uploadDir) {
            if (is_array($item)) {
                foreach ($item as &$value) {
                    processBase64Images($value, $uploadDir);
                }
            } elseif (is_string($item) && preg_match('/^data:image\/(\w+);base64,/', $item, $type)) {
                // Extract base64 payload and decode
                $imageData = substr($item, strpos($item, ',') + 1);
                $imageData = base64_decode($imageData);
                
                if ($imageData !== false) {
                    $extension = strtolower($type[1]); // e.g., png, jpeg, webp
                    if ($extension === 'jpeg') { $extension = 'jpg'; }
                    
                    $fileName = time() . '_' . uniqid() . '.' . $extension;
                    $filePath = $uploadDir . $fileName;

                    // Save the image file to 'product uploads/'
                    if (file_put_contents($filePath, $imageData)) {
                        // Replace the original Base64 string with the relative file path
                        $item = 'product uploads/' . $fileName;
                    }
                }
            }
        }

        // Process any embedded base64 images inside the new JSON payload
        processBase64Images($newData, $uploadDir);

        // -------------------------------------------------------------
        // AUTOMATIC IMAGE CLEANUP (GARBAGE COLLECTION)
        // Deletes files from the server if they are removed from the JSON
        // -------------------------------------------------------------
        if (file_exists($jsonFilePath)) {
            $oldData = json_decode(file_get_contents($jsonFilePath), true);
            
            // Helper function to extract all image paths from the JSON data
            function extractImagePaths($data, &$paths = []) {
                if (is_array($data)) {
                    foreach ($data as $value) {
                        extractImagePaths($value, $paths);
                    }
                } elseif (is_string($data) && strpos($data, 'product uploads/') === 0) {
                    $paths[] = $data;
                }
                return $paths;
            }

            // Extract paths from both the old JSON and the newly submitted JSON
            $oldPaths = extractImagePaths($oldData);
            $newPaths = extractImagePaths($newData);

            // Find images that exist in the old file but are missing from the new file
            $imagesToDelete = array_diff($oldPaths, $newPaths);
            
            // Delete the orphaned files from the server
            foreach ($imagesToDelete as $imagePath) {
                $fullPath = __DIR__ . '/' . $imagePath;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    unlink($fullPath); 
                }
            }
        }

        // Save the clean JSON payload back to product_catalog.json
        $formattedJson = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($jsonFilePath, $formattedJson)) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Catalog and images updated successfully. Unused images removed.'
            ]);
        } else {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Could not write to product_catalog.json. Check file permissions.'
            ]);
        }
        exit;
    }
}

// -------------------------------------------------------------
// 2. MULTIPART FORM DATA HANDLING ($_FILES + $_POST)
// Fallback if uploading via standard HTTP multipart forms
// -------------------------------------------------------------
if (isset($_POST['catalog_data']) || isset($_FILES['product_image'])) {
    $savedImagePath = null;

    if (isset($_FILES['product_image']) && $_FILES['product_image']['error'] === UPLOAD_ERR_OK) {
        $cleanFileName = preg_replace("/[^a-zA-Z0-9\._-]/", "", basename($_FILES['product_image']['name']));
        $fileName = time() . '_' . $cleanFileName;
        $targetFilePath = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES['product_image']['tmp_name'], $targetFilePath)) {
            $savedImagePath = 'product uploads/' . $fileName;
        }
    }

    if (isset($_POST['catalog_data'])) {
        file_put_contents($jsonFilePath, $_POST['catalog_data']);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Data saved successfully.',
        'image_path' => $savedImagePath
    ]);
    exit;
}

// Default response if no valid input was detected
echo json_encode([
    'status' => 'error', 
    'message' => 'No valid data or JSON payload received.'
]);
?>