<?php
/*********************************
 * CORS Configuration
 *********************************/
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, X-Device-ID, X-Platform, X-App-Version");
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
$baseUrl = "https://dropx12-production.up.railway.app";

/*********************************
 * INITIALIZATION & HELPER FUNCTIONS
 *********************************/
function initDatabase() {
    $db = new Database();
    return $db->getConnection();
}

function getBaseUrl() {
    global $baseUrl;
    return $baseUrl;
}

/*********************************
 * AUTHENTICATION HELPER
 *********************************/
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/*********************************
 * ROUTER - FIXED PATH PARSING
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    $requestUri = $_SERVER['REQUEST_URI'];
    
    // Remove query string if present
    $path = parse_url($requestUri, PHP_URL_PATH);
    $queryString = parse_url($requestUri, PHP_URL_QUERY);
    
    // Parse query parameters
    parse_str($queryString ?? '', $queryParams);
    
    error_log("=== ROUTER DEBUG ===");
    error_log("Full URI: " . $requestUri);
    error_log("Path: " . $path);
    error_log("Query String: " . ($queryString ?: 'none'));
    error_log("Method: " . $method);
    
    // Initialize database
    $conn = initDatabase();
    $baseUrl = getBaseUrl();
    
    if (!$conn) {
        ResponseHandler::error('Database connection failed', 500);
    }
    
    // === FIXED ROUTING LOGIC ===
    
    // Handle /merchants.php/3/menu
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/menu$#', $path, $matches)) {
        error_log("Matched menu endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        $includeVariants = isset($queryParams['include_variants'])
            ? filter_var($queryParams['include_variants'], FILTER_VALIDATE_BOOLEAN)
            : true;
        $includeAddOns = isset($queryParams['include_add_ons'])
            ? filter_var($queryParams['include_add_ons'], FILTER_VALIDATE_BOOLEAN)
            : true;
        getMerchantMenu($conn, $merchantId, $baseUrl, $includeVariants, $includeAddOns);
        exit();
    }
    
    // Handle /merchants.php/3/categories
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/categories$#', $path, $matches)) {
        error_log("Matched categories endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantCategories($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/3/reviews
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/reviews$#', $path, $matches)) {
        error_log("Matched reviews endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantReviews($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/3/hours
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/hours$#', $path, $matches)) {
        error_log("Matched hours endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantOperatingHours($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/3/promotions
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/promotions$#', $path, $matches)) {
        error_log("Matched promotions endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantPromotions($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/3/cuisine-types
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/cuisine-types$#', $path, $matches)) {
        error_log("Matched cuisine types endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantCuisineTypes($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/3/payment-methods
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/payment-methods$#', $path, $matches)) {
        error_log("Matched payment methods endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantPaymentMethods($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/3/stats
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)/stats$#', $path, $matches)) {
        error_log("Matched stats endpoint for merchant ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantStats($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/3 (merchant details)
    if ($method === 'GET' && preg_match('#/merchants\.php/(\d+)$#', $path, $matches)) {
        error_log("Matched merchant details endpoint for ID: " . $matches[1]);
        $merchantId = intval($matches[1]);
        getMerchantDetails($conn, $merchantId, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/nearby
    if ($method === 'GET' && preg_match('#/merchants\.php/nearby$#', $path)) {
        error_log("Matched nearby merchants endpoint");
        getNearbyMerchants($conn, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/by-category
    if ($method === 'GET' && preg_match('#/merchants\.php/by-category$#', $path)) {
        error_log("Matched merchants by category endpoint");
        getMerchantsByCategory($conn, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php/favorites
    if ($method === 'GET' && preg_match('#/merchants\.php/favorites$#', $path)) {
        error_log("Matched favorites endpoint");
        getFavoriteMerchants($conn, $baseUrl);
        exit();
    }
    
    // Handle /merchants.php (merchants list)
    if ($method === 'GET' && preg_match('#/merchants\.php$#', $path)) {
        error_log("Matched merchants list endpoint");
        getMerchantsList($conn, $baseUrl);
        exit();
    }
    
    // Handle POST requests to merchants.php
    if ($method === 'POST' && preg_match('#/merchants\.php$#', $path)) {
        error_log("Matched POST endpoint");
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    // Handle POST requests to merchants.php/favorite
    if ($method === 'POST' && preg_match('#/merchants\.php/favorite$#', $path)) {
        error_log("Matched favorite POST endpoint");
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    // Handle POST requests to merchants.php/review
    if ($method === 'POST' && preg_match('#/merchants\.php/review$#', $path)) {
        error_log("Matched review POST endpoint");
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    // Handle POST requests to merchants.php/report
    if ($method === 'POST' && preg_match('#/merchants\.php/report$#', $path)) {
        error_log("Matched report POST endpoint");
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    // Handle POST requests to merchants.php/check-availability
    if ($method === 'POST' && preg_match('#/merchants\.php/check-availability$#', $path)) {
        error_log("Matched check availability POST endpoint");
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    // Handle POST requests to merchants.php/check-delivery
    if ($method === 'POST' && preg_match('#/merchants\.php/check-delivery$#', $path)) {
        error_log("Matched check delivery POST endpoint");
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    // Handle POST requests to merchants.php/multiple
    if ($method === 'POST' && preg_match('#/merchants\.php/multiple$#', $path)) {
        error_log("Matched multiple merchants POST endpoint");
        handlePostRequest($conn, $baseUrl);
        exit();
    }
    
    // If no route matches
    error_log("No route matched for path: " . $path);
    ResponseHandler::error('Endpoint not found: ' . $path, 404);
    
} catch (Exception $e) {
    error_log("Router Error: " . $e->getMessage());
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET MERCHANTS LIST - ENHANCED
 *********************************/
