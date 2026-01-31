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
 * SESSION CONFIG - MUST MATCH auth.php EXACTLY
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

/*********************************
 * AUTHENTICATION CHECK - SIMPLIFIED
 *********************************/
function checkAuthentication() {
    // Simple check - same as auth.php
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['logged_in'])) {
        return $_SESSION['user_id'];
    }
    return null;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ResponseHandler.php';

/*********************************
 * ROUTER
 *********************************/
try {
    $method = $_SERVER['REQUEST_METHOD'];

    // Check authentication first for all requests
    $userId = checkAuthentication();
    if (!$userId) {
        ResponseHandler::error('Authentication required. Please login.', 401);
    }

    // Route authenticated requests
    if ($method === 'GET') {
        handleGetRequest($userId);
    } elseif ($method === 'POST') {
        handlePostRequest($userId);
    } elseif ($method === 'PUT') {
        handlePutRequest($userId);
    } elseif ($method === 'DELETE') {
        handleDeleteRequest($userId);
    } else {
        ResponseHandler::error('Method not allowed', 405);
    }
} catch (Exception $e) {
    ResponseHandler::error('Server error: ' . $e->getMessage(), 500);
}

/*********************************
 * GET REQUESTS
 *********************************/
function handleGetRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $notificationId = $_GET['id'] ?? null;
    $action = $_GET['action'] ?? null;
    
    if ($notificationId) {
        getNotificationDetail($conn, $userId, $notificationId);
    } elseif ($action === 'statistics') {
        getNotificationStatistics($conn, $userId);
    } else {
        getNotificationsList($conn, $userId);
    }
}

/*********************************
 * GET NOTIFICATIONS LIST
 *********************************/
function getNotificationsList($conn, $userId) {
    // Get query parameters
    $page = max(1, intval($_GET['page'] ?? 1));
    $limit = min(50, max(1, intval($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;
    
    $type = $_GET['type'] ?? '';
    $isRead = $_GET['is_read'] ?? null;
    $search = $_GET['search'] ?? '';
    $sortBy = $_GET['sort_by'] ?? 'created_at';
    $sortOrder = strtoupper($_GET['sort_order'] ?? 'DESC');

    // Build WHERE clause
    $whereConditions = ["user_id = :user_id"];
    $params = [':user_id' => $userId];

    if ($type && $type !== 'all') {
        $whereConditions[] = "type = :type";
        $params[':type'] = $type;
    }

    if ($isRead !== null) {
        $whereConditions[] = "is_read = :is_read";
        $params[':is_read'] = ($isRead === 'true' || $isRead === '1') ? 1 : 0;
    }

    if ($search) {
        $whereConditions[] = "(title LIKE :search OR message LIKE :search)";
        $params[':search'] = "%$search%";
    }

    $whereClause = "WHERE " . implode(" AND ", $whereConditions);

    // Validate sort options
    $allowedSortColumns = ['created_at', 'sent_at', 'title'];
    $sortBy = in_array($sortBy, $allowedSortColumns) ? $sortBy : 'created_at';
    $sortOrder = $sortOrder === 'ASC' ? 'ASC' : 'DESC';

    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total FROM notifications $whereClause";
    $countStmt = $conn->prepare($countSql);
    $countStmt->execute($params);
    $totalCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];

    // Get notifications
    $sql = "SELECT 
                id,
                type,
                title,
                message,
                data,
                is_read,
                read_at,
                sent_via,
                sent_at,
                created_at
            FROM notifications
            $whereClause
            ORDER BY $sortBy $sortOrder
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
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format notification data
    $formattedNotifications = array_map('formatNotificationData', $notifications);

    // Get unread count
    $unreadStmt = $conn->prepare(
        "SELECT COUNT(*) as unread_count 
         FROM notifications 
         WHERE user_id = :user_id AND is_read = 0"
    );
    $unreadStmt->execute([':user_id' => $userId]);
    $unreadCount = $unreadStmt->fetch(PDO::FETCH_ASSOC)['unread_count'];

    ResponseHandler::success([
        'notifications' => $formattedNotifications,
        'unread_count' => $unreadCount,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $limit,
            'total_items' => $totalCount,
            'total_pages' => ceil($totalCount / $limit)
        ]
    ]);
}

/*********************************
 * GET NOTIFICATION DETAIL
 *********************************/
function getNotificationDetail($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "SELECT 
            id,
            type,
            title,
            message,
            data,
            is_read,
            read_at,
            sent_via,
            sent_at,
            created_at
        FROM notifications
        WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$notification) {
        ResponseHandler::error('Notification not found', 404);
    }

    // Mark as read when viewing details
    if (!$notification['is_read']) {
        $updateStmt = $conn->prepare(
            "UPDATE notifications 
             SET is_read = 1, read_at = NOW()
             WHERE id = :id AND user_id = :user_id"
        );
        $updateStmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    }

    ResponseHandler::success([
        'notification' => formatNotificationData($notification)
    ]);
}

