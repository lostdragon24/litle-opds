<?php
// reader.php

define('LOPDS_ROOT', __DIR__);

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'init.php';

$db = Database::getInstance();
$inReader = true;

// Проверяем ID книги
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    die(__('book_invalid_id'));
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    header('HTTP/1.0 404 Not Found');
    die(__('book_not_found'));
}

// Получаем номер страницы из GET параметра
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;

// Если страница не указана, пробуем восстановить из закладок
if (!isset($_GET['page'])) {
    $fingerprint = $_COOKIE['device_fp'] ?? '';
    if ($fingerprint) {
        try {
            $stmt = $db->getConnection()->prepare("
                SELECT page_number FROM bookmarks
                WHERE user_fingerprint = :fingerprint
                  AND book_id = :book_id
                  AND note = 'Последнее прочитанное'
                  AND is_deleted = 0
                ORDER BY last_read DESC
                LIMIT 1
            ");
            $stmt->execute([
                ':fingerprint' => $fingerprint,
                ':book_id' => $bookId
            ]);
            $lastPos = $stmt->fetch();
            if ($lastPos && $lastPos['page_number'] > 1) {
                $page = $lastPos['page_number'];
            }
        } catch (Exception $e) {
            // Игнорируем ошибки БД
        }
    }
}

$fileType = strtolower($book['file_type']);
require 'templates/header.php';
?>

<div class="reader-wrapper">
    <!-- Верхняя панель -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark reader-navbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="book_detail.php?id=<?php echo $bookId; ?>">
                <i class="fas fa-arrow-left me-2"></i>
                <?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 50)); ?>
            </a>
            <div class="navbar-nav ms-auto">
                <span class="navbar-text me-3" id="pageInfo"><?php echo __('reader_loading'); ?></span>
                <a href="./api/download.php?id=<?php echo $bookId; ?>" class="btn btn-success me-2">
                    <i class="fas fa-download me-1"></i><?php echo __('download'); ?>
                </a>
                <button class="btn btn-outline-light" onclick="toggleFullscreen()" title="<?php echo __('reader_fullscreen'); ?>">
                    <i class="fas fa-expand"></i>
                </button>
            </div>
        </div>
    </nav>

    <!-- Область чтения -->
    <div class="reader-container" id="readerContainer">
        <?php if ($fileType === 'fb2'): ?>
            <iframe src="./api/read_fb2.php?id=<?php echo $bookId; ?>&page=<?php echo $page; ?>"
                    class="fb2-iframe"
                    id="readerFrame"
                    frameborder="0"
                    title="<?php echo __('reader_fb2_title'); ?>"></iframe>
        <?php elseif ($fileType === 'epub'): ?>
            <iframe src="./api/read_epub.php?id=<?php echo $bookId; ?>"
                    class="epub-iframe"
                    id="readerFrame"
                    frameborder="0"
                    title="<?php echo __('reader_epub_title'); ?>"></iframe>
        <?php elseif ($fileType === 'pdf'): ?>
            <iframe src="./api/read_pdf.php?id=<?php echo $bookId; ?>"
                    class="pdf-iframe"
                    id="readerFrame"
                    frameborder="0"
                    title="<?php echo __('reader_pdf_title'); ?>"></iframe>
        <?php else: ?>
            <div class="alert alert-warning m-4">
                <h5><i class="fas fa-exclamation-triangle me-2"></i><?php echo __('reader_format_not_supported'); ?></h5>
                <p><?php echo sprintf(__('reader_format_desc'), strtoupper($fileType)); ?></p>
                <a href="./api/download.php?id=<?php echo $bookId; ?>" class="btn btn-primary">
                    <i class="fas fa-download me-2"></i><?php echo __('reader_download'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>


    <!-- Нижняя панель управления -->
    <div class="reader-controls">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-4">
                    <div class="btn-group" role="group">
                        <button class="btn btn-outline-secondary" onclick="changeFontSize(-1)" title="<?php echo __('reader_font_decrease'); ?>">
                            <i class="fas fa-minus"></i>
                        </button>
                        <span class="btn btn-outline-secondary disabled" id="fontSizeDisplay">100%</span>
                        <button class="btn btn-outline-secondary" onclick="changeFontSize(1)" title="<?php echo __('reader_font_increase'); ?>">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <div class="col-4 text-center">
                    <div class="btn-group">
                        <button class="btn btn-primary" onclick="prevPage()" title="<?php echo __('reader_prev_page'); ?>">
                            <i class="fas fa-chevron-left"></i>
                        </button>
                        <button class="btn btn-primary" onclick="nextPage()" title="<?php echo __('reader_next_page'); ?>">
                            <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>
                <div class="col-4 text-end">
                    <span id="save-indicator" style="opacity: 0; transition: opacity 0.3s; font-size: 12px; margin-right: 10px;">
                        <?php echo __('save_bookmark'); ?>
                    </span>
                    <span id="activity-indicator" style="color: #28a745; font-size: 12px; margin-right: 10px;">
                        <?php echo __('read_book'); ?>
                    </span>
                    <div class="btn-group">


                        <!-- Кнопка открытия панели (добавьте в .reader-controls) -->
    <button class="btn btn-outline-info annotations-toggle-btn"
            onclick="toggleAnnotationsPanel()"
            title="Заметки и цитаты">
        <i class="fas fa-bookmark"></i>
        <span class="badge bg-danger annotation-badge" id="annotationBadge" style="display:none;">0</span>
    </button>

                        <button class="btn btn-outline-secondary" onclick="toggleTheme()" title="<?php echo __('reader_toggle_theme'); ?>">
                            <i class="fas fa-moon"></i>
                        </button>

                        <button class="btn btn-outline-secondary" onclick="toggleSettings()" title="<?php echo __('reader_settings'); ?>">
                            <i class="fas fa-cog"></i>
                        </button>

                        <button onclick="addBookmark()" class="btn btn-outline-primary">
                            <i class="fas fa-bookmark"></i>
                        </button>

                    </div>
                </div>
            </div>
        </div>
    </div>








</div>

<!-- Панель настроек (скрытая) -->
<div class="settings-panel" id="settingsPanel" style="display: none;">
    <div class="settings-panel-header">
        <h6><?php echo __('reader_settings_title'); ?></h6>
        <button type="button" class="btn-close" onclick="toggleSettings()"></button>
    </div>
    <div class="settings-panel-body">
        <div class="mb-3">
            <label class="form-label"><?php echo __('reader_font_family'); ?></label>
            <select class="form-select" id="fontFamily" onchange="changeFontFamily(this.value)">
                <option value="default"><?php echo __('reader_font_default'); ?></option>
                <option value="serif"><?php echo __('reader_font_serif'); ?></option>
                <option value="sans-serif"><?php echo __('reader_font_sans'); ?></option>
                <option value="monospace"><?php echo __('reader_font_mono'); ?></option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label"><?php echo __('reader_line_height'); ?></label>
            <select class="form-select" id="lineHeight" onchange="changeLineHeight(this.value)">
                <option value="1.2"><?php echo __('reader_line_compact'); ?></option>
                <option value="1.5" selected><?php echo __('reader_line_normal'); ?></option>
                <option value="1.8"><?php echo __('reader_line_relaxed'); ?></option>
                <option value="2.0"><?php echo __('reader_line_double'); ?></option>
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label"><?php echo __('reader_margin'); ?></label>
            <select class="form-select" id="margin" onchange="changeMargin(this.value)">
                <option value="0"><?php echo __('reader_margin_none'); ?></option>
                <option value="20" selected><?php echo __('reader_margin_small'); ?></option>
                <option value="40"><?php echo __('reader_margin_medium'); ?></option>
                <option value="60"><?php echo __('reader_margin_large'); ?></option>
            </select>
        </div>
    </div>
</div>



<!-- Боковая панель заметок (ПРАВИЛЬНОЕ РАСПОЛОЖЕНИЕ) -->
    <div class="annotations-panel" id="annotationsPanel" style="display: none;">
        <div class="panel-header">
            <h6><i class="fas fa-bookmark me-2"></i>Заметки и цитаты</h6>
            <button class="btn-close-panel" onclick="toggleAnnotationsPanel()">
                <i class="fas fa-times"></i>
            </button>
        </div>

        <div class="panel-search">
            <input type="text" id="searchAnnotations"
                   placeholder="Поиск по заметкам..."
                   class="form-control form-control-sm">
        </div>

        <div class="panel-filters">
            <select id="filterType" class="form-select form-select-sm">
                <option value="all">Все</option>
                <option value="quote">💬 Цитаты</option>
                <option value="note">📝 Заметки</option>
                <option value="highlight">🖍 Подсветки</option>
                <option value="bookmark">🔖 Закладки</option>
            </select>
        </div>

        <div class="annotations-list" id="annotationsList">
            <div class="text-center text-muted py-4">
                <i class="fas fa-spinner fa-spin"></i> Загрузка...
            </div>
        </div>

        <div class="panel-footer">
            <div class="annotation-stats">
                <span class="badge bg-warning" title="Цитаты">
                    <i class="fas fa-quote-left"></i> <span id="quotesCount">0</span>
                </span>
                <span class="badge bg-info" title="Заметки">
                    <i class="fas fa-sticky-note"></i> <span id="notesCount">0</span>
                </span>
                <span class="badge bg-success" title="Подсветки">
                    <i class="fas fa-highlighter"></i> <span id="highlightsCount">0</span>
                </span>
            </div>
            <button class="btn btn-sm btn-outline-primary w-100 mt-2" onclick="exportAnnotations()">
                <i class="fas fa-download me-1"></i> Экспорт
            </button>
        </div>
    </div>

<script>
// ============================================
// ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ
// ============================================

let currentPage = <?php echo $page; ?>;
let totalPages = 1;
let fontSize = 100;
let readerFrame = document.getElementById('readerFrame');
let navigationTimeout;
let settingsVisible = false;
let pageLoadTime = Date.now();
let saveIndicatorTimeout = null;

// Translations for JavaScript
const readerTranslations = {
    loading: '<?php echo __('reader_loading'); ?>',
    page: '<?php echo __('reader_page'); ?>',
    of: '<?php echo __('of'); ?>'
};

console.log('Reader initialized with page:', currentPage);

function addBookmark() {
    const bookId = <?php echo $bookId; ?>;
    const pageNumber = getCurrentPageNumber();
    const percentage = getCurrentPercentage();
    const note = prompt('<?php echo __('bookmark_name'); ?>', '<?php echo __('bookmarks'); ?>');

    if (note === null) return;

    const fingerprint = getDeviceFingerprint();

    fetch('./api/bookmarks.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            action: 'add',
            book_id: bookId,
            page_number: pageNumber,
            percentage: percentage,
            note: note,
            fingerprint: fingerprint,
            cfi_range: '' // для FB2 оставляем пустым
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('<?php echo __('bookmark_add'); ?>');
            location.reload(); // обновляем страницу для отображения новой закладки
        } else {
            alert('<?php echo __('bookmark_error'); ?>' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('<?php echo __('bookmark_msg'); ?>');
    });
}

function getDeviceFingerprint() {
    // Получаем fingerprint из куки
    const match = document.cookie.match(/device_fp=([^;]+)/);
    return match ? match[1] : null;
}

function getCurrentPageNumber() {
    // Получаем текущую страницу из URL или из iframe
    const urlParams = new URLSearchParams(window.location.search);
    return parseInt(urlParams.get('page')) || 1;
}

function getCurrentPercentage() {
    // Вычисляем процент на основе текущей страницы и общего количества
    const totalPages = <?php echo $totalPages ?? 100; ?>;
    const currentPage = getCurrentPageNumber();
    return Math.round((currentPage / totalPages) * 100);
}

// ============================================
// РАБОТА С ПАГИНАЦИЕЙ
// ============================================

function updatePageInfo() {
    document.getElementById('pageInfo').textContent =
        readerTranslations.page + ' ' + currentPage + ' ' + readerTranslations.of + ' ' + totalPages;

    // Обновляем URL
    const url = new URL(window.location.href);
    url.searchParams.set('page', currentPage);
    window.history.replaceState({}, '', url);
}

function nextPage() {
    if (!readerFrame || !readerFrame.contentWindow) return;

    const nextPageNum = currentPage + 1;
    if (nextPageNum <= totalPages) {
        currentPage = nextPageNum;
        updatePageInfo();

        readerFrame.contentWindow.postMessage({
            type: 'navigate',
            direction: 'next'
        }, '*');

        navigationTimeout = setTimeout(() => {
            console.log('Navigation timeout - requesting status');
            readerFrame.contentWindow.postMessage({
                type: 'getStatus'
            }, '*');
        }, 1000);
    }
}

function prevPage() {
    if (!readerFrame || !readerFrame.contentWindow) return;

    const prevPageNum = Math.max(1, currentPage - 1);
    if (prevPageNum < currentPage) {
        currentPage = prevPageNum;
        updatePageInfo();

        readerFrame.contentWindow.postMessage({
            type: 'navigate',
            direction: 'prev'
        }, '*');

        navigationTimeout = setTimeout(() => {
            console.log('Navigation timeout - requesting status');
            readerFrame.contentWindow.postMessage({
                type: 'getStatus'
            }, '*');
        }, 1000);
    }
}

// ============================================
// ОБРАБОТКА СООБЩЕНИЙ ОТ IFRAME
// ============================================

window.addEventListener('message', function(event) {
    // Проверяем происхождение
    if (event.origin !== window.location.origin) {
        return;
    }

    console.log('Message from iframe:', event.data);

    // Пагинация
    if (event.data.type === 'pagination') {
        currentPage = event.data.currentPage || 1;
        totalPages = event.data.totalPages || 1;
        updatePageInfo();

        if (navigationTimeout) {
            clearTimeout(navigationTimeout);
        }
    }

    // Готовность
    if (event.data.type === 'ready') {
        console.log('Reader ready, sending init...');
        setTimeout(() => {
            if (readerFrame && readerFrame.contentWindow) {
                readerFrame.contentWindow.postMessage({
                    type: 'init',
                    fontSize: fontSize,
                    theme: document.body.classList.contains('dark-theme'),
                    fontFamily: document.getElementById('fontFamily')?.value || 'default',
                    lineHeight: document.getElementById('lineHeight')?.value || '1.5',
                    margin: document.getElementById('margin')?.value || '20'
                }, '*');
            }
        }, 100);
    }

    // Сохранить прогресс
    if (event.data.type === 'saveProgress') {
        console.log('Saving progress from iframe:', event.data);
        saveProgressToServer(
            event.data.bookId || <?php echo $bookId; ?>,
            event.data.position || { page: event.data.page || currentPage },
            event.data.duration || 0
        );
    }

    // Страница загружена
    if (event.data.type === 'pageLoaded') {
        updateReadingStats(event.data.page, event.data.totalPages);
        if (event.data.page) {
            currentPage = event.data.page;
            updatePageInfo();
        }
    }

    // Активность чтения
    if (event.data.type === 'readingActivity') {
        updateActivityStatus(event.data.active, event.data.page);
    }

    if (event.data.type === 'createAnnotation') {
    console.log('Creating annotation:', event.data);

    const formData = new FormData();
    formData.append('action', 'create_annotation');
    formData.append('book_id', event.data.bookId || <?php echo $bookId; ?>);
    formData.append('type', event.data.annotationType);
    formData.append('page_number', event.data.position?.page || 1);
    formData.append('percentage', event.data.position?.percentage || 0);
    formData.append('cfi_range', event.data.position?.cfi || '');
    formData.append('selected_text', event.data.selectedText || '');
    formData.append('note', event.data.note || '');
    formData.append('color', event.data.color || 'yellow');
    formData.append('fingerprint', getFingerprint());

    fetch('./api/bookmarks.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Обновляем панель если открыта
            if (annotationsPanelOpen) {
                loadAnnotations();
            }
        } else {
            console.error('Failed to create annotation:', data.message);
        }
    })
    .catch(err => console.error('Error:', err));
}


});