function getMerchantsList($conn, $baseUrl) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $category = $_GET['category'] ?? '';
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'rating';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $minRating = floatval($_GET['min_rating'] ?? 0);
    $isOpen = $_GET['is_open'] ?? null;
    $isFeatured = $_GET['is_featured'] ?? null;
    $businessType = $_GET['business_type'] ?? '';
    $cuisineType = $_GET['cuisine_type'] ?? '';
    $deliveryFee = $_GET['delivery_fee'] ?? null;
    $minDeliveryFee = isset($_GET['min_delivery_fee']) ? floatval($_GET['min_delivery_fee']) : null;
    $maxDeliveryFee = isset($_GET['max_delivery_fee']) ? floatval($_GET['max_delivery_fee']) : null;
    $acceptsMpamba = $_GET['accepts_mpamba'] ?? null;
    $acceptsCard = $_GET['accepts_card'] ?? null;
    $acceptsCash = $_GET['accepts_cash'] ?? null;
    $userLatitude = isset($_GET['user_latitude']) ? floatval($_GET['user_latitude']) : null;
    $userLongitude = isset($_GET['user_longitude']) ? floatval($_GET['user_longitude']) : null;
    $maxDistance = isset($_GET['max_distance']) ? floatval($_GET['max_distance']) : null;
    $hasPromotions = $_GET['has_promotions'] ?? null;
    $minPrepTime = isset($_GET['min_prep_time']) ? intval($_GET['min_prep_time']) : null;
    $maxPrepTime = isset($_GET['max_prep_time']) ? intval($_GET['max_prep_time']) : null;

    $whereConditions = ["m.is_active = 1"];
    $params = [];

    if ($category && $category !== 'All') {
        $whereConditions[] = "m.category = :category";
        $params[':category'] = $category;
    }

    if ($businessType) {
        $whereConditions[] = "m.business_type = :business_type";
        $params[':business_type'] = $businessType;
    }

    if ($cuisineType) {
        $whereConditions[] = "JSON_CONTAINS(m.cuisine_type, :cuisine_type)";
        $params[':cuisine_type'] = json_encode($cuisineType);
    }

    if ($search) {
        $whereConditions[] = "(m.name LIKE :search OR m.category LIKE :search OR m.tags LIKE :search OR m.description LIKE :search)";
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

    if ($isFeatured !== null) {
        $whereConditions[] = "m.is_featured = :is_featured";
        $params[':is_featured'] = $isFeatured === 'true' ? 1 : 0;
    }

    if ($deliveryFee !== null) {
        if ($deliveryFee === 'free') {
            $whereConditions[] = "m.delivery_fee = 0";
        } elseif ($deliveryFee === 'paid') {
            $whereConditions[] = "m.delivery_fee > 0";
        }
    }

    if ($minDeliveryFee !== null) {
        $whereConditions[] = "m.delivery_fee >= :min_delivery_fee";
        $params[':min_delivery_fee'] = $minDeliveryFee;
    }

    if ($maxDeliveryFee !== null) {
        $whereConditions[] = "m.delivery_fee <= :max_delivery_fee";
        $params[':max_delivery_fee'] = $maxDeliveryFee;
    }

    if ($acceptsMpamba !== null) {
        $acceptsMpamba = $acceptsMpamba === 'true' ? 1 : 0;
        $whereConditions[] = "JSON_CONTAINS(m.payment_methods, '\"mpamba\"') = :accepts_mpamba";
        $params[':accepts_mpamba'] = $acceptsMpamba;
    }

    if ($acceptsCard !== null) {
        $acceptsCard = $acceptsCard === 'true' ? 1 : 0;
        $whereConditions[] = "JSON_CONTAINS(m.payment_methods, '\"card\"') = :accepts_card";
        $params[':accepts_card'] = $acceptsCard;
    }

    if ($acceptsCash !== null) {
        $acceptsCash = $acceptsCash === 'true' ? 1 : 0;
        $whereConditions[] = "JSON_CONTAINS(m.payment_methods, '\"cash\"') = :accepts_cash";
        $params[':accepts_cash'] = $acceptsCash;
    }

    if ($hasPromotions !== null) {
        $hasPromotions = $hasPromotions === 'true' ? 1 : 0;
        $whereConditions[] = "EXISTS (SELECT 1 FROM promotions WHERE merchant_id = m.id AND is_active = 1 AND start_date <= NOW() AND end_date >= NOW()) = :has_promotions";
        $params[':has_promotions'] = $hasPromotions;
    }

    if ($minPrepTime !== null) {
        $whereConditions[] = "CAST(SUBSTRING_INDEX(m.preparation_time, '-', 1) AS UNSIGNED) >= :min_prep_time";
        $params[':min_prep_time'] = $minPrepTime;
    }

    if ($maxPrepTime !== null) {
        $whereConditions[] = "CAST(SUBSTRING_INDEX(m.preparation_time, '-', -1) AS UNSIGNED) <= :max_prep_time";
        $params[':max_prep_time'] = $maxPrepTime;
    }

    // Distance calculation if user location provided
    if ($userLatitude !== null && $userLongitude !== null) {
        // Haversine formula for distance calculation
        $distanceFormula = "ROUND(6371 * 2 * ASIN(SQRT(POWER(SIN((:user_lat - ABS(m.latitude)) * PI()/180 / 2), 2) + COS(:user_lat * PI()/180) * COS(ABS(m.latitude) * PI()/180) * POWER(SIN((:user_lng - m.longitude) * PI()/180 / 2), 2))), 1)";
        
        if ($maxDistance !== null) {
            $whereConditions[] = "$distanceFormula <= :max_distance";
            $params[':user_lat'] = $userLatitude;
            $params[':user_lng'] = $userLongitude;
            $params[':max_distance'] = $maxDistance;
        }
    }

    $whereClause = count($whereConditions) > 0 ? "WHERE " . implode(" AND ", $whereConditions) : "";

    $allowedSortColumns = ['rating', 'review_count', 'name', 'delivery_fee', 'created_at', 'distance'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'rating';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Build SELECT with distance if location provided
    $selectFields = "m.id,
                m.name,
                m.description,
                m.category,
                m.business_type,
                m.cuisine_type,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.logo_url,
                m.is_open,
                m.is_featured,
                m.delivery_fee,
                m.min_order_amount,
                m.free_delivery_threshold,
                m.delivery_radius,
                m.delivery_time,
                m.preparation_time,
                m.address,
                m.phone,
                m.email,
                m.latitude,
                m.longitude,
                m.opening_hours,
                m.tags,
                m.payment_methods,
                m.created_at,
                m.updated_at";

    if ($userLatitude !== null && $userLongitude !== null) {
        $distanceFormula = "ROUND(6371 * 2 * ASIN(SQRT(POWER(SIN((:user_lat - ABS(m.latitude)) * PI()/180 / 2), 2) + COS(:user_lat * PI()/180) * COS(ABS(m.latitude) * PI()/180) * POWER(SIN((:user_lng - m.longitude) * PI()/180 / 2), 2))), 1) as distance";
        $selectFields = str_replace('m.id', "$distanceFormula, m.id", $selectFields);
    } else {
        $selectFields = "0 as distance, " . $selectFields;
    }

    $sql = "SELECT $selectFields
            FROM merchants m
            $whereClause
            ORDER BY m.is_featured DESC, m.$sortBy $sortOrder
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    // Bind distance calculation parameters if they exist
    if ($userLatitude !== null && $userLongitude !== null) {
        $stmt->bindValue(':user_lat', $userLatitude);
        $stmt->bindValue(':user_lng', $userLongitude);
    }
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } elseif (!in_array($key, [':user_lat', ':user_lng'])) {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get filter options
    $categoryStmt = $conn->prepare(
        "SELECT DISTINCT category FROM merchants WHERE category IS NOT NULL AND category != '' ORDER BY category"
    );
    $categoryStmt->execute();
    $availableCategories = $categoryStmt->fetchAll(PDO::FETCH_COLUMN);

    $businessTypeStmt = $conn->prepare(
        "SELECT DISTINCT business_type FROM merchants WHERE business_type IS NOT NULL ORDER BY business_type"
    );
    $businessTypeStmt->execute();
    $availableBusinessTypes = $businessTypeStmt->fetchAll(PDO::FETCH_COLUMN);

    // Get cuisine types
    $cuisineTypes = [];
    $cuisineStmt = $conn->prepare(
        "SELECT DISTINCT JSON_EXTRACT(cuisine_type, '$') as cuisine FROM merchants WHERE cuisine_type IS NOT NULL"
    );
    $cuisineStmt->execute();
    while ($row = $cuisineStmt->fetch(PDO::FETCH_ASSOC)) {
        $cuisine = json_decode($row['cuisine'], true);
        if (is_array($cuisine)) {
            $cuisineTypes = array_merge($cuisineTypes, $cuisine);
        }
    }
    $cuisineTypes = array_values(array_unique($cuisineTypes));
    sort($cuisineTypes);

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
        ],
        'filters' => [
            'available_categories' => $availableCategories,
            'available_business_types' => $availableBusinessTypes,
            'available_cuisine_types' => $cuisineTypes,
            'business_type_counts' => getBusinessTypeCounts($conn)
        ]
    ]);
}

