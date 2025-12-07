<?php

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/Fb2CoverParser.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('HTTP/1.0 400 Bad Request');
    die('Неверный ID книги');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    header('HTTP/1.0 404 Not Found');
    die('Книга не найдена');
}

// Получаем читаемое название жанра
$readableGenre = $db->getReadableGenre($book['genre']);

// Проверяем наличие обложки
$hasCover = hasBookCover($book);
$coverCachePath = Config::COVER_CACHE_DIR . '/' . $bookId . '.jpg';
$coverExistsInCache = file_exists($coverCachePath);

// Получаем связанные книги (с защитой от ошибок)
$relatedBooks = getRelatedBooks($book, $db);

require 'templates/header.php';
?>

<div class="row">
    <div class="col-md-3 text-center">
        <!-- Блок обложки -->
        <div class="cover-container mb-3">
            <?php if ($hasCover): ?>
                <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>" 
                     class="img-fluid book-cover-main shadow" 
                     alt="Обложка книги <?php echo htmlspecialchars($book['title']); ?>"
                     style="max-width: 100%; height: auto; border-radius: 8px;"
                     id="mainCover"
                     onerror="handleCoverError(this)"
                     loading="eager">
                
                <!-- Запасной вариант если обложка не загрузится -->
                <div class="cover-placeholder bg-light d-none align-items-center justify-content-center" 
                     style="height: 400px; border-radius: 8px;">
                    <div class="text-center">
                        <span class="text-muted d-block">📚</span>
                        <small class="text-muted">Обложка не загружена</small>
                    </div>
                </div>
                
                <!-- Миниатюра для предпросмотра -->
                <div class="mt-3 text-center">
                    <small class="text-muted d-block mb-2">Миниатюра:</small>
                    <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>&thumb=1" 
                         class="img-thumbnail cover-thumb" 
                         alt="Миниатюра"
                         style="max-width: 100px; border-radius: 4px;"
                         onerror="this.style.display='none'">
                </div>
            <?php else: ?>
                <!-- Заглушка если обложки нет в книге -->
                <div class="cover-placeholder bg-light d-flex align-items-center justify-content-center shadow" 
                     style="height: 400px; border-radius: 8px;">
                    <div class="text-center">
                        <span class="text-muted d-block mb-2" style="font-size: 4rem;">📖</span>
                        <span class="text-muted">Нет обложки</span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Блок действий -->
        <div class="action-buttons mt-3">
            <a href="./api/download.php?id=<?php echo $book['id']; ?>" 
               class="btn btn-success btn-lg w-100 mb-2" 
               id="downloadBtn">
                📥 Скачать книгу
            </a>
            
            <div class="btn-group w-100" role="group">
                <a href="./api/opds.php" class="btn btn-outline-secondary btn-sm">OPDS-каталог</a>
                <button type="button" class="btn btn-outline-info btn-sm" onclick="shareBook()">📤 Поделиться</button>
            </div>
        </div>
        
        <!-- Блок с технической информацией -->
        <div class="card mt-3">
            <div class="card-header bg-light">
                <h6 class="card-title mb-0">📄 Информация о файле</h6>
            </div>
            <div class="card-body p-2">
                <small>
                    <strong>Формат:</strong> 
                    <span class="badge bg-primary"><?php echo strtoupper($book['file_type']); ?></span>
                    <br>
                    
                    <?php if ($book['archive_path']): ?>
                        <strong>📦 В архиве:</strong> 
                        <br><small class="text-muted"><?php echo htmlspecialchars(basename($book['archive_path'])); ?></small>
                        <br>
                        <strong>📄 Файл:</strong> 
                        <br><small class="text-muted"><?php echo htmlspecialchars($book['archive_internal_path']); ?></small>
                    <?php else: ?>
                        <strong>📄 Файл:</strong> 
                        <br><small class="text-muted"><?php echo htmlspecialchars(basename($book['file_path'])); ?></small>
                    <?php endif; ?>
                    
                    <br>
                    <strong>🖼️ Обложка:</strong> 
                    <?php if ($hasCover): ?>
                        <span class="text-success">✅ Встроенная</span>
                        <?php if ($coverExistsInCache): ?>
                            <br><small class="text-muted">(кэширована)</small>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">❌ Отсутствует</span>
                    <?php endif; ?>
                    
                    <?php if (isset($book['file_size']) && $book['file_size']): ?>
                        <br><strong>📏 Размер:</strong> 
                        <small class="text-muted"><?php echo formatFileSize($book['file_size']); ?></small>
                    <?php endif; ?>
                </small>
            </div>
        </div>
        
        <!-- Статус файла -->
        <div class="card mt-2">
            <div class="card-body p-2 text-center">
                <small>
                    <?php if (checkFileExists($book)): ?>
                        <span class="text-success">✅ Файл доступен</span>
                    <?php else: ?>
                        <span class="text-danger">❌ Файл не найден</span>
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    
    <div class="col-md-9">
        <!-- Навигация -->
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">🏠 Главная</a></li>
                <li class="breadcrumb-item"><a href="index.php">📚 Все книги</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?></li>
            </ol>
        </nav>
        
        <!-- Заголовок и автор -->
        <h1 class="display-6 mb-3"><?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?></h1>
        
        <?php if (!empty($book['author'])): ?>
            <p class="lead mb-4">
                <strong>✍️ Автор:</strong> 
                <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                   class="text-decoration-none fw-bold author-link">
                    <?php echo htmlspecialchars($book['author']); ?>
                </a>
            </p>
        <?php endif; ?>
        
        <!-- Блок с метаданными -->
        <div class="row mb-4">
            <?php if ($readableGenre): ?>
                <div class="col-md-6 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2">
                            <strong>📚 Жанр:</strong><br>
                            <a href="index.php?field=genre&q=<?php echo urlencode($book['genre']); ?>" 
                               class="badge bg-primary text-decoration-none genre-badge">
                                <?php echo htmlspecialchars($readableGenre); ?>
                            </a>
                            <?php if ($book['genre'] !== $readableGenre): ?>
                                <br><small class="text-muted">(<?php echo htmlspecialchars($book['genre']); ?>)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($book['series'])): ?>
                <div class="col-md-6 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2">
                            <strong>📖 Серия:</strong><br>
                            <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" 
                               class="text-decoration-none series-link">
                                <?php echo htmlspecialchars($book['series']); ?>
                            </a>
                            <?php if (!empty($book['series_number'])): ?>
                                <span class="badge bg-secondary ms-1">Книга <?php echo $book['series_number']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($book['year'])): ?>
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2 text-center">
                            <strong>📅 Год</strong><br>
                            <span class="year-badge"><?php echo $book['year']; ?></span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($book['language'])): ?>
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2 text-center">
                            <strong>🌐 Язык</strong><br>
                            <?php echo getLanguageName($book['language']); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($book['publisher'])): ?>
                <div class="col-md-6 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2">
                            <strong>🏢 Издательство:</strong><br>
                            <?php echo htmlspecialchars($book['publisher']); ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Аннотация -->
        <?php if (!empty($book['description'])): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">📝 Аннотация</h5>
                </div>
                <div class="card-body">
                    <div class="book-description">
                        <?php echo formatDescription($book['description']); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Связанные книги -->
        <?php if (!empty($relatedBooks)): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">📚 Похожие книги</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach (array_slice($relatedBooks, 0, 4) as $relatedBook): ?>
                            <div class="col-md-6 mb-2">
                                <div class="d-flex align-items-center">
                                    <div class="flex-shrink-0">
                                        <?php if (hasBookCover($relatedBook)): ?>
                                            <img src="./api/cover_direct.php?id=<?php echo $relatedBook['id']; ?>&thumb=1" 
                                                 class="img-thumbnail" 
                                                 alt="Обложка"
                                                 style="width: 50px; height: 75px; object-fit: cover;"
                                                 onerror="this.style.display='none'">
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex-grow-1 ms-2">
                                        <a href="book_detail.php?id=<?php echo $relatedBook['id']; ?>" 
                                           class="text-decoration-none">
                                            <small class="d-block fw-bold"><?php echo htmlspecialchars($relatedBook['title'] ?: 'Без названия'); ?></small>
                                        </a>
                                        <?php if (!empty($relatedBook['author'])): ?>
                                            <small class="text-muted"><?php echo htmlspecialchars($relatedBook['author']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Дополнительная информация -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">🔍 Дополнительная информация</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>📁 Полный путь:</strong><br>
                        <small class="text-muted file-path"><?php echo htmlspecialchars($book['file_path']); ?></small></p>
                        
                        <?php if (!empty($book['archive_path'])): ?>
                            <p><strong>🗜️ Архив:</strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($book['archive_path']); ?></small></p>
                            
                            <p><strong>📄 Файл в архиве:</strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($book['archive_internal_path']); ?></small></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <p><strong>⏰ Добавлено в каталог:</strong><br>
                        <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($book['added_date'])); ?></small></p>
                        
                        <?php if (!empty($book['last_modified']) && $book['last_modified'] !== $book['added_date']): ?>
                            <p><strong>✏️ Обновлено:</strong><br>
                            <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($book['last_modified'])); ?></small></p>
                        <?php endif; ?>
                        
                        <?php if ($hasCover): ?>
                            <p><strong>🖼️ Обложка:</strong><br>
                            <small class="text-success">✅ Извлечена из файла книги</small></p>
                        <?php else: ?>
                            <p><strong>🖼️ Обложка:</strong><br>
                            <small class="text-muted">❌ Не найдена в файле</small></p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Тестовые ссылки на обложку -->
                <?php if ($hasCover): ?>
                    <div class="mt-3 p-3 bg-light rounded">
                        <h6>🔗 Ссылки на обложку:</h6>
                        <div class="btn-group" role="group">
                            <a href="./api/cover_direct.php?id=<?php echo $book['id']; ?>" 
                               class="btn btn-outline-primary btn-sm" target="_blank">
                                Полная обложка
                            </a>
                            <a href="./api/cover_direct.php?id=<?php echo $book['id']; ?>&thumb=1" 
                               class="btn btn-outline-secondary btn-sm" target="_blank">
                                Миниатюра
                            </a>
                            <button type="button" class="btn btn-outline-info btn-sm" onclick="reloadCover()">
                                🔄 Обновить
                            </button>
                        </div>
                        <small class="text-muted d-block mt-1">Обложка загружается напрямую из файла книги</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Навигация между книгами -->
        <div class="card mt-4">
            <div class="card-body text-center">
                <a href="index.php" class="btn btn-outline-primary">← Назад к списку книг</a>
                <?php if (!empty($book['author'])): ?>
                    <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" class="btn btn-outline-secondary ms-2">
                        Все книги автора
                    </a>
                <?php endif; ?>
                <?php if (!empty($book['series'])): ?>
                    <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" class="btn btn-outline-info ms-2">
                        Все книги серии
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Стили -->
<style>
.book-cover-main {
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    cursor: pointer;
}