// ============================================
// РАБОТА С НАСТРОЙКАМИ
// ============================================

function changeFontSize(delta) {
    fontSize = Math.max(70, Math.min(200, fontSize + delta * 10));
    document.getElementById('fontSizeDisplay').textContent = fontSize + '%';

    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'fontSize',
            size: fontSize
        }, '*');
    }

    document.cookie = 'reader_font_size=' + fontSize + '; path=/; max-age=31536000';
}

function changeFontFamily(fontFamily) {
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'fontFamily',
            family: fontFamily
        }, '*');
    }
    document.cookie = 'reader_font_family=' + fontFamily + '; path=/; max-age=31536000';
}

function changeLineHeight(lineHeight) {
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'lineHeight',
            height: lineHeight
        }, '*');
    }
    document.cookie = 'reader_line_height=' + lineHeight + '; path=/; max-age=31536000';
}

function changeMargin(margin) {
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'margin',
            margin: margin
        }, '*');
    }
    document.cookie = 'reader_margin=' + margin + '; path=/; max-age=31536000';
}

function toggleTheme() {
    document.body.classList.toggle('dark-theme');
    const isDark = document.body.classList.contains('dark-theme');

    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'theme',
            dark: isDark
        }, '*');
    }

    const themeBtn = document.querySelector('[onclick="toggleTheme()"] i');
    if (themeBtn) {
        themeBtn.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
    }

    document.cookie = 'reader_dark_theme=' + (isDark ? '1' : '0') + '; path=/; max-age=31536000';
}

