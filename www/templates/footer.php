    </div>
    
    <footer class="bg-dark text-light mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-6">
                    <h6><?php echo htmlspecialchars(Config::SITE_TITLE); ?></h6>
                    <p class="mb-0">Ваша личная библиотека с поддержкой OPDS</p>
                    <small class="text-muted">
                        Версия: 2.0 | 
                        <a href="./cache_stats.php" class="text-light">Статистика кэша</a>
                    </small>
                </div>
                <div class="col-md-6 text-end">
                    <?php
                    // Получаем статистику с кэшированием
                    try {
                        $db = Database::getInstance();
                        $stats = $db->getCollectionStats();
                        
                        // Информация о производительности
                        $load = sys_getloadavg();
                        $memory_usage = memory_get_peak_usage(true);
                        $memory_usage_mb = round($memory_usage / 1024 / 1024, 2);
                        $memory_limit = ini_get('memory_limit');
                        
                    } catch (Exception $e) {
                        $stats = [
                            'total_books' => 0,
                            'total_authors' => 0,
                            'total_genres' => 0,
                            'total_series' => 0,
                            'last_update' => null
                        ];
                        $load = [0, 0, 0];
                        $memory_usage_mb = 0;
                        $memory_limit = 'N/A';
                    }
                    
                    // Информация о кэше если доступна
                    $cache_info = '';
                    if (Config::ENABLE_CACHE && class_exists('Cache')) {
                        try {
                            $cache_stats = Cache::getStats();
                            $total_hits = 0;
                            $total_misses = 0;
                            
                            foreach ($cache_stats as $cache_type => $stat) {
                                $total_hits += $stat['hits'];
                                $total_misses += $stat['misses'];
                            }
                            
                            $total_requests = $total_hits + $total_misses;
                            $cache_hit_rate = $total_requests > 0 ? round(($total_hits / $total_requests) * 100, 1) : 0;
                            
                            $cache_info = " | Кэш: {$cache_hit_rate}%";
                            
                        } catch (Exception $e) {
                            $cache_info = " | Кэш: недоступен";
                        }
                    }
                    ?>
                    
                    <div class="stats">
                        <small>
                            <strong>📊 Коллекция:</strong><br>
                            📚 Книг: <?php echo number_format($stats['total_books'], 0, '', ' '); ?> | 
                            ✍️ Авторов: <?php echo number_format($stats['total_authors'], 0, '', ' '); ?> | 
                            🏷️ Жанров: <?php echo number_format($stats['total_genres'], 0, '', ' '); ?>
                            <?php if ($stats['total_series'] > 0): ?>
                                | 📖 Серий: <?php echo number_format($stats['total_series'], 0, '', ' '); ?>
                            <?php endif; ?>
                        </small>
                        
                        <?php if ($stats['last_update']): ?>
                            <br>
                            <small>
                                <strong>🕒 Обновлено:</strong> 
                                <?php echo date('d.m.Y H:i', strtotime($stats['last_update'])); ?>
                            </small>
                        <?php endif; ?>
                        
                        <!-- Информация о производительности -->
                        <br>
                        <small class="text-muted">
                            <strong>⚡ Производительность:</strong><br>
                            Нагрузка: <?php echo round($load[0], 2); ?> | 
                            Память: <?php echo $memory_usage_mb; ?>MB/<?php echo $memory_limit; ?>
                            <?php echo $cache_info; ?>
                        </small>
                    </div>
                </div>
            </div>
            
            <hr class="my-3">
            
            <div class="row">
                <div class="col-md-8">
                    <small>
                        <strong>🔧 Технологии:</strong>
                        PHP <?php echo PHP_VERSION; ?> | 
                        <?php 
                        if (Config::DB_TYPE === 'sqlite') {
                            echo 'SQLite | ';
                        } elseif (Config::DB_TYPE === 'mysql') {
                            echo 'MySQL | ';
                        } elseif (Config::DB_TYPE === 'pgsql') {
                            echo 'PostgreSQL | ';
                        }
                        
                        if (Config::ENABLE_CACHE) {
                            if (Config::USE_APCU && extension_loaded('apcu') && apcu_enabled()) {
                                echo 'APCu ' . phpversion('apcu') . ' | ';
                            }
                            if (Config::USE_MEMCACHED && extension_loaded('memcached')) {
                                echo 'Memcached | ';
                            }
                        }
                        ?>
                        Bootstrap 5
                    </small>
                </div>
                <div class="col-md-4 text-end">
                    <small>
                        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Config::SITE_TITLE); ?> | 
                        <a href="./api/opds.php" class="text-light" target="_blank">OPDS-каталог</a> | 
                        <a href="./stats.php" class="text-light">Статистика</a>
                        <?php if (Config::ENABLE_CACHE): ?>
                            | <a href="./cache_stats.php" class="text-light">Кэш</a>
                        <?php endif; ?>
                    </small>
                </div>
            </div>
            
            <!-- Дополнительная техническая информация (только для отладки) -->
            <?php if (isset($_GET['debug']) || (isset($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'debug') !== false)): ?>
            <div class="row mt-3">
                <div class="col-12">
                    <div class="card bg-secondary">
                        <div class="card-body p-2">
                            <small class="text-light">
                                <strong>🐛 Отладочная информация:</strong><br>
                                Время выполнения: <?php echo round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3); ?>s | 
                                Запросов к БД: <?php echo isset($GLOBALS['query_count']) ? $GLOBALS['query_count'] : 'N/A'; ?> | 
                                Пиковая память: <?php echo round(memory_get_peak_usage(true) / 1024 / 1024, 2); ?>MB |
                                User Agent: <?php echo substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 50); ?>
                            </small>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </footer>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Ленивая загрузка изображений -->
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Ленивая загрузка обложек
        var lazyImages = [].slice.call(document.querySelectorAll("img.lazy"));
        
        if ("IntersectionObserver" in window) {
            let lazyImageObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        let lazyImage = entry.target;
                        lazyImage.src = lazyImage.dataset.src;
                        lazyImage.classList.remove("lazy");
                        lazyImageObserver.unobserve(lazyImage);
                        
                        // Обработка ошибок загрузки
                        lazyImage.onerror = function() {
                            this.style.display = 'none';
                            var placeholder = this.nextElementSibling;
                            if (placeholder && placeholder.classList.contains('book-cover-placeholder')) {
                                placeholder.style.display = 'flex';
                            }
                        };
                    }
                });
            });
            
            lazyImages.forEach(function(lazyImage) {
                lazyImageObserver.observe(lazyImage);
            });
        } else {
            // Fallback для старых браузеров
            lazyImages.forEach(function(lazyImage) {
                lazyImage.src = lazyImage.dataset.src;
                lazyImage.classList.remove("lazy");
            });
        }
        
        // Обработка ошибок загрузки для обычных изображений
        var coverImages = document.querySelectorAll('.book-cover:not(.lazy)');
        coverImages.forEach(function(img) {
            img.onerror = function() {
                this.style.display = 'none';
                var placeholder = this.nextElementSibling;
                if (placeholder && placeholder.classList.contains('book-cover-placeholder')) {
                    placeholder.style.display = 'flex';
                }
            };
        });
        
        // Показ времени загрузки страницы
        window.addEventListener('load', function() {
            var loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            console.log('Page load time: ' + loadTime + 'ms');
        });
    });
    
    // Функция для предзагрузки обложек при наведении
    function preloadCover(bookId) {
        var img = new Image();
        img.src = './api/cover_direct.php?id=' + bookId + '&thumb=1';
    }
    </script>
    
    <!-- Стили для улучшенного внешнего вида -->
    <style>
    .stats {
        font-size: 0.8rem;
        line-height: 1.3;
    }
    
    footer a {
        transition: color 0.3s ease;
    }
    
    footer a:hover {
        color: #20c997 !important;
    }
    
    /* Адаптивность для мобильных устройств */
    @media (max-width: 768px) {
        footer .text-end {
            text-align: left !important;
            margin-top: 1rem;
        }
        
        .stats {
            font-size: 0.75rem;
        }
    }
    
    /* Анимация для статистики */
    .stats strong {
        background: linear-gradient(45deg, #20c997, #0dcaf0);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    </style>
</body>
</html>