.book-cover-main:hover {
    transform: scale(1.02);
    box-shadow: 0 8px 25px rgba(0,0,0,0.15) !important;
}

.cover-thumb {
    transition: transform 0.2s ease;
}

.cover-thumb:hover {
    transform: scale(1.1);
}

.author-link, .series-link, .genre-badge {
    transition: all 0.3s ease;
}

.author-link:hover, .series-link:hover {
    color: #0d6efd !important;
    text-decoration: underline !important;
}

.genre-badge:hover {
    background-color: #0b5ed7 !important;
    transform: translateY(-1px);
}

.file-path {
    word-break: break-all;
}

.book-description {
    line-height: 1.6;
    text-align: justify;
}

.action-buttons .btn {
    transition: all 0.3s ease;
}

.action-buttons .btn:hover {
    transform: translateY(-2px);
}

.year-badge {
    font-size: 1.1em;
    font-weight: bold;
    color: #495057;
}
</style>

<script>
// Обработка ошибок загрузки обложки
function handleCoverError(imgElement) {
    console.error('Ошибка загрузки обложки:', imgElement.src);
    imgElement.style.display = 'none';
    const placeholder = imgElement.nextElementSibling;
    if (placeholder && placeholder.classList.contains('cover-placeholder')) {
        placeholder.classList.remove('d-none');
        placeholder.classList.add('d-flex');
    }
}