function toggleSettings() {
    const panel = document.getElementById('settingsPanel');
    settingsVisible = !settingsVisible;
    panel.style.display = settingsVisible ? 'block' : 'none';
}

function toggleFullscreen() {
    const readerWrapper = document.querySelector('.reader-wrapper');

    if (!readerWrapper) {
        console.error('Reader wrapper not found');
        return;
    }

    if (!document.fullscreenElement) {
        if (readerWrapper.requestFullscreen) {
            readerWrapper.requestFullscreen();
        } else if (readerWrapper.webkitRequestFullscreen) {
            readerWrapper.webkitRequestFullscreen();
        } else if (readerWrapper.msRequestFullscreen) {
            readerWrapper.msRequestFullscreen();
        }

        const btn = document.querySelector('[onclick="toggleFullscreen()"] i');
        if (btn) {
            btn.className = 'fas fa-compress';
        }
    } else {
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        }

        const btn = document.querySelector('[onclick="toggleFullscreen()"] i');
        if (btn) {
            btn.className = 'fas fa-expand';
        }
    }
}

// Следим за изменением полноэкранного режима
document.addEventListener('fullscreenchange', updateFullscreenButton);
document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
document.addEventListener('msfullscreenchange', updateFullscreenButton);

function updateFullscreenButton() {
    const btn = document.querySelector('[onclick="toggleFullscreen()"] i');
    if (!btn) return;

    if (document.fullscreenElement) {
        btn.className = 'fas fa-compress';
    } else {
        btn.className = 'fas fa-expand';
    }
}