/*********************************
 * GET NEARBY MERCHANTS
 *********************************/
function getNearbyMerchants($conn, $baseUrl) {
    $latitude = floatval($_GET['latitude'] ?? 0);
    $longitude = floatval($_GET['longitude'] ?? 0);
    $radius = floatval($_GET['radius'] ?? 10.0);
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $category = $_GET['category'] ?? '';
    $isOpen = $_GET['is_open'] ?? null;

    if ($latitude == 0 || $longitude == 0) {
        ResponseHandler::error('Valid latitude and longitude are required', 400);
    }

    $whereConditions = ["m.is_active = 1"];
    $params = [
        ':user_lat' => $latitude,
        ':user_lng' => $longitude,
        ':radius' => $radius
    ];

    if ($category && $category !== 'All') {
        $whereConditions[] = "m.category = :category";
        $params[':category'] = $category;
    }

    if ($isOpen !== null) {
        $whereConditions[] = "m.is_open = :is_open";
        $params[':is_open'] = $isOpen === 'true' ? 1 : 0;
    }

    // Haversine formula for distance calculation
    $distanceFormula = "ROUND(6371 * 2 * ASIN(SQRT(POWER(SIN((:user_lat - ABS(m.latitude)) * PI()/180 / 2), 2) + COS(:user_lat * PI()/180) * COS(ABS(m.latitude) * PI()/180) * POWER(SIN((:user_lng - m.longitude) * PI()/180 / 2), 2))), 1) as distance";
    
    $whereConditions[] = "$distanceFormula <= :radius";

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $sql = "SELECT 
                m.id,
                m.name,
                m.description,
                m.category,
                m.business_type,
                m.cuisine_type,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.logo_url,
                m.is_open,
                m.is_featured,
                m.delivery_fee,
                m.min_order_amount,
                m.free_delivery_threshold,
                m.delivery_radius,
                m.delivery_time,
                m.preparation_time,
                m.address,
                m.phone,
                m.email,
                m.latitude,
                m.longitude,
                $distanceFormula
            FROM merchants m
            $whereClause
            ORDER BY distance ASC
            LIMIT :limit OFFSET :offset";

    $countSql = "SELECT COUNT(*) as total 
                 FROM merchants m 
                 $whereClause";

    $countStmt = $conn->prepare($countSql);
    $countStmt->bindValue(':user_lat', $latitude);
    $countStmt->bindValue(':user_lng', $longitude);
    $countStmt->bindValue(':radius', $radius);
    if (isset($params[':category'])) {
        $countStmt->bindValue(':category', $params[':category']);
    }
    if (isset($params[':is_open'])) {
        $countStmt->bindValue(':is_open', $params[':is_open']);
    }
    $countStmt->execute();
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $conn->prepare($sql);
    $stmt->bindValue(':user_lat', $latitude);
    $stmt->bindValue(':user_lng', $longitude);
    $stmt->bindValue(':radius', $radius);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    if (isset($params[':category'])) {
        $stmt->bindValue(':category', $params[':category']);
    }
    if (isset($params[':is_open'])) {
        $stmt->bindValue(':is_open', $params[':is_open']);
    }
    $stmt->execute();
    
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
 * GET MERCHANTS BY CATEGORY
 *********************************/
function getMerchantsByCategory($conn, $baseUrl) {
    $category = $_GET['category'] ?? '';
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    $userLatitude = isset($_GET['user_latitude']) ? floatval($_GET['user_latitude']) : null;
    $userLongitude = isset($_GET['user_longitude']) ? floatval($_GET['user_longitude']) : null;

    if (!$category) {
        ResponseHandler::error('Category is required', 400);
    }

    $whereConditions = ["m.is_active = 1", "m.category = :category"];
    $params = [':category' => $category];

    // Distance calculation if user location provided
    $selectFields = "m.id,
                m.name,
                m.description,
                m.category,
                m.business_type,
                m.cuisine_type,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.logo_url,
                m.is_open,
                m.is_featured,
                m.delivery_fee,
                m.min_order_amount,
                m.free_delivery_threshold,
                m.delivery_radius,
                m.delivery_time,
                m.preparation_time,
                m.address,
                m.phone,
                m.email,
                m.latitude,
                m.longitude,
                m.created_at";

    if ($userLatitude !== null && $userLongitude !== null) {
        $distanceFormula = "ROUND(6371 * 2 * ASIN(SQRT(POWER(SIN((:user_lat - ABS(m.latitude)) * PI()/180 / 2), 2) + COS(:user_lat * PI()/180) * COS(ABS(m.latitude) * PI()/180) * POWER(SIN((:user_lng - m.longitude) * PI()/180 / 2), 2))), 1) as distance";
        $selectFields = str_replace('m.id', "$distanceFormula, m.id", $selectFields);
    } else {
        $selectFields = "0 as distance, " . $selectFields;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $countSql = "SELECT COUNT(*) as total FROM merchants m $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $orderBy = $userLatitude !== null ? "distance ASC" : "m.rating DESC, m.review_count DESC";
    
    $sql = "SELECT $selectFields
            FROM merchants m
            $whereClause
            ORDER BY m.is_featured DESC, $orderBy
            LIMIT :limit OFFSET :offset";

    $stmt = $conn->prepare($sql);
    
    if ($userLatitude !== null && $userLongitude !== null) {
        $stmt->bindValue(':user_lat', $userLatitude);
        $stmt->bindValue(':user_lng', $userLongitude);
    }
    
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;
    
    foreach ($params as $key => $value) {
        if ($key === ':limit' || $key === ':offset') {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } elseif (!in_array($key, [':user_lat', ':user_lng'])) {
            $stmt->bindValue($key, $value);
        }
    }
    
    $stmt->execute();
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
 * GET MERCHANT DETAILS - ENHANCED
 *********************************/
function getMerchantDetails($conn, $merchantId, $baseUrl) {
    $userId = getCurrentUserId();
    
    $stmt = $conn->prepare(
        "SELECT 
            m.id,
            m.name,
            m.description,
            m.category,
            m.business_type,
            m.cuisine_type,
            m.rating,
            m.review_count,
            m.image_url,
            m.logo_url,
            m.is_open,
            m.is_featured,
            m.address,
            m.phone,
            m.email,
            m.latitude,
            m.longitude,
            m.opening_hours,
            m.tags,
            m.payment_methods,
            m.delivery_fee,
            m.min_order_amount,
            m.free_delivery_threshold,
            m.delivery_radius,
            m.delivery_time,
            m.preparation_time,
            m.distance,
            m.created_at,
            m.updated_at,
            (SELECT COUNT(*) FROM merchant_reviews WHERE merchant_id = m.id) as total_reviews
        FROM merchants m
        WHERE m.id = :id AND m.is_active = 1"
    );
    
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    // Get merchant reviews with pagination
    $reviewsPage = isset($_GET['reviews_page']) ? intval($_GET['reviews_page']) : 1;
    $reviewsLimit = isset($_GET['reviews_limit']) ? intval($_GET['reviews_limit']) : 10;
    $reviewsOffset = ($reviewsPage - 1) * $reviewsLimit;

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
        LIMIT :limit OFFSET :offset"
    );
    
    $reviewsStmt->bindValue(':merchant_id', $merchantId);
    $reviewsStmt->bindValue(':limit', $reviewsLimit, PDO::PARAM_INT);
    $reviewsStmt->bindValue(':offset', $reviewsOffset, PDO::PARAM_INT);
    $reviewsStmt->execute();
    $reviews = $reviewsStmt->fetchAll(PDO::FETCH_ASSOC);

    $reviewsCountStmt = $conn->prepare(
        "SELECT COUNT(*) as total FROM merchant_reviews WHERE merchant_id = :merchant_id"
    );
    $reviewsCountStmt->execute([':merchant_id' => $merchantId]);
    $totalReviews = $reviewsCountStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get menu categories summary with item counts
    $categoriesStmt = $conn->prepare(
        "SELECT 
            COALESCE(NULLIF(category, ''), 'Uncategorized') as name,
            COUNT(*) as item_count
        FROM menu_items 
        WHERE merchant_id = :merchant_id
        AND is_available = 1
        GROUP BY COALESCE(NULLIF(category, ''), 'Uncategorized')
        ORDER BY name
        LIMIT 20"
    );
    
    $categoriesStmt->execute([':merchant_id' => $merchantId]);
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get popular menu items with variants and add-ons
    $popularItemsStmt = $conn->prepare(
        "SELECT 
            mi.id,
            mi.name,
            mi.description,
            mi.price,
            mi.image_url,
            COALESCE(NULLIF(mi.category, ''), 'Uncategorized') as category,
            mi.item_type,
            mi.unit_type,
            mi.unit_value,
            mi.is_available,
            mi.is_popular,
            mi.has_variants,
            mi.variant_type,
            mi.variants_json,
            mi.add_ons_json,
            mi.preparation_time,
            mi.max_quantity,
            mi.stock_quantity
        FROM menu_items mi
        WHERE mi.merchant_id = :merchant_id
        AND mi.is_popular = 1
        AND mi.is_available = 1
        ORDER BY mi.sort_order, mi.name
        LIMIT 6"
    );
    
    $popularItemsStmt->execute([':merchant_id' => $merchantId]);
    $popularMenuItems = $popularItemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get promotions
    $promotionsStmt = $conn->prepare(
        "SELECT 
            id,
            title,
            description,
            discount_type,
            discount_value,
            min_order_amount,
            start_date,
            end_date,
            is_active
        FROM promotions 
        WHERE merchant_id = :merchant_id
        AND is_active = 1
        AND start_date <= NOW()
        AND end_date >= NOW()
        ORDER BY created_at DESC"
    );
    $promotionsStmt->execute([':merchant_id' => $merchantId]);
    $promotions = $promotionsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if favorite
    $isFavorite = false;
    if ($userId) {
        $favStmt = $conn->prepare(
            "SELECT id FROM user_favorite_merchants 
             WHERE user_id = :user_id AND merchant_id = :merchant_id"
        );
        $favStmt->execute([
            ':user_id' => $userId,
            ':merchant_id' => $merchantId
        ]);
        $isFavorite = $favStmt->fetch() ? true : false;
    }

    $merchantData = formatMerchantDetailData($merchant, $baseUrl);
    $merchantData['reviews'] = [
        'data' => array_map('formatReviewData', $reviews),
        'pagination' => [
            'current_page' => $reviewsPage,
            'per_page' => $reviewsLimit,
            'total_items' => $totalReviews,
            'total_pages' => ceil($totalReviews / $reviewsLimit)
        ]
    ];
    $merchantData['is_favorite'] = $isFavorite;
    $merchantData['promotions'] = array_map('formatPromotionData', $promotions);
    
    $merchantData['menu_summary'] = [
        'categories' => array_map(function($cat) {
            return [
                'name' => $cat['name'],
                'item_count' => intval($cat['item_count'])
            ];
        }, $categories),
        'popular_items' => array_map(function($item) use ($baseUrl) {
            return formatMenuItemData($item, $baseUrl);
        }, $popularMenuItems),
        'has_menu' => !empty($categories) || !empty($popularMenuItems)
    ];

    ResponseHandler::success([
        'merchant' => $merchantData
    ]);
}

/*********************************
 * GET MERCHANT MENU - CLEANED (NO QUICK ORDERS)
 *********************************/
function getMerchantMenu($conn, $merchantId, $baseUrl, $includeVariants = true, $includeAddOns = true) {
    error_log("=== MENU DEBUG START ===");
    error_log("Getting menu for merchant ID: " . $merchantId);
    
    // Verify merchant exists
    $checkStmt = $conn->prepare(
        "SELECT id, name, business_type FROM merchants 
         WHERE id = :id AND is_active = 1"
    );
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        error_log("Merchant not found or inactive");
        ResponseHandler::error('Merchant not found or inactive', 404);
    }
    
    error_log("Merchant found: " . $merchant['name']);

    // Get menu items
    $selectFields = "SELECT 
            mi.id,
            mi.name,
            mi.description,
            mi.price,
            mi.image_url,
            COALESCE(NULLIF(mi.category, ''), 'Uncategorized') as category,
            mi.item_type,
            mi.is_available,
            mi.is_popular,
            mi.preparation_time,
            mi.unit_type,
            mi.unit_value,
            mi.max_quantity,
            mi.stock_quantity,
            mi.sort_order";

    if ($includeVariants) {
        $selectFields .= ", mi.has_variants, mi.variant_type, mi.variants_json";
    }
    
    if ($includeAddOns) {
        $selectFields .= ", mi.add_ons_json";
    }

    $menuStmt = $conn->prepare(
        "$selectFields
        FROM menu_items mi
        WHERE mi.merchant_id = :merchant_id
        AND mi.is_available = 1
        ORDER BY mi.sort_order, mi.name ASC"
    );
    
    $menuStmt->execute([':merchant_id' => $merchantId]);
    $menuItems = $menuStmt->fetchAll(PDO::FETCH_ASSOC);
    
    error_log("Found " . count($menuItems) . " menu items");

    // Group items by category
    $categories = [];
    $totalItems = 0;
    
    foreach ($menuItems as $item) {
        $categoryName = $item['category'] ?: 'Uncategorized';
        
        if (!isset($categories[$categoryName])) {
            $categories[$categoryName] = [
                'category_name' => $categoryName,
                'category_info' => [
                    'name' => $categoryName
                ],
                'items' => []
            ];
        }
        
        $categories[$categoryName]['items'][] = formatMenuItemData($item, $baseUrl);
        $totalItems++;
    }
    
    // Convert to indexed array
    $menuList = array_values($categories);
    
    error_log("Organized into " . count($menuList) . " categories");
    error_log("Total items: " . $totalItems);
    error_log("=== MENU DEBUG END ===");
    
    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'business_type' => $merchant['business_type'],
        'menu' => $menuList,
        'total_items' => $totalItems,
        'total_categories' => count($menuList)
    ]);
}

