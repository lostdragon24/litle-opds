<?php

require_once 'config/config.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$stats = $db->getCollectionStats();
$genres = $db->getGenresWithCount();

require 'templates/header.php';
?>

<div class="row">
    <div class="col-12">
        <h1>Статистика коллекции</h1>
        
        <!-- Основная статистика -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card text-white bg-primary">
                    <div class="card-body text-center">
                        <h4><?php echo number_format($stats['total_books'], 0, '', ' '); ?></h4>
                        <p class="card-text">Книг в коллекции</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-success">
                    <div class="card-body text-center">
                        <h4><?php echo number_format($stats['total_authors'], 0, '', ' '); ?></h4>
                        <p class="card-text">Авторов</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-warning">
                    <div class="card-body text-center">
                        <h4><?php echo number_format($stats['total_genres'], 0, '', ' '); ?></h4>
                        <p class="card-text">Жанров</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-white bg-info">
                    <div class="card-body text-center">
                        <h4><?php echo number_format($stats['total_series'], 0, '', ' '); ?></h4>
                        <p class="card-text">Серий</p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Статистика по жанрам -->
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0">Распределение по жанрам</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <?php 
                    $counter = 0;
                    foreach ($genres as $genre): 
                        if ($counter % 4 === 0 && $counter > 0): ?>
                            </div><div class="row">
                        <?php endif; ?>
                        <div class="col-md-3 mb-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <a href="index.php?field=genre&q=<?php echo urlencode($genre['genre']); ?>" 
                                       class="text-decoration-none">
                                        <?php echo htmlspecialchars($genre['readable_name']); ?>
                                    </a>
                                </span>
                                <span class="badge bg-secondary"><?php echo $genre['count']; ?></span>
                            </div>
                        </div>
                    <?php 
                        $counter++;
                    endforeach; 
                    ?>
                </div>
            </div>
        </div>
        
        <?php if ($stats['last_update']): ?>
            <div class="mt-3 text-end">
                <small class="text-muted">
                    Данные обновлены: <?php echo date('d.m.Y H:i', strtotime($stats['last_update'])); ?>
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require 'templates/footer.php'; ?>