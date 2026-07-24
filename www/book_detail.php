<?php
// book_detail.php

define('LOPDS_ROOT', __DIR__);

require_once 'config/config.php';
require_once 'lib/Database.php';
require_once 'lib/BookHelper.php';
require_once 'lib/Cache.php';
require_once 'lib/PageCache.php';
require_once 'init.php';

$db = Database::getInstance();

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

// Получаем данные для отображения
$readableGenre = $db->getReadableGenre($book['genre']);
$hasCover = BookHelper::hasCover($book);
$description = $book['description'] ?? BookHelper::extractDescription($book);

// Получаем рейтинг и статус избранного
$rating = $db->getBookRating($bookId);
//$userRating = $db->getUserRating($bookId, $_SERVER['REMOTE_ADDR']);
//$isFavorite = $db->isBookInFavorites($bookId, $_SERVER['REMOTE_ADDR']);

$userRating = $db->getUserRating($bookId, DEVICE_ID);
$isFavorite = $db->isBookInFavorites($bookId, DEVICE_ID);
error_log("DEVICE_ID: " . DEVICE_ID);

require 'templates/header.php';
?>



<script src="<?php echo $basePath; ?>/js/epubjs/jszip.min.js"></script>

<!-- Стили для конвертера -->
<style>
    .fb2-converter {
        margin-top: 1rem;
        padding: 1rem;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #dee2e6;
    }
    .fb2-converter .progress {
        height: 20px;
        margin: 10px 0;
        display: none;
    }
    .fb2-converter .progress-bar {
        width: 0%;
        transition: width 0.3s;
    }
    #converterStatus {
        margin-top: 10px;
    }
</style>

<div class="container py-4">
    <!-- Хлебные крошки -->
    <nav aria-label="breadcrumb" class="mb-4">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">🏠 <?php echo __('home'); ?></a></li>
            <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">📚 <?php echo __('all_books'); ?></a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars(mb_substr($book['title'] ?: __('book_untitled'), 0, 40)); ?></li>
        </ol>
    </nav>

    <div class="row">
        <!-- Левая колонка - Обложка и действия -->
        <div class="col-lg-4 mb-4">
            <!-- Обложка -->
            <div class="card shadow-sm mb-4">
                <div class="card-body p-3 text-center">
                
                  <div class="cover-container mb-3">
                        <?php if ($hasCover): ?>
                            <img src="./api/cover.php?id=<?php echo $book['id']; ?>" 
                                 class="img-fluid rounded shadow zoom-effect" 
                                 alt="Обложка книги <?php echo htmlspecialchars($book['title']); ?>"
                                 style="max-height: 400px; width: auto;"
                                 loading="eager">
                            
                            <div class="mt-2">
                                <span class="badge bg-primary">
                                    <?php echo strtoupper($book['file_type']); ?>
                                </span>
                                <?php if ($book['archive_path']): ?>
                                <span class="badge bg-secondary ms-1">
                                    📦 <?php echo __('book_status_archive'); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            
                        <?php else: ?>
                            <div class="bg-light d-flex align-items-center justify-content-center rounded" 
                                 style="height: 300px;">
                                <div class="text-center">
                                    <i class="fas fa-book text-muted mb-3" style="font-size: 4rem;"></i>
                                    <p class="text-muted mb-0">Нет обложки</p>
                                    <p class="text-muted mb-0">
                                        <small><?php echo strtoupper($book['file_type']); ?></small>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                   <!-- Действия -->
                    <div class="d-grid gap-2">
                        <a href="./api/download.php?id=<?php echo $book['id']; ?>" 
                           class="btn btn-lg btn-success">
                            <i class="fas fa-download me-2"></i><?php echo __('download_book'); ?>
                        </a>

                        <?php if (in_array(strtolower($book['file_type']), ['fb2', 'epub', 'pdf'])): ?>
                            <a href="reader.php?id=<?php echo $book['id']; ?>" 
                               class="btn btn-lg btn-primary mt-2">
                                <i class="fas fa-book-open me-2"></i><?php echo __('read_online'); ?>
                            </a>
                        <?php endif; ?>


                        <div class="fb2-converter">
    <h5><i class="fas fa-exchange-alt me-2"></i><?php echo __('fb2_2_epub'); ?></h5>
    <p class="text-muted small"><?php echo __('fb2_2_epub_msg'); ?></p>

    <button id="convertToEpubBtn" class="btn btn-success btn-sm">
        <i class="fas fa-file-export me-1"></i>
        <?php echo __('fb2_2_epub_btn'); ?>
    </button>

    <div class="progress mt-2" id="converterProgress">
        <div class="progress-bar progress-bar-striped progress-bar-animated"
             id="converterProgressBar"
             role="progressbar"
             style="width: 0%">
            0%
        </div>
    </div>

    <div id="converterStatus" class="mt-2"></div>

    <a id="downloadEpubBtn" class="btn btn-primary btn-sm mt-2" style="display:none;">
        <i class="fas fa-download me-1"></i>
        <?php echo __('fb2_2_epub_btn_download'); ?>
    </a>
