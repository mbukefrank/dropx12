<?php
// auth.php - COMPLETE VERSION WITH ALL FUNCTIONS
ob_start();

/*********************************
 * CORS CONFIGURATION
 *********************************/
$frontend = 'https://dropx-frontend-seven.vercel.app';

// Always set the CORS headers
header("Access-Control-Allow-Origin: $frontend");
header("Access-Control-Allow-Credentials: true");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin");
header("Content-Type: application/json; charset=UTF-8");

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

/*********************************
 * SESSION CONFIGURATION
 *********************************/
// Configure session parameters BEFORE session_start()
ini_set('session.cookie_samesite', 'None');
ini_set('session.cookie_secure', true);
ini_set('session.cookie_httponly', true);
ini_set('session.use_only_cookies', 1);

session_set_cookie_params([
    'lifetime' => 86400 * 30, // 30 days
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'None'
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*********************************
 * ERROR HANDLING
 *********************************/
function sendResponse($success, $message, $data = [], $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ]);
    exit();
}

function sendError($message, $statusCode = 400) {
    sendResponse(false, $message, [], $statusCode);
}

function sendSuccess($data = [], $message = 'Success', $statusCode = 200) {
    sendResponse(true, $message, $data, $statusCode);
}

/*********************************
 * DATABASE CONNECTION
 *********************************/
try {
    require_once __DIR__ . '/../config/database.php';
    $database = new Database();
    $conn = $database->getConnection();
} catch (Exception $e) {
    sendError('Database connection failed: ' . $e->getMessage(), 500);
}

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        handleGetRequest($conn);
    } elseif ($method === 'POST') {
        handlePostRequest($conn);
    } else {
        sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    sendError('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUEST HANDLER
 *********************************/
function handleGetRequest($conn) {
    $action = $_GET['action'] ?? 'check';
    
    switch ($action) {
        case 'check':
            checkAuth($conn);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

/*********************************
 * POST REQUEST HANDLER
 *********************************/
function handlePostRequest($conn) {
    // Get input data
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($contentType, 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendError('Invalid JSON input', 400);
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
        case 'update_profile':
            updateProfile($conn, $input);
            break;
        case 'change_password':
            changePassword($conn, $input);
            break;
        default:
            sendError('Invalid action', 400);
    }
}

/*********************************
 * AUTH CHECK FUNCTION
 *********************************/
function checkAuth($conn) {
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
            sendSuccess([
                'authenticated' => true,
                'user' => formatUserData($user)
            ]);
        }
    }
    
    sendSuccess(['authenticated' => false]);
}

/*********************************
 * LOGIN FUNCTION
 *********************************/
function loginUser($conn, $data) {
    $identifier = trim($data['identifier'] ?? $data['username'] ?? $data['email'] ?? '');
    $password = $data['password'] ?? '';
    
    if (empty($identifier) || empty($password)) {
        sendError('Identifier and password are required', 400);
    }
    
    // Determine identifier type
    if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
        // Login by email
        $stmt = $conn->prepare("SELECT * FROM users WHERE email = :identifier");
    } elseif (preg_match('/^[\+\s\-\(\)0-9]+$/', $identifier)) {
        // Login by phone
        $phone = cleanPhoneNumber($identifier);
        if (strlen($phone) < 10) {
            sendError('Invalid phone number', 400);
        }
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :identifier");
        $identifier = $phone;
    } else {
        // Login by username
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = :identifier");
    }
    
    $stmt->execute([':identifier' => $identifier]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        sendError('Invalid credentials', 401);
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        sendError('Invalid credentials', 401);
    }
    
    // Update session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['logged_in'] = true;
    
    sendSuccess([
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
    if (empty($username) || empty($email) || empty($password)) {
        sendError('Username, email and password are required', 400);
    }
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        sendError('Invalid email format', 400);
    }
    
    if (strlen($password) < 6) {
        sendError('Password must be at least 6 characters', 400);
    }
    
    if ($phone && strlen($phone) < 10) {
        sendError('Invalid phone number', 400);
    }
    
    // Check for existing user
    $checkSql = "SELECT id FROM users WHERE email = :email OR username = :username";
    $checkParams = [':email' => $email, ':username' => $username];
    
    if ($phone) {
        $checkSql .= " OR phone = :phone";
        $checkParams[':phone'] = $phone;
    }
    
    $checkStmt = $conn->prepare($checkSql);
    $checkStmt->execute($checkParams);
    
    if ($checkStmt->rowCount() > 0) {
        sendError('User already exists', 409);
    }
    
    // Insert new user
    $sql = "INSERT INTO users (
                username, email, password, full_name, phone, 
                wallet_balance, member_level, member_points, total_orders, 
                rating, verified, join_date, created_at, updated_at
            ) VALUES (
                :username, :email, :password, :full_name, :phone,
                :wallet_balance, :member_level, :member_points, :total_orders,
                :rating, :verified, :join_date, NOW(), NOW()
            )";
    
    $params = [
        ':username' => $username,
        ':email' => $email,
        ':password' => password_hash($password, PASSWORD_DEFAULT),
        ':full_name' => $full_name,
        ':phone' => $phone,
        ':wallet_balance' => 0.00,
        ':member_level' => 'basic',
        ':member_points' => 0,
        ':total_orders' => 0,
        ':rating' => 0.00,
        ':verified' => 0,
        ':join_date' => date('F j, Y')
    ];
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $user_id = $conn->lastInsertId();
        
        // Set session
        $_SESSION['user_id'] = $user_id;
        $_SESSION['logged_in'] = true;
        
        // Get the newly created user
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        sendSuccess([
            'user' => formatUserData($user)
        ], 'Registration successful', 201);
        
    } catch (PDOException $e) {
        sendError('Registration failed: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * UPDATE PROFILE FUNCTION
 *********************************/
function updateProfile($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        sendError('Unauthorized', 401);
    }
    
    $fields = [];
    $params = [':id' => $_SESSION['user_id']];
    
    // Only update fields that exist and are allowed
    $allowedFields = ['full_name', 'email', 'phone', 'address'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $value = trim($data[$field]);
            
            // Special handling for email
            if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                sendError('Invalid email format', 400);
            }
            
            // Special handling for phone
            if ($field === 'phone' && $value) {
                $value = cleanPhoneNumber($value);
                if (strlen($value) < 10) {
                    sendError('Invalid phone number', 400);
                }
            }
            
            $fields[] = "$field = :$field";
            $params[":$field"] = $value;
        }
    }
    
    if (empty($fields)) {
        sendError('No fields to update', 400);
    }
    
    // Check for duplicate email/phone
    if (isset($params[':email']) || isset($params[':phone'])) {
        $checkSql = "SELECT id FROM users WHERE (";
        $checkParams = [];
        
        if (isset($params[':email'])) {
            $checkSql .= "email = :email";
            $checkParams[':email'] = $params[':email'];
        }
        
        if (isset($params[':phone']) && $params[':phone']) {
            if (isset($params[':email'])) {
                $checkSql .= " OR ";
            }
            $checkSql .= "phone = :phone";
            $checkParams[':phone'] = $params[':phone'];
        }
        
        $checkSql .= ") AND id != :id";
        $checkParams[':id'] = $params[':id'];
        
        $checkStmt = $conn->prepare($checkSql);
        $checkStmt->execute($checkParams);
        
        if ($checkStmt->rowCount() > 0) {
            sendError('Email or phone already in use', 409);
        }
    }
    
    // Add updated_at field
    $fields[] = "updated_at = NOW()";
    
    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        // Get updated user data
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        sendSuccess([
            'user' => formatUserData($user)
        ], 'Profile updated successfully');
        
    } catch (PDOException $e) {
        sendError('Update failed: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * CHANGE PASSWORD FUNCTION
 *********************************/
function changePassword($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        sendError('Unauthorized', 401);
    }
    
    $current_password = $data['current_password'] ?? '';
    $new_password = $data['new_password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';
    
    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        sendError('All password fields are required', 400);
    }
    
    if ($new_password !== $confirm_password) {
        sendError('New passwords do not match', 400);
    }
    
    if (strlen($new_password) < 6) {
        sendError('New password must be at least 6 characters', 400);
    }
    
    // Verify current password
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user || !password_verify($current_password, $user['password'])) {
        sendError('Current password is incorrect', 401);
    }
    
    // Update password
    try {
        $stmt = $conn->prepare("UPDATE users SET password = :password, updated_at = NOW() WHERE id = :id");
        $stmt->execute([
            ':password' => password_hash($new_password, PASSWORD_DEFAULT),
            ':id' => $_SESSION['user_id']
        ]);
        
        sendSuccess([], 'Password changed successfully');
        
    } catch (PDOException $e) {
        sendError('Failed to change password: ' . $e->getMessage(), 500);
    }
}

