#!/bin/bash

# Детальный просмотр структуры и данных таблицы books
# Использование: ./show_book_details.sh [путь_к_базе_данных] [количество_записей]

set -e

DB_PATH="${1:-./library.db}"
LIMIT="${2:-10}"

if [[ ! -f "$DB_PATH" ]]; then
    echo "❌ Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

echo "📖 ДЕТАЛЬНЫЙ ПРОСМОТР ТАБЛИЦЫ BOOKS"
echo "================================================"
echo "📁 База данных: $DB_PATH"
echo "📅 Дата: $(date '+%Y-%m-%d %H:%M:%S')"
echo ""

# Функция для выполнения SQL
run_sql() {
    sqlite3 "$DB_PATH" "$1"
}

# Функция для выполнения SQL с выводом
run_sql_header() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

# 1. Общая информация о таблице
echo "1. ОБЩАЯ ИНФОРМАЦИЯ О ТАБЛИЦЕ:"
echo "==============================="
run_sql_header "
SELECT 
    name as 'Таблица',
    (SELECT COUNT(*) FROM books) as 'Записей',
    (SELECT COUNT(*) FROM sqlite_master WHERE type='index' AND tbl_name='books') as 'Индексов'
FROM sqlite_master 
WHERE type='table' AND name='books';"

# 2. Детальная структура таблицы
echo ""
echo "2. СТРУКТУРА ТАБЛИЦЫ BOOKS:"
echo "============================"
run_sql_header "
SELECT 
    cid as '№',
    name as 'Колонка',
    type as 'Тип',
    CASE WHEN [notnull] = 1 THEN 'NOT NULL' ELSE 'NULL' END as 'Ограничение',
    CASE WHEN pk = 1 THEN 'PRIMARY KEY' ELSE '' END as 'Ключ',
    COALESCE(dflt_value, 'NULL') as 'Значение по умолчанию'
FROM pragma_table_info('books')
ORDER BY cid;"

# 3. Статистика заполнения колонок
echo ""
echo "3. СТАТИСТИКА ЗАПОЛНЕНИЯ КОЛОНОК:"
echo "================================="
run_sql_header "
SELECT 
    name as 'Колонка',
    (SELECT COUNT(*) FROM books WHERE name IS NOT NULL) as 'Заполнено',
    (SELECT COUNT(*) FROM books) as 'Всего',
    ROUND((SELECT COUNT(*) FROM books WHERE name IS NOT NULL) * 100.0 / (SELECT COUNT(*) FROM books), 2) as 'Процент %'
FROM pragma_table_info('books')
WHERE name IN ('file_hash', 'file_size', 'title', 'author', 'genre', 'series', 'year', 'language', 'publisher', 'description')
UNION ALL
SELECT 
    'file_path' as 'Колонка',
    (SELECT COUNT(*) FROM books WHERE file_path IS NOT NULL AND file_path != ''),
    (SELECT COUNT(*) FROM books),
    ROUND((SELECT COUNT(*) FROM books WHERE file_path IS NOT NULL AND file_path != '') * 100.0 / (SELECT COUNT(*) FROM books), 2)
ORDER BY 'Процент %' DESC;"

# 4. Примеры данных с ВСЕМИ полями
echo ""
echo "4. ПРИМЕРЫ ДАННЫХ (первые $LIMIT записей):"
echo "=========================================="
run_sql_header "
SELECT 
    id as 'ID',
    substr(file_path, 1, 30) as 'Путь к файлу',
    file_name as 'Имя файла',
    file_size as 'Размер',
    file_type as 'Тип файла',
    archive_path as 'Путь к архиву',
    archive_internal_path as 'Внутренний путь',
    substr(file_hash, 1, 10) as 'Хеш',
    substr(title, 1, 20) as 'Название',
    substr(author, 1, 15) as 'Автор',
    substr(genre, 1, 15) as 'Жанр',
    substr(series, 1, 15) as 'Серия',
    series_number as '№ в серии',
    year as 'Год',
    language as 'Язык',
    substr(publisher, 1, 15) as 'Издатель',
    CASE 
        WHEN description IS NULL THEN 'NULL'
        WHEN description = '' THEN 'EMPTY'
        ELSE substr(description, 1, 20) || '...'
    END as 'Описание',
    added_date as 'Дата добавления',
    last_modified as 'Посл. изменение',
    last_scanned as 'Посл. сканирование',
    file_mtime as 'Время файла'
FROM books 
ORDER BY id 
LIMIT $LIMIT;"

# 5. Анализ заполнения конкретных полей
echo ""
echo "5. АНАЛИЗ ЗАПОЛНЕНИЯ КЛЮЧЕВЫХ ПОЛЕЙ:"
echo "===================================="