</div>



                    </div>
                </div>
            </div>
            
            <!-- Техническая информация -->
            <div class="card shadow-sm">
                <div class="card-body">
                    <h6 class="card-title border-bottom pb-2 mb-3">
                        <i class="fas fa-info-circle me-2"></i><?php echo __('info'); ?>
                    </h6>
                    
                    <div class="row">
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block"><?php echo __('book_format'); ?></small>
                            <span class="badge bg-primary"><?php echo strtoupper($book['file_type']); ?></span>
                        </div>
                        
                        <div class="col-6 mb-3">
                            <small class="text-muted d-block"><?php echo __('book_added'); ?></small>
                            <strong><?php echo date('d.m.Y H:i', strtotime($book['added_date'])); ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Правая колонка - Основная информация -->
        <div class="col-lg-8">
            <!-- Заголовок и автор -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h1 class="h2 mb-3"><?php echo htmlspecialchars($book['title'] ?: __('book_untitled')); ?></h1>
                    
                    <?php if (!empty($book['author'])): ?>
                    <div class="mb-4">
                        <h5 class="text-muted mb-2"><?php echo __('book_author'); ?></h5>
                        <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                           class="h4 text-decoration-none">
                            <?php echo htmlspecialchars($book['author']); ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- РЕЙТИНГ И ИЗБРАННОЕ -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="fas fa-star text-warning me-2"></i>
                                <?php echo __('rating_title'); ?>
                            </h5>
                            <div id="rating-section" data-book-id="<?php echo $bookId; ?>">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="text-center mb-3 mb-md-0">
                                            <div class="h1 mb-0 text-warning" id="average-rating">
                                                <?php echo number_format($rating['average'], 1); ?>
                                            </div>
                                            <div class="star-rating-large mb-2" id="average-stars">
                                                <?php
                                                $fullStars = floor($rating['average_rounded']);
$halfStar = ($rating['average_rounded'] - $fullStars) >= 0.5;
$emptyStars = 5 - $fullStars - ($halfStar ? 1 : 0);

for ($i = 0; $i < $fullStars; $i++) {
    echo '<i class="fas fa-star text-warning fa-2x"></i>';
}
if ($halfStar) {
    echo '<i class="fas fa-star-half-alt text-warning fa-2x"></i>';
}
for ($i = 0; $i < $emptyStars; $i++) {
    echo '<i class="far fa-star text-warning fa-2x"></i>';
}
?>
                                            </div>
                                            <div>
                                                <small class="text-muted" id="votes-count">
                                                    <?php echo $rating['votes'] . ' ' . ($rating['votes'] == 1 ? __('rating_vote_1') : ($rating['votes'] < 5 ? __('rating_vote_2') : __('rating_vote_5'))); ?>
                                                </small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <h6 class="mb-2"><?php echo __('rating_your'); ?></h6>
                                        <div class="star-rating-select mb-3" id="user-rating-stars">
                                            <div class="d-flex justify-content-center">
                                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                                    <button type="button"
                                                            class="btn btn-link p-0 me-2 rating-star"
                                                            data-rating="<?php echo $star; ?>"
                                                            data-book-id="<?php echo $bookId; ?>">
                                                        <i class="<?php echo $userRating >= $star ? 'fas' : 'far'; ?> fa-star fa-2x <?php echo $userRating >= $star ? 'text-warning' : 'text-muted'; ?>"></i>
                                                    </button>
                                                <?php endfor; ?>
                                            </div>
                                            <div class="text-center mt-2">
                                                <small class="text-muted" id="user-rating-text">
                                                    <?php
    if ($userRating > 0) {
        echo sprintf(__('rating_your_value'), $userRating, $userRating == 1 ? __('rating_star_1') : ($userRating < 5 ? __('rating_star_2') : __('rating_star_5')));
    } else {
        echo __('rating_click_to_rate');
    }
