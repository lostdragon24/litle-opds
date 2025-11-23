#!/bin/bash

# Настройки подключения
MYSQL_HOST="localhost"
MYSQL_USER="opds"
MYSQL_PASSWORD="passvord"
MYSQL_DATABASE="mybook"

echo "=== ОЧИСТКА БАЗЫ ДАННЫХ MYSQL ==="
echo "Хост: $MYSQL_HOST"
echo "База данных: $MYSQL_DATABASE"
echo ""

# Проверяем подключение
if ! mysql -h $MYSQL_HOST -u $MYSQL_USER -p$MYSQL_PASSWORD -e "USE $MYSQL_DATABASE;" 2>/dev/null; then
    echo "ОШИБКА: Не удалось подключиться к базе данных!"
    exit 1
fi

# Показываем текущую статистику
echo "Текущее состояние базы данных:"
mysql -h $MYSQL_HOST -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE << EOF
SELECT 
    (SELECT COUNT(*) FROM books) as books_count,
    (SELECT COUNT(*) FROM archives) as archives_count,
    (SELECT MAX(id) FROM books) as max_book_id,
    (SELECT MAX(id) FROM archives) as max_archive_id;
EOF

echo ""
read -p "Вы уверены, что хотите очистить базу данных? (y/N): " confirm

if [[ $confirm != [yY] ]]; then
    echo "Очистка отменена."
    exit 0
fi

echo "Начинаем очистку..."

# Очищаем базу данных
mysql -h $MYSQL_HOST -u $MYSQL_USER -p$MYSQL_PASSWORD $MYSQL_DATABASE << EOF
-- Отключаем проверку внешних ключей для безопасной очистки
SET FOREIGN_KEY_CHECKS = 0;

-- Очищаем таблицы
TRUNCATE TABLE books;
TRUNCATE TABLE archives;

-- Включаем проверку внешних ключей обратно
SET FOREIGN_KEY_CHECKS = 1;

-- Показываем результат очистки
SELECT 
    'После очистки:' as status,
    (SELECT COUNT(*) FROM books) as books_count,
    (SELECT COUNT(*) FROM archives) as archives_count;
EOF

echo "✅ База данных успешно очищена!"