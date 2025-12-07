<?php

require_once 'config/config.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$stats = $db->getCollectionStats();
$genres = $db->getGenresWithCount();
$topAuthors = $db->getTopAuthors(20);
$topSeries = $db->getTopSeries(20);

// Получаем статистику кэширования если доступно
$cacheStats = [];
$apcuStats = [];
$systemInfo = [];

try {
    if (Config::ENABLE_CACHE && Config::USE_APCU && extension_loaded('apcu') && apcu_enabled()) {
        $apcuStats = apcu_cache_info(true);
        $cacheStats['apcu'] = [
            'hits' => $apcuStats['num_hits'] ?? 0,
            'misses' => $apcuStats['num_misses'] ?? 0,
            'entries' => $apcuStats['num_entries'] ?? 0,
            'memory_usage' => $apcuStats['mem_size'] ?? 0,
            'effectiveness' => ($apcuStats['num_hits'] + $apcuStats['num_misses']) > 0 ? 
                round($apcuStats['num_hits'] / ($apcuStats['num_hits'] + $apcuStats['num_misses']) * 100, 1) : 0
        ];
    }
    
    // Статистика кэша БД если доступна
    if (method_exists($db, 'getCacheStats')) {
        $dbCacheStats = $db->getCacheStats();
        $cacheStats['database'] = $dbCacheStats;
    }
    
    // Системная информация
    $systemInfo = [
        'load' => sys_getloadavg(),
        'memory_usage' => memory_get_peak_usage(true),
        'memory_limit' => ini_get('memory_limit'),
        'php_version' => PHP_VERSION,
        'db_type' => Config::DB_TYPE,
        'apcu_enabled' => extension_loaded('apcu') && apcu_enabled(),
        'memcached_enabled' => extension_loaded('memcached') && Config::USE_MEMCACHED
    ];
    
} catch (Exception $e) {
    error_log("Error getting cache stats: " . $e->getMessage());
}

// Обработка действий
$action = $_GET['action'] ?? '';
$message = '';

if ($action === 'clear_cache' && Config::ENABLE_CACHE) {
    try {
        if (extension_loaded('apcu') && apcu_enabled()) {
            apcu_clear_cache();
        }
        if (method_exists($db, 'clearCache')) {
            $db->clearCache();
        }
        $message = 'success:Кэш успешно очищен!';
    } catch (Exception $e) {
        $message = 'danger:Ошибка при очистке кэша: ' . $e->getMessage();
    }
}

if ($action === 'optimize_db') {
    try {
        if (method_exists($db, 'optimizeDatabase')) {
            $db->optimizeDatabase();
            $message = 'success:База данных оптимизирована!';
        }
    } catch (Exception $e) {
        $message = 'danger:Ошибка при оптимизации БД: ' . $e->getMessage();
    }
}

require 'templates/header.php';

// Показываем сообщения
if ($message) {
    list($type, $text) = explode(':', $message, 2);
    echo '<div class="alert alert-' . htmlspecialchars($type) . ' alert-dismissible fade show" role="alert">
            ' . htmlspecialchars($text) . '
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
          </div>';
}
?>

