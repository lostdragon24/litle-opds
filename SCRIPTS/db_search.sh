#!/bin/bash

# Поиск книг в базе данных
# Использование: ./db_search.sh [термин_поиска] [путь_к_базе_данных]

set -e

SEARCH_TERM="$1"
DB_PATH="${2:-./library.db}"

if [[ -z "$SEARCH_TERM" ]]; then
    echo "Использование: $0 [термин_поиска] [путь_к_базе_данных]"
    echo "Примеры:"
    echo "  $0 'Лукьяненко'"
    echo "  $0 'фантастика' ./books.db"
    exit 1
fi

if [[ ! -f "$DB_PATH" ]]; then
    echo "Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

run_sql() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

echo "🔍 Результаты поиска: '$SEARCH_TERM'"
echo "================================================"

# Поиск по названию
echo -e "\n📖 ПО НАЗВАНИЮ:"
run_sql "SELECT 
    title as 'Название',
    author as 'Автор',
    series as 'Серия',
    year as 'Год'
FROM books 
WHERE title LIKE '%$SEARCH_TERM%' 
ORDER BY year DESC 
LIMIT 10;"

# Поиск по автору
echo -e "\n👤 ПО АВТОРУ:"
run_sql "SELECT 
    title as 'Название',
    author as 'Автор', 
    series as 'Серия',
    year as 'Год'
FROM books 
WHERE author LIKE '%$SEARCH_TERM%' 
ORDER BY year DESC 
LIMIT 10;"

# Поиск по серии
echo -e "\n📚 ПО СЕРИИ:"
run_sql "SELECT 
    title as 'Название',
    author as 'Автор',
    series as 'Серия',
    series_number as '№'
FROM books 
WHERE series LIKE '%$SEARCH_TERM%' 
ORDER BY series, series_number 
LIMIT 10;"

# Поиск по жанру
echo -e "\n🎭 ПО ЖАНРУ:"
run_sql "SELECT 
    title as 'Название',
    author as 'Автор',
    genre as 'Жанр',
    year as 'Год'
FROM books 
WHERE genre LIKE '%$SEARCH_TERM%' 
ORDER BY year DESC 
LIMIT 10;"