/*********************************
 * GET NOTIFICATION STATISTICS
 *********************************/
function getNotificationStatistics($conn, $userId) {
    $stmt = $conn->prepare(
        "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread,
            COUNT(CASE WHEN type = 'order' THEN 1 END) as order_notifications,
            COUNT(CASE WHEN type = 'promotion' THEN 1 END) as promotion_notifications,
            COUNT(CASE WHEN type = 'delivery' THEN 1 END) as delivery_notifications,
            COUNT(CASE WHEN type = 'system' THEN 1 END) as system_notifications
         FROM notifications 
         WHERE user_id = :user_id"
    );
    $stmt->execute([':user_id' => $userId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    ResponseHandler::success([
        'statistics' => [
            'total' => intval($stats['total'] ?? 0),
            'unread' => intval($stats['unread'] ?? 0),
            'by_type' => [
                'order' => intval($stats['order_notifications'] ?? 0),
                'promotion' => intval($stats['promotion_notifications'] ?? 0),
                'delivery' => intval($stats['delivery_notifications'] ?? 0),
                'system' => intval($stats['system_notifications'] ?? 0)
            ]
        ]
    ]);
}

/*********************************
 * POST REQUESTS
 *********************************/
function handlePostRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $action = $input['action'] ?? '';

    switch ($action) {
        case 'mark_all_read':
            markAllAsRead($conn, $userId, $input);
            break;
        case 'clear_all':
            clearAllNotifications($conn, $userId, $input);
            break;
        case 'batch_update':
            batchUpdateNotifications($conn, $userId, $input);
            break;
        case 'update_preferences':
            updateNotificationPreferences($conn, $userId, $input);
            break;
        default:
            ResponseHandler::error('Invalid action', 400);
    }
}

/*********************************
 * PUT REQUESTS
 *********************************/
function handlePutRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        parse_str(file_get_contents('php://input'), $input);
    }
    
    $notificationId = $input['id'] ?? null;
    
    if (!$notificationId) {
        ResponseHandler::error('Notification ID is required', 400);
    }

    markAsRead($conn, $userId, $notificationId);
}

/*********************************
 * DELETE REQUESTS
 *********************************/
function handleDeleteRequest($userId) {
    $db = new Database();
    $conn = $db->getConnection();
    
    $input = json_decode(file_get_contents('php://input'), true);
    $notificationId = $_GET['id'] ?? ($input['id'] ?? null);
    
    if (!$notificationId) {
        ResponseHandler::error('Notification ID is required', 400);
    }

    deleteNotification($conn, $userId, $notificationId);
}

/*********************************
 * MARK NOTIFICATION AS READ
 *********************************/
function markAsRead($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "UPDATE notifications 
         SET is_read = 1, read_at = NOW()
         WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Notification not found', 404);
    }

    ResponseHandler::success([], 'Notification marked as read');
}