// ============================================
// РАБОТА С ЗАКЛАДКАМИ
// ============================================

function getFingerprint() {
    const match = document.cookie.match(/device_fp=([^;]+)/);
    return match ? match[1] : null;
}

function updateReadingStats(page, totalPages) {
    console.log('Reading stats:', { page, totalPages });
    if (page) {
        currentPage = page;
        updatePageInfo();
    }
}

function updateActivityStatus(active, page) {
    const indicator = document.getElementById('activity-indicator');
    if (indicator) {
        indicator.style.color = active ? '#28a745' : '#6c757d';
        indicator.textContent = active ? '<?php echo __('read_book'); ?>' : '<?php echo __('read_book_pause'); ?>';
    }
}

function saveProgressToServer(bookId, position, duration) {
    if (!bookId) {
        console.error('Book ID is required');
        return;
    }

    const fingerprint = getFingerprint();
    if (!fingerprint) {
        console.error('Fingerprint not found');
        return;
    }

    // Используем номер страницы из position или currentPage
    const page = position.page || currentPage || 1;
    const percentage = position.percentage || Math.round((page / totalPages) * 100) || 1;

    const apiUrl = './api/bookmarks.php';

    console.log('=== SAVING PROGRESS ===');
    console.log('URL:', apiUrl);
    console.log('Book ID:', bookId);
    console.log('Page:', page);
    console.log('Percentage:', percentage);
    console.log('Fingerprint:', fingerprint);

    const formData = new FormData();
    formData.append('action', 'save_progress');
    formData.append('book_id', String(bookId));
    formData.append('cfi_range', position.cfi || 'epubcfi(/test/' + page + ')');
    formData.append('page_number', String(page));
    formData.append('percentage', String(percentage));
    formData.append('duration', String(duration || 0));
    formData.append('fingerprint', fingerprint);

    fetch(apiUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('HTTP error: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        console.log('Save response:', data);
        if (data.success) {
            showSaveIndicator(true);
        } else {
            console.error('Save failed:', data.message);
            showSaveIndicator(false);
        }
    })
    .catch(error => {
        console.error('Network error:', error);
        showSaveIndicator(false);
    });
}

