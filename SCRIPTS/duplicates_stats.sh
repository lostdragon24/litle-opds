#!/bin/bash

# Расширенная статистика по дубликатам
# Использование: ./duplicates_stats.sh [путь_к_базе_данных]

set -e

DB_PATH="${1:-./library.db}"

if [[ ! -f "$DB_PATH" ]]; then
    echo "❌ Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

echo "📊 РАСШИРЕННАЯ СТАТИСТИКА ДУБЛИКАТОВ"
echo "================================================"

run_sql() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

# 1. Топ дубликатов по размеру
echo "1. ТОП ДУБЛИКАТОВ ПО РАЗМЕРУ:"
echo "-----------------------------"
run_sql "
SELECT 
    file_hash as 'Хеш',
    COUNT(*) as 'Копий',
    SUM(file_size) as 'Общий размер',
    MAX(file_size) as 'Макс. размер',
    MIN(file_size) as 'Мин. размер',
    GROUP_CONCAT(file_path, ' | ') as 'Файлы'
FROM books 
WHERE file_hash IS NOT NULL AND file_hash != ''
GROUP BY file_hash 
HAVING COUNT(*) > 1
ORDER BY SUM(file_size) DESC
LIMIT 10;"

# 2. Дубликаты по типам файлов
echo ""
echo "2. ДУБЛИКАТЫ ПО ТИПАМ ФАЙЛОВ:"
echo "-----------------------------"
run_sql "
SELECT 
    file_type as 'Тип файла',
    COUNT(*) as 'Всего файлов',
    SUM(CASE WHEN dup_count > 1 THEN 1 ELSE 0 END) as 'Файлов с дубликатами',
    ROUND(SUM(CASE WHEN dup_count > 1 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as 'Процент дубликатов'
FROM (
    SELECT 
        file_type,
        file_hash,
        COUNT(*) as dup_count
    FROM books 
    WHERE file_hash IS NOT NULL
    GROUP BY file_type, file_hash
)
GROUP BY file_type
ORDER BY SUM(CASE WHEN dup_count > 1 THEN 1 ELSE 0 END) DESC;"

# 3. Дубликаты по авторам
echo ""
echo "3. АВТОРЫ С НАИБОЛЬШИМ КОЛИЧЕСТВОМ ДУБЛИКАТОВ:"
echo "----------------------------------------------"
run_sql "
SELECT 
    author as 'Автор',
    COUNT(*) as 'Всего книг',
    SUM(dup_count) as 'Дубликатов',
    ROUND(SUM(dup_count) * 100.0 / COUNT(*), 2) as 'Процент дубликатов'
FROM (
    SELECT 
        author,
        title,
        COUNT(*) as dup_count
    FROM books 
    WHERE author IS NOT NULL AND title IS NOT NULL
    GROUP BY author, title
    HAVING COUNT(*) > 1
)
GROUP BY author
ORDER BY SUM(dup_count) DESC
LIMIT 15;"

# 4. Экономия места при удалении дубликатов
echo ""
echo "4. ЭКОНОМИЯ МЕСТА ПРИ УДАЛЕНИИ ДУБЛИКАТОВ:"
echo "------------------------------------------"
run_sql "
SELECT 
    'По хешу' as 'Тип',
    COUNT(*) as 'Групп дубликатов',
    SUM(file_count - 1) as 'Файлов для удаления',
    SUM(total_size - max_size) as 'Экономия (байт)',
    ROUND(SUM(total_size - max_size) / 1024.0 / 1024.0, 2) as 'Экономия (МБ)'
FROM (
    SELECT 
        file_hash,
        COUNT(*) as file_count,
        SUM(file_size) as total_size,
        MAX(file_size) as max_size
    FROM books 
    WHERE file_hash IS NOT NULL AND file_hash != ''
    GROUP BY file_hash 
    HAVING COUNT(*) > 1
)
UNION ALL
SELECT 
    'По названию/автору',
    COUNT(*),
    SUM(file_count - 1),
    SUM(total_size - max_size),
    ROUND(SUM(total_size - max_size) / 1024.0 / 1024.0, 2)
FROM (
    SELECT 
        title,
        author,
        COUNT(*) as file_count,
        SUM(file_size) as total_size,
        MAX(file_size) as max_size
    FROM books 
    WHERE title IS NOT NULL AND author IS NOT NULL
    GROUP BY title, author 
    HAVING COUNT(*) > 1
);"