?>
                                                </small>
                                            </div>
                                        </div>

                                        <!-- Распределение оценок -->
                                        <?php if ($rating['votes'] > 0): ?>
                                        <div class="mt-3">
                                            <h6 class="mb-2"><?php echo __('rating_distribution'); ?></h6>
                                            <div id="rating-distribution">
                                                <?php
                                                $distribution = $rating['distribution'] ?? [0, 0, 0, 0, 0];
                                            for ($star = 5; $star >= 1; $star--):
                                                $index = 5 - $star;
                                                $count = $distribution[$index] ?? 0;
                                                $percent = $rating['votes'] > 0 ? ($count / $rating['votes'] * 100) : 0;
                                                $color = '';

                                                switch ($star) {
                                                    case 5: $color = 'bg-success';
                                                        break;
                                                    case 4: $color = 'bg-info';
                                                        break;
                                                    case 3: $color = 'bg-primary';
                                                        break;
                                                    case 2: $color = 'bg-warning';
                                                        break;
                                                    case 1: $color = 'bg-danger';
                                                        break;
                                                    default: $color = 'bg-secondary';
                                                }
                                                ?>
                                                <div class="d-flex align-items-center mb-1">
                                                    <div class="me-2" style="width: 20px;">
                                                        <small><?php echo $star; ?></small>
                                                    </div>
                                                    <div class="flex-grow-1">
                                                        <div class="progress" style="height: 10px;">
                                                            <div class="progress-bar <?php echo $color; ?>"
                                                                 role="progressbar"
                                                                 style="width: <?php echo $percent; ?>%"
                                                                 aria-valuenow="<?php echo $percent; ?>"
                                                                 aria-valuemin="0"
                                                                 aria-valuemax="100">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="ms-2" style="width: 40px; text-align: right;">
                                                        <small class="text-muted star-count"><?php echo $count; ?></small>
                                                    </div>
                                                </div>
                                                <?php endfor; ?>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column justify-content-center">
                            <h5 class="card-title">
                                <i class="fas fa-heart text-danger me-2"></i>
                                <?php echo __('favorites'); ?>
                            </h5>
                            <div class="text-center">
                            
                                <button id="favorite-btn"
                                        class="btn <?php echo $isFavorite ? 'btn-danger' : 'btn-outline-danger'; ?>"
                                        data-book-id="<?php echo $bookId; ?>"
                                        style="min-width: 150px;">
                                    <i class="<?php echo $isFavorite ? 'fas' : 'far'; ?> fa-heart me-2"></i>
                                    <span><?php echo $isFavorite ? __('favorites_in') : __('favorites_add'); ?></span>
                                </button>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <?php echo __('favorites_for_quick'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Метаданные -->
            <div class="row g-3 mb-4">
                <?php if ($readableGenre): ?>
                <div class="col-md-6">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-2"><?php echo __('book_genre'); ?></h6>
                            <a href="index.php?field=genre&q=<?php echo urlencode($book['genre']); ?>" 
                               class="text-decoration-none">
                                <span class="h5"><?php echo htmlspecialchars($readableGenre); ?></span>
                            </a>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($book['series'])): ?>
                <div class="col-md-6">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body">
                            <h6 class="card-title text-muted mb-2"><?php echo __('book_series'); ?></h6>
                            <a href="index.php?field=series&q=<?php echo urlencode($book['series']); ?>" 
                               class="text-decoration-none">
                                <span class="h5"><?php echo htmlspecialchars($book['series']); ?></span>
                            </a>
                            <?php if (!empty($book['series_number'])): ?>
                            <span class="badge bg-secondary"><?php echo sprintf(__('book_number'), $book['series_number']); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if (!empty($book['year'])): ?>
                <div class="col-md-4">
                    <div class="card h-100 border-0 bg-light">
                        <div class="card-body text-center">
                            <h6 class="card-title text-muted mb-2"><?php echo __('book_year'); ?></h6>
                            <span class="h3 text-primary"><?php echo $book['year']; ?></span>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ОПИСАНИЕ КНИГИ -->
            <div class="card shadow-sm mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3 border-bottom pb-2">
                        <i class="fas fa-file-alt me-2"></i><?php echo __('book_description'); ?>
                    </h5>
                    <div class="book-description">
                        <?php if (!empty($description)): ?>
                            <p><?php echo nl2br(htmlspecialchars($description)); ?></p>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                <i class="fas fa-info-circle me-2"></i>
                                <?php echo __('book_description_missing'); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Навигация -->
            <div class="card shadow-sm">
                <div class="card-body text-center">
                    <div class="btn-group" role="group">
                        <a href="index.php" class="btn btn-outline-primary">
                            <i class="fas fa-arrow-left me-2"></i><?php echo __('back_to_list'); ?>
                        </a>
                        <?php if (!empty($book['author'])): ?>
                        <a href="index.php?field=author&q=<?php echo urlencode($book['author']); ?>" 
                           class="btn btn-outline-secondary ms-2">
                            <i class="fas fa-user me-2"></i><?php echo __('all_books_by_author'); ?>
                        </a>
                        <?php endif; ?>
                        <a href="favorites.php" class="btn btn-outline-danger ms-2">
                            <i class="fas fa-heart me-2"></i><?php echo __('my_favorites'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>


<style>
.table th {
    width: 60%;
    font-weight: 500;
    color: #495057;
}
.table td {
    width: 40%;
}
.progress {
    border-radius: 12px;
    overflow: hidden;
}
.progress-bar {
    transition: width 0.6s ease;
    font-weight: 600;
}
.badge {
    font-size: 0.9rem;
    padding: 0.5rem 0.75rem;
}
.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1) !important;
}
@media (max-width: 768px) {
    .table th {
        width: 50%;
    }
    .table td {
        width: 50%;
    }
    .btn {
        width: 100%;
        margin: 5px 0 !important;
    }
    .d-flex {
        flex-direction: column;
    }
}
.zoom-effect {
  transition: transform 0.3s ease; /* Плавный переход */
}

