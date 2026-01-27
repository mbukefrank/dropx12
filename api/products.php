<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * DEPENDENCIES
 *********************************/
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $db = new Database();
    $conn = $db->getConnection();

    if ($method === 'GET') {
        handleGetRequest($conn);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($conn) {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'merchants':
            getMerchants($conn);
            break;
        case 'categories':
            getCategories($conn);
            break;
        case '':
        default:
            getProducts($conn);
            break;
    }
}

/*********************************
 * GET PRODUCTS
 *********************************/
function getProducts($conn) {
    $category = $_GET['category'] ?? null;
    $search = $_GET['search'] ?? null;
    $limit = $_GET['limit'] ?? 20;
    $offset = $_GET['offset'] ?? 0;

    // Build query
    $sql = "SELECT 
                p.*,
                m.name as merchant_name,
                m.logo as merchant_logo,
                m.rating as merchant_rating,
                m.is_dropx,
                m.status as merchant_status
            FROM products p
            INNER JOIN merchants m ON p.merchant_id = m.id
            WHERE p.status = 'active' AND m.status = 'active'";
    
    $params = [];
    
    if ($category) {
        $sql .= " AND p.category = :category";
        $params[':category'] = $category;
    }
    
    if ($search) {
        $sql .= " AND (p.name LIKE :search OR p.description LIKE :search OR p.tags LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql .= " ORDER BY p.featured DESC, p.rating DESC, p.created_at DESC";
    $sql .= " LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    
    // Bind parameters
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $formattedProducts = array_map(function($product) {
        return [
            'id' => $product['id'],
            'name' => $product['name'],
            'description' => $product['description'],
            'price' => (float)$product['price'],
            'image' => $product['image'],
            'category' => $product['category'],
            'merchant_id' => $product['merchant_id'],
            'merchant_name' => $product['merchant_name'],
            'merchant_logo' => $product['merchant_logo'],
            'is_dropx' => (bool)$product['is_dropx'],
            'rating' => (float)$product['rating'],
            'prep_time' => (int)$product['prep_time'],
            'available' => (bool)$product['available'],
            'tags' => json_decode($product['tags'] ?? '[]', true),
            'created_at' => $product['created_at'],
            'updated_at' => $product['updated_at']
        ];
    }, $products);
    
    ResponseHandler::success([
        'products' => $formattedProducts,
        'count' => count($formattedProducts),
        'total' => getTotalProductsCount($conn, $category, $search)
    ], 'Products retrieved successfully');
}

/*********************************
 * GET MERCHANTS
 *********************************/
function getMerchants($conn) {
    $limit = $_GET['limit'] ?? 10;
    $offset = $_GET['offset'] ?? 0;
    $category = $_GET['category'] ?? null;

    $sql = "SELECT * FROM merchants WHERE status = 'active'";
    $params = [];
    
    if ($category) {
        $sql .= " AND category = :category";
        $params[':category'] = $category;
    }
    
    $sql .= " ORDER BY rating DESC, is_dropx DESC, created_at DESC";
    $sql .= " LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format response
    $formattedMerchants = array_map(function($merchant) {
        return [
            'id' => $merchant['id'],
            'name' => $merchant['name'],
            'description' => $merchant['description'],
            'logo' => $merchant['logo'],
            'category' => $merchant['category'],
            'rating' => (float)$merchant['rating'],
            'delivery_time' => $merchant['delivery_time'],
            'min_order' => (float)$merchant['min_order'],
            'delivery_fee' => (float)$merchant['delivery_fee'],
            'address' => $merchant['address'],
            'city' => $merchant['city'],
            'phone' => $merchant['phone'],
            'email' => $merchant['email'],
            'is_dropx' => (bool)$merchant['is_dropx'],
            'open_hours' => json_decode($merchant['open_hours'] ?? '{}', true),
            'status' => $merchant['status'],
            'created_at' => $merchant['created_at'],
            'updated_at' => $merchant['updated_at']
        ];
    }, $merchants);
    
    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'count' => count($formattedMerchants),
        'total' => getTotalMerchantsCount($conn, $category)
    ], 'Merchants retrieved successfully');
}

/*********************************
 * GET CATEGORIES
 *********************************/
function getCategories($conn) {
    $stmt = $conn->prepare("
        SELECT category, COUNT(*) as product_count 
        FROM products 
        WHERE status = 'active' 
        GROUP BY category 
        ORDER BY product_count DESC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'categories' => $categories
    ], 'Categories retrieved successfully');
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
function getTotalProductsCount($conn, $category = null, $search = null) {
    $sql = "SELECT COUNT(*) as total FROM products p 
            INNER JOIN merchants m ON p.merchant_id = m.id
            WHERE p.status = 'active' AND m.status = 'active'";
    
    $params = [];
    
    if ($category) {
        $sql .= " AND p.category = :category";
        $params[':category'] = $category;
    }
    
    if ($search) {
        $sql .= " AND (p.name LIKE :search OR p.description LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return (int)$result['total'];
}

function getTotalMerchantsCount($conn, $category = null) {
    $sql = "SELECT COUNT(*) as total FROM merchants WHERE status = 'active'";
    $params = [];
    
    if ($category) {
        $sql .= " AND category = :category";
        $params[':category'] = $category;
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return (int)$result['total'];
}