function showSaveIndicator(success) {
    const indicator = document.getElementById('save-indicator');
    if (!indicator) return;

    indicator.textContent = success ? '<?php echo __('save_bookmark'); ?>' : '<?php echo __('bookmark_error_'); ?>';
    indicator.style.color = success ? '#28a745' : '#dc3545';
    indicator.style.opacity = '1';

    clearTimeout(saveIndicatorTimeout);
    saveIndicatorTimeout = setTimeout(() => {
        indicator.style.opacity = '0';
    }, 2000);
}

// ============================================
// ЗАГРУЗКА СОХРАНЕННЫХ НАСТРОЕК
// ============================================

(function() {
    const cookies = document.cookie.split(';').reduce((acc, cookie) => {
        const [key, value] = cookie.trim().split('=');
        acc[key] = value;
        return acc;
    }, {});

    if (cookies.reader_font_size) {
        fontSize = parseInt(cookies.reader_font_size);
        document.getElementById('fontSizeDisplay').textContent = fontSize + '%';
    }

    if (cookies.reader_font_family) {
        document.getElementById('fontFamily').value = cookies.reader_font_family;
    }

    if (cookies.reader_line_height) {
        document.getElementById('lineHeight').value = cookies.reader_line_height;
    }

    if (cookies.reader_margin) {
        document.getElementById('margin').value = cookies.reader_margin;
    }

    if (cookies.reader_dark_theme === '1') {
        document.body.classList.add('dark-theme');
        document.querySelector('[onclick="toggleTheme()"] i').className = 'fas fa-sun';
    }
})();

// ============================================
// КЛАВИШИ НАВИГАЦИИ
// ============================================