# file_hash
echo "🔹 ПОЛЕ file_hash:"
run_sql_header "
SELECT 
    CASE 
        WHEN file_hash IS NULL THEN 'NULL'
        WHEN file_hash = '' THEN 'EMPTY'
        ELSE 'HAS VALUE'
    END as 'Статус',
    COUNT(*) as 'Количество',
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM books), 2) as 'Процент %'
FROM books 
GROUP BY 
    CASE 
        WHEN file_hash IS NULL THEN 'NULL'
        WHEN file_hash = '' THEN 'EMPTY'
        ELSE 'HAS VALUE'
    END;"

# file_size
echo ""
echo "🔹 ПОЛЕ file_size:"
run_sql_header "
SELECT 
    CASE 
        WHEN file_size IS NULL THEN 'NULL'
        WHEN file_size = 0 THEN 'ZERO'
        ELSE 'HAS VALUE'
    END as 'Статус',
    COUNT(*) as 'Количество',
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM books), 2) as 'Процент %'
FROM books 
GROUP BY 
    CASE 
        WHEN file_size IS NULL THEN 'NULL'
        WHEN file_size = 0 THEN 'ZERO'
        ELSE 'HAS VALUE'
    END;"

# title и author
echo ""
echo "🔹 ПОЛЯ title И author:"
run_sql_header "
SELECT 
    CASE 
        WHEN title IS NULL OR title = '' THEN 'NO TITLE'
        WHEN author IS NULL OR author = '' THEN 'NO AUTHOR'
        ELSE 'BOTH FILLED'
    END as 'Статус',
    COUNT(*) as 'Количество',
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM books), 2) as 'Процент %'
FROM books 
GROUP BY 
    CASE 
        WHEN title IS NULL OR title = '' THEN 'NO TITLE'
        WHEN author IS NULL OR author = '' THEN 'NO AUTHOR'
        ELSE 'BOTH FILLED'
    END;"

# 6. Типы файлов в коллекции
echo ""
echo "6. РАСПРЕДЕЛЕНИЕ ПО ТИПАМ ФАЙЛОВ:"
echo "================================="
run_sql_header "
SELECT 
    file_type as 'Тип файла',
    COUNT(*) as 'Количество',
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM books), 2) as 'Процент %'
FROM books 
WHERE file_type IS NOT NULL AND file_type != ''
GROUP BY file_type 
ORDER BY COUNT(*) DESC;"

# 7. Книги в архивах
echo ""
echo "7. КНИГИ В АРХИВАХ:"
echo "==================="
run_sql_header "
SELECT 
    CASE 
        WHEN archive_path IS NOT NULL THEN 'IN ARCHIVE'
        ELSE 'REGULAR FILE'
    END as 'Тип',
    COUNT(*) as 'Количество',
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(*) FROM books), 2) as 'Процент %'
FROM books 
GROUP BY 
    CASE 
        WHEN archive_path IS NOT NULL THEN 'IN ARCHIVE'
        ELSE 'REGULAR FILE'
    END;"

# 8. Примеры записей с разными типами проблем
echo ""
echo "8. ПРИМЕРЫ ЗАПИСЕЙ С ПРОБЛЕМАМИ:"
echo "================================"

# Записи без file_hash
echo "🔸 ЗАПИСИ БЕЗ file_hash:"
run_sql_header "
SELECT 
    id as 'ID',
    substr(file_path, 1, 30) as 'Путь',
    substr(title, 1, 20) as 'Название',
    substr(author, 1, 15) as 'Автор'
FROM books 
WHERE file_hash IS NULL OR file_hash = ''
ORDER BY id 
LIMIT 5;"

# Записи без file_size
echo ""
echo "🔸 ЗАПИСИ БЕЗ file_size:"
run_sql_header "
SELECT 
    id as 'ID',
    substr(file_path, 1, 30) as 'Путь',
    substr(title, 1, 20) as 'Название',
    substr(author, 1, 15) as 'Автор'
FROM books 
WHERE file_size IS NULL
ORDER BY id 
LIMIT 5;"

# Записи без title или author
echo ""
echo "🔸 ЗАПИСИ БЕЗ НАЗВАНИЯ ИЛИ АВТОРА:"
run_sql_header "
SELECT 
    id as 'ID',
    substr(file_path, 1, 30) as 'Путь',
    CASE 
        WHEN title IS NULL OR title = '' THEN 'NO TITLE'
        ELSE substr(title, 1, 20)
    END as 'Название',
    CASE 
        WHEN author IS NULL OR author = '' THEN 'NO AUTHOR'
        ELSE substr(author, 1, 15)
    END as 'Автор'
FROM books 
WHERE title IS NULL OR title = '' OR author IS NULL OR author = ''
ORDER BY id 
LIMIT 5;"

echo ""
echo "================================================"
echo "📊 ВСЕГО ЗАПИСЕЙ В БАЗЕ: $(run_sql "SELECT COUNT(*) FROM books;")"
echo "💡 Для просмотра большего количества записей: $0 $DB_PATH 50"