<div class="row">
    <div class="col-12">
        <h1 class="mb-4">📊 Статистика коллекции</h1>
        
        <!-- Панель управления -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <h5 class="card-title">⚙️ Управление системой</h5>
                        <p class="card-text text-muted">Операции обслуживания и мониторинг</p>
                    </div>
                    <div class="col-md-4 text-end">
                        <div class="btn-group" role="group">
                            <?php if (Config::ENABLE_CACHE): ?>
                                <a href="?action=clear_cache" class="btn btn-warning btn-sm" 
                                   onclick="return confirm('Очистить весь кэш?')">
                                    🗑️ Очистить кэш
                                </a>
                            <?php endif; ?>
                            <a href="?action=optimize_db" class="btn btn-info btn-sm"
                               onclick="return confirm('Оптимизировать базу данных?')">
                                🔧 Оптимизировать БД
                            </a>
                            <a href="cache_stats.php" class="btn btn-outline-secondary btn-sm">
                                📈 Подробная статистика
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Основная статистика -->
        <div class="row mb-4">
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-primary h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon mb-2" style="font-size: 2rem;">📚</div>
                        <h3 class="card-title"><?php echo number_format($stats['total_books'], 0, '', ' '); ?></h3>
                        <p class="card-text">Книг в коллекции</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-success h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon mb-2" style="font-size: 2rem;">✍️</div>
                        <h3 class="card-title"><?php echo number_format($stats['total_authors'], 0, '', ' '); ?></h3>
                        <p class="card-text">Авторов</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-warning h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon mb-2" style="font-size: 2rem;">🏷️</div>
                        <h3 class="card-title"><?php echo number_format($stats['total_genres'], 0, '', ' '); ?></h3>
                        <p class="card-text">Жанров</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 mb-3">
                <div class="card text-white bg-info h-100">
                    <div class="card-body text-center">
                        <div class="stats-icon mb-2" style="font-size: 2rem;">📖</div>
                        <h3 class="card-title"><?php echo number_format($stats['total_series'], 0, '', ' '); ?></h3>
                        <p class="card-text">Серий</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Левая колонка -->
            <div class="col-md-8">
                <!-- Статистика по форматам -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">📁 Распределение по форматам</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Формат</th>
                                        <th>Количество</th>
                                        <th>Процент</th>
                                        <th>Прогресс</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $totalBooks = $stats['total_books'];
                                    foreach ($stats['file_types'] as $fileType): 
                                        $percentage = $totalBooks > 0 ? round(($fileType['count'] / $totalBooks) * 100, 1) : 0;
                                        $progressWidth = min($percentage, 100);
                                    ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo strtoupper($fileType['file_type']); ?></span>
                                        </td>
                                        <td><?php echo number_format($fileType['count'], 0, '', ' '); ?></td>
                                        <td><?php echo $percentage; ?>%</td>
                                        <td style="width: 200px;">
                                            <div class="progress" style="height: 20px;">
                                                <div class="progress-bar" 
                                                     role="progressbar" 
                                                     style="width: <?php echo $progressWidth; ?>%"
                                                     aria-valuenow="<?php echo $progressWidth; ?>" 
                                                     aria-valuemin="0" 
                                                     aria-valuemax="100">
                                                    <?php if ($progressWidth > 15): ?>
                                                        <?php echo $percentage; ?>%
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Топ авторов -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">👑 Топ авторов</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach (array_slice($topAuthors, 0, 10) as $index => $author): ?>
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex justify-content-between align-items-center p-2 border rounded">
                                        <div>
                                            <span class="badge bg-primary me-2">#<?php echo $index + 1; ?></span>
                                            <a href="index.php?field=author&q=<?php echo urlencode($author['author']); ?>" 
                                               class="text-decoration-none">
                                                <?php echo htmlspecialchars($author['author']); ?>
                                            </a>
                                        </div>
                                        <span class="badge bg-secondary"><?php echo $author['count']; ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Правая колонка -->
            <div class="col-md-4">
                <!-- Системная информация -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">⚙️ Системная информация</h5>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <strong>📊 Производительность:</strong><br>
                            <small class="text-muted">
                                Нагрузка: <?php echo round($systemInfo['load'][0] ?? 0, 2); ?><br>
                                Память: <?php echo round(($systemInfo['memory_usage'] ?? 0) / 1024 / 1024, 2); ?>MB / <?php echo $systemInfo['memory_limit'] ?? 'N/A'; ?><br>
                                PHP: <?php echo $systemInfo['php_version'] ?? 'N/A'; ?><br>
                                БД: <?php echo strtoupper($systemInfo['db_type'] ?? 'N/A'); ?>
                            </small>
                        </div>

                        <div class="mb-3">
                            <strong>🔧 Кэширование:</strong><br>
                            <small class="text-muted">
                                APCu: <?php echo $systemInfo['apcu_enabled'] ? '✅ Включен' : '❌ Выключен'; ?><br>
                                Memcached: <?php echo $systemInfo['memcached_enabled'] ? '✅ Включен' : '❌ Выключен'; ?>
                            </small>
                        </div>

                        <?php if ($stats['books_in_archives'] > 0): ?>
                        <div class="mb-3">
                            <strong>📦 Архивы:</strong><br>
                            <small class="text-muted">
                                Книг в архивах: <?php echo number_format($stats['books_in_archives'], 0, '', ' '); ?>
                            </small>
                        </div>
                        <?php endif; ?>

                        <?php if ($stats['last_update']): ?>
                        <div>
                            <strong>🕒 Обновление:</strong><br>
                            <small class="text-muted">
                                <?php echo date('d.m.Y H:i', strtotime($stats['last_update'])); ?>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Статистика кэширования -->
                <?php if (!empty($cacheStats) && Config::ENABLE_CACHE): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">📈 Кэширование</h5>
                    </div>
                    <div class="card-body">
                        <?php if (isset($cacheStats['apcu'])): ?>
                        <div class="mb-3">
                            <strong>APCu Cache:</strong><br>
                            <small class="text-muted">
                                Попадания: <?php echo number_format($cacheStats['apcu']['hits'], 0, '', ' '); ?><br>
                                Промахи: <?php echo number_format($cacheStats['apcu']['misses'], 0, '', ' '); ?><br>
                                Эффективность: <span class="fw-bold"><?php echo $cacheStats['apcu']['effectiveness']; ?>%</span><br>
                                Записей: <?php echo number_format($cacheStats['apcu']['entries'], 0, '', ' '); ?><br>
                                Память: <?php echo round($cacheStats['apcu']['memory_usage'] / 1024 / 1024, 2); ?> MB
                            </small>
                        </div>
                        <?php endif; ?>

                        <?php if (isset($cacheStats['database'])): ?>
                        <div>
                            <strong>Database Cache:</strong><br>
                            <small class="text-muted">
                                Попадания: <?php echo number_format($cacheStats['database']['hits'], 0, '', ' '); ?><br>
                                Промахи: <?php echo number_format($cacheStats['database']['misses'], 0, '', ' '); ?><br>
                                Эффективность: <span class="fw-bold"><?php echo $cacheStats['database']['effectiveness']; ?>%</span>
                            </small>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Топ серий -->
                <?php if (!empty($topSeries)): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">📖 Популярные серии</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach (array_slice($topSeries, 0, 5) as $series): ?>
                            <div class="mb-2">
                                <div class="d-flex justify-content-between align-items-center">
                                    <a href="index.php?field=series&q=<?php echo urlencode($series['series']); ?>" 
                                       class="text-decoration-none small">
                                        <?php echo htmlspecialchars($series['series']); ?>
                                    </a>
                                    <span class="badge bg-secondary"><?php echo $series['count']; ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Распределение по жанрам -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">🏷️ Распределение по жанрам</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php 
                    $counter = 0;
                    $totalGenres = count($genres);
                    $halfGenres = ceil($totalGenres / 2);
                    
                    $firstColumn = array_slice($genres, 0, $halfGenres);
                    $secondColumn = array_slice($genres, $halfGenres);
                    ?>
                    
                    <div class="col-md-6">
                        <?php foreach ($firstColumn as $genre): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                <div>
                                    <a href="index.php?field=genre&q=<?php echo urlencode($genre['genre']); ?>" 
                                       class="text-decoration-none">
                                        <?php echo htmlspecialchars($genre['readable_name'] ?? $genre['genre']); ?>
                                    </a>
                                </div>
                                <div>
                                    <span class="badge bg-primary"><?php echo $genre['count']; ?></span>
                                    <small class="text-muted ms-1">
                                        (<?php echo $totalBooks > 0 ? round(($genre['count'] / $totalBooks) * 100, 1) : 0; ?>%)
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="col-md-6">
                        <?php foreach ($secondColumn as $genre): ?>
                            <div class="d-flex justify-content-between align-items-center mb-2 p-2 border-bottom">
                                <div>
                                    <a href="index.php?field=genre&q=<?php echo urlencode($genre['genre']); ?>" 
                                       class="text-decoration-none">
                                        <?php echo htmlspecialchars($genre['readable_name'] ?? $genre['genre']); ?>
                                    </a>
                                </div>
                                <div>
                                    <span class="badge bg-primary"><?php echo $genre['count']; ?></span>
                                    <small class="text-muted ms-1">
                                        (<?php echo $totalBooks > 0 ? round(($genre['count'] / $totalBooks) * 100, 1) : 0; ?>%)
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <?php if ($totalGenres > 20): ?>
                <div class="mt-3 text-center">
                    <small class="text-muted">
                        Показано <?php echo $totalGenres; ?> жанров из <?php echo $totalGenres; ?>
                    </small>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Время генерации -->
        <div class="mt-3 text-end">
            <small class="text-muted">
                Страница сгенерирована за <?php echo round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3); ?> сек.
                <?php if (method_exists($db, 'getQueryCount')): ?>
                    | Запросов к БД: <?php echo $db->getQueryCount(); ?>
                <?php endif; ?>
            </small>
        </div>
    </div>