/*********************************
 * LOGOUT FUNCTION
 *********************************/
function logoutUser() {
    // Clear all session variables
    $_SESSION = [];
    
    // Destroy the session
    if (session_destroy()) {
        // Clear session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        sendSuccess([], 'Logged out successfully');
    } else {
        sendError('Failed to logout', 500);
    }
}

/*********************************
 * FORMAT USER DATA
 *********************************/
function formatUserData($user) {
    return [
        'id' => $user['id'] ?? '',
        'username' => $user['username'] ?? '',
        'email' => $user['email'] ?? '',
        'phone' => $user['phone'] ?? '',
        'full_name' => $user['full_name'] ?? ($user['username'] ?? ''),
        'address' => $user['address'] ?? '',
        'avatar' => $user['avatar'] ?? null,
        'wallet_balance' => isset($user['wallet_balance']) ? (float) $user['wallet_balance'] : 0.00,
        'member_level' => $user['member_level'] ?? 'basic',
        'member_points' => isset($user['member_points']) ? (int) $user['member_points'] : 0,
        'total_orders' => isset($user['total_orders']) ? (int) $user['total_orders'] : 0,
        'rating' => isset($user['rating']) ? (float) $user['rating'] : 0.00,
        'verified' => isset($user['verified']) ? (bool) $user['verified'] : false,
        'join_date' => $user['join_date'] ?? '',
        'created_at' => $user['created_at'] ?? '',
        'updated_at' => $user['updated_at'] ?? ''
    ];
}