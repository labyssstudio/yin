<?php
// save_json.php
header('Content-Type: application/json');

// Define directory paths dynamically (resolves to D:\xampp\htdocs\labyss studio web\product uploads\)
$uploadDir = __DIR__ . '/product uploads/';
$jsonFilePath = __DIR__ . '/product_catalog.json';

// Ensure the 'product uploads' directory exists
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// -------------------------------------------------------------
// 1. RAW JSON INPUT HANDLING (php://input)
// Handles combined { heroImages, products } JSON payloads
// -------------------------------------------------------------
$rawInput = file_get_contents('php://input');

if (!empty($rawInput)) {
    $newData = json_decode($rawInput, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        
        /**
         * Recursively searches for Base64 image strings across heroImages and products,
         * saves physical image files to 'product uploads/', and replaces Base64 strings 
         * with local relative file paths.
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
                    $extension = strtolower($type[1]);
                    if ($extension === 'jpeg') { $extension = 'jpg'; }
                    
                    $fileName = time() . '_' . uniqid() . '.' . $extension;
                    $filePath = $uploadDir . $fileName;

                    // Save binary image file to 'product uploads/'
                    if (file_put_contents($filePath, $imageData)) {
                        // Replace Base64 string with web-accessible relative path
                        $item = 'product uploads/' . $fileName;
                    }
                }
            }
        }

        // Process all embedded Base64 images (hero images + product images)
        processBase64Images($newData, $uploadDir);

        // -------------------------------------------------------------
        // AUTOMATIC IMAGE CLEANUP (GARBAGE COLLECTION)
        // Deletes files from server if removed from heroImages or products
        // -------------------------------------------------------------
        if (file_exists($jsonFilePath)) {
            $oldData = json_decode(file_get_contents($jsonFilePath), true);
            
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

            // Extract paths from both previous catalog and new incoming payload
            $oldPaths = extractImagePaths($oldData);
            $newPaths = extractImagePaths($newData);

            // Identify and remove deleted images
            $imagesToDelete = array_diff($oldPaths, $newPaths);
            
            foreach ($imagesToDelete as $imagePath) {
                $fullPath = __DIR__ . '/' . $imagePath;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    unlink($fullPath);
                }
            }
        }

        // Save updated JSON payload back to product_catalog.json using atomic lock
        $formattedJson = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($jsonFilePath, $formattedJson, LOCK_EX)) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Catalog and hero images saved successfully. Unused files cleaned.',
                'data' => $newData // Returns processed paths back to JS frontend
            ]);
        } else {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Could not write to product_catalog.json. Check folder permissions.'
            ]);
        }
        exit;
    }
}

// -------------------------------------------------------------
// 2. MULTIPART FORM DATA HANDLING ($_FILES + $_POST Fallback)
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
        file_put_contents($jsonFilePath, $_POST['catalog_data'], LOCK_EX);
    }

    echo json_encode([
        'status' => 'success',
        'message' => 'Data saved successfully.',
        'image_path' => $savedImagePath
    ]);
    exit;
}

// Default response if payload is empty or invalid
echo json_encode([
    'status' => 'error', 
    'message' => 'No valid data or JSON payload received.'
]);
?>