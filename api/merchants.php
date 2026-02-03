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
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30,
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * BASE URL CONFIGURATION
 *********************************/
// Update this with your actual backend URL
$baseUrl = "https://dropxbackend-production.up.railway.app";

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        handleGetRequest();
    } elseif ($method === 'POST') {
        handlePostRequest();
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    // Check for specific merchant ID
    $merchantId = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? '';
    
    if ($merchantId) {
        if ($action === 'get_menu') {
            getMerchantMenu($conn, $merchantId, $baseUrl);
        } elseif ($action === 'get_categories') {
            getMerchantCategories($conn, $merchantId);
        } else {
            getMerchantDetails($conn, $merchantId, $baseUrl);
        }
    } else {
        getMerchantsList($conn, $baseUrl);
    }
}

/*********************************
 * GET MERCHANTS LIST
 *********************************/
function getMerchantsList($conn, $baseUrl) {
    // Get query parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'rating';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $minRating = floatval($_GET['min_rating'] ?? 0);
    $isOpen = $_GET['is_open'] ?? null;
    $isPromoted = $_GET['is_promoted'] ?? null;

    // Build WHERE clause
    $whereConditions = ["m.is_active = 1"];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "m.category LIKE :category";
        $params[':category'] = "%$category%";
    }

    if ($search) {
        $whereConditions[] = "(m.name LIKE :search OR m.category LIKE :search OR m.tags LIKE :search)";
        $params[':search'] = "%$search%";
    }

    if ($minRating > 0) {
        $whereConditions[] = "m.rating >= :min_rating";
        $params[':min_rating'] = $minRating;
    }

    if ($isOpen !== null) {
        $whereConditions[] = "m.is_open = :is_open";
        $params[':is_open'] = $isOpen === 'true' ? 1 : 0;
    }

    if ($isPromoted !== null) {
        $whereConditions[] = "m.is_promoted = :is_promoted";
        $params[':is_promoted'] = $isPromoted === 'true' ? 1 : 0;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['rating', 'review_count', 'name', 'delivery_fee', 'created_at'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'rating';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get merchants
    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.is_open,
                m.is_promoted,
                m.created_at,
                m.updated_at
            FROM merchants m
            $whereClause
            ORDER BY m.is_promoted DESC, m.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format merchant data
    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET MERCHANT DETAILS
 *********************************/
function getMerchantDetails($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            m.id,
            m.name,
            m.category,
            m.rating,
            m.review_count,
            CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
            m.image_url,
            m.is_open,
            m.is_promoted,
            m.full_description,
            m.address,
            m.phone,
            m.popular_items,
            m.min_order,
            m.delivery_time,
            m.delivery_fee,
            m.distance,
            m.tags,
            m.created_at,
            m.updated_at
        FROM merchants m
        WHERE m.id = :id AND m.is_active = 1"
    );
    
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    // Get merchant reviews
    $reviewsStmt = $conn->prepare(
        "SELECT 
            mr.id,
            mr.user_id,
            u.full_name as user_name,
            u.avatar as user_avatar,
            mr.rating,
            mr.comment,
            mr.created_at
        FROM merchant_reviews mr
        LEFT JOIN users u ON mr.user_id = u.id
        WHERE mr.merchant_id = :merchant_id
        ORDER BY mr.created_at DESC
        LIMIT 10"
    );
    
    $reviewsStmt->execute([':merchant_id' => $merchantId]);
    $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if user has favorited this merchant
    $isFavorite = false;
    if (!empty($_SESSION['user_id'])) {
        $favStmt = $conn->prepare(
            "SELECT id FROM user_favorite_merchants 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $favStmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':merchant_id' => $merchantId
        ]);
        $isFavorite = $favStmt->fetch() ? true : false;
    }

    $merchantData = formatMerchantDetailData($merchant, $baseUrl);
    $merchantData['reviews'] = array_map('formatReviewData', $reviews);
    $merchantData['is_favorite'] = $isFavorite;

    ResponseHandler::success([
        'merchant' => $merchantData
    ]);
}