.zoom-effect:hover {
  transform: scale(1.2); /* Увеличение на 20% */
}

</style>



<!-- JavaScript для страницы книги -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    console.log('Book detail page loaded');
    
    // Загружаем рейтинг
    const ratingSection = document.getElementById('rating-section');
    if (ratingSection && window.loadBookRating) {
        window.loadBookRating(<?php echo $bookId; ?>, ratingSection);
    }
    
    // Инициализация кнопки избранного
    const favBtn = document.getElementById('favorite-btn');
    if (favBtn) {
        const newBtn = favBtn.cloneNode(true);
        favBtn.parentNode.replaceChild(newBtn, favBtn);
        
        newBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            if (this.disabled) return;
            
            const bookId = this.getAttribute('data-book-id');
            if (bookId && window.toggleFavorite) {
                window.toggleFavorite(bookId, this);
            }
        });
    }
    
    // Инициализация звезд
    const stars = document.querySelectorAll('.rating-star');
    stars.forEach(star => {
        const newStar = star.cloneNode(true);
        star.parentNode.replaceChild(newStar, star);
        
        newStar.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const rating = this.getAttribute('data-rating');
            const bookId = this.getAttribute('data-book-id');
            if (window.rateBook) {
                window.rateBook(bookId, rating, this);
            }
        });
        
        newStar.addEventListener('mouseenter', function() {
            const rating = this.getAttribute('data-rating');
            if (window.highlightStars) {
                window.highlightStars(rating);
            }
        });
        
        newStar.addEventListener('mouseleave', function() {
            if (window.resetStars) {
                window.resetStars();
            }
        });
    });
});

// Локальная функция для обработки ошибок загрузки обложек (на случай, если глобальной нет)
if (typeof handleCoverError !== 'function') {
    window.handleCoverError = function(img, height = 400) {
        img.style.display = 'none';
        const parent = img.parentNode;
        const noCoverText = '<?php echo __('book_no_cover'); ?>';
        const fileType = '<?php echo strtoupper($book['file_type']); ?>';
        
        if (parent.querySelector('.cover-placeholder')) {
            return;
        }
        
        const placeholder = document.createElement('div');
        placeholder.className = 'bg-light d-flex align-items-center justify-content-center rounded cover-placeholder';
        placeholder.style.cssText = `width:100%; height:${height}px;`;
        
        if (height >= 300) {
            placeholder.innerHTML = `
                <div class="text-center">
                    <i class="fas fa-book text-muted mb-3" style="font-size: 4rem;"></i>
                    <p class="text-muted mb-0">${noCoverText}</p>
                    <p class="text-muted mb-0">
                        <small>${fileType}</small>
                    </p>
                </div>
            `;
        } else {
            placeholder.innerHTML = '<small class="text-muted">' + noCoverText + '</small>';
        }
        
        parent.innerHTML = '';
        parent.appendChild(placeholder);
    };
}



