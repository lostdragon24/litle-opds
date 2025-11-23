#!/bin/bash

# Поиск дубликатов книг в базе данных
# Использование: ./find_duplicates.sh [путь_к_базе_данных]

set -e

DB_PATH="${1:-./library.db}"

if [[ ! -f "$DB_PATH" ]]; then
    echo "❌ Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

echo "🔍 ПОИСК ДУБЛИКАТОВ КНИГ"
echo "================================================"
echo "📁 База данных: $DB_PATH"
echo "📅 Дата проверки: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# Функция для выполнения SQL запроса
run_sql() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

# 1. Дубликаты по хешу файла
echo "1. ДУБЛИКАТЫ ПО ХЕШУ ФАЙЛА:"
echo "---------------------------"
run_sql "
SELECT 
    file_hash as 'Хеш',
    COUNT(*) as 'Дубликатов',
    GROUP_CONCAT(file_path, ' | ') as 'Файлы'
FROM books 
WHERE file_hash IS NOT NULL AND file_hash != ''
GROUP BY file_hash 
HAVING COUNT(*) > 1
ORDER BY COUNT(*) DESC
LIMIT 20;"

# 2. Дубликаты по названию и автору
echo ""
echo "2. ДУБЛИКАТЫ ПО НАЗВАНИЮ И АВТОРУ:"
echo "----------------------------------"
run_sql "
SELECT 
    title as 'Название',
    author as 'Автор', 
    COUNT(*) as 'Дубликатов',
    GROUP_CONCAT(file_path, ' | ') as 'Файлы'
FROM books 
WHERE title IS NOT NULL AND author IS NOT NULL
GROUP BY title, author 
HAVING COUNT(*) > 1
ORDER BY COUNT(*) DESC
LIMIT 20;"

# 3. Дубликаты по пути файла (разные версии)
echo ""
echo "3. ДУБЛИКАТЫ ПО ПУТИ ФАЙЛА:"
echo "---------------------------"
run_sql "
SELECT 
    file_path as 'Путь',
    COUNT(*) as 'Записей',
    GROUP_CONCAT(id, ', ') as 'ID записей'
FROM books 
GROUP BY file_path 
HAVING COUNT(*) > 1
ORDER BY COUNT(*) DESC
LIMIT 15;"

# 4. Статистика дубликатов
echo ""
echo "4. СТАТИСТИКА ДУБЛИКАТОВ:"
echo "-------------------------"
run_sql "
SELECT 
    'По хешу' as 'Тип',
    COUNT(DISTINCT file_hash) as 'Дубликатных групп',
    SUM(cnt) - COUNT(*) as 'Всего дубликатов'
FROM (
    SELECT file_hash, COUNT(*) as cnt
    FROM books 
    WHERE file_hash IS NOT NULL 
    GROUP BY file_hash 
    HAVING COUNT(*) > 1
)
UNION ALL
SELECT 
    'По названию и автору',
    COUNT(DISTINCT title || '|' || author),
    SUM(cnt) - COUNT(*)
FROM (
    SELECT title, author, COUNT(*) as cnt
    FROM books 
    WHERE title IS NOT NULL AND author IS NOT NULL
    GROUP BY title, author 
    HAVING COUNT(*) > 1
);"

# 5. Книги без хеша (потенциальные проблемы)
echo ""
echo "5. КНИГИ БЕЗ ХЕША:"
echo "------------------"
run_sql "
SELECT COUNT(*) as 'Книг без хеша' 
FROM books 
WHERE file_hash IS NULL OR file_hash = '';"