document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
        return;
    }

    if (e.key === 'ArrowRight') {
        nextPage();
        e.preventDefault();
    } else if (e.key === 'ArrowLeft') {
        prevPage();
        e.preventDefault();
    } else if (e.key === '+' || e.key === '=') {
        changeFontSize(1);
        e.preventDefault();
    } else if (e.key === '-' || e.key === '_') {
        changeFontSize(-1);
        e.preventDefault();
    } else if (e.key === 'f' || e.key === 'F') {
        toggleFullscreen();
        e.preventDefault();
    } else if (e.key === 's' || e.key === 'S') {
        toggleSettings();
        e.preventDefault();
    } else if (e.key === 't' || e.key === 'T') {
        toggleTheme();
        e.preventDefault();
    } else if (e.key === 'Escape') {
        if (document.fullscreenElement) {
            toggleFullscreen();
        }
        if (settingsVisible) {
            toggleSettings();
        }
    }
});

// ============================================
// ИНИЦИАЛИЗАЦИЯ
// ============================================

window.addEventListener('load', function() {
    updateFullscreenButton();
    updatePageInfo();
    console.log('Reader fully loaded, page:', currentPage);

    // Сохраняем прогресс через 5 секунд после загрузки
    setTimeout(() => {
        saveProgressToServer(<?php echo $bookId; ?>, {
            page: currentPage,
            percentage: Math.round((currentPage / totalPages) * 100) || 1,
            cfi: 'epubcfi(/init/' + currentPage + ')'
        }, 0);
    }, 5000);
});


// боковая панель

// ===== ПАНЕЛЬ АННОТАЦИЙ =====
let annotationsPanelOpen = false;

function toggleAnnotationsPanel() {
    console.log('toggleAnnotationsPanel called');

    const panel = document.getElementById('annotationsPanel');
    if (!panel) {
        console.error('Annotations panel not found!');
        return;
    }

    annotationsPanelOpen = !annotationsPanelOpen;
    console.log('Panel state:', annotationsPanelOpen ? 'open' : 'closed');

    if (annotationsPanelOpen) {
        panel.classList.add('open');
        panel.style.display = 'flex'; // Явно показываем
        loadAnnotations();
    } else {
        panel.classList.remove('open');
        setTimeout(() => {
            panel.style.display = 'none';
        }, 300);
    }
}

// Загрузка аннотаций
async function loadAnnotations() {
    const list = document.getElementById('annotationsList');
    const bookId = <?php echo $bookId; ?>;
    const filterType = document.getElementById('filterType').value;

    list.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin"></i> Загрузка...</div>';

    try {
        const response = await fetch(`./api/bookmarks.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'get_annotations',
                book_id: bookId,
                type: filterType,
                fingerprint: getFingerprint()
            })
        });

        const data = await response.json();

        if (data.success && data.annotations) {
            renderAnnotations(data.annotations);
            updateAnnotationCounts(data.annotations);
        } else {
            list.innerHTML = '<div class="text-center text-muted py-4">Нет заметок</div>';
        }
    } catch (error) {
        console.error('Error loading annotations:', error);
        list.innerHTML = '<div class="text-center text-danger py-4">Ошибка загрузки</div>';
    }
}

// Отрисовка аннотаций
function renderAnnotations(annotations) {
    const list = document.getElementById('annotationsList');

    if (annotations.length === 0) {
        list.innerHTML = `
            <div class="text-center text-muted py-5">
                <i class="fas fa-bookmark fa-3x mb-3"></i>
                <p>Нет заметок для этой книги</p>
                <small>Выделите текст и добавьте заметку</small>
            </div>
        `;
        return;
    }

    const icons = {
        'quote': 'fa-quote-left',
        'note': 'fa-sticky-note',
        'highlight': 'fa-highlighter',
        'bookmark': 'fa-bookmark'
    };

    list.innerHTML = annotations.map(ann => `
        <div class="annotation-item type-${ann.type}"
             data-id="${ann.id}"
             onclick="goToAnnotation(${ann.page_number})">
            <div class="annotation-text">
                <i class="fas ${icons[ann.type]} me-1"></i>
                ${ann.selected_text ?
                    `"${ann.selected_text.substring(0, 100)}${ann.selected_text.length > 100 ? '...' : ''}"` :
                    (ann.note || 'Без текста')}
            </div>
            ${ann.note && ann.type !== 'note' ?
                `<div class="annotation-note small text-muted mt-1">${ann.note}</div>` : ''}
            <div class="annotation-meta">
                <span>
                    <i class="fas fa-file-alt me-1"></i>
                    Стр. ${ann.page_number} (${Math.round(ann.percentage)}%)
                </span>
                <div class="annotation-actions">
                    <button class="btn btn-sm btn-outline-danger"
                            onclick="event.stopPropagation(); deleteAnnotation(${ann.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

// Обновление счётчиков
function updateAnnotationCounts(annotations) {
    const counts = {
        quote: annotations.filter(a => a.type === 'quote').length,
        note: annotations.filter(a => a.type === 'note').length,
        highlight: annotations.filter(a => a.type === 'highlight').length
    };

    document.getElementById('quotesCount').textContent = counts.quote;
    document.getElementById('notesCount').textContent = counts.note;
    document.getElementById('highlightsCount').textContent = counts.highlight;

    // Обновляем бейдж
    const total = counts.quote + counts.note + counts.highlight;
    const badge = document.getElementById('annotationBadge');
    if (total > 0) {
        badge.style.display = 'flex';
        badge.textContent = total;
    } else {
        badge.style.display = 'none';
    }
}

// Переход к аннотации
function goToAnnotation(page) {
    const readerFrame = document.getElementById('readerFrame');
    if (readerFrame && readerFrame.contentWindow) {
        readerFrame.contentWindow.postMessage({
            type: 'navigate',
            page: page
        }, '*');
    }

    // Закрываем панель на мобильных
    if (window.innerWidth < 768) {
        toggleAnnotationsPanel();
    }
}

// Удаление аннотации
async function deleteAnnotation(id) {
    if (!confirm('Удалить эту заметку?')) return;

    try {
        const response = await fetch('./api/bookmarks.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'delete',
                bookmark_id: id,
                fingerprint: getFingerprint()
            })
        });

        const data = await response.json();
        if (data.success) {
            showNotification('Заметка удалена', 'info');
            loadAnnotations();
        }
    } catch (error) {
        console.error('Error deleting annotation:', error);
    }
}

