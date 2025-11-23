#!/bin/bash

# Скрипт для поиска и удаления дубликатов книг
# Оставляет книгу с наибольшим размером

DB_FILE="${1:-./library.db}"

if [ ! -f "$DB_FILE" ]; then
    echo "❌ База данных не найдена: $DB_FILE"
    exit 1
fi

echo "🔍 ПОИСК И УДАЛЕНИЕ ДУБЛИКАТОВ КНИГ"
echo "================================================"
echo "📁 База данных: $DB_FILE"
echo

# Создаем временную директорию для бэкапа
BACKUP_DIR="./backup_$(date +%Y%m%d_%H%M%S)"
mkdir -p "$BACKUP_DIR"

# Создаем бэкап базы данных
echo "💾 Создаем бэкап базы данных..."
cp "$DB_FILE" "$BACKUP_DIR/"

# Функция для вывода статистики
show_stats() {
    echo
    echo "📊 СТАТИСТИКА:"
    echo "--------------"
    sqlite3 "$DB_FILE" "SELECT COUNT(*) as 'Всего книг' FROM books;"
    sqlite3 "$DB_FILE" "SELECT COUNT(DISTINCT title || '|' || author) as 'Уникальных книг (название+автор)' FROM books;"
    echo
}

# Выводим начальную статистику
echo "📈 НАЧАЛЬНАЯ СТАТИСТИКА:"
show_stats

echo "🔎 Ищем дубликаты по названию и автору..."
sqlite3 -header -column "$DB_FILE" "
SELECT 
    title as 'Название',
    author as 'Автор', 
    COUNT(*) as 'Дубликатов',
    MIN(file_size) as 'Мин. размер',
    MAX(file_size) as 'Макс. размер',
    GROUP_CONCAT(id, ', ') as 'ID книг'
FROM books 
GROUP BY title, author 
HAVING COUNT(*) > 1 
ORDER BY COUNT(*) DESC, title
LIMIT 20;
" | head -20

echo
echo "🗑️  Начинаем удаление дубликатов..."

# Создаем временную таблицу для хранения ID книг, которые нужно сохранить
sqlite3 "$DB_FILE" "
-- Создаем временную таблицу с книгами для сохранения (самые большие по размеру)
CREATE TEMPORARY TABLE books_to_keep AS
SELECT 
    b1.id,
    b1.title,
    b1.author,
    b1.file_size,
    ROW_NUMBER() OVER (PARTITION BY b1.title, b1.author ORDER BY b1.file_size DESC, b1.id DESC) as rn
FROM books b1
WHERE b1.title IS NOT NULL AND b1.author IS NOT NULL;

-- Подсчитываем сколько будет удалено
CREATE TEMPORARY TABLE deletion_stats AS
SELECT COUNT(*) as to_delete FROM books_to_keep WHERE rn > 1;

-- Удаляем дубликаты (оставляем только первую запись для каждой группы)
DELETE FROM books 
WHERE id IN (
    SELECT id FROM books_to_keep WHERE rn > 1
);

-- Выводим статистику удаления
SELECT 'Удалено дубликатов: ' || (SELECT to_delete FROM deletion_stats) as result;

-- Удаляем временные таблицы
DROP TABLE books_to_keep;
DROP TABLE deletion_stats;
"

echo
echo "✅ Удаление завершено!"

# Выводим конечную статистику
echo "📈 КОНЕЧНАЯ СТАТИСТИКА:"
show_stats

# Показываем топ-10 самых больших сохраненных книг
echo "🏆 ТОП-10 САМЫХ БОЛЬШИХ КНИГ:"
sqlite3 -header -column "$DB_FILE" "
SELECT 
    title as 'Название',
    author as 'Автор',
    file_size as 'Размер',
    file_type as 'Тип'
FROM books 
WHERE file_size > 0 
ORDER BY file_size DESC 
LIMIT 10;
"

# Проверяем остались ли дубликаты
echo
echo "🔍 ПРОВЕРКА НА ДУБЛИКАТЫ:"
DUPLICATES=$(sqlite3 "$DB_FILE" "
SELECT COUNT(*) 
FROM (
    SELECT title, author 
    FROM books 
    GROUP BY title, author 
    HAVING COUNT(*) > 1
);
")

if [ "$DUPLICATES" -eq 0 ]; then
    echo "✅ Дубликатов не найдено!"
else
    echo "⚠️  Найдено групп дубликатов: $DUPLICATES"
    sqlite3 -header -column "$DB_FILE" "
    SELECT 
        title as 'Название',
        author as 'Автор', 
        COUNT(*) as 'Дубликатов'
    FROM books 
    GROUP BY title, author 
    HAVING COUNT(*) > 1 
    ORDER BY COUNT(*) DESC
    LIMIT 10;
    "
fi

echo
echo "💾 Бэкап сохранен в: $BACKUP_DIR"
echo "🎉 Готово!"