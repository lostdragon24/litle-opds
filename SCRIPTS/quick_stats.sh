#!/bin/bash

# Быстрая статистика базы данных

# Цвета
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

# Поиск файла БД
find_db() {
    for file in books.db library.db *.db; do
        if [ -f "$file" ]; then
            echo "$file"
            return
        fi
    done
    echo ""
}

DB_FILE=$(find_db)

if [ -z "$DB_FILE" ]; then
    echo "Файл базы данных не найден!"
    exit 1
fi

echo -e "${GREEN}📊 БЫСТРАЯ СТАТИСТИКА: $DB_FILE${NC}"
echo "================================"

# Основные цифры
TOTAL_BOOKS=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM books;" 2>/dev/null || echo "0")
AUTHORS_COUNT=$(sqlite3 "$DB_FILE" "SELECT COUNT(DISTINCT author) FROM books WHERE author IS NOT NULL;" 2>/dev/null || echo "0")
GENRES_COUNT=$(sqlite3 "$DB_FILE" "SELECT COUNT(DISTINCT genre) FROM books WHERE genre IS NOT NULL;" 2>/dev/null || echo "0")
ARCHIVES_COUNT=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM archives;" 2>/dev/null || echo "0")

echo -e "📚 Книг: ${YELLOW}$TOTAL_BOOKS${NC}"
echo -e "👤 Авторов: ${YELLOW}$AUTHORS_COUNT${NC}" 
echo -e "🏷️  Жанров: ${YELLOW}$GENRES_COUNT${NC}"
echo -e "📦 Архивов: ${YELLOW}$ARCHIVES_COUNT${NC}"

# Топ-5 форматов
echo
echo -e "${GREEN}Топ-5 форматов:${NC}"
sqlite3 "$DB_FILE" "
SELECT file_type, COUNT(*) 
FROM books 
GROUP BY file_type 
ORDER BY COUNT(*) DESC 
LIMIT 5;" 2>/dev/null | while IFS='|' read format count; do
    echo -e "  ${YELLOW}$format:${NC} $count"
done

# Последние добавленные
echo
echo -e "${GREEN}Последние добавленные:${NC}"
sqlite3 "$DB_FILE" "
SELECT title, author 
FROM books 
ORDER BY added_date DESC 
LIMIT 3;" 2>/dev/null | while IFS='|' read title author; do
    echo -e "  📖 $title - $author"
done