// ============================================================
// Конвертор FB2 в EPUB
// ============================================================



document.addEventListener('DOMContentLoaded', function() {
    const convertBtn = document.getElementById('convertToEpubBtn');
    const progress = document.getElementById('converterProgress');
    const progressBar = document.getElementById('converterProgressBar');
    const statusDiv = document.getElementById('converterStatus');
    const downloadBtn = document.getElementById('downloadEpubBtn');

    const bookId = <?php echo $book['id']; ?>;

    convertBtn.addEventListener('click', async function() {
        // Проверяем, что книга в FB2
        const fileType = '<?php echo $book['file_type']; ?>';
        if (fileType !== 'fb2') {
            showStatus('<?php echo __('fb2_2_epub_msg_script_1'); ?>', 'warning');
            return;
        }

        convertBtn.disabled = true;
        convertBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Загрузка...';

        try {
            // 1. Загружаем FB2 контент
            const response = await fetch(`./api/book_content.php?id=${bookId}&format=raw`);
            if (!response.ok) {
                throw new Error('<?php echo __('fb2_2_epub_msg_script_2'); ?>');
            }

            const fb2Text = await response.text();
            showStatus('<?php echo __('fb2_2_epub_msg_script_3'); ?>', 'info');

            // 2. Конвертируем
            progress.style.display = 'block';
            progressBar.style.width = '10%';
            progressBar.textContent = '10%';

            const epubBlob = await convertFB2ToEpub(fb2Text, {
                title: '<?php echo addslashes($book['title']); ?>',
                author: '<?php echo addslashes($book['author']); ?>',
                language: '<?php echo $book['language'] ?? 'ru'; ?>'
            });

            progressBar.style.width = '100%';
            progressBar.textContent = '100%';

            // 3. Создаем ссылку для скачивания
            const url = URL.createObjectURL(epubBlob);

            const fileName = '<?php echo preg_replace('/[^a-zA-Z0-9а-яА-ЯёЁ\s\-_]/u', '', $book['title']); ?>.epub';

            downloadBtn.href = url;
            downloadBtn.download = fileName;
            downloadBtn.style.display = 'inline-block';

            showStatus('<?php echo __('fb2_2_epub_msg_script_4'); ?>', 'success');

        } catch (error) {
            showStatus('Ошибка: ' + error.message, 'danger');
            console.error('Conversion error:', error);
        } finally {
            convertBtn.disabled = false;
            convertBtn.innerHTML = '<i class="fas fa-file-export me-1"></i><?php echo __('fb2_2_epub_msg_script_5'); ?>';
        }
    });

    function showStatus(message, type) {
        const types = {
            info: 'text-primary',
            success: 'text-success',
            warning: 'text-warning',
            danger: 'text-danger'
        };
        statusDiv.className = types[type] || 'text-muted';
        statusDiv.textContent = message;
    }
});

// ============================================================
// FB2 → EPUB Конвертер (адаптирован для использования в браузере)
// ============================================================

