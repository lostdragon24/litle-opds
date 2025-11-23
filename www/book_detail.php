<?php

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/Fb2CoverParser.php';

$db = Database::getInstance();

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die('Неверный ID книги');
}

$bookId = intval($_GET['id']);
$book = $db->getBook($bookId);

if (!$book) {
    die('Книга не найдена');
}

// Получаем читаемое название жанра
$readableGenre = $db->getReadableGenre($book['genre']);

// Проверяем наличие обложки в книге
$hasCover = hasBookCover($book);

require 'templates/header.php';
?>

<div class="row">
    <div class="col-md-3 text-center">
        <?php if ($hasCover): ?>
            <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>" 
                 class="img-fluid mb-3" 
                 alt="Обложка книги <?php echo htmlspecialchars($book['title']); ?>"
                 style="max-width: 100%; height: auto;"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            
            <!-- Миниатюра для предпросмотра -->
            <div class="mt-2">
                <small class="text-muted">Миниатюра:</small><br>
                <img src="./api/cover_direct.php?id=<?php echo $book['id']; ?>&thumb=1" 
                     class="img-thumbnail" 
                     alt="Миниатюра"
                     style="max-width: 100px;">
            </div>
        <?php else: ?>
            <div class="bg-light d-flex align-items-center justify-content-center" style="height: 400px;">
                <span class="text-muted">Нет обложки</span>
            </div>
        <?php endif; ?>
        
        <div class="mt-3">
            <a href="./api/download.php?id=<?php echo $book['id']; ?>" class="btn btn-success btn-lg w-100 mb-2">
                📥 Скачать книгу
            </a>
            <a href="./api/opds.php" class="btn btn-outline-secondary btn-sm w-100">OPDS-каталог</a>
        </div>
        
        <!-- Блок с технической информацией -->
        <div class="card mt-3">
            <div class="card-header bg-light">
                <h6 class="card-title mb-0">📄 Информация о файле</h6>
            </div>
            <div class="card-body p-2">
                <small>
                    <strong>Формат:</strong> <?php echo strtoupper($book['file_type']); ?><br>
                    <?php if ($book['archive_path']): ?>
                        <strong>В архиве:</strong> <?php echo htmlspecialchars(basename($book['archive_path'])); ?><br>
                        <strong>Файл:</strong> <?php echo htmlspecialchars($book['archive_internal_path']); ?>
                    <?php else: ?>
                        <strong>Файл:</strong> <?php echo htmlspecialchars(basename($book['file_path'])); ?>
                    <?php endif; ?>
                    <?php if ($hasCover): ?>
                        <br><strong>Обложка:</strong> ✅ Встроенная
                    <?php else: ?>
                        <br><strong>Обложка:</strong> ❌ Отсутствует
                    <?php endif; ?>
                </small>
            </div>
        </div>
    </div>
    
    <div class="col-md-9">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php">🏠 Главная</a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?></li>
            </ol>
        </nav>
        
        <h1 class="display-6"><?php echo htmlspecialchars($book['title'] ?: 'Без названия'); ?></h1>
        
        <?php if ($book['author']): ?>
            <p class="lead">
                <strong>✍️ Автор:</strong> 
                <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" class="text-decoration-none fw-bold">
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
                            <a href="index.php?field=genre&q=<?php echo urlencode($book['genre']); ?>" class="badge bg-primary text-decoration-none">
                                <?php echo htmlspecialchars($readableGenre); ?>
                            </a>
                            <?php if ($book['genre'] !== $readableGenre): ?>
                                <br><small class="text-muted">(<?php echo htmlspecialchars($book['genre']); ?>)</small>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($book['series']): ?>
                <div class="col-md-6 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2">
                            <strong>📖 Серия:</strong><br>
                            <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" class="text-decoration-none">
                                <?php echo htmlspecialchars($book['series']); ?>
                            </a>
                            <?php if ($book['series_number']): ?>
                                <span class="badge bg-secondary">Книга <?php echo $book['series_number']; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($book['year']): ?>
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2 text-center">
                            <strong>📅 Год</strong><br>
                            <?php echo $book['year']; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($book['language']): ?>
                <div class="col-md-3 mb-2">
                    <div class="card h-100">
                        <div class="card-body py-2 text-center">
                            <strong>🌐 Язык</strong><br>
                            <?php 
                            $languages = [
                                'ru' => 'Русский',
                                'en' => 'Английский',
                                'de' => 'Немецкий',
                                'fr' => 'Французский',
                                'es' => 'Испанский',
                                'pl' => 'Польский'
                            ];
                            echo $languages[strtolower($book['language'])] ?? strtoupper($book['language']); 
                            ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($book['publisher']): ?>
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
        
        <?php if ($book['description']): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">📝 Аннотация</h5>
                </div>
                <div class="card-body">
                    <p class="card-text"><?php echo nl2br(htmlspecialchars($book['description'])); ?></p>
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
                        <small class="text-muted"><?php echo htmlspecialchars($book['file_path']); ?></small></p>
                        
                        <?php if ($book['archive_path']): ?>
                            <p><strong>🗜️ Архив:</strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($book['archive_path']); ?></small></p>
                            
                            <p><strong>📄 Файл в архиве:</strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars($book['archive_internal_path']); ?></small></p>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <p><strong>⏰ Добавлено в каталог:</strong><br>
                        <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($book['added_date'])); ?></small></p>
                        
                        <?php if ($book['last_modified'] && $book['last_modified'] !== $book['added_date']): ?>
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
                <?php if ($book['author']): ?>
                    <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" class="btn btn-outline-secondary ms-2">
                        Все книги автора
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Обработка ошибок загрузки обложки
document.addEventListener('DOMContentLoaded', function() {
    const coverImage = document.querySelector('.col-md-3 img');
    if (coverImage) {
        coverImage.addEventListener('error', function() {
            console.error('Ошибка загрузки обложки:', this.src);
            this.style.display = 'none';
            const placeholder = this.nextElementSibling;
            if (placeholder) {
                placeholder.style.display = 'flex';
            }
        });
        
        coverImage.addEventListener('load', function() {
            console.log('Обложка успешно загружена:', this.src);
        });
    }
});
</script>

<?php
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
    if ($book['archive_path'] && $book['archive_internal_path']) {
        $zip = new ZipArchive();
        if ($zip->open($book['archive_path']) === TRUE) {
            $content = $zip->getFromName($book['archive_internal_path']);
            $zip->close();
            return $content;
        }
    } else {
        return @file_get_contents($book['file_path']);
    }
    return false;
}

require 'templates/footer.php';
?>