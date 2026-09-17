<?php
// save_json.php
header('Content-Type: application/json');

// Enable full error reporting to capture local filesystem issues
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Define absolute path to your upload directory
$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'product uploads' . DIRECTORY_SEPARATOR;
$jsonFilePath = __DIR__ . DIRECTORY_SEPARATOR . 'product_catalog.json';

// Ensure the 'product uploads' directory exists with write permissions
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to create directory at: ' . $uploadDir
        ]);
        exit;
    }
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
                    if (file_put_contents($filePath, $imageData) !== false) {
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

            $oldPaths = extractImagePaths($oldData);
            $newPaths = extractImagePaths($newData);
            $imagesToDelete = array_diff($oldPaths, $newPaths);
            
            foreach ($imagesToDelete as $imagePath) {
                $fullPath = __DIR__ . '/' . $imagePath;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    unlink($fullPath); 
                }
            }
        }

        // Save the clean JSON payload back to product_catalog.json
        $formattedJson = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($jsonFilePath, $formattedJson) !== false) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Catalog and images saved successfully.',
                'target_folder' => realpath($uploadDir)
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

// Default error response if JSON is missing or malformed
echo json_encode([
    'status' => 'error', 
    'message' => 'No valid data or JSON payload received.'
]);
?>