/*********************************
 * GET MERCHANT REVIEWS
 *********************************/
function getMerchantReviews($conn, $merchantId, $baseUrl) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');
    $minRating = isset($_GET['min_rating']) ? intval($_GET['min_rating']) : null;

    $checkStmt = $conn->prepare("SELECT id, name FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    $merchant = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $whereConditions = ["mr.merchant_id = :merchant_id"];
    $params = [':merchant_id' => $merchantId];

    if ($minRating !== null) {
        $whereConditions[] = "mr.rating >= :min_rating";
        $params[':min_rating'] = $minRating;
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    $allowedSortColumns = ['created_at', 'rating'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    $countSql = "SELECT COUNT(*) as total FROM merchant_reviews mr $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $sql = "SELECT 
                mr.id,
                mr.user_id,
                u.full_name as user_name,
                u.avatar as user_avatar,
                mr.rating,
                mr.comment,
                mr.created_at
            FROM merchant_reviews mr
            LEFT JOIN users u ON mr.user_id = u.id
            $whereClause
            ORDER BY mr.$sortBy $sortOrder
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
    $reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get rating distribution
    $distStmt = $conn->prepare(
        "SELECT 
            rating,
            COUNT(*) as count
        FROM merchant_reviews
        WHERE merchant_id = :merchant_id
        GROUP BY rating
        ORDER BY rating DESC"
    );
    $distStmt->execute([':merchant_id' => $merchantId]);
    $distribution = $distStmt->fetchAll(PDO::FETCH_ASSOC);

    $ratingDistribution = [];
    for ($i = 5; $i >= 1; $i--) {
        $ratingDistribution[$i] = 0;
    }
    foreach ($distribution as $d) {
        $ratingDistribution[$d['rating']] = intval($d['count']);
    }

    $formattedReviews = array_map('formatReviewData', $reviews);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'merchant_name' => $merchant['name'],
        'reviews' => $formattedReviews,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ],
        'rating_distribution' => $ratingDistribution
    ]);
}