// Экспорт аннотаций
function exportAnnotations() {
    const bookId = <?php echo $bookId; ?>;
    window.open(`./api/bookmarks.php?action=export_annotations&book_id=${bookId}&format=markdown`, '_blank');
}

// Поиск по аннотациям
document.getElementById('searchAnnotations')?.addEventListener('input', debounce(function(e) {
    const query = e.target.value.toLowerCase();
    const items = document.querySelectorAll('.annotation-item');

    items.forEach(item => {
        const text = item.textContent.toLowerCase();
        item.style.display = text.includes(query) ? 'block' : 'none';
    });
}, 300));

// Фильтр по типу
document.getElementById('filterType')?.addEventListener('change', function() {
    loadAnnotations();
});

// Debounce функция
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}





</script>

<style>
.reader-wrapper {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: #fff;
    z-index: 1050;
    display: flex;
    flex-direction: column;
}

.reader-navbar {
    flex-shrink: 0;
    border-radius: 0;
    padding: 0.5rem 1rem;
}

.reader-container {
    flex: 1;
    overflow: hidden;
    background: #f8f9fa;
}

.fb2-iframe,
.epub-iframe,
.pdf-iframe {
    width: 100%;
    height: 100%;
    border: none;
    background: inherit;
}

.reader-controls {
    flex-shrink: 0;
    background: #fff;
    border-top: 1px solid #dee2e6;
    padding: 10px 0;
}

.settings-panel {
    position: fixed;
    top: 70px;
    right: 20px;
    width: 300px;
    background: white;
    border-radius: 8px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    z-index: 1060;
    animation: slideIn 0.3s ease;
}

.settings-panel-header {
    padding: 15px;
    border-bottom: 1px solid #dee2e6;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.settings-panel-header h6 {
    margin: 0;
    font-weight: 600;
}

.settings-panel-body {
    padding: 15px;
}

