<?php
/*********************************
 * CORS (Vercel frontend)
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
 * SESSION CONFIG
 *********************************/
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 86400 * 30, // 30 days
        'path' => '/',
        'domain' => '',
        'secure' => true,        // HTTPS required on Render
        'httponly' => true,
        'samesite' => 'None'     // Required for cross-domain cookies
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
                    avatar_updated_at, wallet_balance, member_level, member_points, 
                    total_orders, rating, verified, join_date, created_at, updated_at
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

    $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
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
        case 'update_avatar':
            updateAvatar($conn, $input);
            break;
        case 'change_password':
            changePassword($conn, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * LOGIN - ACCEPTS EMAIL OR PHONE
 *********************************/
function loginUser($conn, $data) {
    $identifier = trim($data['identifier'] ?? '');
    $password = $data['password'] ?? '';

    if (!$identifier || !$password) {
        ResponseHandler::error('Identifier and password required', 400);
    }

    // Check if identifier is phone number (contains only digits, +, spaces, dashes, parentheses)
    $isPhone = preg_match('/^[\+\s\-\(\)0-9]+$/', $identifier);
    
    if ($isPhone) {
        // Phone login - clean the phone number
        $phone = cleanPhoneNumber($identifier);
        
        if (!$phone || strlen($phone) < 10) {
            ResponseHandler::error('Invalid phone number', 400);
        }
        
        // Query with phone number
        $stmt = $conn->prepare("SELECT * FROM users WHERE phone = :phone");
        $stmt->execute([':phone' => $phone]);
    } else {
        // Email/username login
        $stmt = $conn->prepare(
            "SELECT * FROM users WHERE email = :id OR username = :id"
        );
        $stmt->execute([':id' => $identifier]);
    }
    
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

    // Remove password from response
    unset($user['password']);

    ResponseHandler::success([
        'user' => formatUserData($user)
    ], 'Login successful');
}

/*********************************
 * CLEAN PHONE NUMBER
 *********************************/
function cleanPhoneNumber($phone) {
    // Remove all non-digit characters except leading +
    $phone = trim($phone);
    
    // If starts with +, keep it
    $hasPlus = substr($phone, 0, 1) === '+';
    
    // Remove all non-digit characters
    $digits = preg_replace('/\D/', '', $phone);
    
    if ($hasPlus) {
        return '+' . $digits;
    }
    
    return $digits;
}

/*********************************
 * REGISTER
 *********************************/
function registerUser($conn, $data) {
    $username = trim($data['username'] ?? '');
    $email = trim($data['email'] ?? '');
    $password = $data['password'] ?? '';
    $phone = !empty($data['phone']) ? cleanPhoneNumber($data['phone']) : null;

    if (!$username || !$email || !$password) {
        ResponseHandler::error('Username, email and password required', 400);
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        ResponseHandler::error('Invalid email format', 400);
    }

    if (strlen($password) < 6) {
        ResponseHandler::error('Password must be at least 6 characters', 400);
    }

    // Check if phone is valid (if provided)
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

    // Prepare SQL with phone field
    $sqlFields = "username, email, password";
    $sqlValues = ":u, :e, :p";
    $params = [
        ':u' => $username,
        ':e' => $email,
        ':p' => password_hash($password, PASSWORD_DEFAULT)
    ];
    
    if ($phone) {
        $sqlFields .= ", phone";
        $sqlValues .= ", :phone";
        $params[':phone'] = $phone;
    }
    
    // Add default values according to new schema
    $sqlFields .= ", full_name, wallet_balance, member_level, member_points, total_orders, rating, verified, join_date";
    $sqlValues .= ", :f, 0.00, 'basic', 0, 0, 0.00, 0, :jd";
    
    $params[':f'] = $username; // Use username as initial full_name
    $params[':jd'] = date('F j, Y');

    $stmt = $conn->prepare(
        "INSERT INTO users ($sqlFields) VALUES ($sqlValues)"
    );

    if (!$stmt->execute($params)) {
        ResponseHandler::error('Registration failed: ' . implode(', ', $stmt->errorInfo()), 500);
    }

    $_SESSION['user_id'] = $conn->lastInsertId();
    $_SESSION['logged_in'] = true;

    ResponseHandler::success([], 'Registration successful', 201);
}

/*********************************
 * UPDATE PROFILE
 *********************************/
function updateProfile($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $fields = [];
    $params = [':id' => $_SESSION['user_id']];

    $allowedFields = ['full_name', 'email', 'phone', 'address'];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field]) && $data[$field] !== '') {
            $value = trim($data[$field]);
            
            // Special handling for phone
            if ($field === 'phone') {
                $value = cleanPhoneNumber($value);
                if ($value && strlen($value) < 10) {
                    ResponseHandler::error('Invalid phone number', 400);
                }
            }
            
            // Special handling for email
            if ($field === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                ResponseHandler::error('Invalid email format', 400);
            }
            
            $fields[] = "$field = :$field";
            $params[":$field"] = $value;
        }
    }

    if (!$fields) {
        ResponseHandler::error('Nothing to update', 400);
    }

    // Check if new email or phone already exists
    if (isset($params[':email']) || isset($params[':phone'])) {
        $checkSql = "SELECT id FROM users WHERE (";
        $checkParams = [];
        
        if (isset($params[':email'])) {
            $checkSql .= "email = :email";
            $checkParams[':email'] = $params[':email'];
        }
        
        if (isset($params[':phone'])) {
            if (isset($params[':email'])) {
                $checkSql .= " OR ";
            }
            $checkSql .= "phone = :phone";
            $checkParams[':phone'] = $params[':phone'];
        }
        
        $checkSql .= ") AND id != :id";
        $checkParams[':id'] = $params[':id'];
        
        $check = $conn->prepare($checkSql);
        $check->execute($checkParams);
        
        if ($check->rowCount()) {
            ResponseHandler::error('Email or phone already in use', 409);
        }
    }

    $fields[] = "updated_at = NOW()";

    $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt->execute($params)) {
        ResponseHandler::error('Update failed', 500);
    }

    // Return updated user data
    $stmt = $conn->prepare(
        "SELECT id, username, email, full_name, phone, address, avatar,
                avatar_updated_at, wallet_balance, member_level, member_points, 
                total_orders, rating, verified, join_date, created_at, updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'user' => formatUserData($user)
    ], 'Profile updated');
}

