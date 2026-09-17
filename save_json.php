<?php
// save_json.php
header('Content-Type: application/json');

// 1. Define absolute paths based on the location of this script
// If this file is in "labyss studio web", __DIR__ resolves to "D:\xampp\htdocs\labyss studio web"
$baseDir = __DIR__; 
$uploadDir = $baseDir . '/product uploads/';
$jsonFilePath = $baseDir . '/product_catalog.json';

// Ensure the 'product uploads' directory exists with full permissions
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Read the incoming JSON payload from your JS fetch request
$rawInput = file_get_contents('php://input');

if (!empty($rawInput)) {
    $newData = json_decode($rawInput, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        
        // Recursive function to find Base64 strings, save them as files, and update the JSON array
        function processBase64Images(&$item, $uploadDir) {
            if (is_array($item)) {
                foreach ($item as &$value) {
                    processBase64Images($value, $uploadDir);
                }
            } elseif (is_string($item) && preg_match('/^data:image\/(\w+);base64,/', $item, $type)) {
                // Extract and decode base64 payload
                $imageData = substr($item, strpos($item, ',') + 1);
                $imageData = base64_decode($imageData);
                
                if ($imageData !== false) {
                    $extension = strtolower($type[1]);
                    if ($extension === 'jpeg') { $extension = 'jpg'; }
                    
                    // Generate unique filename
                    $fileName = time() . '_' . uniqid() . '.' . $extension;
                    $filePath = $uploadDir . $fileName;

                    // Save the physical file to D:\xampp\htdocs\labyss studio web\product uploads\
                    if (file_put_contents($filePath, $imageData) !== false) {
                        // Replace the Base64 string in the JSON payload with the relative path
                        $item = 'product uploads/' . $fileName;
                    }
                }
            }
        }

        // Process both 'products' and 'heroImages' inside the payload
        processBase64Images($newData, $uploadDir);

        // Garbage Collection: Delete old unused images from the server
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
                $fullPath = $baseDir . '/' . $imagePath;
                if (file_exists($fullPath) && is_file($fullPath)) {
                    unlink($fullPath); 
                }
            }
        }

        // Save the updated JSON object back to product_catalog.json
        $formattedJson = json_encode($newData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($jsonFilePath, $formattedJson) !== false) {
            echo json_encode([
                'status' => 'success', 
                'message' => 'Catalog and Hero Images saved successfully.',
                'debug_upload_path' => $uploadDir // Use this to verify the XAMPP path in your browser console
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

// Default error response
echo json_encode([
    'status' => 'error', 
    'message' => 'No valid JSON payload received.'
]);
?>