/*********************************
 * GET MERCHANT MENU
 *********************************/
function getMerchantMenu($conn, $merchantId, $baseUrl) {
    // First check if merchant exists and is active
    $checkStmt = $conn->prepare(
        "SELECT id, name FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    // Get menu items with categories
    $menuStmt = $conn->prepare(
        "SELECT 
            mi.id,
            mi.name,
            mi.description,
            mi.price,
            mi.image_url,
            mi.category,
            mi.is_available,
            mi.is_popular,
            mi.options,
            mi.ingredients,
            mi.calories,
            mi.preparation_time,
            mi.created_at,
            mi.updated_at,
            mc.name as category_name,
            mc.display_order
        FROM menu_items mi
        LEFT JOIN menu_categories mc ON mi.category = mc.name
        WHERE mi.merchant_id = :merchant_id
        AND mi.is_active = 1
        ORDER BY mc.display_order ASC, mi.display_order ASC, mi.name ASC"
    );
    
    $menuStmt->execute([':merchant_id' => $merchantId]);
    $menuItems = $menuStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique categories
    $categoriesStmt = $conn->prepare(
        "SELECT DISTINCT 
            mc.name,
            mc.display_order
        FROM menu_categories mc
        WHERE mc.merchant_id = :merchant_id
        ORDER BY mc.display_order ASC, mc.name ASC"
    );
    
    $categoriesStmt->execute([':merchant_id' => $merchantId]);
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // If no categories exist, create them from menu items
    if (empty($categories) && !empty($menuItems)) {
        $uniqueCategories = [];
        foreach ($menuItems as $item) {
            $categoryName = $item['category'] ?: 'Uncategorized';
            if (!isset($uniqueCategories[$categoryName])) {
                $uniqueCategories[$categoryName] = [
                    'name' => $categoryName,
                    'display_order' => count($uniqueCategories) + 1
                ];
            }
        }
        $categories = array_values($uniqueCategories);
    }

    // Format menu items
    $formattedMenuItems = array_map(function($item) use ($baseUrl) {
        return formatMenuItemData($item, $baseUrl);
    }, $menuItems);

    // Format categories
    $formattedCategories = array_map(function($cat) {
        return [
            'name' => $cat['name'],
            'display_order' => intval($cat['display_order'] ?? 0)
        ];
    }, $categories);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'menu_items' => $formattedMenuItems,
        'categories' => $formattedCategories
    ]);
}

/*********************************
 * GET MERCHANT CATEGORIES
 *********************************/
function getMerchantCategories($conn, $merchantId) {
    // First check if merchant exists
    $checkStmt = $conn->prepare(
        "SELECT id FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $categoriesStmt = $conn->prepare(
        "SELECT 
            mc.id,
            mc.name,
            mc.description,
            mc.display_order,
            mc.image_url,
            mc.created_at,
            COUNT(mi.id) as item_count
        FROM menu_categories mc
        LEFT JOIN menu_items mi ON mc.merchant_id = mi.merchant_id 
            AND mc.name = mi.category 
            AND mi.is_active = 1
        WHERE mc.merchant_id = :merchant_id
        GROUP BY mc.id, mc.name, mc.description, mc.display_order, mc.image_url, mc.created_at
        ORDER BY mc.display_order ASC, mc.name ASC"
    );
    
    $categoriesStmt->execute([':merchant_id' => $merchantId]);
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'categories' => $categories
    ]);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest() {
    global $baseUrl;
    $db = new Database();
    $conn = $db->getConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'create_review':
            createMerchantReview($conn, $input);
            break;
        case 'toggle_favorite':
            toggleMerchantFavorite($conn, $input);
            break;
        case 'get_favorites':
            getFavoriteMerchants($conn, $input, $baseUrl);
            break;
        case 'report_merchant':
            reportMerchant($conn, $input);
            break;
        case 'search_menu':
            searchMenuItems($conn, $input, $baseUrl);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * CREATE MERCHANT REVIEW
 *********************************/
function createMerchantReview($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    // Check if merchant exists
    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    // Check if user has already reviewed
    $existingStmt = $conn->prepare(
        "SELECT id FROM merchant_reviews 
         WHERE merchant_id = :merchant_id AND user_id = :user_id"
    );
    $existingStmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId
    ]);
    
    if ($existingStmt->fetch()) {
        ResponseHandler::error('You have already reviewed this merchant', 409);
    }

    // Create review
    $stmt = $conn->prepare(
        "INSERT INTO merchant_reviews 
            (merchant_id, user_id, rating, comment, created_at)
         VALUES (:merchant_id, :user_id, :rating, :comment, NOW())"
    );
    
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId,
        ':rating' => $rating,
        ':comment' => $comment
    ]);

    // Update merchant rating
    updateMerchantRating($conn, $merchantId);

    ResponseHandler::success([], 'Review submitted successfully');
}

