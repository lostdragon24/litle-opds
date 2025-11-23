#!/bin/bash

# Удаление дубликатов книг из базы и файловой системы
# Использование: ./remove_duplicates.sh [путь_к_базе_данных] [--dry-run]

set -e

DB_PATH="${1:-./library.db}"
DRY_RUN=false

# Проверяем флаг dry-run
if [[ "$1" == "--dry-run" ]]; then
    DB_PATH="./library.db"
    DRY_RUN=true
elif [[ "$2" == "--dry-run" ]]; then
    DRY_RUN=true
fi

if [[ ! -f "$DB_PATH" ]]; then
    echo "❌ Ошибка: База данных '$DB_PATH' не найдена!"
    exit 1
fi

echo "🗑️  УДАЛЕНИЕ ДУБЛИКАТОВ КНИГ"
echo "================================================"
echo "📁 База данных: $DB_PATH"
echo "📅 Дата: $(date '+%Y-%m-%d %H:%M:%S')"
echo "🔍 Режим: $([ "$DRY_RUN" = true ] && echo 'ТЕСТОВЫЙ (без удаления)' || echo 'РЕАЛЬНЫЙ')"
echo ""

# Создаем резервную копию базы
BACKUP_FILE="${DB_PATH}.backup.$(date +%Y%m%d_%H%M%S)"
if [[ "$DRY_RUN" = false ]]; then
    cp "$DB_PATH" "$BACKUP_FILE"
    echo "📦 Создана резервная копия: $BACKUP_FILE"
fi

# Функция для выполнения SQL
run_sql() {
    sqlite3 "$DB_PATH" "$1"
}

# Функция для выполнения SQL с выводом
run_sql_header() {
    sqlite3 -header -column "$DB_PATH" "$1"
}

echo "🔍 Поиск дубликатов по названию и автору..."
echo "-------------------------------------------"

# Создаем временный файл для хранения ID на удаление
TEMP_FILE=$(mktemp)

# Находим дубликаты по названию и автору (оставляем первую запись)
run_sql "
SELECT b.id
FROM books b
JOIN (
    SELECT 
        title,
        author,
        MIN(id) as keep_id,
        COUNT(*) as dup_count
    FROM books 
    WHERE title IS NOT NULL AND author IS NOT NULL AND title != '' AND author != ''
    GROUP BY title, author 
    HAVING COUNT(*) > 1
) dups ON b.title = dups.title AND b.author = dups.author
WHERE b.id != dups.keep_id;" > "$TEMP_FILE"

