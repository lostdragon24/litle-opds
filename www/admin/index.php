<?php

// admin/index.php

require_once __DIR__ . '/../define.php';

defined('LOPDS_ROOT') or die(__('admin_access_denied'));

// Отключаем буферизацию
while (ob_get_level()) {
    ob_end_clean();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../csp.php';



my_log("=== ADMIN INDEX ===");
my_log("Session ID: " . session_id());
my_log("Session name: " . session_name());
my_log("Session data: " . print_r($_SESSION, true));

// После успешного логина
if (isset($_SESSION['just_installed'])) {
    $_SESSION['message'] = __('admin_welcome_install');
    $_SESSION['message_type'] = 'info';
    unset($_SESSION['just_installed']);
}

require_once __DIR__ . '/AdminController.php';

$controller = new AdminController();
$controller->handleRequest();
