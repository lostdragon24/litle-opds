#!/bin/bash

# Расширенная статистика базы данных
# Использование: ./db_detailed_stats.sh [путь_к_базе_данных]

set -e

DB_PATH="${1:-./library.db}"

if [[ ! -f "$DB_PATH" ]]; then
    echo "Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

run_sql() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

print_header() {
    echo
    echo "================================================"
    echo "$1"
    echo "================================================"
}

print_header "📈 РАСШИРЕННАЯ СТАТИСТИКА"

# Размер базы данных
DB_SIZE=$(du -h "$DB_PATH" | cut -f1)
echo "💾 Размер базы данных: $DB_SIZE"

# Количество записей в каждой таблице
print_header "🗃️  ЗАПИСЕЙ В ТАБЛИЦАХ"
run_sql "SELECT 
    name as 'Таблица', 
    (SELECT COUNT(*) FROM books) as 'Записей'
FROM sqlite_master 
WHERE type='table';"

# Архивы и их содержимое
print_header "📦 СТАТИСТИКА ПО АРХИВАМ"
run_sql "SELECT 
    archive_path as 'Архив',
    file_count as 'Файлов',
    total_size as 'Размер',
    datetime(last_scanned) as 'Последнее сканирование'
FROM archives 
ORDER BY file_count DESC 
LIMIT 10;"

# Самые большие серии
print_header "🏆 САМЫЕ БОЛЬШИЕ СЕРИИ"
run_sql "SELECT 
    series as 'Серия',
    COUNT(*) as 'Книг',
    MIN(year) as 'Первый год',
    MAX(year) as 'Последний год'
FROM books 
WHERE series IS NOT NULL AND series != ''
GROUP BY series 
HAVING COUNT(*) > 1
ORDER BY COUNT(*) DESC 
LIMIT 10;"

# Авторы с наибольшим количеством серий
print_header "🎭 АВТОРЫ С НАИБОЛЬШИМ КОЛИЧЕСТВОМ СЕРИЙ"
run_sql "SELECT 
    author as 'Автор',
    COUNT(DISTINCT series) as 'Серий',
    COUNT(*) as 'Всего книг'
FROM books 
WHERE author IS NOT NULL 
  AND author != '' 
  AND series IS NOT NULL 
  AND series != ''
GROUP BY author 
ORDER BY COUNT(DISTINCT series) DESC 
LIMIT 10;"

# Активность добавления книг по времени
print_header "📊 АКТИВНОСТЬ ДОБАВЛЕНИЯ КНИГ"
run_sql "SELECT 
    DATE(added_date) as 'Дата',
    COUNT(*) as 'Книг добавлено'
FROM books 
GROUP BY DATE(added_date) 
ORDER BY DATE(added_date) DESC 
LIMIT 10;"