/*********************************
 * GET MERCHANT OPERATING HOURS
 *********************************/
function getMerchantOperatingHours($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT opening_hours, is_open FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $operatingHours = [];
    if (!empty($merchant['opening_hours'])) {
        $operatingHours = json_decode($merchant['opening_hours'], true);
        if (!is_array($operatingHours)) {
            $operatingHours = [];
        }
    }

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'is_open' => boolval($merchant['is_open']),
        'operating_hours' => $operatingHours
    ]);
}

/*********************************
 * GET MERCHANT PROMOTIONS
 *********************************/
function getMerchantPromotions($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT 
            id,
            title,
            description,
            discount_type,
            discount_value,
            min_order_amount,
            start_date,
            end_date,
            is_active,
            image_url,
            terms_conditions,
            created_at
        FROM promotions 
        WHERE merchant_id = :merchant_id
        AND is_active = 1
        AND start_date <= NOW()
        AND end_date >= NOW()
        ORDER BY created_at DESC"
    );
    
    $stmt->execute([':merchant_id' => $merchantId]);
    $promotions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedPromotions = array_map(function($promo) use ($baseUrl) {
        return formatPromotionData($promo, $baseUrl);
    }, $promotions);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'promotions' => $formattedPromotions,
        'total' => count($formattedPromotions)
    ]);
}

/*********************************
 * GET MERCHANT CUISINE TYPES
 *********************************/
function getMerchantCuisineTypes($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT cuisine_type FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $cuisineTypes = [];
    if (!empty($merchant['cuisine_type'])) {
        $cuisineTypes = json_decode($merchant['cuisine_type'], true);
        if (!is_array($cuisineTypes)) {
            $cuisineTypes = [];
        }
    }

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'cuisine_types' => $cuisineTypes
    ]);
}

/*********************************
 * GET MERCHANT PAYMENT METHODS
 *********************************/
function getMerchantPaymentMethods($conn, $merchantId, $baseUrl) {
    $stmt = $conn->prepare(
        "SELECT payment_methods FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found or inactive', 404);
    }

    $paymentMethods = [];
    if (!empty($merchant['payment_methods'])) {
        $paymentMethods = json_decode($merchant['payment_methods'], true);
        if (!is_array($paymentMethods)) {
            $paymentMethods = explode(',', $merchant['payment_methods']);
        }
    }

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'payment_methods' => $paymentMethods
    ]);
}

/*********************************
 * GET MERCHANT STATS
 *********************************/
function getMerchantStats($conn, $merchantId, $baseUrl) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }

    // Verify merchant exists and belongs to user or user is admin
    $checkStmt = $conn->prepare(
        "SELECT id FROM merchants WHERE id = :id AND (user_id = :user_id OR :is_admin = 1)"
    );
    $checkStmt->execute([
        ':id' => $merchantId,
        ':user_id' => $userId,
        ':is_admin' => isAdmin($conn, $userId) ? 1 : 0
    ]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found or access denied', 403);
    }

    // Get order stats
    $orderStmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as average_order_value,
            COUNT(DISTINCT user_id) as unique_customers
        FROM orders 
        WHERE merchant_id = :merchant_id"
    );
    $orderStmt->execute([':merchant_id' => $merchantId]);
    $orderStats = $orderStmt->fetch(PDO::FETCH_ASSOC);

    // Get today's orders
    $todayStmt = $conn->prepare(
        "SELECT COUNT(*) as today_orders
        FROM orders 
        WHERE merchant_id = :merchant_id
        AND DATE(created_at) = CURDATE()"
    );
    $todayStmt->execute([':merchant_id' => $merchantId]);
    $todayStats = $todayStmt->fetch(PDO::FETCH_ASSOC);

    // Get popular items
    $itemsStmt = $conn->prepare(
        "SELECT 
            oi.item_name,
            COUNT(*) as order_count,
            SUM(oi.quantity) as total_quantity,
            SUM(oi.total) as revenue
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        WHERE o.merchant_id = :merchant_id
        GROUP BY oi.item_name
        ORDER BY total_quantity DESC
        LIMIT 10"
    );
    $itemsStmt->execute([':merchant_id' => $merchantId]);
    $popularItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get rating stats
    $ratingStmt = $conn->prepare(
        "SELECT 
            AVG(rating) as avg_rating,
            COUNT(*) as total_reviews,
            SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) as five_star,
            SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) as four_star,
            SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) as three_star,
            SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) as two_star,
            SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) as one_star
        FROM merchant_reviews 
        WHERE merchant_id = :merchant_id"
    );
    $ratingStmt->execute([':merchant_id' => $merchantId]);
    $ratingStats = $ratingStmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'merchant_id' => $merchantId,
        'orders' => [
            'total' => intval($orderStats['total_orders'] ?? 0),
            'completed' => intval($orderStats['completed_orders'] ?? 0),
            'cancelled' => intval($orderStats['cancelled_orders'] ?? 0),
            'today' => intval($todayStats['today_orders'] ?? 0)
        ],
        'revenue' => [
            'total' => floatval($orderStats['total_revenue'] ?? 0),
            'average_order' => floatval($orderStats['average_order_value'] ?? 0),
            'unique_customers' => intval($orderStats['unique_customers'] ?? 0)
        ],
        'popular_items' => array_map(function($item) {
            return [
                'name' => $item['item_name'],
                'order_count' => intval($item['order_count']),
                'total_quantity' => intval($item['total_quantity']),
                'revenue' => floatval($item['revenue'])
            ];
        }, $popularItems),
        'ratings' => [
            'average' => floatval($ratingStats['avg_rating'] ?? 0),
            'total' => intval($ratingStats['total_reviews'] ?? 0),
            'distribution' => [
                5 => intval($ratingStats['five_star'] ?? 0),
                4 => intval($ratingStats['four_star'] ?? 0),
                3 => intval($ratingStats['three_star'] ?? 0),
                2 => intval($ratingStats['two_star'] ?? 0),
                1 => intval($ratingStats['one_star'] ?? 0)
            ]
        ]
    ]);
}

