<?php

require_once __DIR__ . '/../lib/NameParser.php';

class UnionFind
{
    private $parent = [];
    private $rank = [];

    public function find($x)
    {
        if (!isset($this->parent[$x])) {
            $this->parent[$x] = $x;
            $this->rank[$x] = 0;
        }
        if ($this->parent[$x] !== $x) {
            $this->parent[$x] = $this->find($this->parent[$x]);
        }
        return $this->parent[$x];
    }

    public function union($x, $y)
    {
        $px = $this->find($x);
        $py = $this->find($y);
        if ($px === $py) {
            return;
        }

        if ($this->rank[$px] < $this->rank[$py]) {
            $this->parent[$px] = $py;
        } elseif ($this->rank[$px] > $this->rank[$py]) {
            $this->parent[$py] = $px;
        } else {
            $this->parent[$py] = $px;
            $this->rank[$px]++;
        }
    }

    public function getComponents()
    {
        $components = [];
        foreach ($this->parent as $node => $parent) {
            $root = $this->find($node);
            $components[$root][] = $node;
        }
        return array_values($components);
    }
}

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
     * Поиск групп дубликатов с использованием Union-Find
     */
    public function findSimilarAuthors($threshold = null)
    {
        if ($threshold === null) {
            $threshold = $this->config['min_similarity'];
        }

        $startTime = microtime(true);

        $stmt = $this->db->getConnection()->query("
        SELECT DISTINCT author
        FROM books
        WHERE author IS NOT NULL AND author != ''
    ");
        $allAuthors = $stmt->fetchAll(PDO::FETCH_COLUMN);

        // Группировка по началу фамилии
        $groups = [];
        foreach ($allAuthors as $author) {
            $parsed = NameParser::parse($author);
            $key = $this->getGroupingKey($parsed);

            if (mb_strlen($key, 'UTF-8') < 2) {
                continue;
            }

            $groups[$key][] = $author;
        }

        // Объединяем группы с похожими ключами
        $groups = $this->mergeNearbyGroups($groups);

        $similarPairs = [];
        $comparisons = 0;

        foreach ($groups as $key => $authorsInGroup) {
            if (count($authorsInGroup) < 2) {
                continue;
            }

            $groupSize = count($authorsInGroup);
            for ($i = 0; $i < $groupSize; $i++) {
                for ($j = $i + 1; $j < $groupSize; $j++) {
                    $comparisons++;
                    $similarity = $this->calculateAuthorSimilarity(
                        $authorsInGroup[$i],
                        $authorsInGroup[$j]
                    );

                    if ($similarity >= $threshold) {
                        $similarPairs[] = [
                            'a' => $authorsInGroup[$i],
                            'b' => $authorsInGroup[$j],
                            'similarity' => $similarity
                        ];
                    }
                }
            }
        }

        // Union-Find
        $uf = new UnionFind();
        foreach ($similarPairs as $pair) {
            $uf->union($pair['a'], $pair['b']);
        }

        $components = $uf->getComponents();
        $foundGroups = [];

        foreach ($components as $component) {
            if (count($component) < 2) {
                continue;
            }

            $maxSimilarity = 0;
            $compSize = count($component);
            for ($i = 0; $i < $compSize; $i++) {
                for ($j = $i + 1; $j < $compSize; $j++) {
                    $sim = $this->calculateAuthorSimilarity($component[$i], $component[$j]);
                    if ($sim > $maxSimilarity) {
                        $maxSimilarity = $sim;
                    }
                }
            }

            $foundGroups[] = [
                'variants' => $component,
                'max_similarity' => round($maxSimilarity, 3)
            ];
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
     * Объединяет группы с ключами, которые являются префиксами друг друга
      */
    private function mergeNearbyGroups($groups)
    {
        $keys = array_keys($groups);
        $merged = [];
        $processed = [];

        foreach ($keys as $key1) {
            if (isset($processed[$key1])) {
                continue;
            }

            $merged[$key1] = $groups[$key1];
            $processed[$key1] = true;

            foreach ($keys as $key2) {
                if (isset($processed[$key2])) {
                    continue;
                }
                if ($key1 === $key2) {
                    continue;
                }

                // Если один ключ — префикс другого
                if (strpos($key1, $key2) === 0 || strpos($key2, $key1) === 0) {
                    $merged[$key1] = array_merge($merged[$key1], $groups[$key2]);
                    $processed[$key2] = true;
                }
            }

            // Убираем дубликаты
            $merged[$key1] = array_unique($merged[$key1]);
        }

        return $merged;
    }


    /**
     * Объединяет группы с похожими ключами
     */
    private function mergeSimilarGroups($groups)
    {
        $keys = array_keys($groups);
        $merged = $groups;
        $mergedKeys = [];

        // Сортируем ключи по алфавиту
        sort($keys);

        // Ищем группы, которые можно объединить
        for ($i = 0; $i < count($keys); $i++) {
            for ($j = $i + 1; $j < count($keys); $j++) {
                $key1 = $keys[$i];
                $key2 = $keys[$j];

                // Если один ключ является префиксом другого
                if (strpos($key1, $key2) === 0 || strpos($key2, $key1) === 0) {
                    $mergedKey = strlen($key1) <= strlen($key2) ? $key1 : $key2;

                    if (!isset($merged[$mergedKey])) {
                        $merged[$mergedKey] = [];
                    }

                    // Объединяем авторов
                    $merged[$mergedKey] = array_merge(
                        $merged[$mergedKey] ?? [],
                        $merged[$key1] ?? [],
                        $merged[$key2] ?? []
                    );
                    $merged[$mergedKey] = array_unique($merged[$mergedKey]);

                    $mergedKeys[$key1] = $mergedKey;
                    $mergedKeys[$key2] = $mergedKey;
                }
                // Если ключи отличаются на 1 символ
                elseif (levenshtein($key1, $key2) <= 2) {
                    $mergedKey = $key1;

                    if (!isset($merged[$mergedKey])) {
                        $merged[$mergedKey] = [];
                    }

                    $merged[$mergedKey] = array_merge(
                        $merged[$mergedKey] ?? [],
                        $merged[$key1] ?? [],
                        $merged[$key2] ?? []
                    );
                    $merged[$mergedKey] = array_unique($merged[$mergedKey]);

                    $mergedKeys[$key1] = $mergedKey;
                    $mergedKeys[$key2] = $mergedKey;
                }
            }
        }

        // Очищаем: удаляем старые ключи, которые были объединены
        foreach ($mergedKeys as $oldKey => $newKey) {
            if ($oldKey !== $newKey) {
                unset($merged[$oldKey]);
            }
        }

        return $merged;
    }

    /**
     * Ключ группировки для предварительного отбора
     */
    private function getGroupingKey($parsed)
    {
        $lastName = $parsed['normalizedLastName'] ?: $parsed['normalizedFull'];
        $lastName = mb_strtolower(trim($lastName), 'UTF-8');

        if (empty($lastName) || mb_strlen($lastName, 'UTF-8') < 2) {
            return '';
        }

        $len = mb_strlen($lastName, 'UTF-8');

        // Всегда берём первые 5 символов как ключ группировки
        // Для коротких фамилий (< 5 символов) берём всю фамилию
        $prefixLen = min(5, $len);
        $key = mb_substr($lastName, 0, $prefixLen, 'UTF-8');

        // Дебаг (убрать после проверки)
        //my_log("Grouping key for '{$lastName}': '{$key}' (length: {$len})");

        return $key;
    }

    /**
     * Предложения для авто-слияния
     */
    public function getAutoMergeSuggestions($threshold = 0.90)
    {
        $result = $this->findSimilarAuthors($threshold);
        $suggestions = [];

        foreach ($result['groups'] as $group) {
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
     * Поиск похожих авторов для конкретного автора
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

        if (empty($lastName)) {
            $candidates = $this->searchByFullString($authorName);
        } else {
            $candidates = $this->searchByNormalizedLastName($authorName, $lastName);
        }

        foreach ($candidates as $candidate) {
            $candidateName = trim($candidate['author']);

            if (mb_strlen($candidateName, 'UTF-8') < 3) {
                continue;
            }
            if ($candidateName === $authorName) {
                continue;
            }

            // ПРЕДВАРИТЕЛЬНЫЙ ФИЛЬТР: проверяем, что у кандидата похожая фамилия
            $candidateParsed = NameParser::parse($candidateName);
            $candidateLastName = $candidateParsed['normalizedLastName'];

            if (!empty($lastName) && !empty($candidateLastName)) {
                // Быстрая проверка: первые 4 символа фамилии должны совпадать
                $prefix1 = mb_substr($lastName, 0, 4, 'UTF-8');
                $prefix2 = mb_substr($candidateLastName, 0, 4, 'UTF-8');

                if ($prefix1 !== $prefix2) {
                    continue; // Пропускаем явно непохожие фамилии
                }
            }

            $similarity = $this->calculateAuthorSimilarity($authorName, $candidateName);

            if ($similarity >= $threshold) {
                $results[] = [
                    'name' => $candidateName,
                    'books' => $candidate['book_count'],
                    'similarity' => round($similarity, 3)
                ];
            }
        }

        // Сортируем по убыванию схожести
        usort($results, function ($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });

        my_log("Final results for '{$authorName}': " . count($results) . " matches");
        foreach ($results as $r) {
            my_log("  - '{$r['name']}' ({$r['similarity']})");
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Улучшенный поиск по нормализованной фамилии
     */
    private function searchByNormalizedLastName($authorName, $normalizedLastName)
    {
        // ВРЕМЕННО: просто ищем по оригинальному имени без нормализации
        // Убираем имя, оставляем только фамилию для поиска
        $parts = explode(' ', trim($authorName));
        $lastNameOriginal = end($parts); // Берём последнее слово как фамилию

        // Ищем по оригинальной фамилии
        $searchTerm = '%' . $lastNameOriginal . '%';

        my_log("SEARCH DEBUG: author='{$authorName}', lastName='{$lastNameOriginal}', term='{$searchTerm}'");

        $stmt = $this->db->getConnection()->prepare("
        SELECT DISTINCT author, COUNT(*) as book_count
        FROM books
        WHERE author IS NOT NULL AND author != ''
        AND author != ?
        AND author LIKE ?
        GROUP BY author
        LIMIT 200
    ");
        $stmt->execute([$authorName, $searchTerm]);

        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

        my_log("SEARCH DEBUG: found " . count($results) . " results");
        foreach ($results as $r) {
            my_log("SEARCH DEBUG:   - '{$r['author']}' ({$r['book_count']} books)");
        }

        return $results;
    }


    private function searchByFullString($authorName)
    {
        // Нормализуем строку для поиска
        $normalized = NameParser::removeDiacritics(mb_strtolower(trim($authorName), 'UTF-8'));
        $normalized = str_replace('ё', 'е', $normalized);
        $searchTerm = '%' . $normalized . '%';

        $stmt = $this->db->getConnection()->prepare("
            SELECT DISTINCT author, COUNT(*) as book_count
            FROM books
            WHERE author IS NOT NULL AND author != ''
            AND author != ?
            AND REPLACE(REPLACE(LOWER(author), 'ё', 'е'), ' ', '') LIKE ?
            GROUP BY author
            LIMIT 50
        ");
        $stmt->execute([$authorName, $searchTerm]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Улучшенная оценка схожести с учётом инициалов
     */
    private function calculateAuthorSimilarity($author1, $author2)
    {
        $parsed1 = NameParser::parse($author1);
        $parsed2 = NameParser::parse($author2);

        $ln1 = $parsed1['normalizedLastName'];
        $ln2 = $parsed2['normalizedLastName'];
        $fn1 = $parsed1['firstName'];
        $fn2 = $parsed2['firstName'];

        // Если у обоих не выделена фамилия — сравниваем полные строки
        if (empty($ln1) && empty($ln2)) {
            return $this->calculateStringSimilarity($author1, $author2);
        }

        // Если фамилия выделена только у одного — штрафуем
        if (empty($ln1) || empty($ln2)) {
            return $this->calculateStringSimilarity($author1, $author2) * 0.6;
        }

        // 1. Фамилия (75% веса) — используем улучшенное сравнение
        $lastNameScore = $this->compareLastNames($ln1, $ln2);

        // Если фамилии совсем разные — дальше не проверяем
        if ($lastNameScore < 0.5) {
            return 0;
        }

        // 2. Имя (25% веса)
        $firstNameScore = $this->compareNames($fn1, $fn2);

        // 3. Отчество (бонус до 5%)
        $patronymicBonus = 0;
        if (!empty($parsed1['patronymic']) && !empty($parsed2['patronymic'])) {
            $patronymicScore = $this->compareNames(
                $parsed1['patronymic'],
                $parsed2['patronymic']
            );
            if ($patronymicScore > 0.8) {
                $patronymicBonus = 0.05;
            }
        }

        // 4. Бонус за вхождение одной строки в другую
        $containmentBonus = 0;
        if ($lastNameScore > 0.7) {
            $containmentBonus = $this->checkContainment($author1, $author2) * 0.1;
        }

        // $totalScore = ($lastNameScore * 0.75) + ($firstNameScore * 0.25) + $patronymicBonus + $containmentBonus;


        $firstNameWeight = (!empty($fn1) && !empty($fn2) && mb_strlen($fn1, 'UTF-8') > 1 && mb_strlen($fn2, 'UTF-8') > 1) ? 0.25 : 0.10;
        $totalScore = ($lastNameScore * (1 - $firstNameWeight)) + ($firstNameScore * $firstNameWeight);




        return min(1.0, $totalScore);
    }

    /**
     * Сравнение имён с учётом инициалов
     */
    private function compareLastNames($ln1, $ln2)
    {
        // Базовая строковая схожесть
        $baseSimilarity = $this->calculateStringSimilarity($ln1, $ln2);

        // Если базовая схожесть уже высокая — возвращаем её
        if ($baseSimilarity >= 0.85) {
            return $baseSimilarity;
        }

        // Проверяем общий корень (для случаев "Белянинов" vs "Белянин")
        $commonRoot = $this->findCommonRoot($ln1, $ln2);

        if ($commonRoot === false) {
            return $baseSimilarity;
        }

        $len1 = mb_strlen($ln1, 'UTF-8');
        $len2 = mb_strlen($ln2, 'UTF-8');
        $rootLen = mb_strlen($commonRoot, 'UTF-8');
        $maxLen = max($len1, $len2);
        $minLen = min($len1, $len2);

        // Если общий корень составляет значительную часть обеих фамилий
        $rootRatio = $rootLen / $maxLen;

        // Проверяем, является ли одна фамилия производной от другой
        $isDerivative = $this->isDerivativeLastName($ln1, $ln2, $commonRoot);

        if ($isDerivative && $rootRatio >= 0.6) {
            // Высокая схожесть для однокоренных фамилий
            return 0.88 + ($rootRatio - 0.6) * 0.3; // 0.88 - 1.0
        }

        if ($rootRatio >= 0.7) {
            // Хорошая схожесть при большом общем корне
            return 0.75 + ($rootRatio - 0.7) * 0.5; // 0.75 - 0.90
        }

        return max($baseSimilarity, $rootRatio * 0.85);
    }


    /**
     * Поиск общего корня двух строк
     * Возвращает общий префикс или false, если корень слишком короткий
     */
    private function findCommonRoot($str1, $str2)
    {
        $len1 = mb_strlen($str1, 'UTF-8');
        $len2 = mb_strlen($str2, 'UTF-8');
        $minLen = min($len1, $len2);

        // Общий корень должен быть не менее 4 символов
        if ($minLen < 4) {
            return false;
        }

        $root = '';
        for ($i = 0; $i < $minLen; $i++) {
            $char1 = mb_substr($str1, $i, 1, 'UTF-8');
            $char2 = mb_substr($str2, $i, 1, 'UTF-8');

            if ($char1 === $char2) {
                $root .= $char1;
            } else {
                break;
            }
        }

        $rootLen = mb_strlen($root, 'UTF-8');

        // Корень должен быть не менее 4 символов или 60% от короткой строки
        if ($rootLen >= 4 || ($rootLen >= 3 && $rootLen / $minLen >= 0.6)) {
            return $root;
        }

        return false;
    }


    /**
     * Проверка, является ли одна фамилия производной от другой
     * "Белянинов" ← "Белянин" (добавлены окончания)
     * "Белянинова" → "Белянинов" (нормализация женского рода)
     */
    private function isDerivativeLastName($ln1, $ln2, $commonRoot)
    {
        $rootLen = mb_strlen($commonRoot, 'UTF-8');

        // Получаем суффиксы после общего корня
        $suffix1 = mb_substr($ln1, $rootLen, null, 'UTF-8');
        $suffix2 = mb_substr($ln2, $rootLen, null, 'UTF-8');

        // Типичные русские суффиксы/окончания
        $derivativeSuffixes = [
            '',        // базовая форма
            'ов', 'ев', 'ёв', 'ин', 'ын',  // мужские
            'ова', 'ева', 'ёва', 'ина', 'ына',  // женские
            'ский', 'цкий', 'ской', 'цкой',  // прилагательные
            'ская', 'цкая',  // женские прилагательные
            'ко', 'енко', 'чук', 'ук', 'юк',  // украинские
            'их', 'ых',  // сибирские
            'овский', 'евский', 'инский',  // комбинированные
            'овская', 'евская', 'инская',
        ];

        $s1IsDerivative = in_array($suffix1, $derivativeSuffixes, true);
        $s2IsDerivative = in_array($suffix2, $derivativeSuffixes, true);

        // Одна из фамилий — базовая, другая — производная
        if (($s1IsDerivative && $suffix2 === '') ||
            ($s2IsDerivative && $suffix1 === '')) {
            return true;
        }

        // Обе производные, но разные (например, "ов" vs "ова")
        if ($s1IsDerivative && $s2IsDerivative && $suffix1 !== $suffix2) {
            return true;
        }

        // Проверяем, что одна строка является частью другой
        if (empty($suffix1) || empty($suffix2)) {
            return true;
        }

        // Дополнительная проверка: возможно, общий корень + суффикс
        $minSuffixLen = min(mb_strlen($suffix1, 'UTF-8'), mb_strlen($suffix2, 'UTF-8'));
        if ($minSuffixLen <= 3) {
            return true;  // Короткие суффиксы — вероятно, однокоренные
        }

        return false;
    }


    /**
     * Нормализация инициалов
     * "А. С." и "А.С." и "Александр Сергеевич" → "А. С."
     */
    private function normalizeInitials($name)
    {
        $name = trim($name);

        // Если это инициалы с точками — убираем лишние пробелы
        if (preg_match('/^[А-ЯA-Z]\.[\s]*[А-ЯA-Z]\.?$/ui', $name)) {
            return preg_replace('/\s+/', ' ', $name);
        }

        // Если есть отдельные буквы с точками или без — приводим к формату "А."
        $name = preg_replace('/\b([А-ЯA-Z])\b(?!\.)/u', '$1.', $name);

        // Убираем множественные точки
        $name = preg_replace('/\.+/', '.', $name);

        // Приводим к нижнему регистру и убираем диакритику
        $name = NameParser::removeDiacritics(mb_strtolower($name, 'UTF-8'));
        $name = str_replace('ё', 'е', $name);

        return $name;
    }

    /**
     * Проверка, является ли одно имя инициалом другого
     * "а." совпадает с "александр"
     * "а. с." совпадает с "александр сергеевич"
     */
    private function isInitialMatch($name1, $name2)
    {
        $parts1 = preg_split('/\s+/', $name1);
        $parts2 = preg_split('/\s+/', $name2);

        if (count($parts1) !== count($parts2)) {
            return false;
        }

        for ($i = 0; $i < count($parts1); $i++) {
            $p1 = $parts1[$i];
            $p2 = $parts2[$i];

            // Если обе части — инициалы
            if (preg_match('/^[a-zа-я]\.$/ui', $p1) && preg_match('/^[a-zа-я]\.$/ui', $p2)) {
                if (mb_substr($p1, 0, 1) !== mb_substr($p2, 0, 1)) {
                    return false;
                }
                continue;
            }

            // Если одна часть — инициал, а другая — полное имя
            if (preg_match('/^[a-zа-я]\.$/ui', $p1) && !preg_match('/\./', $p2)) {
                if (mb_substr($p1, 0, 1) !== mb_substr($p2, 0, 1)) {
                    return false;
                }
                continue;
            }

            if (!preg_match('/\./', $p1) && preg_match('/^[a-zа-я]\.$/ui', $p2)) {
                if (mb_substr($p1, 0, 1) !== mb_substr($p2, 0, 1)) {
                    return false;
                }
                continue;
            }

            // Если обе части — полные имена
            if ($p1 !== $p2) {
                return false;
            }
        }

        return true;
    }

    private function checkContainment($str1, $str2)
    {
        $s1 = NameParser::removeDiacritics(mb_strtolower(trim($str1), 'UTF-8'));
        $s2 = NameParser::removeDiacritics(mb_strtolower(trim($str2), 'UTF-8'));

        $len1 = mb_strlen($s1, 'UTF-8');
        $len2 = mb_strlen($s2, 'UTF-8');

        if ($len1 == 0 || $len2 == 0) {
            return 0;
        }

        // Проверяем полное вхождение одной строки в другую
        if (mb_strpos($s1, $s2) !== false || mb_strpos($s2, $s1) !== false) {
            $commonLen = min($len1, $len2);
            $totalLen = max($len1, $len2);
            $ratio = $commonLen / $totalLen;

            if ($ratio > 0.7) {
                return 0.9;
            } elseif ($ratio > 0.5) {
                return 0.6;
            }
            return 0.3;
        }

        // Проверяем вхождение значительной части
        $minLen = min($len1, $len2);
        $maxLen = max($len1, $len2);

        // Ищем общую подстроку минимальной длины 5 символов
        $minSubstrLen = min(5, (int)($minLen * 0.7));

        for ($subLen = $minLen; $subLen >= $minSubstrLen; $subLen--) {
            for ($start = 0; $start <= $minLen - $subLen; $start++) {
                $substr = mb_substr($minLen === $len1 ? $s1 : $s2, $start, $subLen, 'UTF-8');
                if (mb_strpos($maxLen === $len1 ? $s1 : $s2, $substr) !== false) {
                    $ratio = $subLen / $maxLen;
                    return 0.3 + $ratio * 0.5; // 0.3 - 0.8 в зависимости от длины общей части
                }
            }
        }

        return 0;
    }

    /**
     * Схожесть строк: улучшенный алгоритм
     */
    private function calculateStringSimilarity($str1, $str2)
    {
        $s1 = NameParser::removeDiacritics(mb_strtolower(trim($str1), 'UTF-8'));
        $s2 = NameParser::removeDiacritics(mb_strtolower(trim($str2), 'UTF-8'));

        // Ё → Е
        $s1 = str_replace(['ё', 'Ё'], ['е', 'Е'], $s1);
        $s2 = str_replace(['ё', 'Ё'], ['е', 'Е'], $s2);

        if ($s1 === $s2) {
            return 1.0;
        }

        $len1 = mb_strlen($s1, 'UTF-8');
        $len2 = mb_strlen($s2, 'UTF-8');

        // Короткие строки: только точное совпадение
        if ($len1 <= 2 || $len2 <= 2) {
            return ($s1 === $s2) ? 1.0 : 0.0;
        }

        // Для коротких строк используем более строгое сравнение
        if ($len1 <= 4 || $len2 <= 4) {
            $distance = $this->levenshtein_utf8($s1, $s2, 1);
            $maxLen = max($len1, $len2);
            return ($maxLen === 0) ? 0 : 1 - ($distance / $maxLen);
        }

        $distance = $this->levenshtein_utf8($s1, $s2, 3);
        $maxLen = max($len1, $len2);
        return ($maxLen === 0) ? 0 : 1 - ($distance / $maxLen);
    }

    /**
     * Оптимизированное расстояние Левенштейна для UTF-8
     */
    private function levenshtein_utf8($str1, $str2, $maxDistance = 4)
    {
        if ($str1 === $str2) {
            return 0;
        }

        $len1 = mb_strlen($str1, 'UTF-8');
        $len2 = mb_strlen($str2, 'UTF-8');

        if (abs($len1 - $len2) > $maxDistance) {
            return $maxDistance + 1;
        }

        if ($len1 === 0) {
            return $len2;
        }
        if ($len2 === 0) {
            return $len1;
        }

        $chars1 = preg_split('//u', $str1, -1, PREG_SPLIT_NO_EMPTY);
        $chars2 = preg_split('//u', $str2, -1, PREG_SPLIT_NO_EMPTY);

        $prevRow = range(0, $len2);
        $currentRow = array_fill(0, $len2 + 1, 0);

        for ($i = 1; $i <= $len1; $i++) {
            $currentRow[0] = $i;
            $minInRow = $i;

            for ($j = max(1, $i - $maxDistance); $j <= min($len2, $i + $maxDistance); $j++) {
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

            if ($minInRow > $maxDistance) {
                return $maxDistance + 1;
            }

            $temp = $prevRow;
            $prevRow = $currentRow;
            $currentRow = $temp;
        }

        return $prevRow[$len2];
    }

    private function selectMainAuthor(array $variants): string
    {
        $scores = [];
        $pdo = $this->db->getConnection();

        foreach ($variants as $author) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM books WHERE author = ?");
            $stmt->execute([$author]);
            $count = (int)$stmt->fetchColumn();

            // Предпочитаем более полные имена (с фамилией и инициалами)
            $completenessBonus = 0;
            $parsed = NameParser::parse($author);
            if (!empty($parsed['firstName'])) {
                $completenessBonus += 10;
            }
            if (!empty($parsed['patronymic'])) {
                $completenessBonus += 5;
            }

            $scores[$author] = $count * 1000 + $completenessBonus + mb_strlen($author, 'UTF-8');
        }

        arsort($scores);
        return key($scores);
    }

    private function getBookCount($author): int
    {
        $stmt = $this->db->getConnection()->prepare("SELECT COUNT(*) FROM books WHERE author = ?");
        $stmt->execute([$author]);
        return (int)$stmt->fetchColumn();
    }

    public function getAllAuthorsList($page = 1, $perPage = 100, $search = '')
    {
        $offset = ($page - 1) * $perPage;
        $pdo = $this->db->getConnection();

        $params = [];
        $where = "WHERE author IS NOT NULL AND author != '' AND LENGTH(author) >= 3";

        if (!empty($search)) {
            $where .= " AND author LIKE ?";
            $params[] = '%' . $search . '%';
        }

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

        $countSql = "SELECT COUNT(DISTINCT author) as total FROM books {$where}";
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

            if (class_exists('Cache')) {
                Cache::invalidateByType('statistics');
                Cache::invalidateByType('search_results');
            }

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



    /**
         * Сравнение имён с учётом инициалов
         */
    private function compareNames($name1, $name2)
    {
        if (empty($name1) && empty($name2)) {
            return 1.0; // Оба имени отсутствуют
        }

        if (empty($name1) || empty($name2)) {
            return 0.3; // Одно имя отсутствует
        }

        // Нормализуем инициалы
        $n1 = $this->normalizeInitials($name1);
        $n2 = $this->normalizeInitials($name2);

        // Если после нормализации строки совпадают
        if ($n1 === $n2) {
            return 1.0;
        }

        // Проверяем, является ли одно имя инициалом другого
        if ($this->isInitialMatch($n1, $n2)) {
            return 0.9;
        }

        // Иначе — строковое сравнение
        return $this->calculateStringSimilarity($n1, $n2);
    }

}
