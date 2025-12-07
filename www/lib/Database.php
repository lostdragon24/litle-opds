<?php

require_once __DIR__ . '/../config/config.php';

class Database {
    private $pdo;
    private static $instance = null;
    private $queryCount = 0;
    private $cacheHits = 0;
    private $cacheMisses = 0;
    
    private function __construct() {
        try {
            switch (Config::DB_TYPE) {
                case 'sqlite':
                    $dsn = 'sqlite:' . Config::DB_PATH;
                    $this->pdo = new PDO($dsn);
                    // Оптимизации для SQLite на Raspberry Pi
                    $this->pdo->exec('PRAGMA journal_mode = WAL');
                    $this->pdo->exec('PRAGMA synchronous = NORMAL');
                    $this->pdo->exec('PRAGMA cache_size = -64000');
                    $this->pdo->exec('PRAGMA temp_store = memory');
                    break;
                case 'mysql':
                    $dsn = 'mysql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME . ';charset=utf8mb4';
                    $this->pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS, [
                        PDO::ATTR_PERSISTENT => false,
                        PDO::ATTR_TIMEOUT => 30,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                    ]);
                    break;
                case 'pgsql':
                    $dsn = 'pgsql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME;
                    $this->pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS);
                    break;
                default:
                    throw new Exception('Unsupported database type');
            }
            
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->pdo;
    }
    
    /**
     * Универсальный метод для выполнения запросов с параметрами
     */
    private function executeQuery($sql, $params = []) {
        $this->queryCount++;
        
        if (Config::PERFORMANCE['enable_query_logging']) {
            error_log("DB Query: " . $sql . " | Params: " . json_encode($params));
        }
        
        $startTime = microtime(true);
        
        try {
            $stmt = $this->pdo->prepare($sql);
            
            // Привязываем параметры с правильными типами
            foreach ($params as $index => $value) {
                $paramType = PDO::PARAM_STR;
                if (is_int($value)) {
                    $paramType = PDO::PARAM_INT;
                } elseif (is_bool($value)) {
                    $paramType = PDO::PARAM_BOOL;
                } elseif (is_null($value)) {
                    $paramType = PDO::PARAM_NULL;
                }
                
                $stmt->bindValue($index + 1, $value, $paramType);
            }
            
            $stmt->execute();
            
            $queryTime = microtime(true) - $startTime;
            if ($queryTime > 1.0 && Config::PERFORMANCE['enable_query_logging']) {
                error_log("Slow query (" . round($queryTime, 3) . "s): " . $sql);
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            error_log("Query failed: " . $sql . " | Error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Получить количество выполненных запросов (для отладки)
     */
    public function getQueryCount() {
        return $this->queryCount;
    }
    
    /**
     * Умное кэширование - только для данных, которые редко меняются
     */
    private function getCached($key, $type = 'default') {
        if (!Config::ENABLE_CACHE || !Config::USE_APCU) {
            return null;
        }
        
        if (extension_loaded('apcu') && apcu_enabled()) {
            $value = apcu_fetch($key, $success);
            if ($success) {
                $this->cacheHits++;
                return $value;
            }
        }
        
        $this->cacheMisses++;
        return null;
    }
    
    private function setCached($key, $data, $type = 'default') {
        if (!Config::ENABLE_CACHE || !Config::USE_APCU) {
            return false;
        }
        
        $config = Config::CACHE_CONFIG[$type] ?? ['ttl' => Config::CACHE_TTL];
        $ttl = $config['ttl'];
        
        if (extension_loaded('apcu') && apcu_enabled()) {
            return apcu_store($key, $data, $ttl);
        }
        
        return false;
    }
    
    /**
     * Получить статистику кэширования
     */
    public function getCacheStats() {
        return [
            'hits' => $this->cacheHits,
            'misses' => $this->cacheMisses,
            'effectiveness' => ($this->cacheHits + $this->cacheMisses) > 0 ? 
                round($this->cacheHits / ($this->cacheHits + $this->cacheMisses) * 100, 1) : 0
        ];
    }
    
    // === ОСНОВНЫЕ МЕТОДЫ ДОСТУПА К ДАННЫМ ===
    
    /**
     * Поиск книг - оптимизированная версия
     */
    public function searchBooks($query, $field = 'all', $page = 1, $perPage = null) {
        if ($perPage === null) {
            $perPage = Config::ITEMS_PER_PAGE;
        }
        
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        $params = [];
        
        $sql = "SELECT * FROM books WHERE 1=1";
        
        if (!empty($query)) {
            switch ($field) {
                case 'author':
                    $sql .= " AND author LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'title':
                    $sql .= " AND title LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'genre':
                    $sql .= " AND genre LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'series':
                    $sql .= " AND series LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'all':
                default:
                    $sql .= " AND (title LIKE ? OR author LIKE ? OR genre LIKE ? OR series LIKE ?)";
                    $params[] = "%$query%";
                    $params[] = "%$query%";
                    $params[] = "%$query%";
                    $params[] = "%$query%";
                    break;
            }
        }
        
        $sql .= " ORDER BY author, title LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить книгу по ID
     */
    public function getBook($id) {
        $stmt = $this->executeQuery("SELECT * FROM books WHERE id = ?", [$id]);
        return $stmt->fetch();
    }
    
    /**
     * Получить последние добавленные книги
     */
    public function getRecentBooks($limit = 10, $offset = 0) {
        $limit = min((int)$limit, 100);
        $offset = (int)$offset;
        
        $sql = "SELECT * FROM books ORDER BY added_date DESC LIMIT ? OFFSET ?";
        $stmt = $this->executeQuery($sql, [$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить количество книг для поиска
     */
    public function getSearchCount($query, $field = 'all') {
        $params = [];
        $sql = "SELECT COUNT(*) as count FROM books WHERE 1=1";
        
        if (!empty($query)) {
            switch ($field) {
                case 'author':
                    $sql .= " AND author LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'title':
                    $sql .= " AND title LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'genre':
                    $sql .= " AND genre LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'series':
                    $sql .= " AND series LIKE ?";
                    $params[] = "%$query%";
                    break;
                case 'all':
                default:
                    $sql .= " AND (title LIKE ? OR author LIKE ? OR genre LIKE ? OR series LIKE ?)";
                    $params[] = "%$query%";
                    $params[] = "%$query%";
                    $params[] = "%$query%";
                    $params[] = "%$query%";
                    break;
            }
        }
        
        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetch();
        $count = $result['count'];
        
        $maxCount = Config::PERFORMANCE['max_search_results'];
        if ($count > $maxCount) {
            $count = $maxCount;
        }
        
        return $count;
    }
    
    /**
     * Получить все авторы
     */
    public function getAuthors() {
        $stmt = $this->executeQuery(
            "SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' ORDER BY author LIMIT 5000"
        );
        return $stmt->fetchAll();
    }
    
    /**
     * Получить все жанры
     */
    public function getGenres() {
        $stmt = $this->executeQuery(
            "SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre LIMIT 1000"
        );
        return $stmt->fetchAll();
    }
    
    /**
     * Получить все серии
     */
    public function getSeries() {
        $stmt = $this->executeQuery(
            "SELECT DISTINCT series FROM books WHERE series IS NOT NULL AND series != '' ORDER BY series LIMIT 5000"
        );
        return $stmt->fetchAll();
    }
    
    /**
     * Получить статистику коллекции
     */
    public function getCollectionStats() {
        $stats = [];
        
        // Общее количество книг
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books");
        $result = $stmt->fetch();
        $stats['total_books'] = $result['count'];
        
        // Количество авторов
        $stmt = $this->executeQuery(
            "SELECT COUNT(DISTINCT author) as count FROM books WHERE author IS NOT NULL AND author != ''"
        );
        $result = $stmt->fetch();
        $stats['total_authors'] = $result['count'];
        
        // Количество жанров
        $stmt = $this->executeQuery(
            "SELECT COUNT(DISTINCT genre) as count FROM books WHERE genre IS NOT NULL AND genre != ''"
        );
        $result = $stmt->fetch();
        $stats['total_genres'] = $result['count'];
        
        // Количество серий
        $stmt = $this->executeQuery(
            "SELECT COUNT(DISTINCT series) as count FROM books WHERE series IS NOT NULL AND series != ''"
        );
        $result = $stmt->fetch();
        $stats['total_series'] = $result['count'];
        
        // Последнее обновление
        $stmt = $this->executeQuery("SELECT MAX(added_date) as last_update FROM books");
        $result = $stmt->fetch();
        $stats['last_update'] = $result['last_update'];
        
        // Статистика по форматам файлов
        $stmt = $this->executeQuery("
            SELECT file_type, COUNT(*) as count 
            FROM books 
            WHERE file_type IS NOT NULL 
            GROUP BY file_type 
            ORDER BY count DESC
            LIMIT 10
        ");
        $stats['file_types'] = $stmt->fetchAll();
        
        // Книги в архивах
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE archive_path IS NOT NULL");
        $result = $stmt->fetch();
        $stats['books_in_archives'] = $result['count'];
        
        return $stats;
    }
    
    /**
     * Преобразовать жанр FB2 в читаемое название
     */
    public function getReadableGenre($genre) {
        if (empty($genre)) {
            return null;
        }
        
        if (in_array($genre, array_values(Config::FB2_GENRES))) {
            return $genre;
        }
        
        if (isset(Config::FB2_GENRES[$genre])) {
            return Config::FB2_GENRES[$genre];
        }
        
        return ucfirst(str_replace('_', ' ', $genre));
    }
    
    /**
     * Получить все жанры с их частотой и читаемыми названиями
     */
    public function getGenresWithCount() {
        $stmt = $this->executeQuery("
            SELECT genre, COUNT(*) as count 
            FROM books 
            WHERE genre IS NOT NULL AND genre != '' 
            GROUP BY genre 
            ORDER BY count DESC, genre
            LIMIT 100
        ");
        $genres = $stmt->fetchAll();
        
        foreach ($genres as &$genre) {
            $genre['readable_name'] = $this->getReadableGenre($genre['genre']);
        }
        
        return $genres;
    }
    
    /**
     * Получить топ авторов
     */
    public function getTopAuthors($limit = 20) {
        $limit = min((int)$limit, 100);
        
        $stmt = $this->executeQuery("
            SELECT author, COUNT(*) as count 
            FROM books 
            WHERE author IS NOT NULL AND author != '' 
            GROUP BY author 
            ORDER BY count DESC, author
            LIMIT ?
        ", [$limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить топ серий
     */
    public function getTopSeries($limit = 20) {
        $limit = min((int)$limit, 100);
        
        $stmt = $this->executeQuery("
            SELECT series, COUNT(*) as count 
            FROM books 
            WHERE series IS NOT NULL AND series != '' 
            GROUP BY series 
            ORDER BY count DESC, series
            LIMIT ?
        ", [$limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить книги по автору с пагинацией
     */
    public function getBooksByAuthor($author, $page = 1, $perPage = 20) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            WHERE author = ? 
            ORDER BY series, series_number, title
            LIMIT ? OFFSET ?
        ", [$author, $perPage, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить количество книг по автору
     */
    public function getBooksCountByAuthor($author) {
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE author = ?", [$author]);
        $result = $stmt->fetch();
        return $result['count'];
    }
    
    /**
     * Получить книги по жанру с пагинацией
     */
    public function getBooksByGenre($genre, $page = 1, $perPage = 20) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            WHERE genre = ? 
            ORDER BY author, title
            LIMIT ? OFFSET ?
        ", [$genre, $perPage, $offset]);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить количество книг по жанру
     */
    public function getBooksCountByGenre($genre) {
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE genre = ?", [$genre]);
        $result = $stmt->fetch();
        return $result['count'];
    }
    
    /**
     * Получить случайные книги
     */
    public function getRandomBooks($limit = 10) {
        $limit = min((int)$limit, 50);
        
        if (Config::DB_TYPE === 'mysql') {
            $randomFunc = 'RAND()';
        } else {
            $randomFunc = 'RANDOM()';
        }
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            ORDER BY {$randomFunc} 
            LIMIT ?
        ", [$limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Проверить существование книги
     */
    public function bookExists($filePath, $archivePath = null, $internalPath = null) {
        $sql = "SELECT id FROM books WHERE file_path = ?";
        $params = [$filePath];
        
        if ($archivePath) {
            $sql .= " AND archive_path = ?";
            $params[] = $archivePath;
        } else {
            $sql .= " AND archive_path IS NULL";
        }
        
        if ($internalPath) {
            $sql .= " AND archive_internal_path = ?";
            $params[] = $internalPath;
        } else {
            $sql .= " AND archive_internal_path IS NULL";
        }
        
        $sql .= " LIMIT 1";
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Получить общее количество книг
     */
    public function getTotalBooksCount() {
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books");
        $result = $stmt->fetch();
        return $result['count'];
    }
    
    /**
     * Получить книги с пагинацией
     */
    public function getBooksWithPagination($page = 1, $perPage = 20, $orderBy = 'added_date', $orderDir = 'DESC') {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = min((int)$perPage, 100);
        
        $allowedOrders = ['added_date', 'title', 'author', 'year'];
        $allowedDirs = ['ASC', 'DESC'];
        
        $orderBy = in_array($orderBy, $allowedOrders) ? $orderBy : 'added_date';
        $orderDir = in_array($orderDir, $allowedDirs) ? $orderDir : 'DESC';
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            ORDER BY {$orderBy} {$orderDir}
            LIMIT ? OFFSET ?
        ", [$perPage, $offset]);
        return $stmt->fetchAll();
    }
    

public function getBooksBySeries($series, $page = 1, $perPage = 20) {
    $offset = (int)(($page - 1) * $perPage);
    $perPage = min((int)$perPage, 100);
    
    $stmt = $this->executeQuery("
        SELECT * FROM books 
        WHERE series = ? 
        ORDER BY series_number, title
        LIMIT ? OFFSET ?
    ", [$series, $perPage, $offset]);
    return $stmt->fetchAll();
}


/**
 * Получить количество книг по серии
 */
public function getBooksCountBySeries($series) {
    $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE series = ?", [$series]);
    $result = $stmt->fetch();
    return $result['count'];
}


    /**
     * Очистить кэш (для админки)
     */
    public function clearCache() {
        if (extension_loaded('apcu') && apcu_enabled()) {
            apcu_clear_cache();
            $this->cacheHits = 0;
            $this->cacheMisses = 0;
        }
        return true;
    }
}

// Сохраняем счетчик запросов в глобальную переменную для футера
$GLOBALS['query_count'] = 0;
?>