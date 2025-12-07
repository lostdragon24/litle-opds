<?php
require_once 'config/config.php';
require_once 'lib/Cache.php';
require_once 'lib/Database.php';

$db = Database::getInstance();
$cacheStats = Cache::getStats();
$dbStats = $db->getCollectionStats();

require 'templates/header.php';
?>

<div class="container">
    <h1>Статистика кэширования</h1>
    
    <div class="row">
        <!-- Статистика APCu -->
        <?php if (isset($cacheStats['apcu'])): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">APCu Cache</h5>
                </div>
                <div class="card-body">
                    <?php $s = $cacheStats['apcu']; ?>
                    <p>Попадания: <strong><?php echo number_format($s['hits']); ?></strong></p>
                    <p>Промахи: <strong><?php echo number_format($s['misses']); ?></strong></p>
                    <p>Эффективность: <strong><?php echo $s['hits'] + $s['misses'] > 0 ? round($s['hits'] / ($s['hits'] + $s['misses']) * 100, 1) : 0; ?>%</strong></p>
                    <p>Записей: <strong><?php echo $s['entries']; ?></strong></p>
                    <p>Память: <strong><?php echo round($s['memory_usage'] / 1024 / 1024, 2); ?> MB</strong></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Статистика Memcached -->
        <?php if (isset($cacheStats['memcached'])): ?>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Memcached</h5>
                </div>
                <div class="card-body">
                    <?php $s = $cacheStats['memcached']; ?>
                    <p>Попадания: <strong><?php echo number_format($s['hits']); ?></strong></p>
                    <p>Промахи: <strong><?php echo number_format($s['misses']); ?></strong></p>
                    <p>Эффективность: <strong><?php echo $s['hits'] + $s['misses'] > 0 ? round($s['hits'] / ($s['hits'] + $s['misses']) * 100, 1) : 0; ?>%</strong></p>
                    <p>Подключения: <strong><?php echo $s['connections']; ?></strong></p>
                    <p>Память: <strong><?php echo round($s['memory_usage'] / 1024 / 1024, 2); ?> MB</strong></p>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="mt-3">
        <a href="?action=clear_cache" class="btn btn-warning">Очистить кэш</a>
        <a href="index.php" class="btn btn-secondary">На главную</a>
    </div>
    
    <?php
    if (isset($_GET['action']) && $_GET['action'] === 'clear_cache') {
        Cache::clear();
        echo '<div class="alert alert-success mt-3">Кэш очищен!</div>';
    }
    ?>
</div>

<?php require 'templates/footer.php'; ?>