/*********************************
 * GET FAVORITE MERCHANTS
 *********************************/
function getFavoriteMerchants($conn, $baseUrl) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.business_type,
                m.cuisine_type,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.logo_url,
                m.is_open,
                m.is_featured,
                m.delivery_fee,
                m.min_order_amount,
                m.distance,
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

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) as total 
         FROM user_favorite_merchants ufm
         JOIN merchants m ON ufm.merchant_id = m.id
         WHERE ufm.user_id = :user_id AND m.is_active = 1"
    );
    $countStmt->execute([':user_id' => $userId]);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        $data = formatMerchantListData($m, $baseUrl);
        $data['favorited_at'] = $m['favorited_at'];
        return $data;
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
 * GET MULTIPLE MERCHANTS
 *********************************/
function getMultipleMerchants($conn, $data, $baseUrl) {
    $merchantIds = $data['merchant_ids'] ?? [];
    
    if (empty($merchantIds)) {
        ResponseHandler::error('Merchant IDs are required', 400);
    }

    $placeholders = implode(',', array_fill(0, count($merchantIds), '?'));
    
    $sql = "SELECT 
                m.id,
                m.name,
                m.category,
                m.business_type,
                m.cuisine_type,
                m.rating,
                m.review_count,
                CONCAT(m.delivery_time, ' • MK ', FORMAT(m.delivery_fee, 0), ' fee') as delivery_info,
                m.image_url,
                m.logo_url,
                m.is_open,
                m.is_featured,
                m.delivery_fee,
                m.min_order_amount,
                m.distance,
                m.created_at
            FROM merchants m
            WHERE m.id IN ($placeholders)
            AND m.is_active = 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute($merchantIds);
    $merchants = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedMerchants = array_map(function($m) use ($baseUrl) {
        return formatMerchantListData($m, $baseUrl);
    }, $merchants);

    ResponseHandler::success([
        'merchants' => $formattedMerchants
    ]);
}

/*********************************
 * POST REQUEST HANDLER - ENHANCED
 *********************************/
function handlePostRequest($conn, $baseUrl) {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    
    // Check specific endpoints first
    if (strpos($path, '/favorite') !== false) {
        toggleMerchantFavorite($conn, $input);
        return;
    }
    
    if (strpos($path, '/review') !== false) {
        createMerchantReview($conn, $input);
        return;
    }
    
    if (strpos($path, '/report') !== false) {
        reportMerchant($conn, $input);
        return;
    }
    
    if (strpos($path, '/check-availability') !== false) {
        checkMerchantAvailability($conn, $input);
        return;
    }
    
    if (strpos($path, '/check-delivery') !== false) {
        checkDeliveryAvailability($conn, $input);
        return;
    }
    
    if (strpos($path, '/multiple') !== false) {
        getMultipleMerchants($conn, $input, $baseUrl);
        return;
    }
    
    // Fallback to action parameter
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
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

function createMerchantReview($conn, $data) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $merchantId = $data['merchant_id'] ?? null;
    $rating = intval($data['rating'] ?? 0);
    $comment = trim($data['comment'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if ($rating < 1 || $rating > 5) {
        ResponseHandler::error('Rating must be between 1 and 5', 400);
    }

    if (!$comment) {
        ResponseHandler::error('Review comment is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

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

    updateMerchantRating($conn, $merchantId);

    ResponseHandler::success([], 'Review submitted successfully');
}

function toggleMerchantFavorite($conn, $data) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $merchantId = $data['merchant_id'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $favStmt = $conn->prepare(
        "SELECT id FROM user_favorite_merchants 
         WHERE user_id = :user_id AND merchant_id = :merchant_id"
    );
    $favStmt->execute([
        ':user_id' => $userId,
        ':merchant_id' => $merchantId
    ]);
    
    if ($favStmt->fetch()) {
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

function reportMerchant($conn, $data) {
    $userId = getCurrentUserId();
    if (!$userId) {
        ResponseHandler::error('Authentication required', 401);
    }
    
    $merchantId = $data['merchant_id'] ?? null;
    $reason = trim($data['reason'] ?? '');
    $details = trim($data['details'] ?? '');

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    if (!$reason) {
        ResponseHandler::error('Report reason is required', 400);
    }

    $checkStmt = $conn->prepare("SELECT id FROM merchants WHERE id = :id AND is_active = 1");
    $checkStmt->execute([':id' => $merchantId]);
    
    if (!$checkStmt->fetch()) {
        ResponseHandler::error('Merchant not found', 404);
    }

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

function checkMerchantAvailability($conn, $data) {
    $merchantId = $data['merchant_id'] ?? null;
    $dateTime = isset($data['date_time']) ? new DateTime($data['date_time']) : new DateTime();

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $stmt = $conn->prepare(
        "SELECT is_open, opening_hours FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $available = boolval($merchant['is_open']);
    $reasons = [];

    if (!$available) {
        $reasons[] = 'Merchant is currently closed';
    }

    // Check operating hours if available
    if (!empty($merchant['opening_hours'])) {
        $hours = json_decode($merchant['opening_hours'], true);
        if (is_array($hours)) {
            $dayOfWeek = strtolower($dateTime->format('l'));
            if (isset($hours[$dayOfWeek])) {
                $timeRange = $hours[$dayOfWeek];
                if ($timeRange !== 'closed') {
                    list($open, $close) = explode('-', $timeRange);
                    $currentTime = $dateTime->format('H:i');
                    if ($currentTime < $open || $currentTime > $close) {
                        $available = false;
                        $reasons[] = 'Outside operating hours';
                    }
                }
            }
        }
    }

    ResponseHandler::success([
        'available' => $available,
        'reasons' => $reasons,
        'merchant_id' => $merchantId,
        'datetime' => $dateTime->format('Y-m-d H:i:s')
    ]);
}

function checkDeliveryAvailability($conn, $data) {
    $merchantId = $data['merchant_id'] ?? null;
    $addressId = $data['address_id'] ?? null;
    $latitude = $data['latitude'] ?? null;
    $longitude = $data['longitude'] ?? null;

    if (!$merchantId) {
        ResponseHandler::error('Merchant ID is required', 400);
    }

    $stmt = $conn->prepare(
        "SELECT delivery_radius, delivery_fee, min_order_amount, free_delivery_threshold 
         FROM merchants WHERE id = :id AND is_active = 1"
    );
    $stmt->execute([':id' => $merchantId]);
    $merchant = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$merchant) {
        ResponseHandler::error('Merchant not found', 404);
    }

    $available = true;
    $reasons = [];
    $deliveryFee = floatval($merchant['delivery_fee']);

    // Check if address is within delivery radius
    if ($latitude && $longitude) {
        // Get merchant coordinates
        $coordStmt = $conn->prepare("SELECT latitude, longitude FROM merchants WHERE id = :id");
        $coordStmt->execute([':id' => $merchantId]);
        $coords = $coordStmt->fetch(PDO::FETCH_ASSOC);

        if ($coords && $coords['latitude'] && $coords['longitude']) {
            // Calculate distance using Haversine formula
            $distance = calculateDistance(
                $coords['latitude'],
                $coords['longitude'],
                $latitude,
                $longitude
            );

            if ($distance > $merchant['delivery_radius']) {
                $available = false;
                $reasons[] = 'Location is outside delivery radius';
            }
        }
    }

    ResponseHandler::success([
        'available' => $available,
        'reasons' => $reasons,
        'merchant_id' => $merchantId,
        'delivery_fee' => $deliveryFee,
        'formatted_delivery_fee' => 'MK ' . number_format($deliveryFee, 2),
        'min_order_amount' => floatval($merchant['min_order_amount']),
        'free_delivery_threshold' => floatval($merchant['free_delivery_threshold']),
        'delivery_radius' => intval($merchant['delivery_radius'])
    ]);
}

/*********************************
 * HELPER FUNCTIONS
 *********************************/
function getBusinessTypeCounts($conn) {
    $counts = [];
    
    $stmt = $conn->prepare(
        "SELECT 
            business_type,
            COUNT(*) as count
        FROM merchants 
        WHERE business_type IS NOT NULL
        AND is_active = 1
        GROUP BY business_type"
    );
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($results as $row) {
        $counts[$row['business_type']] = intval($row['count']);
    }
    
    return $counts;
}

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

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));

    return round($earthRadius * $c, 1);
}

function isAdmin($conn, $userId) {
    $stmt = $conn->prepare("SELECT is_admin FROM users WHERE id = :id");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user && ($user['is_admin'] == 1);
}

/*********************************
 * FORMATTING FUNCTIONS - ENHANCED
 *********************************/
function formatMerchantListData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/merchants/' . ltrim($m['image_url'], '/');
        }
    }
    
    $logoUrl = '';
    if (!empty($m['logo_url'])) {
        if (strpos($m['logo_url'], 'http') === 0) {
            $logoUrl = $m['logo_url'];
        } else {
            $logoUrl = rtrim($baseUrl, '/') . '/uploads/merchants/logos/' . ltrim($m['logo_url'], '/');
        }
    }
    
    $cuisineTypes = [];
    if (!empty($m['cuisine_type'])) {
        $cuisineTypes = json_decode($m['cuisine_type'], true);
        if (!is_array($cuisineTypes)) {
            $cuisineTypes = [];
        }
    }
    
    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'description' => $m['description'] ?? '',
        'category' => $m['category'] ?? '',
        'business_type' => $m['business_type'] ?? 'restaurant',
        'cuisine_types' => $cuisineTypes,
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'delivery_info' => $m['delivery_info'] ?? '',
        'image_url' => $imageUrl,
        'logo_url' => $logoUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_featured' => boolval($m['is_featured'] ?? false),
        'delivery_fee' => floatval($m['delivery_fee'] ?? 0),
        'formatted_delivery_fee' => 'MK ' . number_format(floatval($m['delivery_fee'] ?? 0), 2),
        'min_order_amount' => floatval($m['min_order_amount'] ?? 0),
        'free_delivery_threshold' => floatval($m['free_delivery_threshold'] ?? 0),
        'delivery_radius' => intval($m['delivery_radius'] ?? 5),
        'delivery_time' => $m['delivery_time'] ?? '',
        'preparation_time' => $m['preparation_time'] ?? '15-20 min',
        'address' => $m['address'] ?? '',
        'phone' => $m['phone'] ?? '',
        'email' => $m['email'] ?? '',
        'latitude' => floatval($m['latitude'] ?? 0),
        'longitude' => floatval($m['longitude'] ?? 0),
        'distance' => floatval($m['distance'] ?? 0),
        'created_at' => $m['created_at'] ?? '',
        'updated_at' => $m['updated_at'] ?? ''
    ];
}

