#!/bin/bash

# Просмотр конкретных книг с полными данными
# Использование: ./show_sample_books.sh [путь_к_базе_данных] [ID_книги или автор]

set -e

DB_PATH="${1:-./library.db}"
FILTER="${2:-}"

if [[ ! -f "$DB_PATH" ]]; then
    echo "❌ Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

echo "📚 ПРОСМОТР КОНКРЕТНЫХ КНИГ"
echo "================================================"
echo "📁 База данных: $DB_PATH"
echo ""

run_sql_header() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

# Если передан ID книги
if [[ "$FILTER" =~ ^[0-9]+$ ]]; then
    echo "🔍 Показываем книгу с ID: $FILTER"
    echo ""
    
    run_sql_header "
    SELECT 
        id as 'ID',
        file_path as 'Полный путь к файлу',
        file_name as 'Имя файла',
        file_size as 'Размер файла (байт)',
        file_type as 'Тип файла',
        archive_path as 'Путь к архиву',
        archive_internal_path as 'Внутренний путь в архиве',
        file_hash as 'Хеш файла',
        title as 'Название',
        author as 'Автор',
        genre as 'Жанр',
        series as 'Серия',
        series_number as 'Номер в серии',
        year as 'Год издания',
        language as 'Язык',
        publisher as 'Издательство',
        description as 'Описание',
        added_date as 'Дата добавления',
        last_modified as 'Последнее изменение',
        last_scanned as 'Последнее сканирование',
        file_mtime as 'Время модификации файла'
    FROM books 
    WHERE id = $FILTER;"

# Если передан автор
elif [[ -n "$FILTER" ]]; then
    echo "🔍 Показываем книги автора: $FILTER"
    echo ""
    
    run_sql_header "
    SELECT 
        id as 'ID',
        substr(file_path, 1, 40) as 'Путь к файлу',
        file_name as 'Имя файла',
        file_size as 'Размер',
        file_type as 'Тип',
        title as 'Название',
        author as 'Автор',
        series as 'Серия',
        year as 'Год',
        added_date as 'Добавлена'
    FROM books 
    WHERE author LIKE '%$FILTER%'
    ORDER BY title
    LIMIT 20;"

# Иначе показываем случайные книги
else
    echo "🔍 СЛУЧАЙНЫЕ КНИГИ ИЗ КОЛЛЕКЦИИ:"
    echo ""
    
    run_sql_header "
    SELECT 
        id as 'ID',
        substr(file_path, 1, 40) as 'Путь к файлу',
        file_name as 'Имя файла',
        file_size as 'Размер',
        file_type as 'Тип',
        title as 'Название',
        author as 'Автор',
        series as 'Серия',
        year as 'Год',
        added_date as 'Добавлена'
    FROM books 
    ORDER BY RANDOM()
    LIMIT 15;"
fi

# Показываем статистику по фильтру
if [[ -n "$FILTER" ]]; then
    echo ""
    echo "📊 СТАТИСТИКА ПО ФИЛЬТРУ '$FILTER':"
    echo "================================="
    
    if [[ "$FILTER" =~ ^[0-9]+$ ]]; then
        run_sql_header "
        SELECT 
            (SELECT COUNT(*) FROM books WHERE id = $FILTER) as 'Найдено записей',
            (SELECT COUNT(*) FROM books) as 'Всего книг в базе';"
    else
        run_sql_header "
        SELECT 
            (SELECT COUNT(*) FROM books WHERE author LIKE '%$FILTER%') as 'Книг автора',
            (SELECT COUNT(DISTINCT author) FROM books WHERE author LIKE '%$FILTER%') as 'Уникальных авторов',
            (SELECT COUNT(*) FROM books) as 'Всего книг в базе';"
    fi
fi