async function convertFB2ToEpub(fb2Text, metadata) {
    const bookMetadata = {
        title: metadata.title || 'Untitled',
        author: metadata.author || 'Unknown Author',
        language: metadata.language || 'en'
    };

    updateProgress(8);

    const parser = new DOMParser();
    const xmlDoc = parser.parseFromString(fb2Text, "text/xml");
    if (xmlDoc.querySelector("parsererror")) {
        throw new Error("Invalid FB2 file format");
    }

    // Извлекаем бинарные данные (изображения)
    const binaries = {};
    const binaryNodes = xmlDoc.querySelectorAll("binary[id]");
    binaryNodes.forEach((b) => {
        const id = b.getAttribute("id");
        const mime = (b.getAttribute("content-type") || "image/jpeg").toLowerCase();
        const base64 = (b.textContent || "").replace(/\s+/g, "");
        binaries[id] = { mime, base64, ext: mimeToExt(mime) };
    });

    updateProgress(18);

    // Извлекаем главы
    const bodies = xmlDoc.querySelectorAll("body");
    const chapters = [];
    let chapterIndex = 1;

    function pushSectionAsChapter(section) {
        const titleNode = section.querySelector(":scope > title");
        const chapTitle = titleNode ? textContentDeep(titleNode).trim() : `Chapter ${chapterIndex}`;
        const htmlContent = serializeSectionToXHTML(section, binaries);
        chapters.push({
            id: `ch${chapterIndex}`,
            title: chapTitle || `Chapter ${chapterIndex}`,
            content: htmlContent
        });
        chapterIndex++;
    }

    if (bodies.length) {
        bodies.forEach((body) => {
            const sections = body.querySelectorAll(":scope > section");
            if (sections.length) {
                sections.forEach((sec) => pushSectionAsChapter(sec));
            } else {
                pushSectionAsChapter(body);
            }
        });
    } else {
        const allSections = xmlDoc.querySelectorAll("section");
        if (allSections.length) {
            allSections.forEach((sec) => pushSectionAsChapter(sec));
        } else {
            const wrapper = xmlDoc.documentElement;
            pushSectionAsChapter(wrapper);
        }
    }

    if (chapters.length === 0) {
        throw new Error("No readable content sections found in FB2.");
    }

    updateProgress(35);

    // Создаем EPUB через JSZip
    const zip = new JSZip();

    // mimetype (должен быть без сжатия)
    zip.file("mimetype", "application/epub+zip", { compression: "STORE" });

    // META-INF/container.xml
    zip.folder("META-INF").file(
        "container.xml",
        `<?xml version="1.0" encoding="UTF-8"?>
<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">
  <rootfiles>
    <rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/>
  </rootfiles>
</container>`
    );

    updateProgress(45);

    const oebps = zip.folder("OEBPS");
    const css = `
@charset "UTF-8";
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; line-height: 1.6; padding: 1rem; }
h1,h2,h3 { line-height: 1.25; }
h1 { font-size: 1.6rem; margin: 1rem 0 .5rem; }
h2 { font-size: 1.4rem; margin: 1rem 0 .5rem; }
h3 { font-size: 1.2rem; margin: .8rem 0 .4rem; }
p { margin: .6rem 0; }
blockquote { margin: .8rem 1rem; padding-left: .8rem; border-left: 3px solid #ccc; }
.poem { margin: .8rem 0; }
.stanza { margin: .6rem 0; }
img { max-width: 100%; height: auto; }
hr { border: 0; border-top: 1px solid #ddd; margin: 1rem 0; }
    `.trim();
    oebps.file("styles.css", css);

    const lang = (bookMetadata.language || "en").toLowerCase();
    const manifestItems = [
        { id: "css", href: "styles.css", mediaType: "text/css" }
    ];
    const spineItemrefs = [];

    chapters.forEach((ch, idx) => {
        const filename = `chapter-${idx + 1}.xhtml`;
        const xhtml = wrapAsXHTML(ch.title, ch.content, lang);
        oebps.file(filename, xhtml);
        manifestItems.push({
            id: ch.id,
            href: filename,
            mediaType: "application/xhtml+xml"
        });
        spineItemrefs.push({ idref: ch.id });
    });

    updateProgress(60);

    // Сохраняем изображения
    const imagesFolder = oebps.folder("images");
    const usedImageHrefs = new Set();

    chapters.forEach((ch) => {
        const regex = /src="images\/([^"]+)"/g;
        let m;
        while ((m = regex.exec(ch.content)) !== null) {
            usedImageHrefs.add(m[1]);
        }
    });

    const imageKeys = usedImageHrefs.size ? [...usedImageHrefs] : Object.keys(binaries).map((id) => `${id}.${binaries[id].ext}`);

    for (const name of imageKeys) {
        let id, ext;
        if (name.includes(".")) {
            id = name.substring(0, name.lastIndexOf("."));
            ext = name.substring(name.lastIndexOf(".") + 1);
        } else {
            id = name;
            ext = (binaries[id] && binaries[id].ext) || "jpg";
        }
        const bin = binaries[id];
        if (!bin) continue;
        const arrayBuf = base64ToUint8Array(bin.base64);
        imagesFolder.file(`${id}.${ext}`, arrayBuf);
        manifestItems.push({
            id: `img_${id}`,
            href: `images/${id}.${ext}`,
            mediaType: bin.mime
        });
    }

    updateProgress(72);

    // nav.xhtml
    const navXhtml = buildNavXHTML(bookMetadata.title || "Untitled", chapters, lang);
    oebps.file("nav.xhtml", navXhtml);
    manifestItems.push({
        id: "nav",
        href: "nav.xhtml",
        mediaType: "application/xhtml+xml",
        properties: "nav"
    });

    // content.opf
    const uniqueId = "urn:uuid:" + generateUUIDv4();
    const contentOpf = buildContentOpf({
        id: uniqueId,
        title: bookMetadata.title || "Untitled",
        author: bookMetadata.author || "Unknown Author",
        lang: lang,
        date: new Date().toISOString().slice(0, 10),
        manifestItems: manifestItems,
        spineItemrefs: spineItemrefs
    });
    oebps.file("content.opf", contentOpf);

    updateProgress(86);

    // Генерируем EPUB
    return await zip.generateAsync({
        type: "blob",
        compression: "DEFLATE",
        compressionOptions: { level: 9 }
    });
}

