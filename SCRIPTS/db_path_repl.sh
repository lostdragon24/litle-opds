#!/bin/bash

DB_FILE="library.db"
OLD_PATH="/home/alex/Загрузки/book"
NEW_PATH="/mnt/sda1/book/_Lib.rus.ec - Официальная/lib.rus.ec/"

echo "Updating paths in database: $DB_FILE"
echo "Changing: $OLD_PATH -> $NEW_PATH"

# Проверяем существование базы данных
if [ ! -f "$DB_FILE" ]; then
    echo "Error: Database file $DB_FILE not found!"
    exit 1
fi

# Создаем резервную копию
BACKUP_FILE="${DB_FILE}.backup.$(date +%Y%m%d_%H%M%S)"
echo "Creating backup: $BACKUP_FILE"
cp "$DB_FILE" "$BACKUP_FILE"

# Выполняем обновление
sqlite3 "$DB_FILE" "
BEGIN TRANSACTION;

-- Обновляем file_path
UPDATE books 
SET file_path = '$NEW_PATH' || SUBSTR(file_path, $((${#OLD_PATH} + 1)))
WHERE file_path LIKE '$OLD_PATH/%';

-- Обновляем archive_path  
UPDATE books 
SET archive_path = '$NEW_PATH' || SUBSTR(archive_path, $((${#OLD_PATH} + 1)))
WHERE archive_path LIKE '$OLD_PATH/%';

-- Обновляем archives таблицу если есть
UPDATE archives 
SET archive_path = '$NEW_PATH' || SUBSTR(archive_path, $((${#OLD_PATH} + 1)))
WHERE archive_path LIKE '$OLD_PATH/%';

COMMIT;
"

# Проверяем результат
UPDATED_COUNT=$(sqlite3 "$DB_FILE" "SELECT COUNT(*) FROM books WHERE file_path LIKE '$NEW_PATH/%';")
echo "Updated $UPDATED_COUNT records"

# Показываем несколько примеров
echo "Sample updated records:"
sqlite3 "$DB_FILE" "SELECT id, file_path FROM books WHERE file_path LIKE '$NEW_PATH/%' LIMIT 5;"