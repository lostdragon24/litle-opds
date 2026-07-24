<?php

// api/bookmarks.php - ПОЛНАЯ ИСПРАВЛЕННАЯ ВЕРСИЯ

// ===== 1. ЖЕСТКАЯ БУФЕРИЗАЦИЯ =====
while (ob_get_level()) {
    ob_end_clean();
}
ob_start();

// ===== 2. ОТКЛЮЧАЕМ ВЫВОД ОШИБОК В ОТВЕТ =====
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);
ini_set('log_errors', 1);

// ===== 3. ПЕРЕХВАТ ФАТАЛЬНЫХ ОШИБОК =====
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level()) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error: ' . $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ]);
    }
});

try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../lib/Database.php';
    require_once __DIR__ . '/../init.php';

    header('Content-Type: application/json; charset=utf-8');

    $db = Database::getInstance();
    $pdo = $db->getConnection();

    // Получаем fingerprint
    $fingerprint = $_COOKIE['device_fp']
                ?? $_POST['fingerprint']
                ?? $_GET['fingerprint']
                ?? null;

    if (!$fingerprint) {
        echo json_encode(['success' => false, 'message' => 'Fingerprint not found']);
        exit;
    }

    $action = $_POST['action'] ?? $_GET['action'] ?? 'get';

    // ===== ОСНОВНОЙ SWITCH =====
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
        case 'get_annotations':
            getAnnotations($pdo, $fingerprint);
            break;
        case 'create_annotation':
            createAnnotation($pdo, $fingerprint);
            break;
        case 'export_annotations':
            exportAnnotations($pdo, $fingerprint);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action: ' . $action]);
    }

} catch (Exception $e) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}

// ===== ФУНКЦИИ =====

