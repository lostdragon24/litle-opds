<?php

require_once __DIR__ . '/../config/config.php';

class Database {
    private $pdo;
    private static $instance = null;
    
    private function __construct() {
        try {
            switch (Config::DB_TYPE) {
                case 'sqlite':
                    $dsn = 'sqlite:' . Config::DB_PATH;
                    $this->pdo = new PDO($dsn);
                    break;
                case 'mysql':
                    $dsn = 'mysql:host=' . Config::DB_HOST . ';dbname=' . Config::DB_NAME . ';charset=utf8';
                    $this->pdo = new PDO($dsn, Config::DB_USER, Config::DB_PASS);
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
            
            // Для SQLite включаем foreign keys
            if (Config::DB_TYPE === 'sqlite') {
                $this->pdo->exec('PRAGMA foreign_keys = ON');
            }
            
        } catch (PDOException $e) {
            die('Database connection failed: ' . $e->getMessage());
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
        return $stmt;
    }
    
    // Поиск книг
    public function searchBooks($query, $field = 'all', $page = 1, $perPage = Config::ITEMS_PER_PAGE) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = (int)$perPage;
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
    
    // Получить книгу по ID
    public function getBook($id) {
        $stmt = $this->executeQuery("SELECT * FROM books WHERE id = ?", [$id]);
        return $stmt->fetch();
    }
    
    // Получить последние добавленные книги (с пагинацией)
    public function getRecentBooks($limit = 10, $offset = 0) {
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        $sql = "SELECT * FROM books ORDER BY added_date DESC LIMIT ? OFFSET ?";
        $stmt = $this->executeQuery($sql, [$limit, $offset]);
        return $stmt->fetchAll();
    }
    
    // Получить количество книг для поиска
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
        return $result['count'];
    }
    
    // Получить все авторы
    public function getAuthors() {
        $stmt = $this->executeQuery(
            "SELECT DISTINCT author FROM books WHERE author IS NOT NULL AND author != '' ORDER BY author"
        );
        return $stmt->fetchAll();
    }
    
    // Получить все жанры
    public function getGenres() {
        $stmt = $this->executeQuery(
            "SELECT DISTINCT genre FROM books WHERE genre IS NOT NULL AND genre != '' ORDER BY genre"
        );
        return $stmt->fetchAll();
    }
    
    // Получить все серии
    public function getSeries() {
        $stmt = $this->executeQuery(
            "SELECT DISTINCT series FROM books WHERE series IS NOT NULL AND series != '' ORDER BY series"
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
        ");
        $stats['file_types'] = $stmt->fetchAll();
        
        // Книги в архивах
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books WHERE archive_path IS NOT NULL");
        $result = $stmt->fetch();
        $stats['books_in_archives'] = $result['count'];
        
        return $stats;
    }
    
    /**
     * Преобразовать жанр FB2 в читаемое название используя маппинг из config.php
     */
    public function getReadableGenre($genre) {
        if (empty($genre)) {
            return null;
        }
        
        // Если жанр уже на русском, возвращаем как есть
        if (in_array($genre, array_values(Config::FB2_GENRES))) {
            return $genre;
        }
        
        // Ищем в маппинге FB2 жанров из config.php
        if (isset(Config::FB2_GENRES[$genre])) {
            return Config::FB2_GENRES[$genre];
        }
        
        // Если не нашли в маппинге, возвращаем оригинальное название
        // с первой заглавной буквой и заменой подчеркиваний на пробелы
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
        ");
        $genres = $stmt->fetchAll();
        
        // Преобразуем названия жанров через маппинг из config.php
        foreach ($genres as &$genre) {
            $genre['readable_name'] = $this->getReadableGenre($genre['genre']);
        }
        
        return $genres;
    }
    
    /**
     * Получить топ авторов
     */
    public function getTopAuthors($limit = 20) {
        $limit = (int)$limit;
        
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
        $limit = (int)$limit;
        
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
        $perPage = (int)$perPage;
        
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
        $perPage = (int)$perPage;
        
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
     * Получить книги по серии с пагинацией
     */
    public function getBooksBySeries($series, $page = 1, $perPage = 20) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = (int)$perPage;
        
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
     * Получить случайные книги
     */
    public function getRandomBooks($limit = 10) {
        $limit = (int)$limit;
        
        // Для MySQL используем RAND(), для SQLite - RANDOM()
        $randomFunc = Config::DB_TYPE === 'mysql' ? 'RAND()' : 'RANDOM()';
        
        $stmt = $this->executeQuery("
            SELECT * FROM books 
            ORDER BY {$randomFunc} 
            LIMIT ?
        ", [$limit]);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить книги, добавленные за последние N дней
     */
    public function getRecentBooksByDays($days = 5) {
        $days = (int)$days;
        
        // Для разных СУБД разный синтаксис дат
        if (Config::DB_TYPE === 'mysql') {
            $stmt = $this->executeQuery("
                SELECT * FROM books 
                WHERE added_date >= DATE_SUB(NOW(), INTERVAL ? DAY) 
                ORDER BY added_date DESC
            ", [$days]);
        } else {
            $stmt = $this->executeQuery("
                SELECT * FROM books 
                WHERE added_date >= datetime('now', '-? days') 
                ORDER BY added_date DESC
            ", [$days]);
        }
        
        return $stmt->fetchAll();
    }
    
    /**
     * Поиск по нескольким полям с пагинацией
     */
    public function advancedSearch($conditions, $page = 1, $perPage = Config::ITEMS_PER_PAGE) {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = (int)$perPage;
        $params = [];
        $sql = "SELECT * FROM books WHERE 1=1";
        
        if (!empty($conditions['title'])) {
            $sql .= " AND title LIKE ?";
            $params[] = "%{$conditions['title']}%";
        }
        
        if (!empty($conditions['author'])) {
            $sql .= " AND author LIKE ?";
            $params[] = "%{$conditions['author']}%";
        }
        
        if (!empty($conditions['genre'])) {
            $sql .= " AND genre LIKE ?";
            $params[] = "%{$conditions['genre']}%";
        }
        
        if (!empty($conditions['series'])) {
            $sql .= " AND series LIKE ?";
            $params[] = "%{$conditions['series']}%";
        }
        
        if (!empty($conditions['year_from'])) {
            $sql .= " AND year >= ?";
            $params[] = $conditions['year_from'];
        }
        
        if (!empty($conditions['year_to'])) {
            $sql .= " AND year <= ?";
            $params[] = $conditions['year_to'];
        }
        
        if (!empty($conditions['language'])) {
            $sql .= " AND language = ?";
            $params[] = $conditions['language'];
        }
        
        $sql .= " ORDER BY author, title LIMIT ? OFFSET ?";
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Получить количество для расширенного поиска
     */
    public function getAdvancedSearchCount($conditions) {
        $params = [];
        $sql = "SELECT COUNT(*) as count FROM books WHERE 1=1";
        
        if (!empty($conditions['title'])) {
            $sql .= " AND title LIKE ?";
            $params[] = "%{$conditions['title']}%";
        }
        
        if (!empty($conditions['author'])) {
            $sql .= " AND author LIKE ?";
            $params[] = "%{$conditions['author']}%";
        }
        
        if (!empty($conditions['genre'])) {
            $sql .= " AND genre LIKE ?";
            $params[] = "%{$conditions['genre']}%";
        }
        
        if (!empty($conditions['series'])) {
            $sql .= " AND series LIKE ?";
            $params[] = "%{$conditions['series']}%";
        }
        
        if (!empty($conditions['year_from'])) {
            $sql .= " AND year >= ?";
            $params[] = $conditions['year_from'];
        }
        
        if (!empty($conditions['year_to'])) {
            $sql .= " AND year <= ?";
            $params[] = $conditions['year_to'];
        }
        
        if (!empty($conditions['language'])) {
            $sql .= " AND language = ?";
            $params[] = $conditions['language'];
        }
        
        $stmt = $this->executeQuery($sql, $params);
        $result = $stmt->fetch();
        return $result['count'];
    }
    
    /**
     * Обновить информацию о книге
     */
    public function updateBook($id, $data) {
        $allowedFields = ['title', 'author', 'genre', 'series', 'series_number', 'year', 'language', 'publisher', 'description'];
        $setParts = [];
        $params = [];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $setParts[] = "$field = ?";
                $params[] = $data[$field];
            }
        }
        
        if (empty($setParts)) {
            return false;
        }
        
        // Для разных СУБД разный синтаксис временных меток
        if (Config::DB_TYPE === 'mysql') {
            $setParts[] = "last_modified = NOW()";
        } else {
            $setParts[] = "last_modified = CURRENT_TIMESTAMP";
        }
        
        $sql = "UPDATE books SET " . implode(', ', $setParts) . " WHERE id = ?";
        $params[] = $id;
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Удалить книгу
     */
    public function deleteBook($id) {
        $stmt = $this->executeQuery("DELETE FROM books WHERE id = ?", [$id]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Проверить существование книги по различным критериям
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
        
        $stmt = $this->executeQuery($sql, $params);
        return $stmt->fetch() !== false;
    }
    
    /**
     * Получить общее количество книг (для пагинации)
     */
    public function getTotalBooksCount() {
        $stmt = $this->executeQuery("SELECT COUNT(*) as count FROM books");
        $result = $stmt->fetch();
        return $result['count'];
    }
    
    /**
     * Получить книги с расширенной пагинацией
     */
    public function getBooksWithPagination($page = 1, $perPage = 20, $orderBy = 'added_date', $orderDir = 'DESC') {
        $offset = (int)(($page - 1) * $perPage);
        $perPage = (int)$perPage;
        
        // Валидация параметров сортировки
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
    
    /**
     * Получить читаемое название жанра для книги (удобный метод для шаблонов)
     */
    public function getBookReadableGenre($bookId) {
        $book = $this->getBook($bookId);
        if ($book && $book['genre']) {
            return $this->getReadableGenre($book['genre']);
        }
        return null;
    }
    
    /**
     * Поиск по читаемым названиям жанров
     */
    public function searchByReadableGenre($readableGenre) {
        // Ищем обратное преобразование - из русского названия в код
        $genreCode = array_search($readableGenre, Config::FB2_GENRES);
        if ($genreCode !== false) {
            return $this->getBooksByGenre($genreCode, 1, 100);
        }
        
        // Если не нашли, ищем по частичному совпадению
        $stmt = $this->executeQuery("
            SELECT b.* 
            FROM books b 
            WHERE b.genre IN (
                SELECT genre FROM books 
                WHERE genre LIKE ? 
                GROUP BY genre
            )
            ORDER BY b.author, b.title
            LIMIT 100
        ", ["%$readableGenre%"]);
        return $stmt->fetchAll();
    }
}