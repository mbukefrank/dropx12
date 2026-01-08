<?php
// settings.php - User Settings API
// Follows the same pattern as wallet.php

// Start output buffering
ob_start();

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set headers first
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://dropx-frontend-seven.vercel.app');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle OPTIONS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Error handling function
function handleError($message, $code = 500) {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'message' => $message,
        'error' => true
    ]);
    exit;
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    handleError('Unauthorized access. Please login.', 401);
}

require_once '../config/database.php';

class SettingsAPI {
    private $conn;
    private $user_id;

    public function __construct() {
        try {
            $database = new Database();
            $this->conn = $database->getConnection();
            
            $this->user_id = $_SESSION['user_id'];
            
            // Validate user exists
            $stmt = $this->conn->prepare("SELECT id FROM users WHERE id = ?");
            $stmt->execute([$this->user_id]);
            if (!$stmt->fetch()) {
                handleError('User not found', 404);
            }
        } catch (Exception $e) {
            handleError('Database connection failed: ' . $e->getMessage(), 500);
        }
    }

    public function handleRequest() {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            
            $action = '';
            
            // Get action from GET or POST
            if ($method === 'GET') {
                $action = $_GET['action'] ?? '';
            } else {
                // Read raw input for POST/PUT requests
                $input = json_decode(file_get_contents('php://input'), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    handleError('Invalid JSON input', 400);
                }
                $action = $input['action'] ?? $_POST['action'] ?? '';
            }
            
            if (empty($action)) {
                handleError('No action specified', 400);
            }
            
            switch ($action) {
                case 'get_settings':
                    $this->getUserSettings();
                    break;
                case 'update_settings':
                    if ($method !== 'POST' && $method !== 'PUT') {
                        handleError('Method not allowed', 405);
                    }
                    $this->updateUserSettings();
                    break;
                case 'reset_settings':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->resetUserSettings();
                    break;
                case 'get_notification_preferences':
                    $this->getNotificationPreferences();
                    break;
                case 'update_notification_preferences':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->updateNotificationPreferences();
                    break;
                case 'get_privacy_settings':
                    $this->getPrivacySettings();
                    break;
                case 'update_privacy_settings':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->updatePrivacySettings();
                    break;
                case 'get_theme_preferences':
                    $this->getThemePreferences();
                    break;
                case 'update_theme_preferences':
                    if ($method !== 'POST') {
                        handleError('Method not allowed', 405);
                    }
                    $this->updateThemePreferences();
                    break;
                case 'export_settings':
                    $this->exportSettings();
                    break;
                default:
                    handleError('Invalid action: ' . htmlspecialchars($action), 400);
            }
        } catch (Exception $e) {
            handleError('Request handling failed: ' . $e->getMessage(), 500);
        }
    }

    private function getUserSettings() {
        try {
            // Check if user has existing settings
            $query = "SELECT settings_json, theme_preferences, notification_preferences, 
                             privacy_settings, created_at, updated_at
                      FROM user_settings 
                      WHERE user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                // User has existing settings
                $settings = json_decode($result['settings_json'], true);
                $themePrefs = json_decode($result['theme_preferences'], true);
                $notifPrefs = json_decode($result['notification_preferences'], true);
                $privacySettings = json_decode($result['privacy_settings'], true);
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'settings' => $settings,
                        'themePreferences' => $themePrefs,
                        'notificationPreferences' => $notifPrefs,
                        'privacySettings' => $privacySettings,
                        'lastUpdated' => $result['updated_at'],
                        'createdAt' => $result['created_at']
                    ]
                ]);
            } else {
                // Return default settings
                $defaultSettings = $this->getDefaultSettings();
                
                echo json_encode([
                    'success' => true,
                    'data' => [
                        'settings' => $defaultSettings,
                        'themePreferences' => [
                            'theme' => 'light',
                            'autoDarkMode' => false,
                            'accentColor' => '#007bff'
                        ],
                        'notificationPreferences' => $defaultSettings['notifications'],
                        'privacySettings' => $defaultSettings['privacy'],
                        'lastUpdated' => null,
                        'createdAt' => null,
                        'isDefault' => true
                    ]
                ]);
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to get user settings: ' . $e->getMessage());
        }
    }

    private function updateUserSettings() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['settings'])) {
                throw new Exception('Settings data is required');
            }
            
            $settings = $input['settings'];
            
            // Validate settings structure
            $this->validateSettings($settings);
            
            $this->conn->beginTransaction();

            try {
                // Check if user already has settings
                $checkQuery = "SELECT id FROM user_settings WHERE user_id = ?";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->execute([$this->user_id]);
                $exists = $checkStmt->fetch();

                $settingsJson = json_encode($settings);
                $themePrefsJson = json_encode($settings['appPreferences']);
                $notifPrefsJson = json_encode($settings['notifications']);
                $privacyJson = json_encode($settings['privacy']);

                if ($exists) {
                    // Update existing settings
                    $query = "UPDATE user_settings 
                             SET settings_json = ?,
                                 theme_preferences = ?,
                                 notification_preferences = ?,
                                 privacy_settings = ?,
                                 updated_at = NOW()
                             WHERE user_id = ?";
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([
                        $settingsJson,
                        $themePrefsJson,
                        $notifPrefsJson,
                        $privacyJson,
                        $this->user_id
                    ]);
                    
                    $message = 'Settings updated successfully';
                } else {
                    // Insert new settings
                    $query = "INSERT INTO user_settings 
                             (user_id, settings_json, theme_preferences, 
                              notification_preferences, privacy_settings, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([
                        $this->user_id,
                        $settingsJson,
                        $themePrefsJson,
                        $notifPrefsJson,
                        $privacyJson
                    ]);
                    
                    $message = 'Settings saved successfully';
                }

                // Create settings change log
                $this->logSettingsChange($settings);

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => $message,
                    'data' => [
                        'settings' => $settings,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to update settings: ' . $e->getMessage());
        }
    }

    private function resetUserSettings() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            $resetType = $input['type'] ?? 'all'; // 'all', 'notifications', 'privacy', 'theme'
            
            $defaultSettings = $this->getDefaultSettings();
            
            $this->conn->beginTransaction();

            try {
                // Get current settings
                $query = "SELECT settings_json FROM user_settings WHERE user_id = ?";
                $stmt = $this->conn->prepare($query);
                $stmt->execute([$this->user_id]);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $currentSettings = $result ? json_decode($result['settings_json'], true) : $defaultSettings;
                
                // Reset based on type
                switch ($resetType) {
                    case 'notifications':
                        $currentSettings['notifications'] = $defaultSettings['notifications'];
                        $notifJson = json_encode($defaultSettings['notifications']);
                        break;
                    case 'privacy':
                        $currentSettings['privacy'] = $defaultSettings['privacy'];
                        $privacyJson = json_encode($defaultSettings['privacy']);
                        break;
                    case 'theme':
                        $currentSettings['appPreferences'] = $defaultSettings['appPreferences'];
                        $themeJson = json_encode($defaultSettings['appPreferences']);
                        break;
                    case 'all':
                    default:
                        $currentSettings = $defaultSettings;
                        $notifJson = json_encode($defaultSettings['notifications']);
                        $privacyJson = json_encode($defaultSettings['privacy']);
                        $themeJson = json_encode($defaultSettings['appPreferences']);
                        break;
                }
                
                $settingsJson = json_encode($currentSettings);
                
                // Update settings
                $updateQuery = "UPDATE user_settings 
                               SET settings_json = ?";
                
                $params = [$settingsJson];
                
                if ($resetType === 'all' || $resetType === 'notifications') {
                    $updateQuery .= ", notification_preferences = ?";
                    $params[] = $notifJson;
                }
                
                if ($resetType === 'all' || $resetType === 'privacy') {
                    $updateQuery .= ", privacy_settings = ?";
                    $params[] = $privacyJson;
                }
                
                if ($resetType === 'all' || $resetType === 'theme') {
                    $updateQuery .= ", theme_preferences = ?";
                    $params[] = $themeJson;
                }
                
                $updateQuery .= ", updated_at = NOW() WHERE user_id = ?";
                $params[] = $this->user_id;
                
                $stmt = $this->conn->prepare($updateQuery);
                $stmt->execute($params);
                
                // Log the reset
                $this->logSettingsChange(['reset_type' => $resetType]);
                
                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Settings reset successfully',
                    'data' => [
                        'settings' => $currentSettings,
                        'reset_type' => $resetType,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to reset settings: ' . $e->getMessage());
        }
    }

    private function getNotificationPreferences() {
        try {
            $query = "SELECT notification_preferences 
                     FROM user_settings 
                     WHERE user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['notification_preferences']) {
                $preferences = json_decode($result['notification_preferences'], true);
            } else {
                $preferences = $this->getDefaultSettings()['notifications'];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'preferences' => $preferences,
                    'last_synced' => $result ? $result['updated_at'] : null
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get notification preferences: ' . $e->getMessage());
        }
    }

    private function updateNotificationPreferences() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['preferences'])) {
                throw new Exception('Notification preferences are required');
            }
            
            $preferences = $input['preferences'];
            $preferencesJson = json_encode($preferences);
            
            $this->conn->beginTransaction();

            try {
                // Check if user has settings
                $checkQuery = "SELECT id FROM user_settings WHERE user_id = ?";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->execute([$this->user_id]);
                $exists = $checkStmt->fetch();

                if ($exists) {
                    // Update existing
                    $query = "UPDATE user_settings 
                             SET notification_preferences = ?, updated_at = NOW()
                             WHERE user_id = ?";
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([$preferencesJson, $this->user_id]);
                } else {
                    // Insert new
                    $defaultSettings = $this->getDefaultSettings();
                    $settingsJson = json_encode($defaultSettings);
                    $themeJson = json_encode($defaultSettings['appPreferences']);
                    $privacyJson = json_encode($defaultSettings['privacy']);
                    
                    $query = "INSERT INTO user_settings 
                             (user_id, settings_json, theme_preferences, 
                              notification_preferences, privacy_settings, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([
                        $this->user_id,
                        $settingsJson,
                        $themeJson,
                        $preferencesJson,
                        $privacyJson
                    ]);
                }

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Notification preferences updated',
                    'data' => [
                        'preferences' => $preferences,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to update notification preferences: ' . $e->getMessage());
        }
    }

    private function getPrivacySettings() {
        try {
            $query = "SELECT privacy_settings 
                     FROM user_settings 
                     WHERE user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['privacy_settings']) {
                $settings = json_decode($result['privacy_settings'], true);
            } else {
                $settings = $this->getDefaultSettings()['privacy'];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'settings' => $settings,
                    'last_synced' => $result ? $result['updated_at'] : null
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get privacy settings: ' . $e->getMessage());
        }
    }

    private function updatePrivacySettings() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['settings'])) {
                throw new Exception('Privacy settings are required');
            }
            
            $settings = $input['settings'];
            $settingsJson = json_encode($settings);
            
            $this->conn->beginTransaction();

            try {
                // Check if user has settings
                $checkQuery = "SELECT id FROM user_settings WHERE user_id = ?";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->execute([$this->user_id]);
                $exists = $checkStmt->fetch();

                if ($exists) {
                    // Update existing
                    $query = "UPDATE user_settings 
                             SET privacy_settings = ?, updated_at = NOW()
                             WHERE user_id = ?";
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([$settingsJson, $this->user_id]);
                } else {
                    // Insert new
                    $defaultSettings = $this->getDefaultSettings();
                    $allSettingsJson = json_encode($defaultSettings);
                    $themeJson = json_encode($defaultSettings['appPreferences']);
                    $notifJson = json_encode($defaultSettings['notifications']);
                    
                    $query = "INSERT INTO user_settings 
                             (user_id, settings_json, theme_preferences, 
                              notification_preferences, privacy_settings, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([
                        $this->user_id,
                        $allSettingsJson,
                        $themeJson,
                        $notifJson,
                        $settingsJson
                    ]);
                }

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Privacy settings updated',
                    'data' => [
                        'settings' => $settings,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to update privacy settings: ' . $e->getMessage());
        }
    }

    private function getThemePreferences() {
        try {
            $query = "SELECT theme_preferences 
                     FROM user_settings 
                     WHERE user_id = ?";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result && $result['theme_preferences']) {
                $preferences = json_decode($result['theme_preferences'], true);
            } else {
                $preferences = $this->getDefaultSettings()['appPreferences'];
            }

            echo json_encode([
                'success' => true,
                'data' => [
                    'preferences' => $preferences,
                    'last_synced' => $result ? $result['updated_at'] : null
                ]
            ]);
            
        } catch (Exception $e) {
            throw new Exception('Failed to get theme preferences: ' . $e->getMessage());
        }
    }

    private function updateThemePreferences() {
        try {
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input || !isset($input['preferences'])) {
                throw new Exception('Theme preferences are required');
            }
            
            $preferences = $input['preferences'];
            $preferencesJson = json_encode($preferences);
            
            $this->conn->beginTransaction();

            try {
                // Check if user has settings
                $checkQuery = "SELECT id FROM user_settings WHERE user_id = ?";
                $checkStmt = $this->conn->prepare($checkQuery);
                $checkStmt->execute([$this->user_id]);
                $exists = $checkStmt->fetch();

                if ($exists) {
                    // Update existing
                    $query = "UPDATE user_settings 
                             SET theme_preferences = ?, updated_at = NOW()
                             WHERE user_id = ?";
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([$preferencesJson, $this->user_id]);
                } else {
                    // Insert new
                    $defaultSettings = $this->getDefaultSettings();
                    $settingsJson = json_encode($defaultSettings);
                    $notifJson = json_encode($defaultSettings['notifications']);
                    $privacyJson = json_encode($defaultSettings['privacy']);
                    
                    $query = "INSERT INTO user_settings 
                             (user_id, settings_json, theme_preferences, 
                              notification_preferences, privacy_settings, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
                    
                    $stmt = $this->conn->prepare($query);
                    $stmt->execute([
                        $this->user_id,
                        $settingsJson,
                        $preferencesJson,
                        $notifJson,
                        $privacyJson
                    ]);
                }

                $this->conn->commit();

                echo json_encode([
                    'success' => true,
                    'message' => 'Theme preferences updated',
                    'data' => [
                        'preferences' => $preferences,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]
                ]);
                
            } catch (Exception $e) {
                $this->conn->rollBack();
                throw $e;
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to update theme preferences: ' . $e->getMessage());
        }
    }

    private function exportSettings() {
        try {
            $format = $_POST['format'] ?? 'json';
            $includeHistory = isset($_POST['include_history']) && $_POST['include_history'] === 'true';
            
            // Get user settings
            $query = "SELECT * FROM user_settings WHERE user_id = ?";
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id]);
            $settings = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$settings) {
                throw new Exception('No settings found');
            }

            // Get user info
            $userQuery = "SELECT id, email, username, full_name, created_at 
                         FROM users WHERE id = ?";
            $userStmt = $this->conn->prepare($userQuery);
            $userStmt->execute([$this->user_id]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            // Get settings history if requested
            $history = [];
            if ($includeHistory) {
                $historyQuery = "SELECT change_type, change_data, changed_at 
                                FROM settings_change_log 
                                WHERE user_id = ? 
                                ORDER BY changed_at DESC 
                                LIMIT 50";
                $historyStmt = $this->conn->prepare($historyQuery);
                $historyStmt->execute([$this->user_id]);
                $history = $historyStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $exportData = [
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'username' => $user['username'],
                    'full_name' => $user['full_name'],
                    'account_created' => $user['created_at']
                ],
                'settings' => [
                    'general' => json_decode($settings['settings_json'], true),
                    'theme' => json_decode($settings['theme_preferences'], true),
                    'notifications' => json_decode($settings['notification_preferences'], true),
                    'privacy' => json_decode($settings['privacy_settings'], true),
                    'last_updated' => $settings['updated_at'],
                    'created' => $settings['created_at']
                ],
                'export_info' => [
                    'exported_at' => date('Y-m-d H:i:s'),
                    'format' => $format,
                    'export_id' => 'SETTINGS_EXPORT_' . time()
                ]
            ];

            if ($includeHistory) {
                $exportData['history'] = $history;
            }

            if ($format === 'csv') {
                $this->generateSettingsCSV($exportData);
                return;
            } else {
                echo json_encode([
                    'success' => true,
                    'data' => $exportData
                ]);
            }
            
        } catch (Exception $e) {
            throw new Exception('Failed to export settings: ' . $e->getMessage());
        }
    }

    private function getDefaultSettings() {
        return [
            'notifications' => [
                'orderUpdates' => true,
                'promotions' => true,
                'walletActivity' => true,
                'securityAlerts' => true,
                'pushNotifications' => true,
                'emailNotifications' => false,
                'smsNotifications' => false,
            ],
            'privacy' => [
                'showProfile' => true,
                'showActivity' => false,
                'showOrders' => true,
                'allowTracking' => true,
                'dataCollection' => true,
                'personalizedAds' => false,
            ],
            'appPreferences' => [
                'theme' => 'light',
                'language' => 'en',
                'currency' => 'USD',
                'autoPlayVideos' => false,
                'highQualityImages' => false,
                'saveDataMode' => false,
            ],
            'security' => [
                'twoFactorAuth' => false,
                'biometricLogin' => false,
                'autoLogout' => 30,
                'sessionTimeout' => 60,
            ],
            'communication' => [
                'marketingEmails' => false,
                'partnerEmails' => true,
                'surveyRequests' => false,
                'productUpdates' => true,
            ],
        ];
    }

    private function validateSettings($settings) {
        $requiredSections = ['notifications', 'privacy', 'appPreferences', 'security', 'communication'];
        
        foreach ($requiredSections as $section) {
            if (!isset($settings[$section]) || !is_array($settings[$section])) {
                throw new Exception("Invalid settings structure: missing $section");
            }
        }

        // Validate notification settings
        $notificationKeys = ['orderUpdates', 'promotions', 'walletActivity', 'securityAlerts', 
                           'pushNotifications', 'emailNotifications', 'smsNotifications'];
        foreach ($notificationKeys as $key) {
            if (!isset($settings['notifications'][$key]) || !is_bool($settings['notifications'][$key])) {
                throw new Exception("Invalid notification setting: $key");
            }
        }

        // Validate app preferences
        $validThemes = ['light', 'dark', 'auto'];
        if (!in_array($settings['appPreferences']['theme'], $validThemes)) {
            throw new Exception("Invalid theme value");
        }

        $validCurrencies = ['USD', 'EUR', 'GBP', 'JPY'];
        if (!in_array($settings['appPreferences']['currency'], $validCurrencies)) {
            throw new Exception("Invalid currency value");
        }

        // Validate auto logout values
        $autoLogout = $settings['security']['autoLogout'];
        $validAutoLogout = [0, 5, 15, 30, 60];
        if (!in_array($autoLogout, $validAutoLogout)) {
            throw new Exception("Invalid auto logout value");
        }
    }

    private function logSettingsChange($changeData) {
        try {
            $changeType = 'settings_update';
            $changeJson = json_encode($changeData);
            
            $query = "INSERT INTO settings_change_log 
                     (user_id, change_type, change_data, changed_at)
                     VALUES (?, ?, ?, NOW())";
            
            $stmt = $this->conn->prepare($query);
            $stmt->execute([$this->user_id, $changeType, $changeJson]);
            
        } catch (Exception $e) {
            // Don't throw error for logging failure
            error_log('Failed to log settings change: ' . $e->getMessage());
        }
    }

    private function generateSettingsCSV($data) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="settings-export-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // User Information
        fputcsv($output, ['User Information']);
        fputcsv($output, ['ID', 'Email', 'Username', 'Full Name', 'Account Created']);
        fputcsv($output, [
            $data['user']['id'],
            $data['user']['email'],
            $data['user']['username'],
            $data['user']['full_name'],
            $data['user']['account_created']
        ]);
        fputcsv($output, []); // Empty row
        
        // Settings Overview
        fputcsv($output, ['Settings Overview']);
        fputcsv($output, ['Created', 'Last Updated']);
        fputcsv($output, [
            $data['settings']['created'],
            $data['settings']['last_updated']
        ]);
        fputcsv($output, []); // Empty row
        
        // Notification Settings
        fputcsv($output, ['Notification Settings']);
        fputcsv($output, ['Setting', 'Value']);
        foreach ($data['settings']['notifications'] as $key => $value) {
            fputcsv($output, [$key, $value ? 'Enabled' : 'Disabled']);
        }
        fputcsv($output, []); // Empty row
        
        // Privacy Settings
        fputcsv($output, ['Privacy Settings']);
        fputcsv($output, ['Setting', 'Value']);
        foreach ($data['settings']['privacy'] as $key => $value) {
            fputcsv($output, [$key, $value ? 'Enabled' : 'Disabled']);
        }
        fputcsv($output, []); // Empty row
        
        // App Preferences
        fputcsv($output, ['App Preferences']);
        fputcsv($output, ['Setting', 'Value']);
        foreach ($data['settings']['theme'] as $key => $value) {
            fputcsv($output, [$key, is_bool($value) ? ($value ? 'Yes' : 'No') : $value]);
        }
        
        fclose($output);
        exit;
    }
}

try {
    $api = new SettingsAPI();
    $api->handleRequest();
} catch (Exception $e) {
    handleError('Application error: ' . $e->getMessage(), 500);
}

// Clean output buffer
ob_end_flush();
?>