</div>

<!-- Стили для улучшения внешнего вида -->
<style>
.stats-icon {
    opacity: 0.9;
}

.card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.progress {
    background-color: #e9ecef;
    border-radius: 10px;
}

.progress-bar {
    border-radius: 10px;
    transition: width 0.6s ease;
}

.table tbody tr:hover {
    background-color: rgba(0,0,0,0.02);
}

.badge {
    font-size: 0.75em;
}

.alert {
    border: none;
    border-radius: 10px;
}

.btn-group .btn {
    border-radius: 6px;
    margin: 0 2px;
}
</style>

<script>
// Автообновление страницы каждые 5 минут для актуальной статистики
setTimeout(function() {
    window.location.reload();
}, 300000); // 5 минут

// Плавная прокрутка к якорям
document.addEventListener('DOMContentLoaded', function() {
    const links = document.querySelectorAll('a[href^="#"]');
    links.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const target = document.querySelector(this.getAttribute('href'));
            if (target) {
                target.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        });
    });
});

// Подсветка эффективных показателей
document.addEventListener('DOMContentLoaded', function() {
    const effectivenessElements = document.querySelectorAll('small .fw-bold');
    effectivenessElements.forEach(el => {
        const value = parseFloat(el.textContent);
        if (value >= 90) {
            el.classList.add('text-success');
        } else if (value >= 70) {
            el.classList.add('text-warning');
        } else {
            el.classList.add('text-danger');
        }
    });
});
</script>

<?php require 'templates/footer.php'; ?>