// Перезагрузка обложки
function reloadCover() {
    const coverImg = document.getElementById('mainCover');
    if (coverImg) {
        // Добавляем параметр для обхода кэша
        const newSrc = coverImg.src + (coverImg.src.includes('?') ? '&' : '?') + 't=' + new Date().getTime();
        coverImg.src = newSrc;
        
        // Показываем сообщение
        showToast('Обложка обновляется...', 'info');
    }
}

// Поделиться книгой
function shareBook() {
    const title = '<?php echo addslashes($book['title'] ?: 'Книга'); ?>';
    const author = '<?php echo addslashes($book['author'] ?? ''); ?>';
    const url = window.location.href;
    
    let text = title;
    if (author) {
        text += ' - ' + author;
    }
    text += '\n\n' + url;
    
    if (navigator.share) {
        navigator.share({
            title: title,
            text: text,
            url: url
        }).catch(console.error);
    } else if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            showToast('Ссылка скопирована в буфер обмена', 'success');
        }).catch(console.error);
    } else {
        // Fallback
        prompt('Скопируйте ссылку на книгу:', url);
    }
}

// Показать уведомление
function showToast(message, type = 'info') {
    // Простая реализация toast
    const toast = document.createElement('div');
    toast.className = `alert alert-${type} position-fixed`;
    toast.style.cssText = 'top: 20px; right: 20px; z-index: 1050; min-width: 250px;';
    toast.textContent = message;
    
    document.body.appendChild(toast);
    
    setTimeout(() => {
        toast.remove();
    }, 3000);
}

