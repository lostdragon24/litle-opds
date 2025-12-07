<?php

require_once __DIR__ . '/Database.php';

class OpdsGenerator {
    private $db;
    private $baseUrl;
    
    public function __construct($baseUrl) {
        $this->db = Database::getInstance();
        $this->baseUrl = rtrim($baseUrl, '/');
    }
    
    public function generateCatalog() {
        header('Content-Type: application/atom+xml; charset=utf-8');
        
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = 25;
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        
        $xml->startDocument('1.0', 'UTF-8');
        
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');
        
        $xml->writeElement('id', Config::OPDS_ID . ($page > 1 ? ':page:' . $page : ''));
        $xml->writeElement('title', Config::OPDS_TITLE . ($page > 1 ? ' - Page ' . $page : ''));
        $xml->writeElement('updated', date('c'));
        $xml->writeElement('icon', $this->baseUrl . '/favicon.ico');
        
        $xml->startElement('author');
        $xml->writeElement('name', Config::OPDS_AUTHOR);
        $xml->endElement();
        
        // Ссылка на сам каталог
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php' . ($page > 1 ? '?page=' . $page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
        
        // Ссылка на стартовую страницу
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
        
        // Навигационные ссылки
        $this->addNavigationLinks($xml);
        
        // Пагинация
        $this->addPaginationLinks($xml, $page, $perPage, 'catalog');
        
        // Книги с пагинацией
        $books = $this->db->getRecentBooks($perPage, ($page - 1) * $perPage);
        foreach ($books as $book) {
            $this->addBookEntry($xml, $book);
        }
        
        $xml->endElement();
        
        return $xml->outputMemory();
    }
    
    /**
     * Навигация по жанрам
     */
    public function generateGenresNavigation() {
        header('Content-Type: application/atom+xml; charset=utf-8');
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        
        $xml->startDocument('1.0', 'UTF-8');
        
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');
        
        $xml->writeElement('id', Config::OPDS_ID . ':genres:navigation');
        $xml->writeElement('title', 'Browse by Genres - ' . Config::OPDS_TITLE);
        $xml->writeElement('updated', date('c'));
        
        $xml->startElement('author');
        $xml->writeElement('name', Config::OPDS_AUTHOR);
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
        
        // Навигация между разделами
        $this->addBrowseLinks($xml);
        
        // Получаем жанры с читаемыми названиями
        $genres = $this->db->getGenresWithCount();
        
        // Группируем жанры по категориям
        $genreCategories = $this->categorizeGenres($genres);
        
        // Основные категории жанров
        foreach ($genreCategories as $category => $categoryGenres) {
            $xml->startElement('entry');
            $xml->writeElement('title', htmlspecialchars($category));
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', 'Browse ' . count($categoryGenres) . ' genres in ' . $category);
            $xml->writeAttribute('type', 'text');
            
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres&category=' . urlencode($category));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
            $xml->endElement();
            
            $xml->endElement();
        }
        
        // Если жанров немного, показываем все сразу
        if (count($genres) <= 50) {
            $xml->startElement('entry');
            $xml->writeElement('title', 'All Genres');
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', 'Browse all ' . count($genres) . ' genres');
            $xml->writeAttribute('type', 'text');
            
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres&view=all');
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
            $xml->endElement();
            
            $xml->endElement();
        }
        
        $xml->endElement();
        
        return $xml->outputMemory();
    }
    
