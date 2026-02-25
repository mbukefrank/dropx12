<?php
// api/category.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../config/database.php';

$database = new Database();
$db = $database->getConnection();

$method = $_SERVER['REQUEST_METHOD'];
$action = isset($_GET['action']) ? $_GET['action'] : '';

// Handle preflight requests
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit();
}

switch ($method) {
    case 'GET':
        if ($action === 'main-categories') {
            getMainCategories($db); // Shows item-based categories
        } else if ($action === 'category-merchants') {
            getMerchantsByCategory($db); // Shows merchants under category
        } else if ($action === 'category-items') {
            getItemsByCategory($db); // Shows items directly under category (for quick orders)
        } else if ($action === 'featured') {
            getFeaturedCategories($db);
        } else {
            getAllCategories($db);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'message' => 'Method not allowed'
        ]);
}

/**
 * GET /api/category.php?action=main-categories
 * Returns item-based categories (what users want to eat/buy)
 */
function getMainCategories($db) {
    try {
        // Pre-defined main categories for DropX
        $mainCategories = [
            // Food Categories
            ['id' => 'fast_food', 'name' => 'Fast Food', 'icon' => 'fastfood', 
             'image' => 'https://yourcdn.com/categories/fast-food.jpg',
             'item_types' => ['food'], 'display_order' => 1,
             'subcategories' => ['Burgers', 'Pizza', 'Fries', 'Shawarma', 'Hot Dogs'],
             'description' => 'Burgers, pizza, fries and more'],
             
            ['id' => 'chicken', 'name' => 'Chicken', 'icon' => 'set_meal',
             'image' => 'https://yourcdn.com/categories/chicken.jpg',
             'item_types' => ['food'], 'display_order' => 2,
             'subcategories' => ['Grilled Chicken', 'Fried Chicken', 'Chicken Wings', 'Chicken Buckets'],
             'description' => 'Grilled, fried, wings and buckets'],
             
            ['id' => 'bbq_grill', 'name' => 'BBQ & Grill', 'icon' => 'outdoor_grill',
             'image' => 'https://yourcdn.com/categories/bbq.jpg',
             'item_types' => ['food', 'farm produce'], 'display_order' => 3,
             'subcategories' => ['Goat Meat', 'Beef', 'Nyama Choma', 'Pork', 'Sausages'],
             'description' => 'Goat meat, beef, nyama choma'],
             
            ['id' => 'local_dishes', 'name' => 'Local Dishes', 'icon' => 'rice_bowl',
             'image' => 'https://yourcdn.com/categories/local-dishes.jpg',
             'item_types' => ['food'], 'display_order' => 4,
             'subcategories' => ['Nsima', 'Rice Meals', 'Stews', 'Relishes'],
             'description' => 'Nsima, rice meals, stews'],
             
            ['id' => 'healthy_salads', 'name' => 'Healthy & Salads', 'icon' => 'eco',
             'image' => 'https://yourcdn.com/categories/healthy.jpg',
             'item_types' => ['food'], 'display_order' => 5,
             'subcategories' => ['Fresh Bowls', 'Diet Meals', 'Salads', 'Smoothies'],
             'description' => 'Fresh bowls, diet meals'],
             
            // Grocery Categories
            ['id' => 'groceries', 'name' => 'Groceries', 'icon' => 'shopping_cart',
             'image' => 'https://yourcdn.com/categories/groceries.jpg',
             'item_types' => ['grocery'], 'display_order' => 6,
             'subcategories' => ['Daily Essentials', 'Pantry Staples', 'Snacks', 'Beverages'],
             'description' => 'Daily essentials and more'],
             
            ['id' => 'fresh_meat', 'name' => 'Fresh Meat', 'icon' => 'set_meal',
             'image' => 'https://yourcdn.com/categories/fresh-meat.jpg',
             'item_types' => ['grocery', 'farm produce'], 'display_order' => 7,
             'subcategories' => ['Beef', 'Goat', 'Chicken (Raw)', 'Pork'],
             'description' => 'Beef, goat, chicken raw'],
             
            ['id' => 'fish_seafood', 'name' => 'Fish & Seafood', 'icon' => 'set_meal',
             'image' => 'https://yourcdn.com/categories/fish.jpg',
             'item_types' => ['grocery', 'farm produce'], 'display_order' => 8,
             'subcategories' => ['Fresh Fish', 'Dried Fish', 'Prawns', 'Crab'],
             'description' => 'Fresh and dried fish, seafood'],
             
            ['id' => 'bakery', 'name' => 'Bakery', 'icon' => 'bakery_dining',
             'image' => 'https://yourcdn.com/categories/bakery.jpg',
             'item_types' => ['grocery', 'food'], 'display_order' => 9,
             'subcategories' => ['Bread', 'Cakes', 'Pastries', 'Snacks'],
             'description' => 'Bread, cakes, snacks'],
             
            ['id' => 'drinks', 'name' => 'Drinks', 'icon' => 'local_cafe',
             'image' => 'https://yourcdn.com/categories/drinks.jpg',
             'item_types' => ['grocery', 'food'], 'display_order' => 10,
             'subcategories' => ['Juice', 'Soda', 'Water', 'Energy Drinks'],
             'description' => 'Juice, soda, water'],
             
            // Farm Produce
            ['id' => 'vegetables', 'name' => 'Vegetables', 'icon' => 'grass',
             'image' => 'https://yourcdn.com/categories/vegetables.jpg',
             'item_types' => ['farm produce', 'grocery'], 'display_order' => 11,
             'subcategories' => ['Leafy Greens', 'Root Vegetables', 'Tomatoes', 'Onions'],
             'description' => 'Fresh vegetables from local farms'],
             
            ['id' => 'fruits', 'name' => 'Fruits', 'icon' => 'emoji_nature',
             'image' => 'https://yourcdn.com/categories/fruits.jpg',
             'item_types' => ['farm produce', 'grocery'], 'display_order' => 12,
             'subcategories' => ['Bananas', 'Oranges', 'Apples', 'Mangoes', 'Seasonal'],
             'description' => 'Fresh fruits'],
             
            ['id' => 'tubers', 'name' => 'Tubers & Staples', 'icon' => 'agriculture',
             'image' => 'https://yourcdn.com/categories/tubers.jpg',
             'item_types' => ['farm produce'], 'display_order' => 13,
             'subcategories' => ['Potatoes', 'Cassava', 'Sweet Potatoes', 'Maize'],
             'description' => 'Potatoes, cassava, maize'],
             
            ['id' => 'dairy_eggs', 'name' => 'Dairy & Eggs', 'icon' => 'egg',
             'image' => 'https://yourcdn.com/categories/dairy.jpg',
             'item_types' => ['grocery', 'farm produce'], 'display_order' => 14,
             'subcategories' => ['Milk', 'Eggs', 'Cheese', 'Yogurt'],
             'description' => 'Fresh milk, eggs, and dairy']
        ];
        
        // Get counts from database for each category
        foreach ($mainCategories as &$category) {
            $category['merchant_count'] = getMerchantCountByCategory($db, $category['id'], $category['item_types']);
            $category['item_count'] = getItemCountByCategory($db, $category['id'], $category['item_types']);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Main categories retrieved',
            'data' => [
                'categories' => $mainCategories,
                'total' => count($mainCategories)
            ],
            'statusCode' => 200
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'data' => ['categories' => []],
            'statusCode' => 500
        ]);
    }
}

