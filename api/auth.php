<?php
// auth.php - COMPLETE VERSION MATCHING YOUR DATABASE SCHEMA
ob_start();

/*********************************
 * CORS HEADERS
 *********************************/
$frontend = 'https://dropx-frontend-seven.vercel.app';

if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] === $frontend) {
    header("Access-Control-Allow-Origin: $frontend");
}

header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept");
header("Content-Type: application/json; charset=UTF-8");

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIGURATION
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 days
        'path' => '/',
        'domain' => '',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'None'
    ]);
    session_start();
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
 * GET: AUTH CHECK
 *********************************/
function handleGetRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        $stmt = $conn->prepare(
            "SELECT id, username, email, full_name, phone, address, avatar,
                    wallet_balance, member_level, member_points, total_orders,
                    rating, verified, join_date, created_at, updated_at
             FROM users WHERE id = :id"
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            ResponseHandler::success([
                'authenticated' => true,
                'user' => formatUserData($user)
            ]);
        }
    }

    ResponseHandler::success(['authenticated' => false]);
}

/*********************************
 * POST ROUTER
 *********************************/
function handlePostRequest() {
    $db = new Database();
    $conn = $db->getConnection();

    // Get input data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ResponseHandler::error('Invalid JSON', 400);
            return;
        }
    } else {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'login':
            loginUser($conn, $input);
            break;
        case 'register':
            registerUser($conn, $input);
            break;
        case 'logout':
            logoutUser();
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * LOGIN FUNCTION
 *********************************/
function loginUser($conn, $data) {
    $identifier = trim($data['identifier'] ?? $data['username'] ?? $data['email'] ?? '');
    $password = $data['password'] ?? '';

    if (!$identifier || !$password) {
        ResponseHandler::error('Identifier and password required', 400);
    }

    // Try email first
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :identifier");
    } 
    // Try phone (if it looks like a phone number)
    else if (preg_match('/^[\+\s\-\(\)0-9]+$/', $identifier)) {
        $phone = cleanPhoneNumber($identifier);
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Invalid phone number', 400);
        }
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :identifier");
        $identifier = $phone;
    }
    // Try username
    else {
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = :identifier");
    }
    
    $stmt->execute([':identifier' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        ResponseHandler::error('Invalid credentials', 401);
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        ResponseHandler::error('Invalid credentials', 401);
    }

    // Set session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;

    ResponseHandler::success([
        'user' => formatUserData($user)
    ], 'Login successful');
}

/*********************************
 * CLEAN PHONE NUMBER
 *********************************/
function cleanPhoneNumber($phone) {
    $phone = trim($phone);
    $hasPlus = substr($phone, 0, 1) === '+';
    $digits = preg_replace('/\D/', '', $phone);
    
    if ($hasPlus) {
        return '+' . $digits;
    }
    
    return $digits;
}

/*********************************
 * REGISTER FUNCTION
 *********************************/
function registerUser($conn, $data) {
    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;
    $full_name = trim($data['full_name'] ?? $username);

    // Validation
    if (!$username || !$email || !$password) {
        ResponseHandler::error('Username, email and password required', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Invalid email format', 400);
    }

    if (strlen($password) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    if ($phone && strlen($phone) < 10) {
        ResponseHandler::error('Invalid phone number', 400);
    }

    // Check for existing user
    $check = $conn->prepare(
        "SELECT id FROM users WHERE email = :email OR username = :username"
        . ($phone ? " OR phone = :phone" : "")
    );
    
    $params = [':email' => $email, ':username' => $username];
    if ($phone) {
        $params[':phone'] = $phone;
    }
    
    $check->execute($params);

    if ($check->rowCount()) {
        ResponseHandler::error('User already exists', 409);
    }

    // Prepare SQL - EXACTLY matches your database schema
    $sqlFields = "username, email, password, full_name, phone, avatar, 
                  wallet_balance, member_level, member_points, total_orders, 
                  rating, verified, join_date, created_at, updated_at";
    
    $sqlValues = ":username, :email, :password, :full_name, :phone, :avatar,
                  :wallet_balance, :member_level, :member_points, :total_orders,
                  :rating, :verified, :join_date, NOW(), NOW()";
    
    $params = [
        ':username' => $username,
        ':email' => $email,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':full_name' => $full_name,
        ':phone' => $phone,
        ':avatar' => null,
        ':wallet_balance' => 0.00,
        ':member_level' => 'basic', // Your database default is 'basic'
        ':member_points' => 0,
        ':total_orders' => 0,
        ':rating' => 0.00,
        ':verified' => 0,
        ':join_date' => date('F j, Y') // Match the format in your database
    ];

    try {
        $stmt = $conn->prepare("INSERT INTO users ($sqlFields) VALUES ($sqlValues)");
        $stmt->execute($params);
        
        $user_id = $conn->lastInsertId();
        
        // Get the newly created user
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Set session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['logged_in'] = true;

        ResponseHandler::success([
            'user' => formatUserData($user)
        ], 'Registration successful', 201);
        
    } catch (PDOException $e) {
        ResponseHandler::error('Registration failed: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * FORMAT USER RESPONSE
 *********************************/
function formatUserData($u) {
    return [
        'id' => $u['id'] ?? '',
        'username' => $u['username'] ?? '',
        'email' => $u['email'] ?? '',
        'phone' => $u['phone'] ?? '',
        'full_name' => $u['full_name'] ?? ($u['username'] ?? ''),
        'address' => $u['address'] ?? '',
        'avatar' => $u['avatar'] ?? null,
        'wallet_balance' => isset($u['wallet_balance']) ? (float) $u['wallet_balance'] : 0.00,
        'member_level' => $u['member_level'] ?? 'basic', // Your database has 'basic'
        'member_points' => isset($u['member_points']) ? (int) $u['member_points'] : 0,
        'total_orders' => isset($u['total_orders']) ? (int) $u['total_orders'] : 0,
        'rating' => isset($u['rating']) ? (float) $u['rating'] : 0.00,
        'verified' => isset($u['verified']) ? (bool) $u['verified'] : false,
        'join_date' => $u['join_date'] ?? '',
        'created_at' => $u['created_at'] ?? '',
        'updated_at' => $u['updated_at'] ?? ''
    ];
}

/*********************************
 * LOGOUT FUNCTION
 *********************************/
function logoutUser() {
    // Clear all session variables
    $_SESSION = array();
    
    // Destroy the session
    session_destroy();
    
    // Clear the session cookie
    if (isset($_COOKIE[session_name()])) {
        setcookie(session_name(), '', time() - 3600, '/');
    }
    
    ResponseHandler::success([], 'Logout successful');
}