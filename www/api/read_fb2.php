<?php

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/SecurityHelper.php';
require_once __DIR__ . '/../init.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    http_response_code(400);
    die('Invalid book ID');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    http_response_code(404);
    die('Book not found');
}

// Получаем номер страницы из GET параметра
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$selectedEncoding = $_GET['encoding'] ?? $_COOKIE['reader_encoding'] ?? 'auto';

$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = rtrim(dirname(dirname($scriptPath)), '/');

// Получаем содержимое книги
$content = getBookContent($book);
if (!$content) {
    http_response_code(500);
    die('Cannot read book file');
}

// Получаем выбранную кодировку из GET или cookie
$selectedEncoding = $_GET['encoding'] ?? $_COOKIE['reader_encoding'] ?? 'auto';
$availableEncodings = [
    'auto' => 'Автоопределение',
    'utf-8' => 'UTF-8',
    'windows-1251' => 'Windows-1251',
    'koi8-r' => 'KOI8-R',
    'cp866' => 'CP866',
    'iso-8859-5' => 'ISO-8859-5'
];

// Применяем выбранную кодировку
if ($selectedEncoding !== 'auto') {
    $converted = @iconv($selectedEncoding, 'UTF-8//IGNORE', $content);
    if ($converted) {
        $content = $converted;
    }
} else {
    // Автоопределение
    $detected = mb_detect_encoding($content, ['UTF-8', 'Windows-1251', 'KOI8-R', 'CP866', 'ISO-8859-5'], true);
    if ($detected && $detected !== 'UTF-8') {
        $content = mb_convert_encoding($content, 'UTF-8', $detected);
    }
}

// Получаем размер шрифта
$fontSize = isset($_COOKIE['reader_font_size']) ? intval($_COOKIE['reader_font_size']) : 100;
$fontSize = max(70, min(200, $fontSize));

// Получаем номер страницы
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Разбиваем на страницы
$pages = splitIntoPages($content);
$totalPages = count($pages);

if ($page > $totalPages) {
    $page = $totalPages;
}

