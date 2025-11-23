#!/bin/bash

# Проверка структуры базы данных
# Использование: ./check_database.sh [путь_к_базе_данных]

set -e

DB_PATH="${1:-./library.db}"

if [[ ! -f "$DB_PATH" ]]; then
    echo "❌ Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

echo "🔍 ПРОВЕРКА СТРУКТУРЫ БАЗЫ ДАННЫХ"
echo "================================================"
echo "📁 База данных: $DB_PATH"
echo ""

run_sql() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

# 1. Список таблиц
echo "1. ТАБЛИЦЫ В БАЗЕ:"
echo "-----------------"
run_sql "SELECT name as 'Таблица' FROM sqlite_master WHERE type='table' ORDER BY name;"

# 2. Структура таблицы books
echo ""
echo "2. СТРУКТУРА ТАБЛИЦЫ BOOKS:"
echo "---------------------------"
run_sql "PRAGMA table_info(books);"

# 3. Проверка важных колонок
echo ""
echo "3. ПРОВЕРКА ВАЖНЫХ КОЛОНОК:"
echo "---------------------------"
run_sql "
SELECT 
    name as 'Колонка',
    CASE 
        WHEN name = 'file_hash' THEN '✅ ДЛЯ ДУБЛИКАТОВ ПО ХЕШУ'
        WHEN name = 'file_size' THEN '✅ ДЛЯ ВЫБОРА САМОГО БОЛЬШОГО ФАЙЛА'
        WHEN name = 'title' THEN '✅ ДЛЯ ДУБЛИКАТОВ ПО НАЗВАНИЮ'
        WHEN name = 'author' THEN '✅ ДЛЯ ДУБЛИКАТОВ ПО АВТОРУ'
        WHEN name = 'file_path' THEN '✅ ДЛЯ УДАЛЕНИЯ ФАЙЛОВ'
        ELSE 'ℹ️  ОБЫЧНАЯ'
    END as 'Назначение'
FROM pragma_table_info('books')
WHERE name IN ('file_hash', 'file_size', 'title', 'author', 'file_path')
ORDER BY name;"

# 4. Статистика по данным
echo ""
echo "4. СТАТИСТИКА ДАННЫХ:"
echo "---------------------"
run_sql "
SELECT 
    'Всего книг' as 'Метрика',
    COUNT(*) as 'Значение'
FROM books
UNION ALL
SELECT 
    'Книг с file_hash',
    COUNT(*)
FROM books 
WHERE file_hash IS NOT NULL AND file_hash != ''
UNION ALL
SELECT 
    'Книг с file_size',
    COUNT(*)
FROM books 
WHERE file_size IS NOT NULL
UNION ALL
SELECT 
    'Книг с title и author',
    COUNT(*)
FROM books 
WHERE title IS NOT NULL AND author IS NOT NULL;"

# 5. Примеры данных
echo ""
echo "5. ПРИМЕРЫ ДАННЫХ:"
echo "------------------"
run_sql "
SELECT 
    id as 'ID',
    substr(title, 1, 20) as 'Название',
    substr(author, 1, 15) as 'Автор',
    file_size as 'Размер',
    substr(file_hash, 1, 10) as 'Хеш'
FROM books 
ORDER BY id 
LIMIT 5;"