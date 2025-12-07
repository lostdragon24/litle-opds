<?php

class Cache {
    private static $memcached = null;
    private static $apcuEnabled = false;
    private static $memcachedEnabled = false;
    
    public static function init() {
        // Проверяем APCu
        self::$apcuEnabled = Config::USE_APCU && extension_loaded('apcu') && apcu_enabled();
        
        // Инициализируем Memcached
        if (Config::USE_MEMCACHED && extension_loaded('memcached')) {
            self::$memcached = new Memcached();
            self::$memcached->addServer(Config::MEMCACHED_HOST, Config::MEMCACHED_PORT);
            self::$memcachedEnabled = self::$memcached->getVersion() !== false;
        }
        
        if (self::$apcuEnabled) {
            error_log("APCu cache enabled");
        }
        if (self::$memcachedEnabled) {
            error_log("Memcached cache enabled");
        }
    }
    
    /**
     * Получить данные из кэша
     */
    public static function get($key, $type = 'default') {
        if (!Config::ENABLE_CACHE) return null;
        
        $config = Config::CACHE_CONFIG[$type] ?? ['level' => Config::CACHE_LEVEL_APCU, 'ttl' => Config::CACHE_TTL];
        
        // Пробуем APCu first (самый быстрый)
        if (self::$apcuEnabled && in_array($config['level'], [Config::CACHE_LEVEL_APCU, 'default'])) {
            $value = apcu_fetch($key, $success);
            if ($success) {
                return $value;
            }
        }
        
        // Пробуем Memcached
        if (self::$memcachedEnabled && in_array($config['level'], [Config::CACHE_LEVEL_MEMCACHED, 'default'])) {
            $value = self::$memcached->get($key);
            if (self::$memcached->getResultCode() === Memcached::RES_SUCCESS) {
                // Сохраняем в APCu для будущих быстрых обращений
                if (self::$apcuEnabled) {
                    apcu_store($key, $value, min($config['ttl'], 3600)); // APCu max 1 hour
                }
                return $value;
            }
        }
        
        return null;
    }
    
    /**
     * Сохранить данные в кэш
     */
    public static function set($key, $data, $type = 'default') {
        if (!Config::ENABLE_CACHE) return false;
        
        $config = Config::CACHE_CONFIG[$type] ?? ['level' => Config::CACHE_LEVEL_APCU, 'ttl' => Config::CACHE_TTL];
        $ttl = $config['ttl'];
        
        $success = true;
        
        // Сохраняем в APCu
        if (self::$apcuEnabled && in_array($config['level'], [Config::CACHE_LEVEL_APCU, 'default'])) {
            $success = $success && apcu_store($key, $data, min($ttl, 3600));
        }
        
        // Сохраняем в Memcached
        if (self::$memcachedEnabled && in_array($config['level'], [Config::CACHE_LEVEL_MEMCACHED, 'default'])) {
            $success = $success && self::$memcached->set($key, $data, $ttl);
        }
        
        return $success;
    }
    
    /**
     * Удалить данные из кэша
     */
    public static function delete($key) {
        $success = true;
        
        if (self::$apcuEnabled) {
            $success = $success && apcu_delete($key);
        }
        
        if (self::$memcachedEnabled) {
            $success = $success && self::$memcached->delete($key);
        }
        
        return $success;
    }
    
    /**
     * Очистить весь кэш (для админки)
     */
    public static function clear() {
        if (self::$apcuEnabled) {
            apcu_clear_cache();
        }
        
        if (self::$memcachedEnabled) {
            self::$memcached->flush();
        }
        
        // Очищаем файловый кэш
        $files = glob(Config::CACHE_DIR . '/cache_*');
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
    }
    
    /**
     * Получить статистику кэша
     */
    public static function getStats() {
        $stats = [];
        
        if (self::$apcuEnabled) {
            $apcuStats = apcu_cache_info(true);
            $stats['apcu'] = [
                'hits' => $apcuStats['num_hits'],
                'misses' => $apcuStats['num_misses'],
                'memory_usage' => $apcuStats['mem_size'],
                'entries' => $apcuStats['num_entries']
            ];
        }
        
        if (self::$memcachedEnabled) {
            $memcachedStats = self::$memcached->getStats();
            $serverKey = Config::MEMCACHED_HOST . ':' . Config::MEMCACHED_PORT;
            if (isset($memcachedStats[$serverKey])) {
                $s = $memcachedStats[$serverKey];
                $stats['memcached'] = [
                    'hits' => $s['get_hits'],
                    'misses' => $s['get_misses'],
                    'memory_usage' => $s['bytes'],
                    'connections' => $s['curr_connections']
                ];
            }
        }
        
        return $stats;
    }
}

// Инициализируем кэш при загрузке
Cache::init();
?>