/**
 * GET /api/category.php?action=category-merchants&category_id=fast_food
 * Returns merchants under a specific category
 */
function getMerchantsByCategory($db) {
    try {
        $categoryId = isset($_GET['category_id']) ? $_GET['category_id'] : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $offset = ($page - 1) * $limit;
        
        if (empty($categoryId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Category ID is required',
                'statusCode' => 400
            ]);
            return;
        }
        
        // Map category to item types and search terms
        $categoryMap = getCategoryMapping($categoryId);
        
        // Build query based on category
        $query = "SELECT 
                    m.*,
                    COUNT(DISTINCT mi.id) as menu_items_count
                  FROM merchants m
                  LEFT JOIN menu_items mi ON m.id = mi.merchant_id AND mi.is_active = 1
                  WHERE m.is_active = 1 ";
        
        $params = [];
        
        // Filter by business type if needed
        if (!empty($categoryMap['business_types'])) {
            $placeholders = implode(',', array_fill(0, count($categoryMap['business_types']), '?'));
            $query .= " AND m.business_type IN ($placeholders)";
            $params = array_merge($params, $categoryMap['business_types']);
        }
        
        // Search in cuisine_type, name, and menu items
        if (!empty($categoryMap['search_terms'])) {
            $searchConditions = [];
            foreach ($categoryMap['search_terms'] as $term) {
                $searchConditions[] = "(m.cuisine_type LIKE ? OR m.name LIKE ? OR mi.category LIKE ?)";
                $params[] = "%$term%";
                $params[] = "%$term%";
                $params[] = "%$term%";
            }
            $query .= " AND (" . implode(' OR ', $searchConditions) . ")";
        }
        
        $query .= " GROUP BY m.id
                    ORDER BY m.is_promoted DESC, m.rating DESC
                    LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $merchants = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $merchants[] = [
                'id' => $row['id'],
                'name' => $row['name'],
                'description' => $row['description'],
                'business_type' => $row['business_type'],
                'rating' => floatval($row['rating']),
                'review_count' => intval($row['review_count']),
                'image_url' => $row['image_url'],
                'logo_url' => $row['logo_url'],
                'is_open' => boolval($row['is_open']),
                'is_promoted' => boolval($row['is_promoted']),
                'delivery_fee' => floatval($row['delivery_fee']),
                'delivery_time' => $row['delivery_time'],
                'min_order_amount' => floatval($row['min_order_amount']),
                'menu_items_count' => intval($row['menu_items_count'])
            ];
        }
        
        // Get total count
        $countQuery = "SELECT COUNT(DISTINCT m.id) as total FROM merchants m WHERE m.is_active = 1";
        $countStmt = $db->query($countQuery);
        $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Merchants retrieved',
            'data' => [
                'merchants' => $merchants,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $limit,
                    'total' => intval($total),
                    'last_page' => ceil($total / $limit)
                ]
            ],
            'statusCode' => 200
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'data' => ['merchants' => []],
            'statusCode' => 500
        ]);
    }
}

