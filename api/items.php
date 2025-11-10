<?php
/**
 * Items (Consoles/Accessories) CRUD API endpoints
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth-check.php';

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'list':
        listItems();
        break;
    
    case 'get':
        getItem();
        break;
    
    case 'create':
        createItem();
        break;
    
    case 'update':
        updateItem();
        break;
    
    case 'delete':
        deleteItem();
        break;
    
    default:
        sendJsonResponse(['success' => false, 'message' => 'Invalid action'], 400);
}

function listItems() {
    global $pdo;
    
    $category = $_GET['category'] ?? '';
    
    $sql = "
        SELECT i.*, 
               COUNT(ii.id) as extra_image_count
        FROM items i
        LEFT JOIN item_images ii ON i.id = ii.item_id
    ";
    
    $params = [];
    if (!empty($category)) {
        $sql .= " WHERE i.category = ?";
        $params[] = $category;
    }
    
    $sql .= " GROUP BY i.id ORDER BY i.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure all items have an 'id' field (SQLite might return it as 'ID' in some cases)
    foreach ($items as &$item) {
        if (isset($item['ID']) && !isset($item['id'])) {
            $item['id'] = $item['ID'];
        }
    }
    unset($item);
    
    sendJsonResponse(['success' => true, 'items' => $items]);
}

function getItem() {
    global $pdo;
    
    $id = $_GET['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Item ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        sendJsonResponse(['success' => false, 'message' => 'Item not found'], 404);
    }
    
    // Ensure id field exists (SQLite might return it as 'ID' in some cases)
    if (isset($item['ID']) && !isset($item['id'])) {
        $item['id'] = $item['ID'];
    }
    
    // Get extra images
    $stmt = $pdo->prepare("SELECT * FROM item_images WHERE item_id = ? ORDER BY uploaded_at DESC");
    $stmt->execute([$id]);
    $item['extra_images'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendJsonResponse(['success' => true, 'item' => $item]);
}

function createItem() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validate required fields
    if (empty($data['title']) || empty($data['category'])) {
        sendJsonResponse(['success' => false, 'message' => 'Title and category are required'], 400);
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO items (
            title, platform, category, description, condition,
            price_paid, pricecharting_price, notes, front_image, back_image
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )
    ");
    
    $stmt->execute([
        $data['title'] ?? '',
        $data['platform'] ?? null,
        $data['category'] ?? '',
        $data['description'] ?? null,
        $data['condition'] ?? null,
        isset($data['price_paid']) ? (float)$data['price_paid'] : null,
        isset($data['pricecharting_price']) ? (float)$data['pricecharting_price'] : null,
        $data['notes'] ?? null,
        $data['front_image'] ?? null,
        $data['back_image'] ?? null
    ]);
    
    $itemId = $pdo->lastInsertId();
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Item created successfully',
        'item_id' => $itemId
    ]);
}

function updateItem() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Item ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("
        UPDATE items SET
            title = ?,
            platform = ?,
            category = ?,
            description = ?,
            condition = ?,
            price_paid = ?,
            pricecharting_price = ?,
            notes = ?,
            front_image = ?,
            back_image = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    
    $stmt->execute([
        $data['title'] ?? '',
        $data['platform'] ?? null,
        $data['category'] ?? '',
        $data['description'] ?? null,
        $data['condition'] ?? null,
        isset($data['price_paid']) ? (float)$data['price_paid'] : null,
        isset($data['pricecharting_price']) ? (float)$data['pricecharting_price'] : null,
        $data['notes'] ?? null,
        $data['front_image'] ?? null,
        $data['back_image'] ?? null,
        $id
    ]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Item updated successfully'
    ]);
}

function deleteItem() {
    global $pdo;
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? 0;
    
    if (!$id) {
        sendJsonResponse(['success' => false, 'message' => 'Item ID is required'], 400);
    }
    
    $stmt = $pdo->prepare("DELETE FROM items WHERE id = ?");
    $stmt->execute([$id]);
    
    sendJsonResponse([
        'success' => true,
        'message' => 'Item deleted successfully'
    ]);
}