@keyframes slideIn {
    from {
        opacity: 0;
        transform: translateX(20px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

/* Темная тема - основные элементы */
body.dark-theme .reader-wrapper {
    background: #1a1a1a;
}

body.dark-theme .reader-container {
    background: #1a1a1a;
}

body.dark-theme .reader-controls {
    background: #2d2d2d;
    border-top-color: #404040;
}

body.dark-theme .btn-outline-secondary {
    color: #e0e0e0;
    border-color: #404040;
}

body.dark-theme .btn-outline-secondary:hover {
    background: #404040;
    color: white;
}

body.dark-theme .settings-panel {
    background: #2d2d2d;
    color: #e0e0e0;
    border: 1px solid #404040;
}

body.dark-theme .settings-panel-header {
    border-bottom-color: #404040;
}

body.dark-theme .form-select {
    background-color: #3d3d3d;
    color: #e0e0e0;
    border-color: #404040;
}

body.dark-theme .form-select option {
    background-color: #3d3d3d;
}

/* ===== ПАНЕЛЬ АННОТАЦИЙ - ИСПРАВЛЕННАЯ ===== */
.annotations-panel {
    position: fixed;
    top: 0;
    right: -420px; /* Ширина панели + отступ */
    width: 400px;
    height: 100vh;
    background: #ffffff;
    box-shadow: -4px 0 20px rgba(0,0,0,0.15);
    z-index: 1070;
    display: flex;
    flex-direction: column;
    transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    border-left: 1px solid #dee2e6;
    overflow: hidden;
}

.annotations-panel.open {
    right: 0 !important; /* Важно: переопределяем !important */
}

/* Заголовок панели */
.annotations-panel .panel-header {
    padding: 16px 20px;
    background: #2c3e50;
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-shrink: 0;
    border-bottom: 2px solid #34495e;
}

.annotations-panel .panel-header h6 {
    margin: 0;
    font-size: 1rem;
    font-weight: 600;
}

/* Кнопка закрытия */
.btn-close-panel {
    background: rgba(255,255,255,0.1);
    border: none;
    color: white;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

.btn-close-panel:hover {
    background: rgba(255,255,255,0.2);
}

/* Поиск и фильтры */
.panel-search {
    padding: 12px 16px;
    border-bottom: 1px solid #eee;
    flex-shrink: 0;
    background: #f8f9fa;
}

.panel-filters {
    padding: 8px 16px 12px;
    border-bottom: 1px solid #eee;
    flex-shrink: 0;
    background: #f8f9fa;
}

/* Список аннотаций */
.annotations-list {
    flex: 1;
    overflow-y: auto;
    padding: 12px 16px;
    background: #ffffff;
}

.annotations-list::-webkit-scrollbar {
    width: 6px;
}

.annotations-list::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.annotations-list::-webkit-scrollbar-thumb {
    background: #c1c1c1;
    border-radius: 3px;
}

.annotations-list::-webkit-scrollbar-thumb:hover {
    background: #a8a8a8;
}

/* Элемент аннотации */
.annotation-item {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 12px 14px;
    margin-bottom: 10px;
    border-left: 4px solid #6c757d;
    cursor: pointer;
    transition: all 0.2s ease;
    position: relative;
}

.annotation-item:hover {
    background: #e9ecef;
    transform: translateX(4px);
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.annotation-item.type-quote { border-left-color: #ffc107; }
.annotation-item.type-note { border-left-color: #17a2b8; }
.annotation-item.type-highlight { border-left-color: #28a745; }
.annotation-item.type-bookmark { border-left-color: #6c757d; }

.annotation-item .annotation-text {
    font-size: 0.9rem;
    margin-bottom: 6px;
    color: #333;
    line-height: 1.5;
    word-wrap: break-word;
}

.annotation-item .annotation-note {
    font-size: 0.85rem;
    color: #6c757d;
    margin-top: 4px;
    padding: 6px 10px;
    background: rgba(0,0,0,0.03);
    border-radius: 4px;
    border-left: 2px solid #dee2e6;
}

.annotation-item .annotation-meta {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 8px;
    font-size: 0.75rem;
    color: #6c757d;
}

.annotation-item .annotation-actions {
    display: flex;
    gap: 4px;
}

.annotation-item .annotation-actions button {
    padding: 2px 8px;
    font-size: 0.7rem;
    border-radius: 4px;
}

/* Подвал панели */
.panel-footer {
    padding: 12px 16px;
    border-top: 1px solid #eee;
    background: #f8f9fa;
    flex-shrink: 0;
}

.annotation-stats {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-bottom: 8px;
}

.annotation-stats .badge {
    font-size: 0.8rem;
    padding: 6px 12px;
}

/* Кнопка открытия панели */
.annotations-toggle-btn {
    position: relative;
    border-radius: 6px;
    margin-right: 4px;
    padding: 6px 12px;
}

.annotations-toggle-btn:hover {
    transform: scale(1.05);
}

.annotation-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    font-size: 0.6rem;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    padding: 0 5px;
}

/* ===== ТЕМНАЯ ТЕМА ===== */
body.dark-theme .annotations-panel {
    background: #1a1a1a;
    color: #e0e0e0;
    border-left-color: #404040;
}

body.dark-theme .panel-search,
body.dark-theme .panel-filters,
body.dark-theme .panel-footer {
    border-color: #404040;
    background: #2d2d2d;
}

body.dark-theme .annotations-list {
    background: #1a1a1a;
}

body.dark-theme .annotation-item {
    background: #2d2d2d;
    color: #e0e0e0;
}

body.dark-theme .annotation-item:hover {
    background: #3d3d3d;
}

body.dark-theme .annotation-item .annotation-text {
    color: #e0e0e0;
}

body.dark-theme .annotation-item .annotation-note {
    color: #b0b0b0;
    background: rgba(255,255,255,0.05);
}

body.dark-theme .form-select,
body.dark-theme .form-control {
    background: #3d3d3d;
    color: #e0e0e0;
    border-color: #404040;
}

/* ===== АДАПТИВНОСТЬ ===== */
@media (max-width: 768px) {
    .annotations-panel {
        width: 100%;
        right: -100%;
    }

    .annotations-panel.open {
        right: 0;
    }
}
</style>

<?php require 'templates/footer.php'; ?>