/**
 * GET /api/category.php?action=category-items&category_id=fast_food
 * Returns items directly under category (for quick orders)
 */
function getItemsByCategory($db) {
    try {
        $categoryId = isset($_GET['category_id']) ? $_GET['category_id'] : '';
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
        $offset = ($page - 1) * $limit;
        
        if (empty($categoryId)) {
            echo json_encode([
                'success' => false,
                'message' => 'Category ID is required',
                'statusCode' => 400
            ]);
            return;
        }
        
        $categoryMap = getCategoryMapping($categoryId);
        
        // Get quick orders in this category
        $query = "SELECT 
                    qo.*,
                    m.name as merchant_name,
                    m.id as merchant_id
                  FROM quick_orders qo
                  JOIN merchants m ON qo.merchant_id = m.id
                  WHERE qo.is_active = 1 AND m.is_active = 1 ";
        
        $params = [];
        
        if (!empty($categoryMap['search_terms'])) {
            $searchConditions = [];
            foreach ($categoryMap['search_terms'] as $term) {
                $searchConditions[] = "(qo.category LIKE ? OR qo.title LIKE ?)";
                $params[] = "%$term%";
                $params[] = "%$term%";
            }
            $query .= " AND (" . implode(' OR ', $searchConditions) . ")";
        }
        
        if (!empty($categoryMap['item_types'])) {
            $placeholders = implode(',', array_fill(0, count($categoryMap['item_types']), '?'));
            $query .= " AND qo.item_type IN ($placeholders)";
            $params = array_merge($params, $categoryMap['item_types']);
        }
        
        $query .= " ORDER BY qo.is_popular DESC, qo.order_count DESC
                    LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = [
                'id' => $row['id'],
                'title' => $row['title'],
                'description' => $row['description'],
                'category' => $row['category'],
                'item_type' => $row['item_type'],
                'image_url' => $row['image_url'],
                'price' => floatval($row['price']),
                'is_popular' => boolval($row['is_popular']),
                'delivery_time' => $row['delivery_time'],
                'rating' => floatval($row['rating']),
                'merchant' => [
                    'id' => $row['merchant_id'],
                    'name' => $row['merchant_name']
                ]
            ];
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Items retrieved',
            'data' => [
                'items' => $items,
                'count' => count($items)
            ],
            'statusCode' => 200
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'data' => ['items' => []],
            'statusCode' => 500
        ]);
    }
}

/**
 * GET /api/category.php?action=featured
 * Returns featured categories for homepage
 */