/*********************************
 * MARK ALL AS READ
 *********************************/
function markAllAsRead($conn, $userId, $data) {
    $type = $data['type'] ?? '';

    $sql = "UPDATE notifications 
            SET is_read = 1, read_at = NOW()
            WHERE user_id = :user_id AND is_read = 0";
    
    $params = [':user_id' => $userId];
    
    if ($type && $type !== 'all') {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $affectedRows = $stmt->rowCount();
    
    ResponseHandler::success([
        'marked_count' => $affectedRows
    ], "Marked $affectedRows notifications as read");
}

/*********************************
 * CLEAR ALL NOTIFICATIONS
 *********************************/
function clearAllNotifications($conn, $userId, $data) {
    $type = $data['type'] ?? '';
    $olderThan = $data['older_than'] ?? '';

    $sql = "DELETE FROM notifications WHERE user_id = :user_id";
    $params = [':user_id' => $userId];

    if ($type && $type !== 'all') {
        $sql .= " AND type = :type";
        $params[':type'] = $type;
    }

    if ($olderThan) {
        $sql .= " AND created_at < :older_than";
        $params[':older_than'] = $olderThan;
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    $deletedCount = $stmt->rowCount();
    
    ResponseHandler::success([
        'deleted_count' => $deletedCount
    ], "Cleared $deletedCount notifications");
}

/*********************************
 * DELETE SINGLE NOTIFICATION
 *********************************/
function deleteNotification($conn, $userId, $notificationId) {
    $stmt = $conn->prepare(
        "DELETE FROM notifications 
         WHERE id = :id AND user_id = :user_id"
    );
    
    $stmt->execute([':id' => $notificationId, ':user_id' => $userId]);
    
    if ($stmt->rowCount() === 0) {
        ResponseHandler::error('Notification not found', 404);
    }

    ResponseHandler::success([], 'Notification deleted successfully');
}

/*********************************
 * BATCH UPDATE NOTIFICATIONS
 *********************************/
function batchUpdateNotifications($conn, $userId, $data) {
    $notificationIds = $data['notification_ids'] ?? [];
    $operation = $data['operation'] ?? ''; // 'mark_read' or 'delete'
    
    if (empty($notificationIds)) {
        ResponseHandler::error('No notification IDs provided', 400);
    }
    
    if (!in_array($operation, ['mark_read', 'delete'])) {
        ResponseHandler::error('Invalid operation. Use "mark_read" or "delete"', 400);
    }
    
    // Create placeholders for IN clause
    $placeholders = implode(',', array_fill(0, count($notificationIds), '?'));
    $params = array_merge([$userId], $notificationIds);
    
    if ($operation === 'mark_read') {
        $sql = "UPDATE notifications 
                SET is_read = 1, read_at = NOW()
                WHERE user_id = ? AND id IN ($placeholders)";
    } else {
        $sql = "DELETE FROM notifications 
                WHERE user_id = ? AND id IN ($placeholders)";
    }
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    $affectedRows = $stmt->rowCount();
    
    ResponseHandler::success([
        'affected_count' => $affectedRows
    ], "Successfully processed $affectedRows notifications");
}

/*********************************
 * UPDATE NOTIFICATION PREFERENCES
 *********************************/
function updateNotificationPreferences($conn, $userId, $data) {
    $pushEnabled = $data['push_enabled'] ?? null;
    $emailEnabled = $data['email_enabled'] ?? null;
    $smsEnabled = $data['sms_enabled'] ?? null;
    
    // Check if settings exist
    $checkStmt = $conn->prepare(
        "SELECT id FROM user_notification_settings WHERE user_id = :user_id"
    );
    $checkStmt->execute([':user_id' => $userId]);
    
    if ($checkStmt->fetch()) {
        // Update existing
        $updateFields = [];
        $params = [':user_id' => $userId];
        
        if ($pushEnabled !== null) {
            $updateFields[] = 'push_enabled = :push';
            $params[':push'] = $pushEnabled ? 1 : 0;
        }
        
        if ($emailEnabled !== null) {
            $updateFields[] = 'email_enabled = :email';
            $params[':email'] = $emailEnabled ? 1 : 0;
        }
        
        if ($smsEnabled !== null) {
            $updateFields[] = 'sms_enabled = :sms';
            $params[':sms'] = $smsEnabled ? 1 : 0;
        }
        
        if (!empty($updateFields)) {
            $sql = "UPDATE user_notification_settings SET " . 
                   implode(', ', $updateFields) . 
                   ", updated_at = NOW() WHERE user_id = :user_id";
            
            $updateStmt = $conn->prepare($sql);
            $updateStmt->execute($params);
        }
    } else {
        // Create new
        $insertStmt = $conn->prepare(
            "INSERT INTO user_notification_settings 
                (user_id, push_enabled, email_enabled, sms_enabled, created_at)
             VALUES (:user_id, :push, :email, :sms, NOW())"
        );
        
        $insertStmt->execute([
            ':user_id' => $userId,
            ':push' => $pushEnabled !== null ? ($pushEnabled ? 1 : 0) : 1,
            ':email' => $emailEnabled !== null ? ($emailEnabled ? 1 : 0) : 1,
            ':sms' => $smsEnabled !== null ? ($smsEnabled ? 1 : 0) : 0
        ]);
    }

    ResponseHandler::success([], 'Notification preferences updated');
}

/*********************************
 * FORMAT NOTIFICATION DATA
 *********************************/
function formatNotificationData($notification) {
    $type = $notification['type'] ?? 'order';
    
    // Map types to icons
    $iconMap = [
        'order' => 'assignment',
        'delivery' => 'delivery_dining',
        'promotion' => 'local_offer',
        'payment' => 'payment',
        'system' => 'info',
        'support' => 'chat',
        'security' => 'security',
        'update' => 'update',
        'warning' => 'warning',
        'success' => 'check_circle'
    ];
    
    // Get appropriate icon based on type
    $icon = $iconMap[$type] ?? 'notifications';
    
    // Parse data JSON
    $data = [];
    if (!empty($notification['data'])) {
        $data = json_decode($notification['data'], true);
        if (!is_array($data)) {
            $data = [];
        }
    }

    // Format time ago
    $createdAt = $notification['created_at'] ?? '';
    $timeAgo = formatTimeAgo($createdAt);

    return [
        'id' => intval($notification['id']),
        'type' => $type,
        'title' => $notification['title'] ?? '',
        'message' => $notification['message'] ?? '',
        'data' => $data,
        'is_read' => boolval($notification['is_read'] ?? false),
        'read_at' => $notification['read_at'] ?? null,
        'sent_via' => $notification['sent_via'] ?? 'in_app',
        'sent_at' => $notification['sent_at'] ?? null,
        'created_at' => $createdAt,
        'time_ago' => $timeAgo,
        'icon' => $icon
    ];
}

/*********************************
 * FORMAT TIME AGO
 *********************************/
function formatTimeAgo($datetime) {
    if (empty($datetime)) return 'Just now';
    
    try {
        $now = new DateTime();
        $then = new DateTime($datetime);
        $interval = $now->diff($then);
        
        if ($interval->y > 0) {
            return $interval->y . ' year' . ($interval->y > 1 ? 's' : '') . ' ago';
        } elseif ($interval->m > 0) {
            return $interval->m . ' month' . ($interval->m > 1 ? 's' : '') . ' ago';
        } elseif ($interval->d > 0) {
            return $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        } elseif ($interval->h > 0) {
            return $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        } elseif ($interval->i > 0) {
            return $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        } else {
            return 'Just now';
        }
    } catch (Exception $e) {
        return 'Recently';
    }
}
?>