    /**
     * Детальная навигация по жанрам (по категориям)
     */
    public function generateGenresByCategory($category) {
        header('Content-Type: application/atom+xml; charset=utf-8');
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        
        $xml->startDocument('1.0', 'UTF-8');
        
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');
        
        $xml->writeElement('id', Config::OPDS_ID . ':genres:category:' . urlencode($category));
        $xml->writeElement('title', 'Genres: ' . htmlspecialchars($category) . ' - ' . Config::OPDS_TITLE);
        $xml->writeElement('updated', date('c'));
        
        $xml->startElement('author');
        $xml->writeElement('name', Config::OPDS_AUTHOR);
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres&category=' . urlencode($category));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
        
        // Навигация
        $this->addBrowseLinks($xml);
        
        // Получаем все жанры
        $genres = $this->db->getGenresWithCount();
        $categoryGenres = $this->getGenresByCategory($genres, $category);
        
        foreach ($categoryGenres as $genre) {
            $xml->startElement('entry');
            
            $genreName = $genre['readable_name'] ?: $genre['genre'];
            $xml->writeElement('title', htmlspecialchars($genreName) . ' (' . $genre['count'] . ')');
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', $genre['count'] . ' books in ' . htmlspecialchars($genreName));
            $xml->writeAttribute('type', 'text');
            
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?genre=' . urlencode($genre['genre']));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();
            
            $xml->endElement();
        }
        
        $xml->endElement();
        
        return $xml->outputMemory();
    }
    
    /**
     * Все жанры в одном списке
     */
    public function generateAllGenres() {
        header('Content-Type: application/atom+xml; charset=utf-8');
        
        $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
        $perPage = 50;
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        
        $xml->startDocument('1.0', 'UTF-8');
        
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');
        
        $xml->writeElement('id', Config::OPDS_ID . ':genres:all' . ($page > 1 ? ':page:' . $page : ''));
        $xml->writeElement('title', 'All Genres - ' . Config::OPDS_TITLE . ($page > 1 ? ' - Page ' . $page : ''));
        $xml->writeElement('updated', date('c'));
        
        $xml->startElement('author');
        $xml->writeElement('name', Config::OPDS_AUTHOR);
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres&view=all' . ($page > 1 ? '&page=' . $page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
        
        // Навигация
        $this->addBrowseLinks($xml);
        
        // Получаем жанры с пагинацией
        $allGenres = $this->db->getGenresWithCount();
        $totalGenres = count($allGenres);
        $totalPages = ceil($totalGenres / $perPage);
        
        // Пагинация
        $this->addPaginationLinks($xml, $page, $perPage, 'genres_all', $totalPages, ['by' => 'genres', 'view' => 'all']);
        
        $startIndex = ($page - 1) * $perPage;
        $genres = array_slice($allGenres, $startIndex, $perPage);
        
        foreach ($genres as $genre) {
            $xml->startElement('entry');
            
            $genreName = $genre['readable_name'] ?: $genre['genre'];
            $xml->writeElement('title', htmlspecialchars($genreName) . ' (' . $genre['count'] . ')');
            $xml->writeElement('updated', date('c'));
            $xml->writeElement('content', $genre['count'] . ' books in ' . htmlspecialchars($genreName));
            $xml->writeAttribute('type', 'text');
            
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'subsection');
            $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?genre=' . urlencode($genre['genre']));
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
            $xml->endElement();
            
            $xml->endElement();
        }
        
        $xml->endElement();
        