function getFeaturedCategories($db) {
    try {
        $featuredIds = ['fast_food', 'chicken', 'groceries', 'vegetables', 'drinks'];
        $featured = [];
        
        foreach ($featuredIds as $id) {
            $category = getCategoryDetails($id);
            if ($category) {
                $category['merchant_count'] = getMerchantCountByCategory($db, $id, $category['item_types']);
                $category['item_count'] = getItemCountByCategory($db, $id, $category['item_types']);
                $featured[] = $category;
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Featured categories retrieved',
            'data' => [
                'categories' => $featured,
                'count' => count($featured)
            ],
            'statusCode' => 200
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage(),
            'data' => ['categories' => []],
            'statusCode' => 500
        ]);
    }
}

// ========== HELPER FUNCTIONS ==========

function getCategoryMapping($categoryId) {
    $mapping = [
        'fast_food' => [
            'name' => 'Fast Food',
            'search_terms' => ['burger', 'pizza', 'fries', 'shawarma', 'fast food'],
            'item_types' => ['food'],
            'business_types' => ['restaurant']
        ],
        'chicken' => [
            'name' => 'Chicken',
            'search_terms' => ['chicken', 'wing', 'bucket', 'grilled chicken', 'fried chicken'],
            'item_types' => ['food'],
            'business_types' => ['restaurant']
        ],
        'bbq_grill' => [
            'name' => 'BBQ & Grill',
            'search_terms' => ['bbq', 'grill', 'goat', 'beef', 'nyama choma', 'meat'],
            'item_types' => ['food', 'farm produce'],
            'business_types' => ['restaurant', 'farm produce']
        ],
        'local_dishes' => [
            'name' => 'Local Dishes',
            'search_terms' => ['nsima', 'rice', 'stew', 'local', 'traditional'],
            'item_types' => ['food'],
            'business_types' => ['restaurant']
        ],
        'healthy_salads' => [
            'name' => 'Healthy & Salads',
            'search_terms' => ['salad', 'healthy', 'diet', 'bowl', 'fresh'],
            'item_types' => ['food'],
            'business_types' => ['restaurant']
        ],
        'groceries' => [
            'name' => 'Groceries',
            'search_terms' => ['grocery', 'essential', 'pantry', 'snack'],
            'item_types' => ['grocery'],
            'business_types' => ['grocery']
        ],
        'fresh_meat' => [
            'name' => 'Fresh Meat',
            'search_terms' => ['fresh meat', 'beef', 'goat', 'raw chicken', 'pork'],
            'item_types' => ['grocery', 'farm produce'],
            'business_types' => ['grocery', 'farm produce']
        ],
        'fish_seafood' => [
            'name' => 'Fish & Seafood',
            'search_terms' => ['fish', 'seafood', 'prawn', 'crab', 'usipa'],
            'item_types' => ['grocery', 'farm produce'],
            'business_types' => ['grocery', 'farm produce']
        ],
        'bakery' => [
            'name' => 'Bakery',
            'search_terms' => ['bread', 'cake', 'pastry', 'baked', 'bakery'],
            'item_types' => ['grocery', 'food'],
            'business_types' => ['grocery', 'restaurant']
        ],
        'drinks' => [
            'name' => 'Drinks',
            'search_terms' => ['drink', 'juice', 'soda', 'water', 'beverage'],
            'item_types' => ['grocery', 'food'],
            'business_types' => ['grocery', 'restaurant']
        ],
        'vegetables' => [
            'name' => 'Vegetables',
            'search_terms' => ['vegetable', 'tomato', 'onion', 'cabbage', 'leafy'],
            'item_types' => ['farm produce', 'grocery'],
            'business_types' => ['farm produce', 'grocery']
        ],
        'fruits' => [
            'name' => 'Fruits',
            'search_terms' => ['fruit', 'banana', 'orange', 'apple', 'mango'],
            'item_types' => ['farm produce', 'grocery'],
            'business_types' => ['farm produce', 'grocery']
        ],
        'tubers' => [
            'name' => 'Tubers & Staples',
            'search_terms' => ['potato', 'cassava', 'sweet potato', 'maize', 'tuber'],
            'item_types' => ['farm produce'],
            'business_types' => ['farm produce']
        ],
        'dairy_eggs' => [
            'name' => 'Dairy & Eggs',
            'search_terms' => ['milk', 'egg', 'cheese', 'yogurt', 'dairy'],
            'item_types' => ['grocery', 'farm produce'],
            'business_types' => ['grocery', 'farm produce']
        ]
    ];
    
    return isset($mapping[$categoryId]) ? $mapping[$categoryId] : [
        'name' => ucfirst(str_replace('_', ' ', $categoryId)),
        'search_terms' => [str_replace('_', ' ', $categoryId)],
        'item_types' => [],
        'business_types' => []
    ];
}

