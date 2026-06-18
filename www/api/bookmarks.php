<?php
// api/bookmarks.php - исправленная версия

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../init.php';

// Включаем отображение ошибок только для отладки
ini_set('display_errors', 1);
ini_set('error_reporting', E_ALL);

header('Content-Type: application/json');

// Логируем все запросы
my_log("=== BOOKMARKS API CALLED ===");
my_log("POST data: " . print_r($_POST, true));
my_log("GET data: " . print_r($_GET, true));
my_log("Cookie: " . print_r($_COOKIE, true));

try {
    $db = Database::getInstance();
    $pdo = $db->getConnection();
    
    // Получаем fingerprint
    $fingerprint = $_COOKIE['device_fp'] ?? $_POST['fingerprint'] ?? $_GET['fingerprint'] ?? null;
    my_log("Fingerprint: " . $fingerprint);
    
    if (!$fingerprint) {
        echo json_encode(['success' => false, 'message' => 'Fingerprint not found', 'cookie' => $_COOKIE]);
        exit;
    }
    
    $action = $_POST['action'] ?? $_GET['action'] ?? 'get';
    my_log("Action: " . $action);
    
    switch ($action) {
        case 'save_progress':
            saveProgress($pdo, $fingerprint);
            break;
        case 'get_last_read':
            getLastRead($pdo, $fingerprint);
            break;
        case 'test':
            testConnection($pdo);
            break;
        case 'delete':
            deleteBookmark($pdo, $fingerprint);
            break;
	case 'add':
	    addBookmark($pdo, $fingerprint);
	break;
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }
    
} catch (Exception $e) {
    my_log("Bookmarks API Error: " . $e->getMessage());
    my_log("Stack trace: " . $e->getTraceAsString());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}