        return $xml->outputMemory();
    }
    
    public function generateByAuthors($page = 1) {
        // ... существующий код без изменений ...
    }
    
    public function generateByGenres($page = 1) {
        // Заменяем старый метод на новый с навигацией
        $view = $_GET['view'] ?? '';
        $category = $_GET['category'] ?? '';
        
        if ($category) {
            return $this->generateGenresByCategory($category);
        } elseif ($view === 'all') {
            return $this->generateAllGenres($page);
        } else {
            return $this->generateGenresNavigation();
        }
    }
    
    public function generateByAuthor($author, $page = 1) {
        // ... существующий код без изменений ...
    }
    
    public function generateByGenre($genre, $page = 1) {
        header('Content-Type: application/atom+xml; charset=utf-8');
        
        $perPage = 25;
        $booksCount = $this->db->getBooksCountByGenre($genre);
        $totalPages = ceil($booksCount / $perPage);
        
        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->setIndentString('  ');
        
        $xml->startDocument('1.0', 'UTF-8');
        
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns', 'http://www.w3.org/2005/Atom');
        $xml->writeAttribute('xmlns:dc', 'http://purl.org/dc/terms/');
        $xml->writeAttribute('xmlns:opds', 'http://opds-spec.org/2010/catalog');
        
        $readableGenre = $this->db->getReadableGenre($genre);
        $displayGenre = $readableGenre ?: $genre;
        
        $xml->writeElement('id', Config::OPDS_ID . ':genre:' . urlencode($genre) . ($page > 1 ? ':page:' . $page : ''));
        $xml->writeElement('title', 'Books in ' . htmlspecialchars($displayGenre) . ($page > 1 ? ' - Page ' . $page : ''));
        $xml->writeElement('updated', date('c'));
        
        $xml->startElement('author');
        $xml->writeElement('name', Config::OPDS_AUTHOR);
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'self');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?genre=' . urlencode($genre) . ($page > 1 ? '&page=' . $page : ''));
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
        
        // Навигация назад к жанрам
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'related');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->writeAttribute('title', 'Browse All Genres');
        $xml->endElement();
        
        // Навигация между разделами
        $this->addBrowseLinks($xml);
        
        // Пагинация
        $this->addPaginationLinks($xml, $page, $perPage, 'genre', $totalPages, ['genre' => $genre]);
        
        $books = $this->db->getBooksByGenre($genre, $page, $perPage);
        foreach ($books as $book) {
            $this->addBookEntry($xml, $book);
        }
        
        $xml->endElement();
        
        return $xml->outputMemory();
    }
    
    public function generateBySeries($series, $page = 1) {
        // ... аналогично generateByGenre ...
    }
    
    public function generateSearchResults($query, $page = 1) {
        // ... существующий код без изменений ...
    }
    
    /**
     * Категоризация жанров
     */
    private function categorizeGenres($genres) {
        $categories = [
            'Фантастика' => ['sf', 'sf_', 'fantasy'],
            'Детективы' => ['det', 'thriller'],
            'Проза' => ['prose', 'novel'],
            'Романы' => ['love', 'romance'],
            'Приключения' => ['adv', 'adventure'],
            'Детские' => ['child', 'children'],
            'Поэзия' => ['poetry', 'dramaturgy'],
            'Наука' => ['sci', 'science'],
            'Компьютеры' => ['comp', 'computers'],
            'Религия' => ['religion'],
            'Юмор' => ['humor'],
            'Домоводство' => ['home'],
            'Другое' => [] // Для остальных жанров
        ];
        
        $categorized = [];
        
        foreach ($genres as $genre) {
            $genreCode = $genre['genre'];
            $assigned = false;
            
            foreach ($categories as $category => $patterns) {
                foreach ($patterns as $pattern) {
                    if (strpos($genreCode, $pattern) === 0) {
                        $categorized[$category][] = $genre;
                        $assigned = true;
                        break 2;
                    }
                }
            }
            
            if (!$assigned) {
                $categorized['Другое'][] = $genre;
            }
        }
        
        // Убираем пустые категории
        foreach ($categorized as $category => $categoryGenres) {
            if (empty($categoryGenres)) {
                unset($categorized[$category]);
            }
        }
        
        return $categorized;
    }
    
    /**
     * Получить жанры по категории
     */
    private function getGenresByCategory($genres, $category) {
        $categorized = $this->categorizeGenres($genres);
        return $categorized[$category] ?? [];
    }
    
    private function addNavigationLinks($xml) {
        // Новые книги
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/sort/new');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->writeElement('title', 'Newest Books');
        $xml->endElement();
        
        // По авторам
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=authors');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->writeElement('title', 'Browse by Authors');
        $xml->endElement();
        
        // По жанрам
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->writeElement('title', 'Browse by Genres');
        $xml->endElement();
        
        // Поиск
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'search');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?search={searchTerms}');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->writeAttribute('opds:searchType', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->endElement();
    }
    
    private function addBrowseLinks($xml) {
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=authors');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->writeElement('title', 'Authors');
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'subsection');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?by=genres');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=navigation');
        $xml->writeElement('title', 'Genres');
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'start');
        $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php');
        $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog;kind=acquisition');
        $xml->writeElement('title', 'New Books');
        $xml->endElement();
    }

    private function addPaginationLinks($xml, $currentPage, $perPage, $type, $totalPages = null, $params = []) {
        if ($currentPage > 1) {
            $prevParams = http_build_query(array_merge($params, ['page' => $currentPage - 1]));
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'previous');
            $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?' . $prevParams);
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog');
            $xml->endElement();
        }
        
        if ($totalPages && $currentPage < $totalPages) {
            $nextParams = http_build_query(array_merge($params, ['page' => $currentPage + 1]));
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'next');
            $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?' . $nextParams);
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog');
            $xml->endElement();
        }
        
        // Первая страница
        if ($currentPage > 2) {
            $firstParams = http_build_query(array_merge($params, ['page' => 1]));
            $xml->startElement('link');
            $xml->writeAttribute('rel', 'first');
            $xml->writeAttribute('href', $this->baseUrl . '/api/opds.php?' . $firstParams);
            $xml->writeAttribute('type', 'application/atom+xml;profile=opds-catalog');
            $xml->endElement();
        }
    }
    
    private function addBookEntry($xml, $book) {
        $xml->startElement('entry');
        
        $xml->writeElement('id', Config::OPDS_ID . ':book:' . $book['id']);
        $xml->writeElement('title', htmlspecialchars($book['title'] ?: 'Unknown Title'));
        $xml->writeElement('updated', date('c', strtotime($book['added_date'])));
        
        if ($book['author']) {
            $xml->startElement('author');
            $xml->writeElement('name', htmlspecialchars($book['author']));
            $xml->endElement();
        }
        
        // Описание
        if ($book['description']) {
            $description = substr($book['description'], 0, 500);
            $xml->writeElement('summary', htmlspecialchars($description));
            $xml->writeAttribute('type', 'text');
        }
        
        // Обложка
        $coverUrl = $this->baseUrl . '/api/cover_direct.php?id=' . $book['id'];
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/image');
        $xml->writeAttribute('href', $coverUrl);
        $xml->writeAttribute('type', 'image/jpeg');
        $xml->endElement();
        
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/image/thumbnail');
        $xml->writeAttribute('href', $coverUrl . '&thumb=1');
        $xml->writeAttribute('type', 'image/jpeg');
        $xml->endElement();
        
        // Ссылка для скачивания - ОЧЕНЬ ВАЖНО для FBReader
        $downloadUrl = $this->baseUrl . '/api/download.php?id=' . $book['id'];
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/acquisition/open-access');
        $xml->writeAttribute('href', $downloadUrl);
        $xml->writeAttribute('type', $this->getMimeType($book['file_type']));
        $xml->endElement();
        
        // Дублирующая ссылка для совместимости
        $xml->startElement('link');
        $xml->writeAttribute('rel', 'http://opds-spec.org/acquisition');
        $xml->writeAttribute('href', $downloadUrl);
        $xml->writeAttribute('type', $this->getMimeType($book['file_type']));
        $xml->endElement();
        
        // Метаданные
        if ($book['genre']) {
            $xml->startElement('category');
            $xml->writeAttribute('term', htmlspecialchars($book['genre']));
            $xml->writeAttribute('label', htmlspecialchars($book['genre']));
            $xml->endElement();
        }
        
        if ($book['language']) {
            $xml->writeElement('dc:language', htmlspecialchars($book['language']));
        }
        
        if ($book['publisher']) {
            $xml->writeElement('dc:publisher', htmlspecialchars($book['publisher']));
        }
        
        if ($book['year']) {
            $xml->writeElement('dc:issued', $book['year']);
        }
        
        $xml->endElement(); // entry
    }
    
    private function getMimeType($fileType) {
        $mimeTypes = [
            'epub' => 'application/epub+zip',
            'pdf' => 'application/pdf',
            'fb2' => 'application/x-fictionbook+xml',
            'mobi' => 'application/x-mobipocket-ebook',
            'txt' => 'text/plain',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed'
        ];
        
        return $mimeTypes[strtolower($fileType)] ?? 'application/octet-stream';
    }
}
?>