function getCategoryDetails($categoryId) {
    $categories = [
        'fast_food' => ['id' => 'fast_food', 'name' => 'Fast Food', 'icon' => 'fastfood', 'display_order' => 1],
        'chicken' => ['id' => 'chicken', 'name' => 'Chicken', 'icon' => 'set_meal', 'display_order' => 2],
        'bbq_grill' => ['id' => 'bbq_grill', 'name' => 'BBQ & Grill', 'icon' => 'outdoor_grill', 'display_order' => 3],
        'local_dishes' => ['id' => 'local_dishes', 'name' => 'Local Dishes', 'icon' => 'rice_bowl', 'display_order' => 4],
        'healthy_salads' => ['id' => 'healthy_salads', 'name' => 'Healthy & Salads', 'icon' => 'eco', 'display_order' => 5],
        'groceries' => ['id' => 'groceries', 'name' => 'Groceries', 'icon' => 'shopping_cart', 'display_order' => 6],
        'fresh_meat' => ['id' => 'fresh_meat', 'name' => 'Fresh Meat', 'icon' => 'set_meal', 'display_order' => 7],
        'fish_seafood' => ['id' => 'fish_seafood', 'name' => 'Fish & Seafood', 'icon' => 'set_meal', 'display_order' => 8],
        'bakery' => ['id' => 'bakery', 'name' => 'Bakery', 'icon' => 'bakery_dining', 'display_order' => 9],
        'drinks' => ['id' => 'drinks', 'name' => 'Drinks', 'icon' => 'local_cafe', 'display_order' => 10],
        'vegetables' => ['id' => 'vegetables', 'name' => 'Vegetables', 'icon' => 'grass', 'display_order' => 11],
        'fruits' => ['id' => 'fruits', 'name' => 'Fruits', 'icon' => 'emoji_nature', 'display_order' => 12],
        'tubers' => ['id' => 'tubers', 'name' => 'Tubers & Staples', 'icon' => 'agriculture', 'display_order' => 13],
        'dairy_eggs' => ['id' => 'dairy_eggs', 'name' => 'Dairy & Eggs', 'icon' => 'egg', 'display_order' => 14]
    ];
    
    return isset($categories[$categoryId]) ? $categories[$categoryId] : null;
}

function getMerchantCountByCategory($db, $categoryId, $itemTypes) {
    $mapping = getCategoryMapping($categoryId);
    
    $query = "SELECT COUNT(DISTINCT m.id) as count 
              FROM merchants m
              LEFT JOIN menu_items mi ON m.id = mi.merchant_id
              WHERE m.is_active = 1";
    
    $params = [];
    
    if (!empty($mapping['business_types'])) {
        $placeholders = implode(',', array_fill(0, count($mapping['business_types']), '?'));
        $query .= " AND m.business_type IN ($placeholders)";
        $params = array_merge($params, $mapping['business_types']);
    }
    
    if (!empty($mapping['search_terms'])) {
        $searchConditions = [];
        foreach ($mapping['search_terms'] as $term) {
            $searchConditions[] = "(m.cuisine_type LIKE ? OR m.name LIKE ? OR mi.category LIKE ?)";
            $params[] = "%$term%";
            $params[] = "%$term%";
            $params[] = "%$term%";
        }
        $query .= " AND (" . implode(' OR ', $searchConditions) . ")";
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return intval($result['count']);
}

function getItemCountByCategory($db, $categoryId, $itemTypes) {
    $mapping = getCategoryMapping($categoryId);
    
    $query = "SELECT COUNT(*) as count FROM quick_orders qo WHERE qo.is_active = 1";
    
    $params = [];
    
    if (!empty($mapping['search_terms'])) {
        $searchConditions = [];
        foreach ($mapping['search_terms'] as $term) {
            $searchConditions[] = "(qo.category LIKE ? OR qo.title LIKE ?)";
            $params[] = "%$term%";
            $params[] = "%$term%";
        }
        $query .= " AND (" . implode(' OR ', $searchConditions) . ")";
    }
    
    if (!empty($mapping['item_types'])) {
        $placeholders = implode(',', array_fill(0, count($mapping['item_types']), '?'));
        $query .= " AND qo.item_type IN ($placeholders)";
        $params = array_merge($params, $mapping['item_types']);
    }
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return intval($result['count']);
}

function getAllCategories($db) {
    // Fallback to main categories
    getMainCategories($db);
}
?>