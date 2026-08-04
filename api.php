<?php
// Prevent PHP warnings/notices from corrupting JSON output
error_reporting(0);
ini_set('display_errors', '0');
ini_set('html_errors', '0');
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

// 1. ABSOLUTE PATH LOCKING USING __DIR__
$storageFile = __DIR__ . '/moss_catalog.json';
$uploadDir   = __DIR__ . '/uploads/';

// Ensure uploads directory exists on the target drive
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true); // Added @ back to silence warnings
}

// Helper function to extract base64 images and save them as actual files
function processItemImages($item, $uploadDir) {
    if (isset($item['imagePaths']) && is_array($item['imagePaths'])) {
        $id = $item['id'] ?? 'unknown';
        foreach ($item['imagePaths'] as $index => $imageStr) {
            if (is_string($imageStr) && strpos($imageStr, 'data:image/') === 0) {
                preg_match('/^data:image\/([^;]+);base64,/', $imageStr, $matches);
                $ext = $matches[1] ?? 'png';
                if ($ext === 'jpeg') $ext = 'jpg';
                
                $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
                $filename = 'moss_' . $safeId . '_' . $index . '_' . time() . '.' . $ext;
                $filePath = $uploadDir . $filename;
                
                $data = explode(',', $imageStr);
                if (isset($data[1])) {
                    $decodedData = base64_decode($data[1]);
                    if ($decodedData !== false && @file_put_contents($filePath, $decodedData) !== false) {
                        $item['imagePaths'][$index] = 'uploads/' . $filename;
                    }
                }
            }
        }
    }
    return $item;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $jsonInput = file_get_contents('php://input');
    $decodedInput = json_decode($jsonInput, true);
    
    if ($decodedInput !== null) {
        $catalogList = [];
        if (file_exists($storageFile)) {
            $fileContent = file_get_contents($storageFile);
            $catalogList = json_decode($fileContent, true) ?? [];
        }
        
        // Process a single item
        if (isset($decodedInput['id'])) {
            $processedItem = processItemImages($decodedInput, $uploadDir);
            
            $foundIndex = -1;
            foreach ($catalogList as $idx => $existingItem) {
                if (isset($existingItem['id']) && $existingItem['id'] === $processedItem['id']) {
                    $foundIndex = $idx;
                    break;
                }
            }
            
            if ($foundIndex > -1) {
                $catalogList[$foundIndex] = $processedItem;
            } else {
                $catalogList[] = $processedItem;
            }
            
            $jsonResult = json_encode($catalogList, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $saved = @file_put_contents($storageFile, $jsonResult);

            // MAGIC FIX: Wipe any hidden warnings or blank spaces before sending JSON
            if (ob_get_length()) ob_clean(); 

            if ($saved === false) {
                http_response_code(500);
                echo json_encode([
                    "status" => "error", 
                    "message" => "Permission denied: Could not write to moss_catalog.json on D: drive."
                ]);
            } else {
                echo json_encode([
                    "status" => "success", 
                    "message" => "Item saved successfully.", 
                    "item" => $processedItem
                ]);
            }
            exit; // Force stop so absolutely nothing else is printed
        } else {
            // Batch fallback save
            $jsonResult = json_encode($decodedInput, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $saved = @file_put_contents($storageFile, $jsonResult);

            if (ob_get_length()) ob_clean(); 

            if ($saved === false) {
                http_response_code(500);
                echo json_encode([
                    "status" => "error", 
                    "message" => "Permission denied: Could not write to moss_catalog.json."
                ]);
            } else {
                echo json_encode(["status" => "success", "message" => "Catalog synced."]);
            }
            exit;
        }
    } else {
        if (ob_get_length()) ob_clean(); 
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid JSON payload."]);
        exit;
    }
} 
elseif ($method === 'DELETE') {
    $idToDelete = $_GET['id'] ?? null;
    if (!$idToDelete) {
        if (ob_get_length()) ob_clean(); 
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing item ID."]);
        exit;
    }

    if (file_exists($storageFile)) {
        $catalogList = json_decode(file_get_contents($storageFile), true) ?? [];
        
        $updatedCatalog = array_values(array_filter($catalogList, function($item) use ($idToDelete) {
            return isset($item['id']) && $item['id'] !== $idToDelete;
        }));

        foreach ($updatedCatalog as $index => &$item) {
            $item['id'] = 'M' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);
        }
        unset($item);

        $jsonResult = json_encode($updatedCatalog, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $saved = @file_put_contents($storageFile, $jsonResult);

        if (ob_get_length()) ob_clean(); 

        if ($saved === false) {
            http_response_code(500);
            echo json_encode([
                "status" => "error", 
                "message" => "Permission denied: Could not write to moss_catalog.json."
            ]);
        } else {
            echo json_encode(["status" => "success", "message" => "Item deleted and IDs sequentially re-assigned."]);
        }
        exit;
    } else {
        if (ob_get_length()) ob_clean(); 
        echo json_encode(["status" => "success", "message" => "Database empty."]);
        exit;
    }
}
elseif ($method === 'GET') {
    if (ob_get_length()) ob_clean(); 
    if (file_exists($storageFile)) {
        echo file_get_contents($storageFile);
    } else {
        echo json_encode([]);
    }
    exit;
} else {
    if (ob_get_length()) ob_clean(); 
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed."]);
    exit;
}