// Предзагрузка обложки при наведении на связанные книги
document.addEventListener('DOMContentLoaded', function() {
    const relatedLinks = document.querySelectorAll('a[href*="book_detail.php"]');
    relatedLinks.forEach(link => {
        link.addEventListener('mouseenter', function() {
            const url = new URL(this.href);
            const bookId = url.searchParams.get('id');
            if (bookId) {
                const img = new Image();
                img.src = `./api/cover_direct.php?id=${bookId}&thumb=1`;
            }
        });
    });
    
    // Клик по обложке для увеличения
    const mainCover = document.getElementById('mainCover');
    if (mainCover) {
        mainCover.addEventListener('click', function() {
            window.open(this.src, '_blank');
        });
    }
});
</script>

<?php
/**
 * Вспомогательные функции
 */

/**
 * Проверить, есть ли обложка в книге
 */
function hasBookCover($book) {
    // Для FB2 файлов проверяем наличие обложки
    if (strtolower($book['file_type']) === 'fb2') {
        $content = getBookContent($book);
        if ($content) {
            return Fb2CoverParser::findCover($content) !== false;
        }
    }
    return false;
}

/**
 * Получить содержимое книги
 */
function getBookContent($book) {
    if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === TRUE) {
            $content = $zip->getFromName($book['archive_internal_path']);
            $zip->close();
            return $content;
        }
    } else if (!empty($book['file_path'])) {
        return @file_get_contents($book['file_path']);
    }
    return false;
}

/**
 * Получить связанные книги (с защитой от ошибок)
 */
function getRelatedBooks($book, $db) {
    $related = [];
    
    try {
        // Книги того же автора
        if (!empty($book['author'])) {
            $authorBooks = $db->getBooksByAuthor($book['author'], 1, 6);
            foreach ($authorBooks as $authorBook) {
                if ($authorBook['id'] != $book['id']) {
                    $related[] = $authorBook;
                }
            }
        }
        
        // Книги из той же серии (если метод существует)
        if (!empty($book['series']) && method_exists($db, 'getBooksBySeries')) {
            $seriesBooks = $db->getBooksBySeries($book['series'], 1, 6);
            foreach ($seriesBooks as $seriesBook) {
                if ($seriesBook['id'] != $book['id']) {
                    $related[] = $seriesBook;
                }
            }
        }
    } catch (Exception $e) {
        // Логируем ошибку, но не прерываем выполнение
        error_log("Error getting related books: " . $e->getMessage());
    }
    
    // Убираем дубликаты
    $uniqueRelated = [];
    $seenIds = [$book['id']];
    
    foreach ($related as $relatedBook) {
        if (!in_array($relatedBook['id'], $seenIds)) {
            $uniqueRelated[] = $relatedBook;
            $seenIds[] = $relatedBook['id'];
        }
    }
    
    return array_slice($uniqueRelated, 0, 8);
}

/**
 * Форматировать описание
 */
function formatDescription($description) {
    $description = htmlspecialchars($description);
    $description = nl2br($description);
    
    // Убираем лишние переносы
    $description = preg_replace('/(<br\s*\/?>\s*){3,}/i', '<br><br>', $description);
    
    return $description;
}

/**
 * Получить название языка
 */
function getLanguageName($code) {
    $languages = [
        'ru' => '🇷🇺 Русский',
        'en' => '🇺🇸 Английский',
        'de' => '🇩🇪 Немецкий',
        'fr' => '🇫🇷 Французский',
        'es' => '🇪🇸 Испанский',
        'pl' => '🇵🇱 Польский',
        'uk' => '🇺🇦 Украинский',
        'be' => '🇧🇾 Белорусский'
    ];
    
    return $languages[strtolower($code)] ?? strtoupper($code);
}

/**
 * Форматировать размер файла
 */
function formatFileSize($bytes) {
    if ($bytes == 0) return '0 B';
    
    $units = ['B', 'KB', 'MB', 'GB'];
    $base = 1024;
    $exp = floor(log($bytes, $base));
    
    return round($bytes / pow($base, $exp), 2) . ' ' . $units[$exp];
}

/**
 * Проверить существование файла
 */
function checkFileExists($book) {
    if (!empty($book['archive_path']) && !empty($book['archive_internal_path'])) {
        return file_exists($book['archive_path']);
    } else if (!empty($book['file_path'])) {
        return file_exists($book['file_path']);
    }
    return false;
}

require 'templates/footer.php';
?>