/*********************************
 * TOGGLE MERCHANT FAVORITE
 *********************************/
function toggleMerchantFavorite($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    // Check if merchant exists
    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    // Check if already favorited
    $favStmt = $conn->prepare(
        "SELECT id FROM user_favorite_merchants 
         WHERE user_id = :user_id AND merchant_id = :merchant_id"
    );
    $favStmt->execute([
        ':user_id' => $userId,
        ':merchant_id' => $merchantId
    ]);
    
    if ($favStmt->fetch()) {
        // Remove from favorites
        $deleteStmt = $conn->prepare(
            "DELETE FROM user_favorite_merchants 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $deleteStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        ResponseHandler::success(['is_favorite' => false], 'Removed from favorites');
    } else {
        // Add to favorites
        $insertStmt = $conn->prepare(
            "INSERT INTO user_favorite_merchants (user_id, merchant_id, created_at)
             VALUES (:user_id, :merchant_id, NOW())"
        );
        $insertStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        
        ResponseHandler::success(['is_favorite' => true], 'Added to favorites');
    }
}

/*********************************
 * GET FAVORITE MERCHANTS
 *********************************/
function getFavoriteMerchants($conn, $data, $baseUrl) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $page = max(1, intval($data['page'] ?? 1));
    $limit = min(50, max(1, intval($data['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.is_open,
                m.is_promoted,
                m.created_at,
                ufm.created_at as favorited_at
            FROM merchants m
            INNER JOIN user_favorite_merchants ufm ON m.id = ufm.merchant_id
            WHERE ufm.user_id = :user_id
            AND m.is_active = 1
            ORDER BY ufm.created_at DESC
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get total count
    $countStmt = $conn->prepare(
        "SELECT COUNT(*) as total 
         FROM user_favorite_merchants ufm
         JOIN merchants m ON ufm.merchant_id = m.id
         WHERE ufm.user_id = :user_id AND m.is_active = 1"
    );
    $countStmt->execute([':user_id' => $userId]);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * REPORT MERCHANT
 *********************************/
function reportMerchant($conn, $data) {
    // Check authentication
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $userId = $_SESSION['user_id'];
    $merchantId = $data['merchant_id'] ?? null;
    $reason = trim($data['reason'] ?? '');
    $details = trim($data['details'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if (!$reason) {
        ResponseHandler::error('Report reason is required', 400);
    }

    // Check if merchant exists
    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    // Create report
    $stmt = $conn->prepare(
        "INSERT INTO merchant_reports 
            (merchant_id, user_id, reason, details, status, created_at)
         VALUES (:merchant_id, :user_id, :reason, :details, 'pending', NOW())"
    );
    
    $stmt->execute([
        ':merchant_id' => $merchantId,
        ':user_id' => $userId,
        ':reason' => $reason,
        ':details' => $details
    ]);

    ResponseHandler::success([], 'Report submitted successfully');
}

/*********************************
 * SEARCH MENU ITEMS
 *********************************/
function searchMenuItems($conn, $data, $baseUrl) {
    $merchantId = $data['merchant_id'] ?? null;
    $query = trim($data['query'] ?? '');
    $category = trim($data['category'] ?? '');
    $sortBy = $data['sort_by'] ?? 'name';
    $sortOrder = strtoupper($data['sort_order'] ?? 'ASC');
    $page = max(1, intval($data['page'] ?? 1));
    $limit = min(50, max(1, intval($data['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    // Check if merchant exists
    $checkStmt = $conn->prepare(
        "SELECT id, name FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    // Build WHERE clause
    $whereConditions = ["mi.merchant_id = :merchant_id", "mi.is_active = 1"];
    $params = [':merchant_id' => $merchantId];

    if ($query) {
        $whereConditions[] = "(mi.name LIKE :query OR mi.description LIKE :query OR mi.ingredients LIKE :query)";
        $params[':query'] = "%$query%";
    }

    if ($category) {
        $whereConditions[] = "mi.category = :category";
        $params[':category'] = $category;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['name', 'price', 'is_popular', 'created_at'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'name';
    $sortOrder = $sortOrder === 'DESC' ? 'DESC' : 'ASC';

    // Get total count
    $countSql = "SELECT COUNT(*) as total FROM menu_items mi $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get menu items
    $sql = "SELECT 
                mi.id,
                mi.name,
                mi.description,
                mi.price,
                mi.image_url,
                mi.category,
                mi.is_available,
                mi.is_popular,
                mi.options,
                mi.ingredients,
                mi.calories,
                mi.preparation_time,
                mi.created_at,
                mi.updated_at
            FROM menu_items mi
            $whereClause
            ORDER BY mi.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    $stmt = $conn->prepare($sql);
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $menuItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format menu items
    $formattedMenuItems = array_map(function($item) use ($baseUrl) {
        return formatMenuItemData($item, $baseUrl);
    }, $menuItems);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'menu_items' => $formattedMenuItems,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'search_params' => [
            'query' => $query,
            'category' => $category,
            'sort_by' => $sortBy,
            'sort_order' => $sortOrder
        ]
    ]);
}

/*********************************
 * UPDATE MERCHANT RATING
 *********************************/
function updateMerchantRating($conn, $merchantId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_reviews,
            AVG(rating) as avg_rating
        FROM merchant_reviews
        WHERE merchant_id = :merchant_id"
    );
    $stmt->execute([':merchant_id' => $merchantId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $updateStmt = $conn->prepare(
        "UPDATE merchants 
         SET rating = :rating, 
             review_count = :review_count,
             updated_at = NOW()
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        ':rating' => $result['avg_rating'] ?? 0,
        ':review_count' => $result['total_reviews'] ?? 0,
        ':id' => $merchantId
    ]);
}

/*********************************
 * FORMAT MERCHANT LIST DATA
 *********************************/
function formatMerchantListData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        // If it's already a full URL, use it as is
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            // Otherwise, build the full URL
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $m['image_url'];
        }
    }
    
    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'category' => $m['category'] ?? '',
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'delivery_info' => $m['delivery_info'] ?? '',
        'image_url' => $imageUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_promoted' => boolval($m['is_promoted'] ?? false),
        'created_at' => $m['created_at'] ?? '',
        'updated_at' => $m['updated_at'] ?? ''
    ];
}

/*********************************
 * FORMAT MERCHANT DETAIL DATA
 *********************************/
function formatMerchantDetailData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        // If it's already a full URL, use it as is
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            // Otherwise, build the full URL
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/' . $m['image_url'];
        }
    }
    
    $popularItems = [];
    if (!empty($m['popular_items'])) {
        $popularItems = json_decode($m['popular_items'], true);
        if (!is_array($popularItems)) {
            $popularItems = explode(',', $m['popular_items']);
        }
    }

    $tags = [];
    if (!empty($m['tags'])) {
        $tags = json_decode($m['tags'], true);
        if (!is_array($tags)) {
            $tags = explode(',', $m['tags']);
        }
    }

    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'category' => $m['category'] ?? '',
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'delivery_info' => $m['delivery_info'] ?? '',
        'image_url' => $imageUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_promoted' => boolval($m['is_promoted'] ?? false),
        'full_description' => $m['full_description'] ?? '',
        'address' => $m['address'] ?? '',
        'phone' => $m['phone'] ?? '',
        'popular_items' => $popularItems,
        'min_order' => $m['min_order'] ?? '',
        'delivery_time' => $m['delivery_time'] ?? '',
        'delivery_fee' => floatval($m['delivery_fee'] ?? 0),
        'distance' => $m['distance'] ?? '',
        'tags' => $tags,
        'created_at' => $m['created_at'] ?? '',
        'updated_at' => $m['updated_at'] ?? ''
    ];
}

/*********************************
 * FORMAT REVIEW DATA
 *********************************/
function formatReviewData($review) {
    global $baseUrl;
    
    $avatarUrl = '';
    if (!empty($review['user_avatar'])) {
        // If it's already a full URL, use it as is
        if (strpos($review['user_avatar'], 'http') === 0) {
            $avatarUrl = $review['user_avatar'];
        } else {
            // Otherwise, build the full URL
            $avatarUrl = rtrim($baseUrl, '/') . '/uploads/avatars/' . $review['user_avatar'];
        }
    }
    
    return [
        'id' => $review['id'],
        'user_id' => $review['user_id'],
        'user_name' => $review['user_name'] ?? 'Anonymous',
        'user_avatar' => $avatarUrl,
        'rating' => intval($review['rating'] ?? 0),
        'comment' => $review['comment'] ?? '',
        'created_at' => $review['created_at'] ?? ''
    ];
}

/*********************************
 * FORMAT MENU ITEM DATA
 *********************************/
function formatMenuItemData($item, $baseUrl) {
    $imageUrl = '';
    if (!empty($item['image_url'])) {
        // If it's already a full URL, use it as is
        if (strpos($item['image_url'], 'http') === 0) {
            $imageUrl = $item['image_url'];
        } else {
            // Otherwise, build the full URL
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu-items/' . $item['image_url'];
        }
    }
    
    // Parse options if they exist
    $options = [];
    if (!empty($item['options'])) {
        try {
            $options = json_decode($item['options'], true);
            if (!is_array($options)) {
                $options = [];
            }
        } catch (Exception $e) {
            $options = [];
        }
    }

    // Parse ingredients if they exist
    $ingredients = [];
    if (!empty($item['ingredients'])) {
        try {
            $ingredients = json_decode($item['ingredients'], true);
            if (!is_array($ingredients)) {
                $ingredients = explode(',', $item['ingredients']);
            }
        } catch (Exception $e) {
            $ingredients = [];
        }
    }

    return [
        'id' => $item['id'],
        'name' => $item['name'] ?? '',
        'description' => $item['description'] ?? '',
        'price' => $item['price'] ?? '',
        'image_url' => $imageUrl,
        'category' => $item['category'] ?? '',
        'category_name' => $item['category_name'] ?? $item['category'] ?? '',
        'is_available' => boolval($item['is_available'] ?? true),
        'is_popular' => boolval($item['is_popular'] ?? false),
        'options' => $options,
        'ingredients' => $ingredients,
        'calories' => $item['calories'] ?? '',
        'preparation_time' => $item['preparation_time'] ?? '',
        'created_at' => $item['created_at'] ?? '',
        'updated_at' => $item['updated_at'] ?? ''
    ];
}
?>