function formatMerchantDetailData($m, $baseUrl) {
    $imageUrl = '';
    if (!empty($m['image_url'])) {
        if (strpos($m['image_url'], 'http') === 0) {
            $imageUrl = $m['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/merchants/' . ltrim($m['image_url'], '/');
        }
    }
    
    $logoUrl = '';
    if (!empty($m['logo_url'])) {
        if (strpos($m['logo_url'], 'http') === 0) {
            $logoUrl = $m['logo_url'];
        } else {
            $logoUrl = rtrim($baseUrl, '/') . '/uploads/merchants/logos/' . ltrim($m['logo_url'], '/');
        }
    }
    
    $cuisineTypes = [];
    if (!empty($m['cuisine_type'])) {
        $cuisineTypes = json_decode($m['cuisine_type'], true);
        if (!is_array($cuisineTypes)) {
            $cuisineTypes = [];
        }
    }

    $tags = [];
    if (!empty($m['tags'])) {
        $tags = json_decode($m['tags'], true);
        if (!is_array($tags)) {
            $tags = explode(',', $m['tags']);
        }
        $tags = array_map('trim', $tags);
    }

    $paymentMethods = [];
    if (!empty($m['payment_methods'])) {
        $paymentMethods = json_decode($m['payment_methods'], true);
        if (!is_array($paymentMethods)) {
            $paymentMethods = explode(',', $m['payment_methods']);
        }
        $paymentMethods = array_map('trim', $paymentMethods);
    }

    $operatingHours = [];
    if (!empty($m['opening_hours'])) {
        $operatingHours = json_decode($m['opening_hours'], true);
        if (!is_array($operatingHours)) {
            $operatingHours = [];
        }
    }

    return [
        'id' => $m['id'],
        'name' => $m['name'] ?? '',
        'description' => $m['description'] ?? '',
        'category' => $m['category'] ?? '',
        'business_type' => $m['business_type'] ?? 'restaurant',
        'cuisine_types' => $cuisineTypes,
        'rating' => floatval($m['rating'] ?? 0),
        'review_count' => intval($m['review_count'] ?? 0),
        'total_reviews' => intval($m['total_reviews'] ?? 0),
        'image_url' => $imageUrl,
        'logo_url' => $logoUrl,
        'is_open' => boolval($m['is_open'] ?? false),
        'is_featured' => boolval($m['is_featured'] ?? false),
        'address' => $m['address'] ?? '',
        'phone' => $m['phone'] ?? '',
        'email' => $m['email'] ?? '',
        'latitude' => floatval($m['latitude'] ?? 0),
        'longitude' => floatval($m['longitude'] ?? 0),
        'operating_hours' => $operatingHours,
        'tags' => $tags,
        'payment_methods' => $paymentMethods,
        'delivery_fee' => floatval($m['delivery_fee'] ?? 0),
        'formatted_delivery_fee' => 'MK ' . number_format(floatval($m['delivery_fee'] ?? 0), 2),
        'min_order_amount' => floatval($m['min_order_amount'] ?? 0),
        'free_delivery_threshold' => floatval($m['free_delivery_threshold'] ?? 0),
        'delivery_radius' => intval($m['delivery_radius'] ?? 5),
        'delivery_time' => $m['delivery_time'] ?? '',
        'preparation_time' => $m['preparation_time'] ?? '15-20 min',
        'distance' => floatval($m['distance'] ?? 0),
        'created_at' => $m['created_at'] ?? '',
        'updated_at' => $m['updated_at'] ?? ''
    ];
}

function formatReviewData($review) {
    global $baseUrl;
    
    $avatarUrl = '';
    if (!empty($review['user_avatar'])) {
        if (strpos($review['user_avatar'], 'http') === 0) {
            $avatarUrl = $review['user_avatar'];
        } else {
            $avatarUrl = rtrim($baseUrl, '/') . '/uploads/avatars/' . ltrim($review['user_avatar'], '/');
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

function formatPromotionData($promo, $baseUrl = null) {
    $imageUrl = '';
    if ($baseUrl && !empty($promo['image_url'])) {
        if (strpos($promo['image_url'], 'http') === 0) {
            $imageUrl = $promo['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/promotions/' . ltrim($promo['image_url'], '/');
        }
    }
    
    return [
        'id' => $promo['id'],
        'title' => $promo['title'] ?? '',
        'description' => $promo['description'] ?? '',
        'discount_type' => $promo['discount_type'] ?? 'percentage',
        'discount_value' => floatval($promo['discount_value'] ?? 0),
        'min_order_amount' => floatval($promo['min_order_amount'] ?? 0),
        'image_url' => $imageUrl,
        'terms_conditions' => $promo['terms_conditions'] ?? '',
        'start_date' => $promo['start_date'] ?? '',
        'end_date' => $promo['end_date'] ?? '',
        'is_active' => boolval($promo['is_active'] ?? true),
        'created_at' => $promo['created_at'] ?? ''
    ];
}

function formatMenuItemData($item, $baseUrl) {
    // SAFETY CHECK: If $item is not an array, try to decode it or return empty array
    if (!is_array($item)) {
        error_log("formatMenuItemData: Expected array, got " . gettype($item));
        
        // If it's a string, try to decode JSON
        if (is_string($item)) {
            $decoded = json_decode($item, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $item = $decoded;
                error_log("Successfully decoded JSON string to array");
            } else {
                error_log("Failed to decode JSON string: " . $item);
                return []; // Return empty array for invalid items
            }
        } else {
            return []; // Return empty array for other non-array types
        }
    }
    
    // Now it's safe to use array_merge since we know $item is an array
    $item = array_merge([
        'stock_quantity' => null,
        'is_available' => true,
        'is_popular' => false,
        'has_variants' => false,
        'variant_type' => null,
        'variants_json' => null,
        'add_ons_json' => null,
        'image_url' => '',
        'description' => '',
        'category' => 'Uncategorized',
        'item_type' => 'food',
        'unit_type' => 'piece',
        'unit_value' => 1,
        'preparation_time' => 15,
        'max_quantity' => 99,
        'sort_order' => 0
    ], $item);
    
    $imageUrl = '';
    if (!empty($item['image_url'])) {
        if (strpos($item['image_url'], 'http') === 0) {
            $imageUrl = $item['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/menu_items/' . ltrim($item['image_url'], '/');
        }
    }
    
    // Parse variants - with safety checks
    $variants = [];
    if (!empty($item['variants_json'])) {
        // If variants_json is already an array, use it directly
        if (is_array($item['variants_json'])) {
            $variants = $item['variants_json'];
        } 
        // If it's a string, decode it
        else if (is_string($item['variants_json'])) {
            $variants = json_decode($item['variants_json'], true);
            if (!is_array($variants)) {
                $variants = [];
            }
        }
        
        // Remove badge and price_per_unit from each variant if they exist
        if (is_array($variants)) {
            foreach ($variants as &$variant) {
                if (is_array($variant)) {
                    unset($variant['badge']);
                    unset($variant['price_per_unit']);
                }
            }
        } else {
            $variants = [];
        }
    }

    // Parse add-ons - with safety checks
    $addOns = [];
    if (!empty($item['add_ons_json'])) {
        // If add_ons_json is already an array, use it directly
        if (is_array($item['add_ons_json'])) {
            $addOns = $item['add_ons_json'];
        }
        // If it's a string, decode it
        else if (is_string($item['add_ons_json'])) {
            $addOns = json_decode($item['add_ons_json'], true);
            if (!is_array($addOns)) {
                $addOns = [];
            }
        }
    }

    $displayPrice = $item['price'];
    $displayUnit = $item['unit_type'];
    
    if ($item['unit_type'] === 'kg' && $item['unit_value'] != 1) {
        $displayPrice = $item['price'] / $item['unit_value'];
        $displayUnit = 'g';
        $displayPrice = round($displayPrice * 1000, 2);
    }

    return [
        'id' => $item['id'] ?? null,
        'name' => $item['name'] ?? '',
        'description' => $item['description'],
        'price' => floatval($item['price'] ?? 0),
        'display_price' => floatval($displayPrice),
        'image_url' => $imageUrl,
        'category' => $item['category'],
        'item_type' => $item['item_type'],
        'unit_type' => $item['unit_type'],
        'unit_value' => floatval($item['unit_value']),
        'is_available' => boolval($item['is_available']),
        'is_popular' => boolval($item['is_popular']),
        'has_variants' => boolval($item['has_variants']),
        'variant_type' => $item['variant_type'],
        'variants' => $variants,
        'add_ons' => $addOns,
        'preparation_time' => intval($item['preparation_time']),
        'max_quantity' => intval($item['max_quantity']),
        'stock_quantity' => $item['stock_quantity'] !== null ? intval($item['stock_quantity']) : null,
        'in_stock' => ($item['stock_quantity'] === null || $item['stock_quantity'] > 0) && $item['is_available'],
        'sort_order' => intval($item['sort_order']),
        'created_at' => $item['created_at'] ?? '',
        'updated_at' => $item['updated_at'] ?? ''
    ];
}
function formatCategoryData($category, $baseUrl) {
    $imageUrl = '';
    if (!empty($category['image_url'])) {
        if (strpos($category['image_url'], 'http') === 0) {
            $imageUrl = $category['image_url'];
        } else {
            $imageUrl = rtrim($baseUrl, '/') . '/uploads/categories/' . ltrim($category['image_url'], '/');
        }
    }
    
    return [
        'id' => intval($category['id'] ?? 0),
        'name' => $category['name'] ?? '',
        'description' => $category['description'] ?? '',
        'image_url' => $imageUrl,
        'display_order' => intval($category['display_order'] ?? 0),
        'item_count' => intval($category['item_count'] ?? 0),
        'item_types' => isset($category['item_types']) && is_array($category['item_types']) ? $category['item_types'] : [],
        'is_active' => boolval($category['is_active'] ?? true)
    ];
}

function formatMenuItemImage($imagePath, $baseUrl) {
    if (empty($imagePath)) {
        return rtrim($baseUrl, '/') . '/uploads/default_food.jpg';
    }
    
    if (strpos($imagePath, 'http') === 0) {
        return $imagePath;
    }
    
    return rtrim($baseUrl, '/') . '/uploads/menu_items/' . ltrim($imagePath, '/');
}
?>