// Получаем содержимое текущей страницы
$pageContent = $pages[$page - 1] ?? '';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($book['title'] ?: "<?php echo __('book_untitled'); ?>", ENT_QUOTES, 'UTF-8'); ?> - "<?php echo __('book_read'); ?>"</title>
    <style>


        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: "Georgia", "Times New Roman", serif;
            line-height: 1.8;
            color: #2c3e50;
            background: #fff;
            padding: 30px 20px;
            font-size: <?php echo $fontSize; ?>%;
        }
        
        .reader-content {
            max-width: 800px;
            margin: 0 auto;
        }
        
        .book-header {
            text-align: center;
            margin-bottom: 2em;
            padding-bottom: 1.5em;
            border-bottom: 2px solid #e9ecef;
        }
        
        .book-title {
            font-size: 2.2em;
            font-weight: bold;
            margin-bottom: 0.3em;
            color: #1a2b3c;
        }
        
        .book-author {
            font-size: 1.2em;
            color: #6c757d;
        }
        
        .fb2-body p {
            margin: 1.2em 0;
            text-align: justify;
            text-indent: 1.5em;
        }
        
        .fb2-body h1, .fb2-body h2, .fb2-body h3 {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            margin: 1.5em 0 0.8em 0;
            font-weight: 600;
            color: #1a2b3c;
        }
        
        .fb2-body h1 { font-size: 1.8em; }
        .fb2-body h2 { font-size: 1.5em; }
        .fb2-body h3 { font-size: 1.3em; }
        
        .fb2-body img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .fb2-body .empty-line {
            height: 1.5em;
        }
        
        /* Пагинация */
        .pagination-info {
            text-align: center;
            margin: 30px 0 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 50px;
            font-size: 0.95em;
        }
        
        .pagination-info span {
            display: inline-block;
            padding: 5px 15px;
            background: #fff;
            border-radius: 30px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        
        /* Индикатор кодировки */
        .encoding-indicator {
            position: fixed;
            bottom: 20px;
            left: 20px;
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 8px 15px;
            border-radius: 30px;
            font-size: 0.9rem;
            z-index: 1000;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .encoding-indicator:hover {
            background: rgba(0,0,0,0.95);
            transform: scale(1.05);
        }
        
        .encoding-indicator i {
            margin-right: 8px;
            color: #4CAF50;
        }
        
        /* Меню выбора кодировки */
        .encoding-menu {
            position: fixed;
            bottom: 80px;
            left: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.2);
            padding: 15px;
            z-index: 1001;
            display: none;
            min-width: 200px;
        }
        
        .encoding-menu.show {
            display: block;
            animation: slideUp 0.3s ease;
        }
        
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .encoding-menu h6 {
            margin: 0 0 10px 0;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
            color: #333;
        }
        
        .encoding-menu button {
            display: block;
            width: 100%;
            padding: 8px 12px;
            margin: 5px 0;
            border: none;
            background: #f8f9fa;
            border-radius: 6px;
            cursor: pointer;
            text-align: left;
            transition: all 0.2s ease;
            color: #333;
        }
        
        .encoding-menu button:hover {
            background: #e9ecef;
        }
        
        .encoding-menu button.active {
            background: #007bff;
            color: white;
        }
        
        /* Темная тема */
        body.dark-theme {
            background: #1a1a1a;
            color: #e0e0e0;
        }
        
        body.dark-theme .book-title { color: #fff; }
        body.dark-theme .book-author { color: #b0b0b0; }
        body.dark-theme .fb2-body h1,
        body.dark-theme .fb2-body h2,
        body.dark-theme .fb2-body h3 { color: #fff; }
        body.dark-theme .pagination-info { background: #2d2d2d; }
        body.dark-theme .pagination-info span { background: #3d3d3d; color: #e0e0e0; }
        body.dark-theme .encoding-menu { background: #2d2d2d; }
        body.dark-theme .encoding-menu h6 { color: #fff; border-bottom-color: #404040; }
        body.dark-theme .encoding-menu button { background: #3d3d3d; color: #e0e0e0; }
        body.dark-theme .encoding-menu button:hover { background: #4d4d4d; }
    </style>
</head>
<body>
    <div class="reader-content">
        <!-- Заголовок книги -->
        <?php if ($page == 1): ?>
        <div class="book-header">
            <div class="book-title"><?php echo htmlspecialchars($book['title'] ?: "<?php echo __('book_untitled'); ?>", ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="book-author"><?php echo htmlspecialchars($book['author'] ?: "<?php echo __('book_unknown_author'); ?>", ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <?php endif; ?>
        









<!-- Содержимое страницы -->
<div class="fb2-body">
<?php 
// Санитизация контента для защиты от XSS
require_once __DIR__ . '/../lib/SecurityHelper.php';
$security = SecurityHelper::getInstance();
echo $security->sanitizeBookContent($pages[$page - 1] ?? ''); 
?>
</div>
        
        <!-- Информация о пагинации -->
        <div class="pagination-info">
            <span>Страница <?php echo $page; ?> из <?php echo $totalPages; ?></span>
        </div>
    </div>
    
    <!-- Индикатор кодировки -->
    <div class="encoding-indicator" onclick="toggleEncodingMenu()">
        <i class="fas fa-language"></i>
        <?php echo $availableEncodings[$selectedEncoding] ?? $selectedEncoding; ?>
    </div>
    
    <!-- Меню выбора кодировки -->
    <div class="encoding-menu" id="encodingMenu">
        <h6>Выберите кодировку:</h6>
        <?php foreach ($availableEncodings as $enc => $name): ?>
        <button onclick="changeEncoding('<?php echo $enc; ?>')" 
                class="<?php echo $enc === $selectedEncoding ? 'active' : ''; ?>">
            <?php echo $name; ?>
        </button>
        <?php endforeach; ?>
    </div>
    
<!-- api/read_fb2.php - полный блок скрипта в конце файла -->

<script>
// ============================================
// СУЩЕСТВУЮЩИЙ КОД (оставляем как есть)
// ============================================

// Сообщаем родительскому окну о пагинации
window.parent.postMessage({
    type: 'pagination',
    currentPage: <?php echo $page; ?>,
    totalPages: <?php echo $totalPages; ?>
}, '*');

// Переключение меню кодировки
function toggleEncodingMenu() {
    const menu = document.getElementById('encodingMenu');
    menu.classList.toggle('show');
}

// Закрыть меню при клике вне его
document.addEventListener('click', function(event) {
    const menu = document.getElementById('encodingMenu');
    const indicator = document.querySelector('.encoding-indicator');
    
    if (!menu.contains(event.target) && !indicator.contains(event.target)) {
        menu.classList.remove('show');
    }
});

// Смена кодировки
function changeEncoding(encoding) {
    document.cookie = 'reader_encoding=' + encoding + '; path=/; max-age=31536000';
    window.location.href = '?id=<?php echo $bookId; ?>&page=<?php echo $page; ?>&encoding=' + encoding;
}

// Слушаем команды от родительского окна (существующий код)
window.addEventListener('message', function(event) {
    // Существующие обработчики
    if (event.data.type === 'navigate') {
        if (event.data.direction === 'next' && <?php echo $page; ?> < <?php echo $totalPages; ?>) {
            window.location.href = '?id=<?php echo $bookId; ?>&page=' + (<?php echo $page; ?> + 1) + '&encoding=<?php echo $selectedEncoding; ?>';
        } else if (event.data.direction === 'prev' && <?php echo $page; ?> > 1) {
            window.location.href = '?id=<?php echo $bookId; ?>&page=' + (<?php echo $page; ?> - 1) + '&encoding=<?php echo $selectedEncoding; ?>';
        }
    } else if (event.data.type === 'fontSize') {
        document.cookie = 'reader_font_size=' + event.data.size + '; path=/';
        window.location.reload();
    } else if (event.data.type === 'theme') {
        if (event.data.dark) {
            document.body.classList.add('dark-theme');
        } else {
            document.body.classList.remove('dark-theme');
        }
    }
    
    // ============================================
    // НОВЫЙ КОД ДЛЯ ЗАКЛАДОК
    // ============================================
    
    // Получить текущую позицию
    if (event.data.type === 'getPosition') {
        const position = getCurrentPosition();
        window.parent.postMessage({
            type: 'position',
            position: position
        }, '*');
    }
    
    // Перейти к позиции (закладке)
    if (event.data.type === 'goTo' && event.data.cfi) {
        goToPosition(event.data.cfi);
    }
});

// ============================================
// НОВЫЕ ФУНКЦИИ ДЛЯ ЗАКЛАДОК
// ============================================

/**
 * Получить текущую позицию чтения
 */
function getCurrentPosition() {
    return {
        cfi: generateCfi(<?php echo $page; ?>),  // Используем PHP переменную $page
        page: <?php echo $page; ?>,               // Используем PHP переменную $page
        totalPages: <?php echo $totalPages; ?>,
        percentage: Math.round((<?php echo $page; ?> / <?php echo $totalPages; ?>) * 100)
    };
}


/**
 * Сгенерировать CFI для текущей страницы FB2
 * Для FB2 используем простой формат: cfi(/6/4[chap]!/4[body]/10[para]/2/1:СТРАНИЦА)
 */
function generateCfi(page) {
    // Для FB2 используем простой формат
    // В реальности нужно генерировать более точный CFI
    return 'epubcfi(/6/4[book]!/4[body]/10[section]/2/1:' + page + ')';
}


/**
 * Перейти к позиции по CFI
 */
function goToPosition(cfi) {
    // Парсим номер страницы из CFI
    let page = null;
    
    // Пробуем разные форматы
    const match1 = cfi.match(/1:(\d+)\)/);
    const match2 = cfi.match(/page=(\d+)/);
    const match3 = cfi.match(/:(\d+)\)/);
    
    if (match1) {
        page = parseInt(match1[1]);
    } else if (match2) {
        page = parseInt(match2[1]);
    } else if (match3) {
        page = parseInt(match3[1]);
    }
    
    if (page && page > 0 && page <= <?php echo $totalPages; ?>) {
        // Переходим на страницу
        window.location.href = '?id=<?php echo $bookId; ?>&page=' + page + '&encoding=<?php echo $selectedEncoding; ?>';
    } else {
        // Если не удалось определить страницу, показываем уведомление
        showNotification('Не удалось перейти к закладке', 'error');
    }
}


/**
 * Получить выделенный текст (для создания заметок)
 */
function getSelectedText() {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) {
        return null;
    }
    return {
        text: selection.toString().trim(),
        startOffset: selection.anchorOffset,
        endOffset: selection.focusOffset,
        // Можно добавить больше информации о контексте
        context: getTextContext(selection.anchorNode)
    };
}

/**
 * Получить контекст выделенного текста (окружающий текст)
 */
function getTextContext(node, contextLength = 50) {
    if (!node || node.nodeType !== Node.TEXT_NODE) {
        return '';
    }
    
    const text = node.textContent || '';
    const offset = node.data?.length || 0;
    
    // Берем текст до и после выделения
    const start = Math.max(0, offset - contextLength);
    const end = Math.min(text.length, offset + contextLength);
    
    return text.substring(start, end);
}

/**
 * Сохранить прогресс чтения (вызывается при смене страницы)
 */
function saveReadingProgress() {
    const position = getCurrentPosition();
    const duration = getReadingDuration();
    
    console.log('Saving progress:', {
        book_id: <?php echo $bookId; ?>,
        page: position.page,
        percentage: position.percentage,
        cfi: position.cfi
    });
    
    // Отправляем родительскому окну
    window.parent.postMessage({
        type: 'saveProgress',
        position: position,
        duration: duration,
        bookId: <?php echo $bookId; ?>
    }, '*');
}

/**
 * Получить время чтения на текущей странице
 */
let pageStartTime = Date.now();
function getReadingDuration() {
    const now = Date.now();
    const duration = Math.round((now - pageStartTime) / 1000);
    pageStartTime = now;
    return duration;
}

/**
 * Сохранять прогресс при уходе со страницы
 */
document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        // Страница скрыта - сохраняем прогресс
        saveReadingProgress();
    }
});

// Сохраняем прогресс при переходе на другую страницу
window.addEventListener('beforeunload', function() {
    saveReadingProgress();
});

// Сохраняем прогресс каждые 30 секунд
let autoSaveInterval = setInterval(function() {
    saveReadingProgress();
}, 30000);

// Останавливаем интервал при закрытии
window.addEventListener('unload', function() {
    clearInterval(autoSaveInterval);
});

// ============================================
// КОНТЕКСТНОЕ МЕНЮ ДЛЯ СОЗДАНИЯ ЗАКЛАДОК
// ============================================

/**
 * Добавляем пункт "Добавить закладку" в контекстное меню
 */
document.addEventListener('contextmenu', function(e) {
    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) {
        return;
    }
    
    // Создаем кастомное меню (если нужно)
    // Или просто обрабатываем выделение
    const selectedText = selection.toString().trim();
    if (selectedText.length > 0) {
        // Показываем кнопку для быстрого создания закладки
        showQuickBookmarkButton(e.clientX, e.clientY, selectedText);
    }
});

/**
 * Показать кнопку быстрого создания закладки
 */
function showQuickBookmarkButton(x, y, text) {
    // Удаляем старую кнопку если есть
    const oldBtn = document.getElementById('quick-bookmark-btn');
    if (oldBtn) oldBtn.remove();
    
    const btn = document.createElement('div');
    btn.id = 'quick-bookmark-btn';
    btn.className = 'quick-bookmark-btn';
    btn.innerHTML = `
        <i class="fas fa-bookmark me-1"></i>
        Добавить закладку
        <span class="bookmark-preview">"${text.substring(0, 30)}${text.length > 30 ? '...' : ''}"</span>
    `;
    btn.style.cssText = `
        position: fixed;
        top: ${Math.min(y + 10, window.innerHeight - 60)}px;
        left: ${Math.min(x + 10, window.innerWidth - 200)}px;
        background: #2c3e50;
        color: white;
        padding: 8px 16px;
        border-radius: 8px;
        cursor: pointer;
        z-index: 10000;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 8px;
        animation: slideUp 0.2s ease;
    `;
    
    // Добавляем стили анимации
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideUp {
            from { transform: translateY(10px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .quick-bookmark-btn:hover {
            background: #34495e;
            transform: scale(1.02);
        }
        .bookmark-preview {
            font-style: italic;
            color: #a0c0d0;
            font-size: 12px;
            max-width: 200px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
    `;
    document.head.appendChild(style);
    
    document.body.appendChild(btn);
    
    // Обработчик клика
    btn.addEventListener('click', function(e) {
        e.stopPropagation();
        this.remove();
        
        // Создаем закладку с выделенным текстом
        const selectedText = window.getSelection().toString().trim();
        const note = selectedText ? 'Цитата: ' + selectedText.substring(0, 100) : '';
        
        // Отправляем родительскому окну
        window.parent.postMessage({
            type: 'createBookmark',
            position: getCurrentPosition(),
            note: note,
            selectedText: selectedText
        }, '*');
        
        // Показываем уведомление
        showNotification('Закладка создана!', 'success');
    });
    
    // Закрываем при клике вне кнопки
    setTimeout(() => {
        document.addEventListener('click', function closeBtn(e) {
            if (!btn.contains(e.target)) {
                btn.remove();
                document.removeEventListener('click', closeBtn);
            }
        });
    }, 100);
}

/**
 * Показать уведомление внутри iframe
 */
function showNotification(message, type = 'info') {
    // Создаем уведомление
    const notification = document.createElement('div');
    notification.className = `iframe-notification iframe-notification-${type}`;
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 24px;
        background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
        color: white;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 99999;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        animation: slideIn 0.3s ease;
        max-width: 400px;
    `;
    
    // Добавляем стили
    const style = document.createElement('style');
    style.textContent = `
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
    `;
    document.head.appendChild(style);
    
    document.body.appendChild(notification);
    
    // Автоматически закрываем через 3 секунды
    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// ============================================
// ПРИМЕНЯЕМ СОХРАНЕННУЮ ТЕМУ (существующий код)
// ============================================

if (window.parent.document.body.classList.contains('dark-theme')) {
    document.body.classList.add('dark-theme');
}

// ============================================
// ДОПОЛНИТЕЛЬНО: ОТСЛЕЖИВАНИЕ ВРЕМЕНИ ЧТЕНИЯ
// ============================================

// Время начала чтения страницы
let pageLoadTime = Date.now();

// Отправляем событие о загрузке страницы
window.parent.postMessage({
    type: 'pageLoaded',
    page: <?php echo $page; ?>,
    totalPages: <?php echo $totalPages; ?>
}, '*');

// Отслеживаем активность пользователя (прокрутка, клики)
let lastActivity = Date.now();
const activityEvents = ['scroll', 'click', 'mousemove', 'keydown'];

activityEvents.forEach(eventType => {
    document.addEventListener(eventType, function() {
        lastActivity = Date.now();
    });
});

// Проверяем активность каждые 5 секунд
setInterval(function() {
    const now = Date.now();
    const inactiveTime = (now - lastActivity) / 1000;
    
    // Если пользователь не активен больше 60 секунд, не считаем время чтения
    if (inactiveTime < 60) {
        // Пользователь активен
        window.parent.postMessage({
            type: 'readingActivity',
            active: true,
            page: <?php echo $page; ?>
        }, '*');
    }
}, 5000);

console.log('Reader bookmarks initialized');
</script>    




    <!-- Font Awesome для иконок -->
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/css/all.min.css">


</body>
</html>
<?php
exit;

/**
 * Получить содержимое книги
 */
function getBookContent($book) {
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === TRUE) {
            $content = $zip->getFromName($book['archive_internal_path']);
            $zip->close();
            return $content;
        }
        return false;
    }
    return @file_get_contents($book['file_path']);
}

/**
 * Разбить FB2 на страницы
 */
function splitIntoPages($content) {
    $pages = [];
    
    // Извлекаем тело книги
    if (preg_match('/<body>(.*?)<\/body>/is', $content, $matches)) {
        $body = $matches[1];
    } else {
        $body = $content;
    }
    
    // Удаляем namespace префиксы
    $body = preg_replace('/<(\/?)[^:>]+:([^>]+)>/', '<$1$2>', $body);
    
    // Конвертируем теги
    $body = preg_replace('/<title>/', '<h2>', $body);
    $body = preg_replace('/<\/title>/', '</h2>', $body);
    $body = preg_replace('/<subtitle>/', '<h3>', $body);
    $body = preg_replace('/<\/subtitle>/', '</h3>', $body);
    $body = preg_replace('/<p>/', '<p>', $body);
    $body = preg_replace('/<\/p>/', '</p>', $body);
    $body = preg_replace('/<empty-line\s*\/>/', '<div class="empty-line"></div>', $body);
    
    // Разбиваем на абзацы
    $paragraphs = preg_split('/(<h[1-3]>.*?<\/h[1-3]>|<p>.*?<\/p>)/i', $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    
    $currentPage = '';
    $currentLength = 0;
    $targetLength = 3000;
    
    foreach ($paragraphs as $para) {
        $cleanPara = strip_tags($para);
        $paraLength = mb_strlen($cleanPara, 'UTF-8');
        
        if ($currentLength + $paraLength > $targetLength && !empty($currentPage)) {
            $pages[] = $currentPage;
            $currentPage = $para;
            $currentLength = $paraLength;
        } else {
            $currentPage .= $para;
            $currentLength += $paraLength;
        }
    }
    
    if (!empty($currentPage)) {
        $pages[] = $currentPage;
    }
    
    if (empty($pages)) {
        $pages[] = $body;
    }
    
    return $pages;
}


?>
