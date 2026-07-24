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

// ============================================
// ЗАГРУЗКА И ВОССТАНОВЛЕНИЕ ПОДСВЕТОК
// ============================================
$highlights = [];
try {
    $fingerprint = $_COOKIE['device_fp'] ?? '';
    if (!empty($fingerprint)) {
        $stmt = $db->getConnection()->prepare("
            SELECT id, selected_text, color, page_number, note, cfi_range
            FROM bookmarks
            WHERE user_fingerprint = :fingerprint
              AND book_id = :book_id
              AND type = 'highlight'
              AND is_deleted = 0
              AND page_number = :page_number
        ");
        $stmt->execute([
            ':fingerprint' => $fingerprint,
            ':book_id' => $bookId,
            ':page_number' => $page
        ]);
        $highlights = $stmt->fetchAll();

        error_log("Loaded " . count($highlights) . " highlights for page " . $page);
    }
} catch (Exception $e) {
    error_log("Error loading highlights: " . $e->getMessage());
}

// ===== ПРИМЕНЯЕМ ПОДСВЕТКИ К СОДЕРЖИМОМУ =====
if (!empty($highlights)) {
    foreach ($highlights as $highlight) {
        $selectedText = trim($highlight['selected_text']);
        $color = $highlight['color'] ?? 'yellow';
        $note = $highlight['note'] ?? '';

        if (empty($selectedText)) {
            continue;
        }

        // Экранируем спецсимволы для использования в regex
        $pattern = '/' . preg_quote($selectedText, '/') . '/u';

        // Создаём замену с подсветкой
        $replacement = '<span class="highlight-' . $color . '" data-annotation-id="' . $highlight['id'] . '" data-note="' . htmlspecialchars($note, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($selectedText, ENT_QUOTES, 'UTF-8') . '</span>';

        // Применяем замену (только первое вхождение)
        $pageContent = preg_replace($pattern, $replacement, $pageContent, 1);

        error_log("Applied highlight: " . substr($selectedText, 0, 30) . "... color: " . $color);
    }
}

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

        /* ===== СТИЛИ ДЛЯ АННОТАЦИЙ ===== */
        .highlight-yellow {
            background-color: rgba(255, 245, 157, 0.5);
            border-bottom: 2px solid #fbc02d;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .highlight-green {
            background-color: rgba(165, 214, 167, 0.5);
            border-bottom: 2px solid #43a047;
            cursor: pointer;
        }
        .highlight-blue {
            background-color: rgba(144, 202, 249, 0.5);
            border-bottom: 2px solid #1e88e5;
            cursor: pointer;
        }
        .highlight-pink {
            background-color: rgba(244, 143, 177, 0.5);
            border-bottom: 2px solid #d81b60;
            cursor: pointer;
        }
        .highlight-orange {
            background-color: rgba(255, 204, 128, 0.5);
            border-bottom: 2px solid #fb8c00;
            cursor: pointer;
        }

        [class^="highlight-"]:hover {
            filter: brightness(0.9);
        }

        [class^="highlight-"][data-note]:hover::after {
            content: attr(data-note);
            position: absolute;
            bottom: 100%;
            left: 0;
            background: #333;
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            max-width: 300px;
            overflow: hidden;
            text-overflow: ellipsis;
            z-index: 1000;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
        }

        .fb2-image {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 20px auto;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        body.dark-theme .fb2-image {
            box-shadow: 0 4px 12px rgba(255,255,255,0.1);
        }

        .annotation-toolbar {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .anno-btn {
            background: transparent;
            border: none;
            color: white;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 14px;
        }

        .anno-btn:hover {
            background: rgba(255,255,255,0.2);
            transform: scale(1.1);
        }

        .color-picker {
            display: flex;
            gap: 4px;
            margin-left: 4px;
            padding-left: 8px;
            border-left: 1px solid rgba(255,255,255,0.3);
        }

        .color-btn {
            width: 20px;
            height: 20px;
            border: 2px solid transparent;
            border-radius: 50%;
            cursor: pointer;
            transition: all 0.2s;
        }

        .color-btn:hover {
            border-color: white;
            transform: scale(1.2);
        }

        /* Для отладки */
        .debug-info {
            display: none;
            background: #f8f9fa;
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            font-size: 12px;
            color: #666;
        }

/* Базовые стили для всех картинок из FB2 */
.fb2-image {
    display: block;
    max-width: 90%;       /* Не дает картинке выходить за пределы экрана */
    max-height: 75vh;     /* Ограничивает высоту до 75% от высоты экрана (удобно для обложек) */
    width: auto;
    height: auto;
    margin: 20px auto;    /* Центрирует картинку по горизонтали и делает отступы */
    object-fit: contain;  /* Сохраняет пропорции без искажений */
    border-radius: 4px;   /* Легкое эстетичное скругление углов */
}

//  для обложки
.fb2-cover-container {
    text-align: center;
    margin: 20px auto;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.fb2-cover-image {
    max-width: 100%;
    height: auto;
    max-height: 75vh;
    border-radius: 4px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
}

// Для темной темы
body.dark-theme .fb2-cover-container {
    background: #2d2d2d;
}


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
$pageContent = $pages[$page - 1] ?? '';

// ============================================
// МАКСИМАЛЬНО ПРОСТАЯ ОЧИСТКА
// ============================================

// 1. Удаляем все битые теги
$pageContent = preg_replace('/<image\/[^>]+>/i', '', $pageContent);

// 2. Удаляем все оставшиеся теги image
$pageContent = preg_replace('/<image[^>]*>.*?<\/image>/is', '', $pageContent);
$pageContent = preg_replace('/<image[^>]*\/?>/i', '', $pageContent);

// 3. Просто выводим с базовым strip_tags
$allowed = '<p><br><h1><h2><h3><strong><em><i><b><ul><ol><li><a><img><div><span><section>';
echo strip_tags($pageContent, $allowed);

// 4. Логируем результат
if (preg_match('/<img[^>]*>/i', $pageContent, $matches)) {
    error_log("OUTPUT: Found img tag: " . substr($matches[0], 0, 100));
} else {
    error_log("OUTPUT: No img tags found");
}

if (preg_match('/<image[^>]*>/i', $pageContent, $matches)) {
    error_log("OUTPUT: Found image tag: " . substr($matches[0], 0, 100));
}
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

<script>
(function() {
    const highlights = <?php echo json_encode($highlights); ?>;

    if (!highlights || highlights.length === 0) {
        console.log('No highlights to restore');
        return;
    }

    console.log('Restoring ' + highlights.length + ' highlights');

    function restoreHighlights() {
        const body = document.querySelector('.fb2-body');
        if (!body) {
            console.error('FB2 body not found');
            return;
        }

        highlights.forEach(function(highlight) {
            const text = highlight.selected_text.trim();
            if (!text) return;

            const color = highlight.color || 'yellow';
            const note = highlight.note || '';
            const id = highlight.id;

            const walker = document.createTreeWalker(
                body,
                NodeFilter.SHOW_TEXT,
                {
                    acceptNode: function(node) {
                        const parent = node.parentElement;
                        if (parent && parent.classList &&
                            parent.classList.contains('highlight-' + color)) {
                            return NodeFilter.FILTER_REJECT;
                        }
                        return NodeFilter.FILTER_ACCEPT;
                    }
                }
            );

            const nodes = [];
            let node;
            while (node = walker.nextNode()) {
                if (node.textContent.includes(text)) {
                    nodes.push(node);
                }
            }

            if (nodes.length > 0) {
                const targetNode = nodes[0];
                const parent = targetNode.parentElement;

                const span = document.createElement('span');
                span.className = 'highlight-' + color;
                span.dataset.annotationId = id;
                if (note) {
                    span.dataset.note = note;
                }
                span.textContent = text;

                const content = parent.innerHTML;
                const newContent = content.replace(text, span.outerHTML);
                parent.innerHTML = newContent;

                console.log('Restored highlight:', text.substring(0, 30) + '...');
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', restoreHighlights);
    } else {
        restoreHighlights();
    }
})();

window.parent.postMessage({
    type: 'pagination',
    currentPage: <?php echo $page; ?>,
    totalPages: <?php echo $totalPages; ?>
}, '*');

function toggleEncodingMenu() {
    const menu = document.getElementById('encodingMenu');
    menu.classList.toggle('show');
}

document.addEventListener('click', function(event) {
    const menu = document.getElementById('encodingMenu');
    const indicator = document.querySelector('.encoding-indicator');

    if (!menu.contains(event.target) && !indicator.contains(event.target)) {
        menu.classList.remove('show');
    }
});

function changeEncoding(encoding) {
    document.cookie = 'reader_encoding=' + encoding + '; path=/; max-age=31536000';
    window.location.href = '?id=<?php echo $bookId; ?>&page=<?php echo $page; ?>&encoding=' + encoding;
}

window.addEventListener('message', function(event) {
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

    if (event.data.type === 'getPosition') {
        const position = getCurrentPosition();
        window.parent.postMessage({
            type: 'position',
            position: position
        }, '*');
    }

    if (event.data.type === 'goTo' && event.data.cfi) {
        goToPosition(event.data.cfi);
    }
});

function getCurrentPosition() {
    return {
        cfi: generateCfi(<?php echo $page; ?>),
        page: <?php echo $page; ?>,
        totalPages: <?php echo $totalPages; ?>,
        percentage: Math.round((<?php echo $page; ?> / <?php echo $totalPages; ?>) * 100)
    };
}

function generateCfi(page) {
    return 'epubcfi(/6/4[book]!/4[body]/10[section]/2/1:' + page + ')';
}

function goToPosition(cfi) {
    let page = null;
    const match = cfi.match(/1:(\d+)\)/);
    if (match) {
        page = parseInt(match[1]);
    }

    if (page && page > 0 && page <= <?php echo $totalPages; ?>) {
        window.location.href = '?id=<?php echo $bookId; ?>&page=' + page + '&encoding=<?php echo $selectedEncoding; ?>';
    } else {
        showNotification('Не удалось перейти к закладке', 'error');
    }
}

function saveReadingProgress() {
    const position = getCurrentPosition();
    const duration = getReadingDuration();

    window.parent.postMessage({
        type: 'saveProgress',
        position: position,
        duration: duration,
        bookId: <?php echo $bookId; ?>
    }, '*');
}

let pageStartTime = Date.now();
function getReadingDuration() {
    const now = Date.now();
    const duration = Math.round((now - pageStartTime) / 1000);
    pageStartTime = now;
    return duration;
}

document.addEventListener('visibilitychange', function() {
    if (document.hidden) {
        saveReadingProgress();
    }
});

window.addEventListener('beforeunload', function() {
    saveReadingProgress();
});

let autoSaveInterval = setInterval(function() {
    saveReadingProgress();
}, 30000);

window.addEventListener('unload', function() {
    clearInterval(autoSaveInterval);
});

document.addEventListener('contextmenu', function(e) {
    e.preventDefault();
    e.stopPropagation();

    const selection = window.getSelection();
    if (!selection || selection.isCollapsed) {
        return;
    }

    const selectedText = selection.toString().trim();
    if (selectedText.length > 0) {
        showQuickBookmarkButton(e.clientX, e.clientY, selectedText);
    }
});

function showQuickBookmarkButton(x, y, text) {
    const oldBtn = document.getElementById('quick-bookmark-btn');
    if (oldBtn) oldBtn.remove();

    const btn = document.createElement('div');
    btn.id = 'quick-bookmark-btn';
    btn.className = 'quick-bookmark-btn';
    btn.innerHTML = `
        <div class="annotation-toolbar">
            <button class="anno-btn" data-type="quote" title="Цитата">
                <i class="fas fa-quote-left"></i>
                <span>Цитата</span>
            </button>
            <button class="anno-btn" data-type="note" title="Заметка">
                <i class="fas fa-sticky-note"></i>
                <span>Заметка</span>
            </button>
            <button class="anno-btn" data-type="highlight" title="Подсветка">
                <i class="fas fa-highlighter"></i>
                <span>Подсветка</span>
            </button>
            <div class="color-picker">
                <button class="color-btn" data-color="yellow" style="background:#fff59d" title="Жёлтый"></button>
                <button class="color-btn" data-color="green" style="background:#a5d6a7" title="Зелёный"></button>
                <button class="color-btn" data-color="blue" style="background:#90caf9" title="Синий"></button>
                <button class="color-btn" data-color="pink" style="background:#f48fb1" title="Розовый"></button>
                <button class="color-btn" data-color="orange" style="background:#ffcc80" title="Оранжевый"></button>
            </div>
            <span class="separator"></span>
            <button class="anno-btn" data-action="search" title="Искать в книге">
                <i class="fas fa-search"></i>
                <span>Поиск</span>
            </button>
            <button class="anno-btn" data-action="copy" title="Копировать">
                <i class="fas fa-copy"></i>
                <span>Копировать</span>
            </button>
        </div>
    `;

    const menuWidth = 520;
    const menuHeight = 60;
    let left = Math.min(x + 10, window.innerWidth - menuWidth - 10);
    let top = Math.min(y + 10, window.innerHeight - menuHeight - 10);

    if (y + menuHeight + 20 > window.innerHeight) {
        top = y - menuHeight - 10;
    }
    if (x + menuWidth + 20 > window.innerWidth) {
        left = x - menuWidth - 10;
    }

    btn.style.cssText = `
        position: fixed;
        top: ${Math.max(10, Math.min(top, window.innerHeight - menuHeight - 10))}px;
        left: ${Math.max(10, Math.min(left, window.innerWidth - menuWidth - 10))}px;
        background: #2c3e50;
        padding: 8px 12px;
        border-radius: 12px;
        z-index: 10000;
        box-shadow: 0 8px 30px rgba(0,0,0,0.4);
        animation: menuSlideUp 0.2s ease;
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.1);
    `;

    document.body.appendChild(btn);

    if (!document.getElementById('menu-animation-styles')) {
        const style = document.createElement('style');
        style.id = 'menu-animation-styles';
        style.textContent = `
            @keyframes menuSlideUp {
                from { opacity: 0; transform: translateY(10px) scale(0.95); }
                to { opacity: 1; transform: translateY(0) scale(1); }
            }
            @keyframes menuSlideDown {
                from { opacity: 1; transform: translateY(0) scale(1); }
                to { opacity: 0; transform: translateY(10px) scale(0.95); }
            }
            .quick-bookmark-btn .annotation-toolbar {
                display: flex;
                align-items: center;
                gap: 2px;
                flex-wrap: wrap;
            }
            .quick-bookmark-btn .anno-btn {
                background: transparent;
                border: none;
                color: rgba(255,255,255,0.8);
                padding: 6px 10px;
                border-radius: 6px;
                cursor: pointer;
                transition: all 0.2s;
                font-size: 12px;
                display: flex;
                align-items: center;
                gap: 4px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                white-space: nowrap;
            }
            .quick-bookmark-btn .anno-btn:hover {
                background: rgba(255,255,255,0.15);
                color: white;
                transform: scale(1.05);
            }
            .quick-bookmark-btn .anno-btn:active {
                transform: scale(0.95);
            }
            .quick-bookmark-btn .anno-btn i {
                font-size: 14px;
            }
            .quick-bookmark-btn .color-picker {
                display: none;
                gap: 4px;
                padding: 4px 6px;
                background: rgba(255,255,255,0.1);
                border-radius: 6px;
                margin: 0 2px;
            }
            .quick-bookmark-btn .color-picker.show {
                display: flex;
                animation: menuSlideUp 0.2s ease;
            }
            .quick-bookmark-btn .color-btn {
                width: 24px;
                height: 24px;
                border: 2px solid transparent;
                border-radius: 50%;
                cursor: pointer;
                transition: all 0.2s;
                padding: 0;
            }
            .quick-bookmark-btn .color-btn:hover {
                border-color: white;
                transform: scale(1.15);
            }
            .quick-bookmark-btn .separator {
                width: 1px;
                height: 24px;
                background: rgba(255,255,255,0.2);
                margin: 0 2px;
            }
            @media (max-width: 600px) {
                .quick-bookmark-btn .anno-btn span {
                    display: none;
                }
                .quick-bookmark-btn .anno-btn i {
                    font-size: 18px;
                }
                .quick-bookmark-btn .annotation-toolbar {
                    justify-content: center;
                }
            }
        `;
        document.head.appendChild(style);
    }

    const highlightBtn = btn.querySelector('[data-type="highlight"]');
    if (highlightBtn) {
        highlightBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();

            const colorPicker = btn.querySelector('.color-picker');
            if (colorPicker) {
                colorPicker.classList.toggle('show');
                console.log('Color picker toggled');
            }
        });
    }

    const colorBtns = btn.querySelectorAll('.color-btn');
    colorBtns.forEach(function(colorBtn) {
        colorBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();

            const color = this.dataset.color;
            console.log('🎨 Выбран цвет:', color);

            const selection = window.getSelection();
            if (!selection || selection.isCollapsed) {
                showNotification('Выделите текст сначала', 'warning');
                return;
            }

            const selectedText = selection.toString().trim();
            if (selectedText.length === 0) {
                showNotification('Выделите текст сначала', 'warning');
                return;
            }

            const range = selection.getRangeAt(0);
            console.log('📝 Текст:', selectedText);

            const success = applyHighlight(range, color);
            console.log('ApplyHighlight result:', success);

            if (success) {
                createAnnotation('highlight', selectedText, range, color);
                showNotification('🖍 Подсветка применена!', 'success');
            } else {
                showNotification('Не удалось применить подсветку', 'error');
            }

            closeMenu(btn);
        });
    });

    const otherBtns = btn.querySelectorAll('.anno-btn:not([data-type="highlight"])');
    otherBtns.forEach(function(otherBtn) {
        otherBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            e.preventDefault();

            const type = this.dataset.type;
            const action = this.dataset.action;

            const selection = window.getSelection();
            if (!selection || selection.isCollapsed) {
                showNotification('Выделите текст сначала', 'warning');
                return;
            }

            const selectedText = selection.toString().trim();
            if (selectedText.length === 0) {
                showNotification('Выделите текст сначала', 'warning');
                return;
            }

            const range = selection.getRangeAt(0);

            if (action === 'copy') {
                navigator.clipboard.writeText(selectedText).then(() => {
                    showNotification('Текст скопирован!', 'success');
                }).catch(() => {
                    const textarea = document.createElement('textarea');
                    textarea.value = selectedText;
                    document.body.appendChild(textarea);
                    textarea.select();
                    document.execCommand('copy');
                    textarea.remove();
                    showNotification('Текст скопирован!', 'success');
                });
                closeMenu(btn);
                return;
            }

            if (action === 'search') {
                window.parent.postMessage({
                    type: 'searchInBook',
                    query: selectedText
                }, '*');
                closeMenu(btn);
                return;
            }

            if (type === 'note') {
                const noteText = prompt('Введите заметку:', '');
                if (noteText !== null && noteText.trim() !== '') {
                    createAnnotation('note', selectedText, range, null, noteText.trim());
                    showNotification('📝 Заметка добавлена!', 'success');
                }
                closeMenu(btn);
                return;
            }

            if (type === 'quote') {
                createAnnotation('quote', selectedText, range);
                showNotification('💬 Цитата сохранена!', 'success');
                closeMenu(btn);
                return;
            }
        });
    });

    setTimeout(() => {
        document.addEventListener('click', function closeOnOutside(e) {
            if (!btn.contains(e.target)) {
                closeMenu(btn);
                document.removeEventListener('click', closeOnOutside);
            }
        });
    }, 100);

    document.addEventListener('keydown', function closeOnEscape(e) {
        if (e.key === 'Escape') {
            closeMenu(btn);
            document.removeEventListener('keydown', closeOnEscape);
        }
    });

}

function closeMenu(btn) {
    if (!btn) return;
    btn.style.animation = 'menuSlideDown 0.2s ease forwards';
    setTimeout(() => {
        if (btn.parentNode) {
            btn.remove();
        }
    }, 200);
}

function createAnnotation(type, selectedText, range, color = null, note = null) {
    const position = getCurrentPosition();

    console.log('=== CREATE ANNOTATION ===');
    console.log('Type:', type);
    console.log('Selected text:', selectedText);
    console.log('Color:', color);
    console.log('Note:', note);
    console.log('Position:', position);

    if (!selectedText || selectedText.length === 0) {
        showNotification('Нет выделенного текста', 'warning');
        return;
    }

    window.parent.postMessage({
        type: 'createAnnotation',
        annotationType: type,
        position: position,
        selectedText: selectedText,
        color: color,
        note: note,
        bookId: <?php echo $bookId; ?>
    }, '*');

    console.log('✅ Message sent to parent window');
}

function applyHighlight(range, color) {
    try {
        console.log('applyHighlight called with color:', color);

        if (!range || !range.commonAncestorContainer) {
            console.error('Invalid range');
            return false;
        }

        if (range.collapsed) {
            console.error('Range is collapsed');
            return false;
        }

        const hexColor = getColorHex(color);
        console.log('HEX color:', hexColor);

        const span = document.createElement('span');
        span.className = 'highlight-' + color;
        span.dataset.annotationId = Date.now();
        span.style.cssText = `
            background-color: ${hexColor};
            padding: 1px 0;
            border-radius: 2px;
            cursor: pointer;
            transition: background-color 0.2s;
        `;

        span.addEventListener('click', function(e) {
            e.stopPropagation();
            showNotification('🖍 Подсветка', 'info');
        });

        try {
            range.surroundContents(span);
            console.log('✅ Highlight applied via surroundContents');
        } catch (e) {
            console.log('Using fallback for highlight');
            const fragment = range.extractContents();
            span.appendChild(fragment);
            range.insertNode(span);
            console.log('✅ Highlight applied via fallback');
        }

        return true;

    } catch (error) {
        console.error('❌ Error applying highlight:', error);
        return false;
    }
}

function getColorHex(color) {
    const colors = {
        'yellow': '#fff59d',
        'green': '#a5d6a7',
        'blue': '#90caf9',
        'pink': '#f48fb1',
        'orange': '#ffcc80'
    };
    return colors[color] || '#fff59d';
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.textContent = message;

    const bgColors = {
        'success': '#28a745',
        'error': '#dc3545',
        'warning': '#ffc107',
        'info': '#17a2b8'
    };

    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 12px 24px;
        background: ${bgColors[type] || '#17a2b8'};
        color: ${type === 'warning' ? '#333' : 'white'};
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.3);
        z-index: 99999;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        font-size: 14px;
        animation: slideIn 0.3s ease;
        max-width: 400px;
    `;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

console.log('Reader bookmarks initialized');

if (window.parent.document.body.classList.contains('dark-theme')) {
    document.body.classList.add('dark-theme');
}

let pageLoadTime = Date.now();

window.parent.postMessage({
    type: 'pageLoaded',
    page: <?php echo $page; ?>,
    totalPages: <?php echo $totalPages; ?>
}, '*');

let lastActivity = Date.now();
const activityEvents = ['scroll', 'click', 'mousemove', 'keydown'];

activityEvents.forEach(eventType => {
    document.addEventListener(eventType, function() {
        lastActivity = Date.now();
    });
});

setInterval(function() {
    const now = Date.now();
    const inactiveTime = (now - lastActivity) / 1000;

    if (inactiveTime < 60) {
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
function getBookContent($book)
{
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === true) {
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
function splitIntoPages($content)
{
    error_log("=== START splitIntoPages ===");

    $images = [];
    $coverId = null;

    // ============================================
    // 1. БЕЗОПАСНЫЙ СБОР КАРТИНОК ИЗ BINARY ЧЕРЕЗ DOM
    // ============================================
    // Отключаем внутренние предупреждения либ XML, так как FB2 может содержать невалидные теги
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    // Загружаем контент с поддержкой UTF-8
    $dom->loadXML($content, LIBXML_NOENT | LIBXML_XINCLUDE | LIBXML_NOERROR | LIBXML_NOWARNING);

    // Извлекаем все двоичные данные (картинки)
    $binaryTags = $dom->getElementsByTagName('binary');
    foreach ($binaryTags as $binary) {
        $id = $binary->getAttribute('id');
        $mimeType = $binary->getAttribute('content-type');
        $base64Data = preg_replace('/\s+/', '', $binary->nodeValue);
        $data = base64_decode($base64Data);

        if ($id && $data !== false && strlen($data) > 0) {
            $images[$id] = [
                'mime' => $mimeType ? $mimeType : 'image/jpeg',
                'base64' => base64_encode($data)
            ];
            error_log("Found image via DOM: $id, size: " . strlen($data));
        }
    }

    // Определяем ID обложки из метаданных coverpage
    $coverpageTags = $dom->getElementsByTagName('coverpage');
    if ($coverpageTags->length > 0) {
        $coverImageTags = $coverpageTags->item(0)->getElementsByTagName('image');
        if ($coverImageTags->length > 0) {
            $href = $coverImageTags->item(0)->getAttribute('href');
            if (empty($href)) {
                // Если пространство имен другое, например xlink:href или l:href
                foreach ($coverImageTags->item(0)->attributes as $attr) {
                    if (strpos($attr->nodeName, 'href') !== false) {
                        $href = $attr->nodeValue;
                        break;
                    }
                }
            }
            $coverId = ltrim($href, '#');
            error_log("Cover image ID identified from DOM metadata: $coverId");
        }
    }

    // ============================================
    // 2. ИЗВЛЕКАЕМ ТОЛЬКО ТЕКСТ ИЗ ТЕГА <body> ЧЕРЕЗ DOM
    // ============================================
    $bodyHtml = '';
    $bodyTags = $dom->getElementsByTagName('body');

    if ($bodyTags->length > 0) {
        // Берем первое тело книги (основной текст)
        $bodyNode = $bodyTags->item(0);
        // Конвертируем дочерние элементы в HTML-строку без использования тяжелых регулярок
        foreach ($bodyNode->childNodes as $child) {
            $bodyHtml .= $dom->saveHTML($child);
        }
    } else {
        // Фолбек на случай странной структуры
        $bodyHtml = $content;
        // Отрезаем тяжелые бинарники вручную через строковые функции, чтобы не перегружать память
        $binPos = strpos($bodyHtml, '<binary');
        if ($binPos !== false) {
            $bodyHtml = substr($bodyHtml, 0, $binPos);
        }
    }

    error_log("DOM Body extracted. Length: " . strlen($bodyHtml));
    libxml_clear_errors();

    // Если по какой-то причине всё упало, страхуемся
    if (empty($bodyHtml)) {
        $bodyHtml = $content;
    }

    // ============================================
    // 3. ЛЕГКАЯ ОЧИСТКА И КОНВЕРТАЦИЯ ТЕГОВ СТРОКОВЫМИ ЗАМЕНАМИ
    // ============================================
    // Используем str_ireplace (без регулярок, работает мгновенно и безопасно)
    $bodyHtml = str_ireplace(
        ['<title>', '</title>', '<subtitle>', '</subtitle>', '<empty-line/>', '<empty-line />', '<empty-line>'],
        ['<h2>', '</h2>', '<h3>', '</h3>', '<div class="empty-line"></div>', '<div class="empty-line"></div>', '<div class="empty-line"></div>'],
        $bodyHtml
    );

    // Очищаем пространства имен из простых тегов, не трогая сложные структуры атрибутов
    $bodyHtml = preg_replace('/<(\/?)[^:>]+:([a-zA-Z0-9\-]+)>/i', '<$1$2>', $bodyHtml);

    // Нормализуем теги картинок для более простого деления (удаляем закрывающие пары)
    $bodyHtml = preg_replace('/<image([^>]* text-placeholder)><\/image>/is', '<image$1/>', $bodyHtml);

    // ============================================
    // 4. ДЕЛЕНИЕ НА ПАРАГРАФЫ С КОНТРОЛЕМ ЛИМИТОВ PHP
    // ============================================
    // Используем максимально легкую регулярку исключительно для разделения блоков текста
    $parts = preg_split('/(<\/h1>|<\/h2>|<\/h3>|<\/p>|<image[^>]*\/?>)/i', $bodyHtml, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

    $paragraphs = [];
    $current = '';
    if (is_array($parts)) {
        foreach ($parts as $part) {
            $current .= $part;
            if (preg_match('/<\/h[1-3]>|<\/p>|<image[^>]*\/?>/i', $part)) {
                $paragraphs[] = $current;
                $current = '';
            }
        }
    }
    if (!empty($current)) {
        $paragraphs[] = $current;
    }

    error_log("Paragraphs count after DOM processing: " . count($paragraphs));

    // ============================================
    // 5. ФОРМИРОВАНИЕ СТРАНИЦ КНИГИ
    // ============================================
    $pages = [];
    $currentPage = '';
    $currentLength = 0;
    $targetLength = 3000;

    foreach ($paragraphs as $para) {
        $hasImg = preg_match('/<image[^>]*\/?>/i', $para);
        $cleanPara = strip_tags($para);
        $paraLength = mb_strlen($cleanPara, 'UTF-8');

        if ($hasImg && !empty($currentPage) && $currentLength > $targetLength * 0.5) {
            $pages[] = $currentPage;
            $currentPage = $para;
            $currentLength = $paraLength;
        } elseif ($currentLength + $paraLength > $targetLength && !empty($currentPage)) {
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
        $pages[] = $bodyHtml;
    }

    // ============================================
    // 6. ИНЪЕКЦИЯ КАРТИНОК И ОБЛОЖКИ В СТРАНИЦЫ
    // ============================================
    foreach ($pages as &$page) {
        $page = preg_replace_callback('/<image([^>]*)\/?>/i', function ($imgMatches) use ($images) {
            $attrs = $imgMatches[1];
            // Ищем атрибуты ссылок
            if (preg_match('/(?:href|l:href|xlink:href)="([^"]+)"/i', $attrs, $hrefMatch)) {
                $imageId = ltrim($hrefMatch[1], '#');
                if (isset($images[$imageId])) {
                    $img = $images[$imageId];
                    return '<img src="data:' . $img['mime'] . ';base64,' . $img['base64'] . '" alt="Иллюстрация" class="fb2-image" loading="lazy">';
                }
            }
            return '';
        }, $page);
    }
    unset($page);

    // Принудительно ставим обложку наверх первой страницы
    if ($coverId && isset($images[$coverId]) && !empty($pages)) {
        $coverSrcSubstring = 'data:' . $images[$coverId]['mime'] . ';base64,';

        // Убираем дубликат, если он просочился
        if (strpos($pages[0], $coverSrcSubstring) === false) {
            $coverHtml = '<div class="fb2-cover-container" style="text-align: center; margin-bottom: 25px; width: 100%;">';
            $coverHtml .= '<img src="data:' . $images[$coverId]['mime'] . ';base64,' . $images[$coverId]['base64'] . '" alt="Обложка" class="fb2-cover-image" style="max-width: 100%; height: auto; max-height: 75vh; display: inline-block;">';
            $coverHtml .= '</div>';

            $pages[0] = $coverHtml . $pages[0];
            error_log("Cover image forced via DOM to page 1.");
        }
    }

    // Финальная проверка для логов
    $totalImgInPages = 0;
    foreach ($pages as $p) {
        if (preg_match_all('/<img[^>]*>/i', $p, $out)) {
            $totalImgInPages += count($out[0]);
        }
    }
    error_log("TOTAL IMGS IN ALL PAGES AFTER INJECTION: " . $totalImgInPages);
    error_log("Pages created: " . count($pages));
    error_log("=== END splitIntoPages ===");

    return $pages;
}


?>