/*********************************
 * UPDATE AVATAR (SEPARATE FUNCTION)
 *********************************/
function updateAvatar($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $avatarUrl = trim($data['avatar'] ?? '');
    
    if (!$avatarUrl) {
        ResponseHandler::error('Avatar URL is required', 400);
    }
    
    // Validate URL format
    if (!filter_var($avatarUrl, FILTER_VALIDATE_URL)) {
        ResponseHandler::error('Invalid avatar URL format', 400);
    }
    
    // Optional: Validate image URL by checking headers
    $headers = @get_headers($avatarUrl);
    if (!$headers || strpos($headers[0], '200') === false) {
        // Don't fail, just log or continue
        error_log("Avatar URL might not be accessible: $avatarUrl");
    }

    $sql = "UPDATE users SET avatar = :avatar, avatar_updated_at = NOW(), updated_at = NOW() WHERE id = :id";
    $stmt = $conn->prepare($sql);
    
    if (!$stmt->execute([':avatar' => $avatarUrl, ':id' => $_SESSION['user_id']])) {
        ResponseHandler::error('Avatar update failed', 500);
    }

    // Return updated user data
    $stmt = $conn->prepare(
        "SELECT id, username, email, full_name, phone, address, avatar,
                avatar_updated_at, wallet_balance, member_level, member_points, 
                total_orders, rating, verified, join_date, created_at, updated_at
         FROM users WHERE id = :id"
    );
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    ResponseHandler::success([
        'user' => formatUserData($user)
    ], 'Avatar updated');
}

/*********************************
 * CHANGE PASSWORD
 *********************************/
function changePassword($conn, $data) {
    if (empty($_SESSION['user_id'])) {
        ResponseHandler::error('Unauthorized', 401);
    }

    $current_password = $data['current_password'] ?? '';
    $new_password = $data['new_password'] ?? '';
    $confirm_password = $data['confirm_password'] ?? '';

    if (!$current_password || !$new_password || !$confirm_password) {
        ResponseHandler::error('All password fields are required', 400);
    }

    if ($new_password !== $confirm_password) {
        ResponseHandler::error('New passwords do not match', 400);
    }

    if (strlen($new_password) < 6) {
        ResponseHandler::error('New password must be at least 6 characters', 400);
    }

    $stmt = $conn->prepare("SELECT password FROM users WHERE id = :id");
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !password_verify($current_password, $user['password'])) {
        ResponseHandler::error('Current password is incorrect', 401);
    }

    // Check if new password is same as old password
    if (password_verify($new_password, $user['password'])) {
        ResponseHandler::error('New password cannot be the same as current password', 400);
    }

    $stmt = $conn->prepare(
        "UPDATE users SET password = :p, updated_at = NOW() WHERE id = :id"
    );
    
    if (!$stmt->execute([
        ':p' => password_hash($new_password, PASSWORD_DEFAULT),
        ':id' => $_SESSION['user_id']
    ])) {
        ResponseHandler::error('Password change failed', 500);
    }

    ResponseHandler::success([], 'Password changed successfully');
}

/*********************************
 * LOGOUT
 *********************************/
function logoutUser() {
    session_destroy();
    ResponseHandler::success([], 'Logout successful');
}

/*********************************
 * FORMAT USER RESPONSE
 *********************************/
function formatUserData($u) {
    return [
        'id' => (int) $u['id'],
        'username' => $u['username'],
        'email' => $u['email'],
        'phone' => $u['phone'] ?? '',
        'name' => $u['full_name'] ?: $u['username'],
        'full_name' => $u['full_name'] ?: $u['username'],
        'address' => $u['address'] ?? '',
        'avatar' => $u['avatar'] ?? null,
        'avatar_updated_at' => $u['avatar_updated_at'] ?? null,
        'wallet_balance' => (float) ($u['wallet_balance'] ?? 0.00),
        'member_level' => $u['member_level'] ?? 'basic',
        'member_points' => (int) ($u['member_points'] ?? 0),
        'total_orders' => (int) ($u['total_orders'] ?? 0),
        'rating' => (float) ($u['rating'] ?? 0.00),
        'verified' => (bool) ($u['verified'] ?? false),
        'join_date' => $u['join_date'] ?? '',
        'created_at' => $u['created_at'],
        'updated_at' => $u['updated_at']
    ];
}