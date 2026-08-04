<?php
// 1. START OUTPUT BUFFERING IMMEDIATELY ON LINE 2
// This creates a trap to catch all HTML warnings/notices before they leak to the browser.
ob_start();

// Suppress error printing
error_reporting(0);
ini_set('display_errors', '0');
ini_set('html_errors', '0');

// Helper function to wipe the buffer trap and send pure JSON
function sendJsonResponse($data, $statusCode = 200) {
    if (ob_get_length()) {
        ob_end_clean(); // Destroys all captured warnings/notices
    }
    http_response_code($statusCode);
    header("Content-Type: application/json; charset=UTF-8");
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type");
    echo json_encode($data);
    exit;
}

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    sendJsonResponse(["status" => "ok"], 200);
}

// 2. PATH DEFINITIONS
$storageFile = __DIR__ . '/moss_catalog.json';
$uploadDir   = __DIR__ . '/uploads/';

if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0777, true);
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

            if ($saved === false) {
                sendJsonResponse([
                    "status" => "error", 
                    "message" => "Permission denied: Could not write to moss_catalog.json."
                ], 500);
            } else {
                sendJsonResponse([
                    "status" => "success", 
                    "message" => "Item saved successfully.", 
                    "item" => $processedItem
                ], 200);
            }
        } else {
            // Batch fallback save
            $jsonResult = json_encode($decodedInput, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $saved = @file_put_contents($storageFile, $jsonResult);

            if ($saved === false) {
                sendJsonResponse([
                    "status" => "error", 
                    "message" => "Permission denied: Could not write to moss_catalog.json."
                ], 500);
            } else {
                sendJsonResponse(["status" => "success", "message" => "Catalog synced."], 200);
            }
        }
    } else {
        sendJsonResponse(["status" => "error", "message" => "Invalid JSON payload."], 400);
    }
} 
elseif ($method === 'DELETE') {
    $idToDelete = $_GET['id'] ?? null;
    if (!$idToDelete) {
        sendJsonResponse(["status" => "error", "message" => "Missing item ID."], 400);
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

        if ($saved === false) {
            sendJsonResponse([
                "status" => "error", 
                "message" => "Permission denied: Could not write to moss_catalog.json."
            ], 500);
        } else {
            sendJsonResponse(["status" => "success", "message" => "Item deleted and IDs sequentially re-assigned."], 200);
        }
    } else {
        sendJsonResponse(["status" => "success", "message" => "Database empty."], 200);
    }
}
elseif ($method === 'GET') {
    if (file_exists($storageFile)) {
        $content = file_get_contents($storageFile);
        if (ob_get_length()) ob_end_clean();
        header("Content-Type: application/json; charset=UTF-8");
        echo $content;
        exit;
    } else {
        sendJsonResponse([], 200);
    }
} else {
    sendJsonResponse(["status" => "error", "message" => "Method not allowed."], 405);
}