function saveProgress($pdo, $fingerprint) {
    $bookId = (int)($_POST['book_id'] ?? 0);
    $cfiRange = $_POST['cfi_range'] ?? '';
    $pageNumber = (int)($_POST['page_number'] ?? 0);
    $percentage = (float)($_POST['percentage'] ?? 0);
    $duration = (int)($_POST['duration'] ?? 0);
    
    my_log("saveProgress: book_id=$bookId, page=$pageNumber, percentage=$percentage");
    
    if (!$bookId) {
        echo json_encode(['success' => false, 'message' => 'Book ID required']);
        return;
    }
    
    // Проверяем, существует ли книга
    $stmt = $pdo->prepare("SELECT id FROM books WHERE id = ?");
    $stmt->execute([$bookId]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Book not found']);
        return;
    }
    
    try {
        // Сохраняем в историю чтения
        $stmt = $pdo->prepare("
            INSERT INTO reading_history (
                user_fingerprint, book_id, cfi_range,
                page_number, percentage, duration_seconds
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $fingerprint, $bookId, $cfiRange,
            $pageNumber, $percentage, $duration
        ]);
        my_log("Reading history saved");
        
        // Проверяем существующую закладку
        $stmt = $pdo->prepare("
            SELECT id FROM bookmarks
            WHERE user_fingerprint = ?
              AND book_id = ?
              AND note = 'Последнее прочитанное'
              AND is_deleted = 0
        ");
        $stmt->execute([$fingerprint, $bookId]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Обновляем
            $stmt = $pdo->prepare("
                UPDATE bookmarks
                SET cfi_range = ?,
                    page_number = ?,
                    percentage = ?,
                    last_read = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $cfiRange, $pageNumber, $percentage, $existing['id']
            ]);
            my_log("Bookmark updated: id=" . $existing['id']);
        } else {
            // Создаем новую
            $stmt = $pdo->prepare("
                INSERT INTO bookmarks (
                    user_fingerprint, book_id, cfi_range,
                    page_number, percentage, note
                ) VALUES (?, ?, ?, ?, ?, 'Последнее прочитанное')
            ");
            $stmt->execute([
                $fingerprint, $bookId, $cfiRange,
                $pageNumber, $percentage
            ]);
            my_log("Bookmark created: " . $pdo->lastInsertId());
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Progress saved',
            'data' => compact('bookId', 'pageNumber', 'percentage', 'fingerprint')
        ]);
        
    } catch (Exception $e) {
        my_log("Error saving progress: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function addBookmark($pdo, $fingerprint) {
    $bookId = (int)($_POST['book_id'] ?? 0);
    $cfiRange = $_POST['cfi_range'] ?? '';
    $pageNumber = (int)($_POST['page_number'] ?? 0);
    $percentage = (float)($_POST['percentage'] ?? 0);
    $note = $_POST['note'] ?? 'Закладка';
    
    if (!$bookId) {
        echo json_encode(['success' => false, 'message' => 'Book ID required']);
        return;
    }
    
    $stmt = $pdo->prepare("
        INSERT INTO bookmarks (
            user_fingerprint, book_id, cfi_range,
            page_number, percentage, note
        ) VALUES (?, ?, ?, ?, ?, ?)
    ");
    $result = $stmt->execute([
        $fingerprint, $bookId, $cfiRange,
        $pageNumber, $percentage, $note
    ]);
    
    echo json_encode([
        'success' => $result,
        'id' => $pdo->lastInsertId()
    ]);
}


function getLastRead($pdo, $fingerprint) {
    $bookId = (int)($_GET['book_id'] ?? 0);
    
    my_log("getLastRead: book_id=$bookId");
    
    if (!$bookId) {
        echo json_encode(['success' => false, 'message' => 'Book ID required']);
        return;
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM bookmarks
        WHERE user_fingerprint = ?
          AND book_id = ?
          AND note = 'Последнее прочитанное'
          AND is_deleted = 0
        ORDER BY last_read DESC
        LIMIT 1
    ");
    $stmt->execute([$fingerprint, $bookId]);
    $bookmark = $stmt->fetch(PDO::FETCH_ASSOC);
    
    my_log("getLastRead result: " . ($bookmark ? 'found' : 'not found'));
    
    echo json_encode([
        'success' => true,
        'data' => $bookmark ?: null
    ]);
}

function deleteBookmark($pdo, $fingerprint) {
    $bookmarkId = (int)($_POST['bookmark_id'] ?? 0);

    my_log("deleteBookmark: id=$bookmarkId, fingerprint=$fingerprint");

    if (!$bookmarkId) {
        echo json_encode(['success' => false, 'message' => 'Bookmark ID required']);
        return;
    }

    // Soft delete - УДАЛЯЕМ ЛЮБЫЕ ЗАКЛАДКИ
    $stmt = $pdo->prepare("
        UPDATE bookmarks
        SET is_deleted = 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
          AND user_fingerprint = ?
    ");
    $result = $stmt->execute([$bookmarkId, $fingerprint]);

    if ($result && $stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Bookmark deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete bookmark']);
    }
}

function testConnection($pdo) {
    my_log("=== TEST CONNECTION ===");

    try {
        // Определяем тип БД по драйверу PDO
        $dbType = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        my_log("DB Driver: " . $dbType);

        $result = [
            'success' => true,
            'db_type' => $dbType,
            'fingerprint' => $_COOKIE['device_fp'] ?? null,
            'tables' => [],
            'has_bookmarks' => false,
            'bookmarks_count' => 0
        ];

        switch ($dbType) {
            case 'sqlite':
                // SQLite
                $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $result['tables'] = $tables;
                $result['has_bookmarks'] = in_array('bookmarks', $tables);

                if ($result['has_bookmarks']) {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM bookmarks");
                    $result['bookmarks_count'] = (int)$stmt->fetchColumn();
                }

                $stmt = $pdo->query("SELECT sqlite_version()");
                $result['version'] = $stmt->fetchColumn();
                break;

            case 'mysql':
                // MySQL
                $stmt = $pdo->query("SELECT VERSION()");
                $result['version'] = $stmt->fetchColumn();

                $stmt = $pdo->query("SHOW TABLES");
                $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

                $result['tables'] = $tables;
                $result['has_bookmarks'] = in_array('bookmarks', $tables);

                if ($result['has_bookmarks']) {
                    $stmt = $pdo->query("SELECT COUNT(*) FROM bookmarks");
                    $result['bookmarks_count'] = (int)$stmt->fetchColumn();
                }

                $stmt = $pdo->query("SELECT DATABASE()");
                $result['database'] = $stmt->fetchColumn();
                break;

            default:
                $result['message'] = "Unknown database type: " . $dbType;
        }

        echo json_encode($result);

    } catch (Exception $e) {
        my_log("Test connection error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
            'db_type' => $dbType ?? 'unknown'
        ]);
    }
}
?>