# Читаем ID для удаления в массив
mapfile -t DELETE_IDS < "$TEMP_FILE"
TOTAL_DUP_COUNT=${#DELETE_IDS[@]}

echo "📊 Найдено дубликатов для удаления: $TOTAL_DUP_COUNT"

if [[ "$TOTAL_DUP_COUNT" -eq 0 ]]; then
    echo "🎉 Дубликатов не найдено!"
    rm "$TEMP_FILE"
    exit 0
fi

# Преобразуем массив ID в строку для SQL запроса
ID_LIST=$(IFS=,; echo "${DELETE_IDS[*]}")

# Показываем примеры дубликатов
echo ""
echo "📋 ПРИМЕРЫ ДУБЛИКАТОВ:"
echo "======================"
run_sql_header "
SELECT 
    dups.title as 'Название',
    dups.author as 'Автор',
    dups.dup_count as 'Копий',
    dups.keep_id as 'Сохранить ID',
    GROUP_CONCAT(b.id, ', ') as 'Удалить ID',
    GROUP_CONCAT(substr(b.file_path, 1, 30), ' | ') as 'Файлы для удаления'
FROM (
    SELECT 
        title,
        author,
        MIN(id) as keep_id,
        COUNT(*) as dup_count
    FROM books 
    WHERE title IS NOT NULL AND author IS NOT NULL AND title != '' AND author != ''
    GROUP BY title, author 
    HAVING COUNT(*) > 1
) dups
JOIN books b ON b.title = dups.title AND b.author = dups.author AND b.id != dups.keep_id
GROUP BY dups.title, dups.author
ORDER BY dups.dup_count DESC
LIMIT 15;"

# 2. УДАЛЕНИЕ ФАЙЛОВ И ЗАПИСЕЙ (если не dry-run)
echo ""
echo "🗑️  ПРОЦЕСС УДАЛЕНИЯ:"
echo "===================="

if [[ "$DRY_RUN" = true ]]; then
    echo "🔒 ТЕСТОВЫЙ РЕЖИМ - файлы не будут удалены"
    echo ""
    echo "📋 Полный список файлов для удаления:"
    run_sql_header "
    SELECT 
        b.id as 'ID',
        b.file_path as 'Файл',
        b.title as 'Название', 
        b.author as 'Автор',
        'title_author_duplicate' as 'Причина'
    FROM books b
    WHERE b.id IN ($ID_LIST)
    ORDER BY b.title, b.author;"
    
    echo ""
    echo "📊 ИТОГО ДЛЯ УДАЛЕНИЯ:"
    run_sql_header "
    SELECT 
        COUNT(*) as 'Всего записей',
        COUNT(DISTINCT file_path) as 'Уникальных файлов'
    FROM books 
    WHERE id IN ($ID_LIST);"
    
    echo ""
    echo "💡 Для реального удаления запустите: $0 $DB_PATH"
else
    # Режим реального удаления
    
    # Создаем лог удаления
    LOG_FILE="duplicates_removal_$(date +%Y%m%d_%H%M%S).log"
    
    echo "📝 Начинаем удаление файлов и записей..."
    echo "📄 Лог будет сохранен в: $LOG_FILE"
    
    # Логируем удаляемые файлы
    {
        echo "Лог удаления дубликатов - $(date)"
        echo "База данных: $DB_PATH"
        echo "Всего записей для удаления: $TOTAL_DUP_COUNT"
        echo "================================================"
        
        # Получаем полную информацию об удаляемых записях
        run_sql_header "
        SELECT 
            b.id as 'ID',
            b.file_path as 'Файл',
            b.title as 'Название', 
            b.author as 'Автор',
            b.file_type as 'Тип',
            'title_author_duplicate' as 'Причина'
        FROM books b
        WHERE b.id IN ($ID_LIST)
        ORDER BY b.title, b.author;"
    } > "$LOG_FILE"
    
    # Удаляем файлы
    echo ""
    echo "🗑️  Удаление файлов..."
    run_sql "SELECT file_path FROM books WHERE id IN ($ID_LIST);" | while read -r file_path; do
        if [[ -n "$file_path" && -f "$file_path" ]]; then
            echo "❌ Удаляем файл: $file_path"
            rm "$file_path"
        elif [[ -n "$file_path" ]]; then
            echo "⚠️  Файл не найден: $file_path"
        fi
    done
    
    # Удаляем записи из базы
    echo ""
    echo "🗃️  Удаление записей из базы данных..."
    run_sql "DELETE FROM books WHERE id IN ($ID_LIST);"
    
    echo ""
    echo "✅ УДАЛЕНИЕ ЗАВЕРШЕНО!"
    echo "📊 РЕЗУЛЬТАТЫ:"
    echo "   - Удалено записей из БД: $TOTAL_DUP_COUNT"
    echo "   - Лог сохранен в: $LOG_FILE"
    echo "   - Резервная копия БД: $BACKUP_FILE"
fi

# Очищаем временный файл
rm "$TEMP_FILE"

# Финальная статистика
echo ""
echo "📈 ФИНАЛЬНАЯ СТАТИСТИКА БАЗЫ:"
echo "============================="
run_sql_header "
SELECT 
    (SELECT COUNT(*) FROM books) as 'Книг в базе',
    (SELECT COUNT(DISTINCT title || '|' || author) FROM books WHERE title IS NOT NULL AND author IS NOT NULL) as 'Уникальных книг',
    (SELECT COUNT(*) FROM (
        SELECT title, author, COUNT(*) as cnt
        FROM books 
        WHERE title IS NOT NULL AND author IS NOT NULL
        GROUP BY title, author 
        HAVING COUNT(*) > 1
    )) as 'Оставшихся дубликатов';"

if [[ "$DRY_RUN" = false ]]; then
    echo ""
    echo "🎉 Очистка дубликатов завершена!"
    echo ""
    echo "💡 Рекомендации:"
    echo "   - Запустите сканер снова для обновления информации о файлах"
    echo "   - Рассмотрите добавление file_hash и file_size для лучшего определения дубликатов"
fi