<?php
header("Content-Type: application/json");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$storageFile = 'moss_catalog.json';
$uploadDir = 'uploads/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

// Helper function to extract base64 images and save them as actual files
function processItemImages($item, $uploadDir) {
    if (isset($item['imagePaths']) && is_array($item['imagePaths'])) {
        $id = $item['id'] ?? 'unknown';
        foreach ($item['imagePaths'] as $index => $imageStr) {
            if (strpos($imageStr, 'data:image/') === 0) {
                preg_match('/^data:image\/(\w+);base64,/', $imageStr, $matches);
                $ext = $matches[1] ?? 'png';
                
                $filename = 'moss_' . $id . '_' . $index . '_' . time() . '.' . $ext;
                $filePath = $uploadDir . $filename;
                
                $data = explode(',', $imageStr);
                if (isset($data[1])) {
                    file_put_contents($filePath, base64_decode($data[1]));
                    $item['imagePaths'][$index] = $filePath;
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
            $catalogList = json_decode(file_get_contents($storageFile), true) ?? [];
        }
        
        // Process only a single item
        if (isset($decodedInput['id'])) {
            $processedItem = processItemImages($decodedInput, $uploadDir);
            
            $foundIndex = -1;
            foreach ($catalogList as $idx => $existingItem) {
                if ($existingItem['id'] === $processedItem['id']) {
                    $foundIndex = $idx;
                    break;
                }
            }
            
            if ($foundIndex > -1) {
                $catalogList[$foundIndex] = $processedItem;
            } else {
                $catalogList[] = $processedItem;
            }
            
            file_put_contents($storageFile, json_encode($catalogList, JSON_UNESCAPED_SLASHES));
            echo json_encode(["status" => "success", "message" => "Item saved.", "item" => $processedItem]);
        } else {
            // Batch fallback save if needed
            file_put_contents($storageFile, json_encode($decodedInput, JSON_UNESCAPED_SLASHES));
            echo json_encode(["status" => "success", "message" => "Catalog synced."]);
        }
    } else {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Invalid JSON."]);
    }
} 
elseif ($method === 'DELETE') {
    // INSTANT DELETION ROUTE
    $idToDelete = $_GET['id'] ?? null;
    if (!$idToDelete) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "Missing item ID."]);
        exit;
    }

    if (file_exists($storageFile)) {
        $catalogList = json_decode(file_get_contents($storageFile), true) ?? [];
        
        // 1. Filter out the deleted item instantly
        $updatedCatalog = array_values(array_filter($catalogList, function($item) use ($idToDelete) {
            return $item['id'] !== $idToDelete;
        }));

        // 2. CONTINUOUS SEQUENTIAL ID RE-ASSIGNMENT (M001, M002, M003...)
        foreach ($updatedCatalog as $index => &$item) {
            $item['id'] = 'M' . str_pad($index + 1, 3, '0', STR_PAD_LEFT);
        }
        unset($item); // Break reference loop safety precaution

        // 3. Save the neatly re-indexed array back to the JSON file
        file_put_contents($storageFile, json_encode($updatedCatalog, JSON_UNESCAPED_SLASHES));
        echo json_encode(["status" => "success", "message" => "Item deleted and IDs sequentially re-assigned."]);
    } else {
        echo json_encode(["status" => "success", "message" => "Database empty."]);
    }
}

elseif ($method === 'GET') {
    if (file_exists($storageFile)) {
        echo file_get_contents($storageFile);
    } else {
        echo json_encode([]);
    }
} else {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed."]);
}
?>

