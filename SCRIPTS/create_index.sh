#!/bin/bash

# create_indexes.sh
# Создаёт необходимые индексы в SQLite-базе книг для ускорения поиска

set -euo pipefail

# === Настройки ===
DB_PATH="${OPDS_DB_PATH:-/home/alex/book_scanner/library.db}"
LOG_FILE="/home/pi/opds2/create_indexes.log"

# Создаём лог-директорию, если нужно
mkdir -p "$(dirname "$LOG_FILE")"

log() {
    echo "[$(date +'%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# Проверка существования базы
if [[ ! -f "$DB_PATH" ]]; then
    echo "Ошибка: база данных не найдена: $DB_PATH" >&2
    exit 1
fi

log "Начинаю создание индексов в $DB_PATH..."

# SQL-запросы для создания индексов
SQL=$(cat <<EOF
CREATE INDEX IF NOT EXISTS idx_books_file_hash ON books(file_hash);
CREATE INDEX IF NOT EXISTS idx_books_file_path ON books(file_path);
CREATE INDEX IF NOT EXISTS idx_books_archive_path ON books(archive_path);
CREATE INDEX IF NOT EXISTS idx_books_title ON books(title);
CREATE INDEX IF NOT EXISTS idx_books_author ON books(author);
CREATE INDEX IF NOT EXISTS idx_books_genre ON books(genre);
CREATE INDEX IF NOT EXISTS idx_books_series ON books(series);
CREATE INDEX IF NOT EXISTS idx_books_year ON books(year);
CREATE INDEX IF NOT EXISTS idx_books_language ON books(language);
CREATE INDEX IF NOT EXISTS idx_books_publisher ON books(publisher);
EOF
)

# Выполняем
if echo "$SQL" | sqlite3 "$DB_PATH"; then
    log "✅ Все индексы успешно созданы."
else
    log "❌ Ошибка при создании индексов!"
    exit 1
fi