function saveProgress($pdo, $fingerprint)
{
    $bookId = (int)($_POST['book_id'] ?? 0);
    $cfiRange = $_POST['cfi_range'] ?? '';
    $pageNumber = (int)($_POST['page_number'] ?? 0);
    $percentage = (float)($_POST['percentage'] ?? 0);
    $duration = (int)($_POST['duration'] ?? 0);

    if (!$bookId) {
        echo json_encode(['success' => false, 'message' => 'Book ID required']);
        return;
    }

    try {
        // Проверяем книгу
        $stmt = $pdo->prepare("SELECT id FROM books WHERE id = ?");
        $stmt->execute([$bookId]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Book not found']);
            return;
        }

        // Сохраняем в историю
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

        // Проверяем наличие колонки type
        $columns = getTableColumns($pdo, 'bookmarks');
        $hasType = in_array('type', $columns);

        // Проверяем существующую закладку "Последнее прочитанное"
        $stmt = $pdo->prepare("
            SELECT id FROM bookmarks
            WHERE user_fingerprint = ? AND book_id = ? AND note = 'Последнее прочитанное' AND is_deleted = 0
        ");
        $stmt->execute([$fingerprint, $bookId]);
        $existing = $stmt->fetch();

        if ($existing) {
            // Обновляем существующую
            if ($hasType) {
                $stmt = $pdo->prepare("
                    UPDATE bookmarks
                    SET cfi_range = ?, page_number = ?, percentage = ?,
                        last_read = CURRENT_TIMESTAMP, type = 'last_read'
                    WHERE id = ?
                ");
                $stmt->execute([$cfiRange, $pageNumber, $percentage, $existing['id']]);
            } else {
                $stmt = $pdo->prepare("
                    UPDATE bookmarks
                    SET cfi_range = ?, page_number = ?, percentage = ?, last_read = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$cfiRange, $pageNumber, $percentage, $existing['id']]);
            }
        } else {
            // Создаём новую
            if ($hasType) {
                $stmt = $pdo->prepare("
                    INSERT INTO bookmarks (
                        user_fingerprint, book_id, cfi_range, page_number,
                        percentage, note, type
                    ) VALUES (?, ?, ?, ?, ?, 'Последнее прочитанное', 'last_read')
                ");
                $stmt->execute([$fingerprint, $bookId, $cfiRange, $pageNumber, $percentage]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO bookmarks (
                        user_fingerprint, book_id, cfi_range, page_number, percentage, note
                    ) VALUES (?, ?, ?, ?, ?, 'Последнее прочитанное')
                ");
                $stmt->execute([$fingerprint, $bookId, $cfiRange, $pageNumber, $percentage]);
            }
        }

        echo json_encode(['success' => true, 'message' => 'Progress saved']);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getLastRead($pdo, $fingerprint)
{
    $bookId = (int)($_GET['book_id'] ?? 0);

    if (!$bookId) {
        echo json_encode(['success' => false, 'message' => 'Book ID required']);
        return;
    }

    $stmt = $pdo->prepare("
        SELECT * FROM bookmarks
        WHERE user_fingerprint = ? AND book_id = ? AND note = 'Последнее прочитанное' AND is_deleted = 0
        ORDER BY last_read DESC LIMIT 1
    ");
    $stmt->execute([$fingerprint, $bookId]);
    $bookmark = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $bookmark ?: null]);
}

function testConnection($pdo)
{
    $dbType = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    $result = [
        'success' => true,
        'db_type' => $dbType,
        'fingerprint' => $_COOKIE['device_fp'] ?? null,
        'tables' => [],
        'has_bookmarks' => false,
        'bookmarks_count' => 0
    ];

    try {
        if ($dbType === 'sqlite') {
            $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $result['tables'] = $tables;
            $result['has_bookmarks'] = in_array('bookmarks', $tables);

            if ($result['has_bookmarks']) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM bookmarks");
                $result['bookmarks_count'] = (int)$stmt->fetchColumn();
            }
        } else {
            $stmt = $pdo->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $result['tables'] = $tables;
            $result['has_bookmarks'] = in_array('bookmarks', $tables);

            if ($result['has_bookmarks']) {
                $stmt = $pdo->query("SELECT COUNT(*) FROM bookmarks");
                $result['bookmarks_count'] = (int)$stmt->fetchColumn();
            }
        }
    } catch (Exception $e) {
        $result['error'] = $e->getMessage();
    }

    echo json_encode($result);
}

function deleteBookmark($pdo, $fingerprint)
{
    $bookmarkId = (int)($_POST['bookmark_id'] ?? 0);

    if (!$bookmarkId) {
        echo json_encode(['success' => false, 'message' => 'Bookmark ID required']);
        return;
    }

    $stmt = $pdo->prepare("
        UPDATE bookmarks SET is_deleted = 1 WHERE id = ? AND user_fingerprint = ?
    ");
    $result = $stmt->execute([$bookmarkId, $fingerprint]);

    echo json_encode([
        'success' => $result && $stmt->rowCount() > 0,
        'message' => $result ? 'Bookmark deleted' : 'Failed to delete'
    ]);
}

function addBookmark($pdo, $fingerprint)
{
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
        INSERT INTO bookmarks (user_fingerprint, book_id, cfi_range, page_number, percentage, note)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $result = $stmt->execute([$fingerprint, $bookId, $cfiRange, $pageNumber, $percentage, $note]);

    echo json_encode([
        'success' => $result,
        'id' => $pdo->lastInsertId()
    ]);
}

function getAnnotations($pdo, $fingerprint)
{
    $bookId = (int)($_POST['book_id'] ?? $_GET['book_id'] ?? 0);

    if (!$bookId) {
        echo json_encode(['success' => false, 'message' => 'Book ID required']);
        return;
    }

    try {
        // Проверяем наличие колонки type
        $columns = getTableColumns($pdo, 'bookmarks');
        $hasType = in_array('type', $columns);

        if ($hasType) {
            $sql = "SELECT * FROM bookmarks
                    WHERE user_fingerprint = ? AND book_id = ? AND is_deleted = 0
                    ORDER BY updated_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$fingerprint, $bookId]);
        } else {
            // Fallback для старой схемы
            $sql = "SELECT * FROM bookmarks
                    WHERE user_fingerprint = ? AND book_id = ? AND is_deleted = 0
                    ORDER BY updated_at DESC";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$fingerprint, $bookId]);
        }

        $annotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'annotations' => $annotations,
            'count' => count($annotations)
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function createAnnotation($pdo, $fingerprint)
{
    $bookId = (int)($_POST['book_id'] ?? 0);
    $type = $_POST['type'] ?? 'bookmark';
    $selectedText = $_POST['selected_text'] ?? '';
    $note = $_POST['note'] ?? '';
    $pageNumber = (int)($_POST['page_number'] ?? 0);
    $percentage = (float)($_POST['percentage'] ?? 0);
    $cfiRange = $_POST['cfi_range'] ?? '';
    $color = $_POST['color'] ?? 'yellow';

    if (!$bookId) {
        echo json_encode(['success' => false, 'message' => 'Book ID required']);
        return;
    }

    try {
        // Проверяем колонки
        $columns = getTableColumns($pdo, 'bookmarks');
        $hasType = in_array('type', $columns);
        $hasColor = in_array('color', $columns);
        $hasSelectedText = in_array('selected_text', $columns);

        if ($hasType && $hasSelectedText) {
            // Полная версия
            $sql = "INSERT INTO bookmarks (
                user_fingerprint, book_id, type, color,
                selected_text, note, page_number, percentage,
                cfi_range
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $fingerprint, $bookId, $type, $color,
                $selectedText, $note, $pageNumber, $percentage,
                $cfiRange
            ]);
        } else {
            // Упрощённая версия
            $noteData = json_encode([
                'type' => $type,
                'color' => $color,
                'selected_text' => $selectedText,
                'note' => $note
            ], JSON_UNESCAPED_UNICODE);

            $sql = "INSERT INTO bookmarks (
                user_fingerprint, book_id, cfi_range,
                page_number, percentage, note
            ) VALUES (?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $fingerprint, $bookId, $cfiRange,
                $pageNumber, $percentage, $noteData
            ]);
        }

        echo json_encode([
            'success' => true,
            'id' => $pdo->lastInsertId(),
            'message' => 'Annotation created'
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function exportAnnotations($pdo, $fingerprint)
{
    $bookId = (int)($_GET['book_id'] ?? 0);

    try {
        $sql = "SELECT b.*, bk.title as book_title, bk.author as book_author
                FROM bookmarks b
                JOIN books bk ON b.book_id = bk.id
                WHERE b.user_fingerprint = ? AND b.is_deleted = 0";
        $params = [$fingerprint];

        if ($bookId > 0) {
            $sql .= " AND b.book_id = ?";
            $params[] = $bookId;
        }

        $sql .= " ORDER BY bk.title, b.page_number";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $annotations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Группируем по книгам
        $grouped = [];
        foreach ($annotations as $ann) {
            $bookKey = $ann['book_id'];
            if (!isset($grouped[$bookKey])) {
                $grouped[$bookKey] = [
                    'title' => $ann['book_title'],
                    'author' => $ann['book_author'],
                    'annotations' => []
                ];
            }
            $grouped[$bookKey]['annotations'][] = $ann;
        }

        // Генерируем Markdown
        $md = "# Мои заметки и цитаты\n\n";
        $md .= "*Экспортировано: " . date('d.m.Y H:i') . "*\n\n---\n\n";

        foreach ($grouped as $book) {
            $md .= "## 📖 {$book['title']}\n";
            $md .= "**{$book['author']}**\n\n";

            foreach ($book['annotations'] as $ann) {
                $type = $ann['type'] ?? 'bookmark';
                $icons = ['quote' => '💬', 'note' => '📝', 'highlight' => '🖍', 'bookmark' => '🔖'];
                $icon = $icons[$type] ?? '•';

                $md .= "### {$icon} Стр. {$ann['page_number']}\n";

                if (!empty($ann['selected_text'])) {
                    $md .= "> {$ann['selected_text']}\n\n";
                }

                if (!empty($ann['note'])) {
                    $md .= "{$ann['note']}\n\n";
                }

                $md .= "---\n\n";
            }
        }

        header('Content-Type: text/markdown; charset=utf-8');
        header('Content-Disposition: attachment; filename="notes_' . date('Y-m-d') . '.md"');
        echo $md;
        exit;

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getTableColumns($pdo, $tableName)
{
    static $cache = [];

    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }

    $dbType = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columns = [];

    try {
        if ($dbType === 'sqlite') {
            $stmt = $pdo->query("PRAGMA table_info(\"$tableName\")");
            while ($row = $stmt->fetch()) {
                $columns[] = $row['name'];
            }
        } else {
            $stmt = $pdo->query("DESCRIBE `$tableName`");
            while ($row = $stmt->fetch()) {
                $columns[] = $row['Field'];
            }
        }
    } catch (Exception $e) {
        // Игнорируем
    }

    $cache[$tableName] = $columns;
    return $columns;
}