// ============================================================
// Вспомогательные функции
// ============================================================

function updateProgress(val) {
    const progressBar = document.getElementById('converterProgressBar');
    if (progressBar) {
        progressBar.style.width = `${val}%`;
        progressBar.textContent = `${val}%`;
    }
}

function textContentDeep(node) {
    if (node.nodeType === 3) return node.nodeValue || "";
    let s = "";
    node.childNodes.forEach((n) => (s += textContentDeep(n)));
    return s;
}

function escapeXML(s) {
    return s
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function serializeInline(node, binaries) {
    if (node.nodeType === 3) {
        return escapeXML(node.nodeValue || "");
    }
    if (node.nodeType !== 1) return "";

    const tag = node.tagName.toLowerCase();
    const children = [...node.childNodes]
        .map((n) => serializeInline(n, binaries))
        .join("");

    switch (tag) {
        case "emphasis": return `<em>${children}</em>`;
        case "strong": return `<strong>${children}</strong>`;
        case "code": return `<code>${children}</code>`;
        case "sub": return `<sub>${children}</sub>`;
        case "sup": return `<sup>${children}</sup>`;
        case "strikethrough": return `<s>${children}</s>`;
        case "a": {
            const href = node.getAttribute("xlink:href") || node.getAttribute("href") || "";
            const safeHref = href.startsWith("#") ? href : escapeXML(href);
            return `<a href="${escapeXML(safeHref)}">${children || escapeXML(node.textContent)}</a>`;
        }
        case "image": {
            const href = (node.getAttribute("xlink:href") || "").replace(/^#/, "");
            if (href && binaries[href]) {
                const ext = binaries[href].ext || "jpg";
                return `<img alt="" src="images/${href}.${ext}" />`;
            }
            return "";
        }
        default:
            return children;
    }
}

function serializeSectionToXHTML(section, binaries) {
    let html = "";
    const nodes = [...section.childNodes];

    const titleNode = section.querySelector(":scope > title");
    if (titleNode) {
        const t = titleNode.querySelector("p")
            ? [...titleNode.querySelectorAll("p")].map((p) => serializeInline(p, binaries)).join(" ")
            : escapeXML(textContentDeep(titleNode).trim());
        html += `<h2>${t}</h2>`;
    }

    for (const node of nodes) {
        if (node.nodeType !== 1) continue;
        const tag = node.tagName.toLowerCase();
        if (tag === "title") continue;

        if (tag === "p") {
            html += `<p>${serializeInline(node, binaries)}</p>`;
        } else if (tag === "subtitle") {
            html += `<h3>${serializeInline(node, binaries)}</h3>`;
        } else if (tag === "epigraph") {
            const inner = [...node.querySelectorAll("p")].map((p) => serializeInline(p, binaries)).join("");
            html += `<blockquote>${inner}</blockquote>`;
        } else if (tag === "cite") {
            const inner = [...node.childNodes].map((n) => serializeInline(n, binaries)).join("");
            html += `<blockquote>${inner}</blockquote>`;
        } else if (tag === "poem") {
            html += `<div class="poem">`;
            const title = node.querySelector(":scope > title");
            if (title) html += `<h3>${serializeInline(title, binaries)}</h3>`;
            node.querySelectorAll(":scope > stanza").forEach((st) => {
                html += `<div class="stanza">`;
                st.querySelectorAll(":scope > v").forEach((v) => {
                    html += `<div>${serializeInline(v, binaries)}</div>`;
                });
                html += `</div>`;
            });
            const author = node.querySelector(":scope > text-author");
            if (author) html += `<div class="text-right italic">${serializeInline(author, binaries)}</div>`;
            html += `</div>`;
        } else if (tag === "empty-line") {
            html += `<hr />`;
        } else if (tag === "image") {
            const href = (node.getAttribute("xlink:href") || "").replace(/^#/, "");
            if (href && binaries[href]) {
                const ext = binaries[href].ext || "jpg";
                html += `<p><img alt="" src="images/${href}.${ext}" /></p>`;
            }
        } else if (tag === "section") {
            html += serializeSectionToXHTML(node, binaries);
        } else {
            html += `<p>${serializeInline(node, binaries)}</p>`;
        }
    }
    return html || "<p></p>";
}

function wrapAsXHTML(title, bodyContent, lang) {
    return `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="${escapeXML(lang)}" lang="${escapeXML(lang)}">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>${escapeXML(title)}</title>
  <link rel="stylesheet" type="text/css" href="styles.css" />
</head>
<body>
  <h1>${escapeXML(title)}</h1>
  ${bodyContent}
</body>
</html>`;
}

function buildNavXHTML(bookTitle, chapters, lang) {
    const lis = chapters.map((ch, i) =>
        `<li><a href="chapter-${i + 1}.xhtml">${escapeXML(ch.title)}</a></li>`
    ).join("\n");
    return `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xmlns:epub="http://www.idpf.org/2007/ops" xml:lang="${escapeXML(lang)}" lang="${escapeXML(lang)}">
<head>
  <meta charset="UTF-8" />
  <title>Table of Contents</title>
  <link rel="stylesheet" type="text/css" href="styles.css" />
</head>
<body>
  <nav epub:type="toc" id="toc">
    <h2>${escapeXML(bookTitle)}</h2>
    <ol>
      ${lis}
    </ol>
  </nav>
</body>
</html>`;
}

function buildContentOpf({ id, title, author, lang, date, manifestItems, spineItemrefs }) {
    const manifestXml = manifestItems.map((it) => {
        const props = it.properties ? ` properties="${it.properties}"` : "";
        return `<item id="${escapeXML(it.id)}" href="${escapeXML(it.href)}" media-type="${escapeXML(it.mediaType)}"${props} />`;
    }).join("\n      ");
    const spineXml = spineItemrefs.map((sr) => `<itemref idref="${escapeXML(sr.idref)}" />`).join("\n      ");
    return `<?xml version="1.0" encoding="UTF-8"?>
<package xmlns="http://www.idpf.org/2007/opf" unique-identifier="pub-id" version="3.0" xml:lang="${escapeXML(lang)}">
  <metadata xmlns:dc="http://purl.org/dc/elements/1.1/">
    <dc:identifier id="pub-id">${escapeXML(id)}</dc:identifier>
    <dc:title>${escapeXML(title)}</dc:title>
    <dc:language>${escapeXML(lang)}</dc:language>
    <dc:creator>${escapeXML(author)}</dc:creator>
    <dc:date>${escapeXML(date)}</dc:date>
    <meta property="dcterms:modified">${new Date().toISOString().replace(/\.\d+Z$/, "Z")}</meta>
  </metadata>
  <manifest>
      ${manifestXml}
  </manifest>
  <spine>
      ${spineXml}
  </spine>
</package>`;
}

function mimeToExt(mime) {
    if (!mime) return "jpg";
    if (mime.includes("jpeg") || mime.includes("jpg")) return "jpg";
    if (mime.includes("png")) return "png";
    if (mime.includes("gif")) return "gif";
    if (mime.includes("svg")) return "svg";
    if (mime.includes("webp")) return "webp";
    return "bin";
}

function base64ToUint8Array(base64) {
    const binaryString = atob(base64);
    const len = binaryString.length;
    const bytes = new Uint8Array(len);
    for (let i = 0; i < len; i++) bytes[i] = binaryString.charCodeAt(i);
    return bytes;
}

function generateUUIDv4() {
    const rnd = crypto.getRandomValues(new Uint8Array(16));
    rnd[6] = (rnd[6] & 0x0f) | 0x40;
    rnd[8] = (rnd[8] & 0x3f) | 0x80;
    const hex = [...rnd].map((b) => b.toString(16).padStart(2, "0")).join("");
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}











</script>

<?php require 'templates/footer.php'; ?>
