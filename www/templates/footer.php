    </div>
    
    <footer class="bg-dark text-light mt-5 py-4">
        <div class="container">
            <div class="row">
                <div class="col-md-8">
                    <h6><?php echo htmlspecialchars(Config::SITE_TITLE); ?></h6>
                    <p class="mb-0">Ваша личная библиотека с поддержкой OPDS</p>
                </div>
                <div class="col-md-4 text-end">
                    <?php
                    // Получаем статистику только если база данных доступна
                    try {
                        $db = Database::getInstance();
                        $stats = $db->getCollectionStats();
                    } catch (Exception $e) {
                        $stats = [
                            'total_books' => 0,
                            'total_authors' => 0,
                            'total_genres' => 0,
                            'total_series' => 0,
                            'last_update' => null
                        ];
                    }
                    ?>
                    
                    <div class="stats">
                        <small>
                            <strong>Коллекция:</strong><br>
                            Книг: <?php echo number_format($stats['total_books'], 0, '', ' '); ?> | 
                            Авторов: <?php echo number_format($stats['total_authors'], 0, '', ' '); ?> | 
                            Жанров: <?php echo number_format($stats['total_genres'], 0, '', ' '); ?>
                            <?php if ($stats['total_series'] > 0): ?>
                                | Серий: <?php echo number_format($stats['total_series'], 0, '', ' '); ?>
                            <?php endif; ?>
                        </small>
                        <?php if ($stats['last_update']): ?>
                            <br>
                            <small>
                                <strong>Обновлено:</strong> 
                                <?php echo date('d.m.Y', strtotime($stats['last_update'])); ?>
                            </small>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <hr class="my-2">
            <div class="row">
                <div class="col-12 text-center">
                    <small>
                        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(Config::SITE_TITLE); ?> | 
                        <a href="./api/opds.php" class="text-light">OPDS-каталог</a> | 
                        Поддержка форматов: EPUB, FB2, PDF, MOBI, TXT
                    </small>
                </div>
            </div>
        </div>
    </footer>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>