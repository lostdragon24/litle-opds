<?php

// admin/AuthorDeduplicator.php
require_once __DIR__ . '/../lib/NameParser.php';

class AuthorDeduplicator
{
    private $db;
    private $config;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->config = [
            'min_similarity' => 0.70,
            'batch_size' => 100
        ];
    }

    /**
     * БЕЗОПАСНЫЙ ПОИСК ГРУПП ДУБЛИКАТОВ (Для отчета/предложений)
     * Не изменяет данные, только анализирует.
     * Оптимизирован: один запрос + группировка в памяти.
     */
    public function findSimilarAuthors($threshold = null)
    {
        if ($threshold === null) {
            $threshold = $this->config['min_similarity'];
        }

        $startTime = microtime(true);

        // 1. Получаем ВСЕХ уникальных авторов одним запросом
        $stmt = $this->db->getConnection()->query("
            SELECT DISTINCT author 
            FROM books 
            WHERE author IS NOT NULL AND author != ''
        ");
        $allAuthors = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Группируем по нормализованной фамилии (ключевая оптимизация)
        $groups = [];
        foreach ($allAuthors as $author) {
            $parsed = NameParser::parse($author);
            $key = mb_strtolower($parsed['normalizedLastName'], 'UTF-8');

            // Игнорируем слишком короткие ключи и частые имена
            if (mb_strlen($key, 'UTF-8') < 3) {
                continue;
            }

            $groups[$key][] = $author;
        }

        // 3. Ищем дубликаты ВНУТРИ групп
        $foundGroups = [];
        $comparisons = 0;

        foreach ($groups as $normName => $authorsInGroup) {
            if (count($authorsInGroup) < 2) {
                continue;
            }

            for ($i = 0; $i < count($authorsInGroup); $i++) {
                for ($j = $i + 1; $j < count($authorsInGroup); $j++) {
                    $comparisons++;
                    $similarity = $this->calculateAuthorSimilarity(
                        $authorsInGroup[$i],
                        $authorsInGroup[$j]
                    );

                    if ($similarity >= $threshold) {
                        // Добавляем в группу или расширяем существующую
                        $groupFound = false;
                        foreach ($foundGroups as &$group) {
                            if (in_array($authorsInGroup[$i], $group['variants']) ||
                                in_array($authorsInGroup[$j], $group['variants'])) {
                                if (!in_array($authorsInGroup[$i], $group['variants'])) {
                                    $group['variants'][] = $authorsInGroup[$i];
                                }
                                if (!in_array($authorsInGroup[$j], $group['variants'])) {
                                    $group['variants'][] = $authorsInGroup[$j];
                                }
                                $groupFound = true;
                                break;
                            }
                        }

                        if (!$groupFound) {
                            $foundGroups[] = [
                                'variants' => [$authorsInGroup[$i], $authorsInGroup[$j]],
                                'max_similarity' => round($similarity, 3)
                            ];
                        }
                    }
                }
            }
        }

        return [
            'groups' => $foundGroups,
            'stats' => [
                'total_authors' => count($allAuthors),
                'groups_found' => count($foundGroups),
                'comparisons' => $comparisons,
                'time' => round(microtime(true) - $startTime, 3)
            ]
        ];
    }

    /**
     * БЕЗОПАСНЫЕ ПРЕДЛОЖЕНИЯ ДЛЯ АВТО-СЛИЯНИЯ
     * Возвращает список пар с высоким порогом (90%+), но НЕ сливает их.
     * Администратор должен подтвердить каждое действие вручную.
     */
    public function getAutoMergeSuggestions($threshold = 0.90)
    {
        $result = $this->findSimilarAuthors($threshold);
        $suggestions = [];

        foreach ($result['groups'] as $group) {
            // Выбираем "главного" автора (у кого больше книг)
            $mainAuthor = $this->selectMainAuthor($group['variants']);

            foreach ($group['variants'] as $duplicate) {
                if ($duplicate === $mainAuthor) {
                    continue;
                }

                $suggestions[] = [
                    'main' => $mainAuthor,
                    'duplicate' => $duplicate,
                    'similarity' => $group['max_similarity'],
                    'books_to_move' => $this->getBookCount($duplicate)
                ];
            }
        }

        return [
            'suggestions' => $suggestions,
            'stats' => $result['stats']
        ];
    }

    /**
     * Выбор главного автора (у кого больше книг или имя длиннее/полнее)
     */
    private function selectMainAuthor(array $variants): string
    {
        $scores = [];
        $pdo = $this->db->getConnection();

        foreach ($variants as $author) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM books WHERE author = ?");
            $stmt->execute([$author]);
            $count = (int)$stmt->fetchColumn();

            // Приоритет: кол-во книг > длина имени (более полное имя лучше)
            $scores[$author] = $count * 1000 + mb_strlen($author);
        }

        arsort($scores);
        return key($scores);
    }

    /**
     * Получить количество книг у автора
     */
    private function getBookCount($author): int
    {
        $stmt = $this->db->getConnection()->prepare("SELECT COUNT(*) FROM books WHERE author = ?");
        $stmt->execute([$author]);
        return (int)$stmt->fetchColumn();
    }

    /**
     * Поиск похожих авторов для конкретного автора (ручной поиск)
     */
    public function findSimilarForAuthor($authorName, $threshold = null, $limit = 20)
    {
        if ($threshold === null) {
            $threshold = $this->config['min_similarity'];
        }

        $results = [];
        $authorName = trim($authorName);
        $parsed = NameParser::parse($authorName);
        $lastName = $parsed['normalizedLastName'];
        $firstName = $parsed['firstName'];

        // Если фамилия не определена — ищем по полной строке
        if (empty($lastName)) {
            return $this->findByFullString($authorName, $threshold, $limit);
        }

        // 1. Поиск по фамилии (ОСНОВНОЙ)
        $candidates = $this->searchByNormalizedLastName($authorName, $lastName);

        // 2. Если по фамилии ничего не найдено — ищем по имени (только если имя длинное)
        if (empty($candidates) && !empty($firstName) && mb_strlen($firstName, 'UTF-8') >= 3) {
            $candidates = $this->searchByFirstName($authorName, $firstName);
        }

        // 3. Если всё ещё пусто — ищем по полной строке (последняя надежда)
        if (empty($candidates)) {
            $candidates = $this->searchByFullString($authorName);
        }

        foreach ($candidates as $candidate) {
            if (strlen($candidate['author']) < 3) {
                continue;
            }
            if ($candidate['author'] === $authorName) {
                continue;
            }

            $parsedCandidate = NameParser::parse($candidate['author']);

            // ===== ЖЁСТКАЯ ПРОВЕРКА ФАМИЛИИ =====
            $lastNameSim = 0;
            if (!empty($parsed['normalizedLastName']) && !empty($parsedCandidate['normalizedLastName'])) {
                $lastNameSim = $this->calculateStringSimilarity(
                    $parsed['normalizedLastName'],
                    $parsedCandidate['normalizedLastName']
                );
            }
            // Если фамилия совпадает меньше чем на 60% — пропускаем (даже если имя совпадает)
            if ($lastNameSim < 0.6) {
                continue;
            }

            // ===== ЖЁСТКАЯ ПРОВЕРКА ИМЕНИ =====
            $firstNameSim = 0;
            if (!empty($parsed['firstName']) && !empty($parsedCandidate['firstName'])) {
                $firstNameSim = $this->calculateStringSimilarity(
                    $parsed['firstName'],
                    $parsedCandidate['firstName']
                );
            }
            // Если имя не совпадает хотя бы на 30% — пропускаем (чтобы отсечь разные имена при одинаковой фамилии)
            if ($firstNameSim < 0.3) {
                continue;
            }

            // Если фамилия совпала на 90%+ и имя на 50%+ — почти точно дубликат
            if ($lastNameSim >= 0.9 && $firstNameSim >= 0.5) {
                $similarity = 0.85;
            } else {
                // Иначе считаем полную схожесть через взвешенный метод
                $similarity = $this->calculateAuthorSimilarity($authorName, $candidate['author']);
            }

            if ($similarity >= $threshold) {
                $results[] = [
                    'name' => $candidate['author'],
                    'books' => $candidate['book_count'],
                    'similarity' => round($similarity, 3)
                ];
            }
        }

        usort($results, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        return array_slice($results, 0, $limit);
    }

    private function searchByNormalizedLastName($authorName, $normalizedLastName)
    {
        $searchTerm = '%' . addslashes($normalizedLastName) . '%';
        $stmt = $this->db->getConnection()->prepare("
            SELECT DISTINCT author, COUNT(*) as book_count
            FROM books
            WHERE author IS NOT NULL AND author != ''
            AND author != ? AND author LIKE ?
            GROUP BY author LIMIT 200
        ");
        $stmt->execute([$authorName, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function searchByFirstName($authorName, $firstName)
    {
        $searchTerm = '%' . addslashes($firstName) . '%';
        $stmt = $this->db->getConnection()->prepare("
            SELECT DISTINCT author, COUNT(*) as book_count
            FROM books
            WHERE author IS NOT NULL AND author != ''
            AND author != ? AND author LIKE ?
            GROUP BY author LIMIT 100
        ");
        $stmt->execute([$authorName, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function searchByFullString($authorName)
    {
        $searchTerm = '%' . addslashes($authorName) . '%';
        $stmt = $this->db->getConnection()->prepare("
            SELECT DISTINCT author, COUNT(*) as book_count
            FROM books
            WHERE author IS NOT NULL AND author != ''
            AND author != ? AND author LIKE ?
            GROUP BY author LIMIT 50
        ");
        $stmt->execute([$authorName, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    private function calculateAuthorSimilarity($author1, $author2)
    {
        $parsed1 = NameParser::parse($author1);
        $parsed2 = NameParser::parse($author2);

        $ln1 = $parsed1['normalizedLastName'];
        $ln2 = $parsed2['normalizedLastName'];

        // 1. Фамилия (ОСНОВНОЙ ПАРАМЕТР — 80%)
        $lastNameScore = 0;
        if (!empty($ln1) && !empty($ln2)) {
            if (mb_strlen($ln1, 'UTF-8') < 5 && mb_strlen($ln2, 'UTF-8') < 5) {
                $lastNameScore = ($ln1 === $ln2) ? 1.0 : 0.0;
            } else {
                $lastNameScore = $this->calculateStringSimilarity($ln1, $ln2);
            }
        }

        if ($lastNameScore < 0.4) {
            return 0;
        }

        // 2. Имя (20%)
        $firstNameScore = 0;
        if (!empty($parsed1['firstName']) && !empty($parsed2['firstName'])) {
            $firstNameScore = $this->calculateStringSimilarity(
                $parsed1['firstName'],
                $parsed2['firstName']
            );
        }

        // 3. Отчество (0% — убираем совсем, чтобы не мешало)
        // Отчество учитываем только как бонус, если фамилия и имя совпадают на 90%+
        $patronymicBonus = 0;
        if ($lastNameScore >= 0.9 && $firstNameScore >= 0.9) {
            if (!empty($parsed1['patronymic']) && !empty($parsed2['patronymic'])) {
                $patronymicBonus = $this->calculateStringSimilarity(
                    $parsed1['patronymic'],
                    $parsed2['patronymic']
                ) * 0.1;
            } elseif (empty($parsed1['patronymic']) && empty($parsed2['patronymic'])) {
                $patronymicBonus = 0.1;
            }
        }

        // 4. Проверка вхождения (только если фамилия и имя совпадают хорошо)
        $containmentScore = 0;
        if ($lastNameScore > 0.7 && $firstNameScore > 0.5) {
            $containmentScore = $this->checkContainment($author1, $author2);
        }

        $totalScore = ($lastNameScore * 0.8) + ($firstNameScore * 0.2) + $patronymicBonus;

        // Бонус за вхождение (если он выше базовой оценки)
        if ($containmentScore > 0.8 && $totalScore < 0.9) {
            $totalScore = max($totalScore, $containmentScore * 0.9);
        }

        return min(1.0, $totalScore);
    }

    private function checkContainment($str1, $str2)
    {
        $s1 = mb_strtolower(trim($str1), 'UTF-8');
        $s2 = mb_strtolower(trim($str2), 'UTF-8');
        if (strlen($s1) == 0 || strlen($s2) == 0) {
            return 0;
        }
        if (strpos($s1, $s2) !== false || strpos($s2, $s1) !== false) {
            return 0.85;
        }
        return 0;
    }

    private function calculateStringSimilarity($str1, $str2)
    {
        $s1 = mb_strtolower(trim($str1), 'UTF-8');
        $s2 = mb_strtolower(trim($str2), 'UTF-8');
        if ($s1 === $s2) {
            return 1.0;
        }
        if (strlen($s1) <= 2 || strlen($s2) <= 2) {
            return ($s1 === $s2) ? 1.0 : 0.0;
        }

        $distance = $this->levenshtein_utf8($s1, $s2);
        $maxLen = max(strlen($s1), strlen($s2));
        return ($maxLen === 0) ? 0 : 1 - ($distance / $maxLen);
    }

    private function levenshtein_utf8($str1, $str2, $maxDistance = 4)
    {
        if ($str1 === $str2) {
            return 0;
        }

        $len1 = mb_strlen($str1, 'UTF-8');
        $len2 = mb_strlen($str2, 'UTF-8');

        // Эвристика 1: Если разница в длине больше максимального порога,
        // они точно не похожи. Сразу выходим.
        if (abs($len1 - $len2) > $maxDistance) {
            return $maxDistance + 1;
        }

        if ($len1 === 0) {
            return $len2;
        }
        if ($len2 === 0) {
            return $len1;
        }

        // Эвристика 2: Работаем с массивами символов, только если прошли проверку
        $chars1 = preg_split('//u', $str1, -1, PREG_SPLIT_NO_EMPTY);
        $chars2 = preg_split('//u', $str2, -1, PREG_SPLIT_NO_EMPTY);

        $prevRow = range(0, $len2);
        $currentRow = array_fill(0, $len2 + 1, 0);

        for ($i = 1; $i <= $len1; $i++) {
            $currentRow[0] = $i;
            $minInRow = $i; // Для досрочного выхода из цикла

            for ($j = 1; $j <= $len2; $j++) {
                $cost = ($chars1[$i - 1] === $chars2[$j - 1]) ? 0 : 1;
                $currentRow[$j] = min(
                    $prevRow[$j] + 1,
                    $currentRow[$j - 1] + 1,
                    $prevRow[$j - 1] + $cost
                );
                if ($currentRow[$j] < $minInRow) {
                    $minInRow = $currentRow[$j];
                }
            }

            // Эвристика 3: Если даже минимальное значение в текущей строке матрицы
            // уже превышает наш порог схожести, прерываем расчет.
            if ($minInRow > $maxDistance) {
                return $maxDistance + 1;
            }

            $temp = $prevRow;
            $prevRow = $currentRow;
            $currentRow = $temp;
        }
        return $prevRow[$len2];
    }

    public function getAllAuthorsList($page = 1, $perPage = 100, $search = '')
    {
        $offset = ($page - 1) * $perPage;
        $pdo = $this->db->getConnection();

        $params = [];
        $where = "WHERE author IS NOT NULL AND author != '' AND LENGTH(author) >= 3";

        // Если есть поисковый запрос — ищем по ВСЕМ записям
        if (!empty($search)) {
            $where .= " AND author LIKE ?";
            $params[] = '%' . addslashes($search) . '%';
        }

        // Получаем авторов с пагинацией (но поиск идёт по ВСЕЙ таблице)
        $sql = "
        SELECT author, COUNT(*) as book_count
        FROM books
        {$where}
        GROUP BY author
        HAVING book_count >= 1
        ORDER BY author ASC
        LIMIT ? OFFSET ?
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$perPage, $offset]));
        $authors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Получаем общее количество (для корректной пагинации)
        $countSql = "
        SELECT COUNT(DISTINCT author) as total
        FROM books
        {$where}
    ";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        return [
            'authors' => $authors,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $total > 0 ? ceil($total / $perPage) : 1,
            'search' => $search
        ];
    }

    public function mergeAuthors($mainAuthor, $duplicateAuthor)
    {
        if (empty($mainAuthor) || empty($duplicateAuthor)) {
            return ['success' => false, 'message' => 'Не указаны авторы'];
        }
        $mainAuthor = trim($mainAuthor);
        $duplicateAuthor = trim($duplicateAuthor);
        if ($mainAuthor === $duplicateAuthor) {
            return ['success' => false, 'message' => 'Авторы идентичны'];
        }

        try {
            $pdo = $this->db->getConnection();
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("UPDATE books SET author = ? WHERE author = ?");
            $stmt->execute([$mainAuthor, $duplicateAuthor]);
            $updated = $stmt->rowCount();

            $this->logMerge($mainAuthor, $duplicateAuthor, $updated);
            $pdo->commit();

            Cache::invalidateByType('statistics');
            Cache::invalidateByType('search_results');

            return [
                'success' => true,
                'message' => "Объединено {$updated} книг: '{$duplicateAuthor}' → '{$mainAuthor}'",
                'updated' => $updated
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()];
        }
    }

    private function logMerge($main, $duplicate, $count)
    {
        $logFile = Config::getCacheDir() . '/author_merge.log';
        $entry = sprintf("[%s] MERGE: '%s' → '%s' (%d books)\n", date('Y-m-d H:i:s'), $duplicate, $main, $count);
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }

    public function getMergeHistory($limit = 50)
    {
        $logFile = Config::getCacheDir() . '/author_merge.log';
        if (!file_exists($logFile)) {
            return [];
        }
        $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -$limit);
        $history = [];
        foreach ($lines as $line) {
            if (preg_match('/\[(.*?)\] MERGE: \'(.*?)\' → \'(.*?)\' \((\d+) books\)/', $line, $m)) {
                $history[] = ['date' => $m[1], 'from' => $m[2], 'to' => $m[3], 'count' => (int)$m[4]];
            }
        }
        return array_reverse($history);
    }

    public function getAuthorStats()
    {
        $pdo = $this->db->getConnection();
        $stmt = $pdo->query("SELECT COUNT(DISTINCT author) as total FROM books WHERE author IS NOT NULL AND author != ''");
        $total = (int)$stmt->fetchColumn();
        return ['total' => $total, 'top_duplicates' => []];
    }
}
